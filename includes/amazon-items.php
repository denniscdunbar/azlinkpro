<?php
/**
 * Amazon product lookups shared by Tables, the product image block and Showcase:
 * credentials, ASIN and image checks, affiliate links, item details and search.
 * Moved out of the Showcase module in 1.4.0-beta.22 so the free build has them.
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_sc_defaults() {
    return array('tag'=>'', 'client_id'=>'', 'secret'=>'', 'marketplace'=>'www.amazon.com');
}
function azlinkpro_sc_credentials() {
    // One shared Amazon configuration for all AzlinkPRO modules.
    return function_exists('azlinkpro_tbl_options') ? azlinkpro_tbl_options() : azlinkpro_sc_defaults();
}

function azlinkpro_sc_valid_asin($asin) {
    if (!is_string($asin) && !is_int($asin)) { return ''; }
    $asin = strtoupper(trim((string) $asin));
    return preg_match('/^[A-Z0-9]{10}$/D', $asin) ? $asin : '';
}
function azlinkpro_sc_safe_amazon_image($url) {
    if (!is_string($url) || !$url) { return ''; }
    $parts=wp_parse_url($url);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') { return ''; }
    $host=strtolower($parts['host'] ?? '');
    if (!in_array($host, array('m.media-amazon.com','images-na.ssl-images-amazon.com','images-eu.ssl-images-amazon.com'),true)) { return ''; }
    return esc_url_raw($url);
}
function azlinkpro_sc_amazon_link($asin, $opt=null) {
    $opt = $opt ?: azlinkpro_sc_credentials();
    $url = 'https://' . azlinkpro_tbl_marketplace($opt) . '/dp/' . rawurlencode($asin);
    return empty($opt['tag']) ? $url : $url . '?tag=' . rawurlencode($opt['tag']);
}
/**
 * Backwards-compatible Showcase token wrapper. All AzlinkPRO modules now share
 * credential-scoped tokens and the same rate-limit/renewal behavior.
 */
function azlinkpro_sc_access_token($opt, $refresh=false) {
    if (!function_exists('azlinkpro_tbl_token') || !function_exists('azlinkpro_tbl_token_cache_key')) {
        return new WP_Error('module_missing', 'Amazon integration is not available.');
    }
    if ($refresh) { delete_transient(azlinkpro_tbl_token_cache_key($opt)); }
    return azlinkpro_tbl_token($opt);
}

function azlinkpro_sc_get_item_from_api($asin, $force=false) {
    $asin=azlinkpro_sc_valid_asin($asin);
    if (!$asin) { return new WP_Error('invalid_asin','Enter a valid 10-character Amazon ASIN.'); }
    $opt=azlinkpro_sc_credentials();
    if (empty($opt['tag'])) { return new WP_Error('missing_tag', 'Configure your own Amazon Partner Tag in AzlinkPRO settings.'); }
    $cache_key='atps_item_' . md5($asin.'|'.$opt['marketplace'].'|'.$opt['tag']);
    // Entries cached before beta.21 have no brand for the short name; fetch those again.
    if (!$force) { $existing=get_transient($cache_key); if (is_array($existing) && array_key_exists('brand',$existing)) { return $existing; } }
    // Force bypasses the product cache, not the access-token cache. The shared
    // catalog client renews tokens once on 401 and applies 429/503 backoff.
    $body=array('itemIds'=>array($asin),'itemIdType'=>'ASIN',
        'resources'=>array('images.primary.large','images.primary.medium','images.primary.small',
          'images.variants.large','images.variants.medium','images.variants.small',
          'itemInfo.title','itemInfo.features','itemInfo.byLineInfo'));
    $result=azlinkpro_tbl_api_call('getItems', $body, $opt);
    if (is_wp_error($result)) { return $result; }
    // Amazon's current official examples use itemsResult; some clients use itemResults.
    $items=$result['itemsResult']['items'] ?? $result['itemResults']['items'] ?? array();
    if (!is_array($items)) { $items=array(); }
    $match=null;
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['asin'] ?? null)) { continue; }
        if (strtoupper($item['asin']) === $asin) { $match=$item; break; }
    }
    if (!is_array($match)) { return new WP_Error('not_found','No product data was returned for this ASIN in the selected marketplace.'); }
    $images=azlinkpro_amazon_image_choices($match);
    $image=$images ? $images[0]['url'] : '';
    $features=$match['itemInfo']['features']['displayValues'] ?? array();
    $bullets=array();
    if (is_array($features)) {
        foreach (array_slice($features,0,8) as $feature) {
            if (is_string($feature) && trim($feature) !== '') { $bullets[]=wp_strip_all_tags($feature); }
        }
    }
    $title = $match['itemInfo']['title']['displayValue'] ?? '';
    $title = sanitize_text_field(is_string($title) ? $title : '');
    $brand = azlinkpro_amazon_brand($match);
    $output=array('asin'=>$asin,'title'=>$title,'brand'=>$brand,'short_title'=>azlinkpro_short_product_name($title,$brand),
        'image'=>$image,'images'=>$images,'bullets'=>$bullets);
    // Store only API-provided image URLs and text for 12 hours; do not download images to WordPress.
    set_transient($cache_key,$output,12*HOUR_IN_SECONDS);
    return $output;
}

/**
 * Search the Amazon Creators API by keyword. Called only from an authorized
 * WordPress admin AJAX request. Returns a short-lived, display-only list;
 * nothing is automatically saved to post meta until the editor saves a chosen ASIN.
 */
function azlinkpro_sc_search_products($keywords, $page=1) {
    $page=max(1,min(10,absint($page)));
    $keywords = trim(sanitize_text_field((string) $keywords));
    if (function_exists('mb_substr')) { $keywords = mb_substr($keywords, 0, 120); }
    else { $keywords = substr($keywords, 0, 120); }
    if (strlen($keywords) < 2) {
        return new WP_Error('short_query','Enter at least two characters to search Amazon products.');
    }
    $opt = azlinkpro_sc_credentials();
    if (empty($opt['tag'])) { return new WP_Error('missing_tag', 'Configure your own Amazon tracking tag in AzlinkPRO settings.'); }
    $cache_key = 'atps_search_' . md5($opt['marketplace'].'|'.$opt['tag'].'|'.strtolower($keywords).'|'.$page);
    $cached = get_transient($cache_key);
    if (is_array($cached)) { return $cached; }
    $request = array(
        'keywords'=>$keywords,
        'itemCount'=>10,
        'itemPage'=>$page,
        'resources'=>array('images.primary.large','images.primary.medium','itemInfo.title','itemInfo.features'),
    );
    $data=azlinkpro_tbl_api_call('searchItems', $request, $opt);
    if (is_wp_error($data)) { return $data; }
    if (!is_array($data)) { return new WP_Error('search_parse','Amazon search returned unexpected data.'); }
    $items=$data['searchResult']['items'] ?? $data['searchResults']['items'] ?? array();
    $results=array();
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $asin=azlinkpro_sc_valid_asin($item['asin'] ?? '');
            if (!$asin) { continue; }
            $image='';
            foreach (array('large','medium','small') as $size) {
                $image=azlinkpro_sc_safe_amazon_image($item['images']['primary'][$size]['url']??'');
                if ($image) { break; }
            }
            $title=$item['itemInfo']['title']['displayValue']??'';
            $name=sanitize_text_field(is_string($title) ? $title : '');
            if (!$name) { $name='Amazon product '.$asin; }
            $results[]=array('asin'=>$asin,'title'=>$name,'image'=>$image);
        }
    }
    // Search results are ephemeral and are never persisted to the Media Library.
    set_transient($cache_key,$results,5*MINUTE_IN_SECONDS);
    return $results;
}

/** Plain-text gold button label; empty falls back to Check Price, like tables and Pros & Cons. */
function azlinkpro_sc_button_label($value) {
    $label = is_scalar($value) ? trim(sanitize_text_field((string) $value)) : '';
    return $label === '' ? 'Check Price' : azlinkpro_text_limit($label, 50);
}
