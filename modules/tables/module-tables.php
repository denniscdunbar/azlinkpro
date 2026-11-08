<?php
/** Internal AzlinkPRO bundled module. */
if (!defined('ABSPATH')) { exit; }

define('AZLINKPRO_TBL_VER', AZLINKPRO_VERSION);
define('AZLINKPRO_TBL_URL', plugin_dir_url(__FILE__));

// Load the public stylesheet in the document head, not when a shortcode is
// processed later in the page. Some mobile themes do not reliably print
// late-enqueued styles in their footer templates.
function azlinkpro_tbl_enqueue_public_assets() {
    if (!azlinkpro_should_load_asset('tables')) { return; }
    wp_enqueue_style('amatablepro-frontend', AZLINKPRO_TBL_URL . 'assets/frontend.css', array(), AZLINKPRO_TBL_VER);
}
add_action('wp_enqueue_scripts', 'azlinkpro_tbl_enqueue_public_assets');

function azlinkpro_tbl_register_type() {
    register_post_type('atp_table', array(
        'labels' => array(
            'name' => 'AzlinkPRO - Tables', 'singular_name' => 'Comparison Table',
            'menu_name' => 'AzlinkPRO - Tables', 'add_new' => 'New Table',
            'add_new_item' => 'Create Comparison Table', 'edit_item' => 'Edit Comparison Table',
            'all_items' => 'AZ Tables', 'search_items' => 'Search Tables',
        ),
        'public' => false, 'show_ui' => true, 'show_in_menu' => 'azlinkpro',
        'menu_icon' => 'dashicons-editor-table', 'supports' => array('title'),
        'capability_type' => 'post', 'map_meta_cap' => true,
        'show_in_rest' => false, 'has_archive' => false,
    ));
}
add_action('init', 'azlinkpro_tbl_register_type');

// Rebrand migration: keep legacy post types/metadata; update only earlier default
// disclosures. A custom disclosure entered by the editor is never overwritten.
function azlinkpro_tbl_default_disclosure() {
    return 'As an Amazon Associate, we may earn a small commission from qualifying purchases.';
}
function azlinkpro_tbl_current_disclosure($saved) {
    $saved = is_string($saved) ? $saved : '';
    $old_defaults = array(
        'As an Amazon Associate, we earn from qualifying purchases.',
        'As an Amazon Associate, we may earn from qualifying purchases.'
    );
    return trim($saved) === '' || in_array(trim($saved), $old_defaults, true) ? azlinkpro_tbl_default_disclosure() : $saved;
}
function azlinkpro_tbl_migrate_default_disclosures() {
    if (get_option('atp_azlink_disclosure_migrated_112', false)) { return; }
    if (!current_user_can('manage_options')) { return; }
    global $wpdb;
    $old_defaults = array(
        'As an Amazon Associate, we earn from qualifying purchases.',
        'As an Amazon Associate, we may earn from qualifying purchases.'
    );
    foreach ($old_defaults as $old) {
        // One-time upgrade of the old default disclosure text in saved tables.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- runs once, on upgrade only; no core API updates meta by value across all posts.
        $wpdb->update(
            $wpdb->postmeta,
            array('meta_value' => azlinkpro_tbl_default_disclosure()),
            array('meta_key' => '_atp_disclosure', 'meta_value' => $old),
            array('%s'), array('%s', '%s')
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    }
    update_option('atp_azlink_disclosure_migrated_112', 1, false);
}
add_action('admin_init', 'azlinkpro_tbl_migrate_default_disclosures');


function azlinkpro_tbl_options() {
    $options = get_option('atp_settings', array());
    if (!is_array($options)) { $options = array(); }
    $options = wp_parse_args($options, array('tag'=>'','client_id'=>'','secret'=>'','marketplace'=>'www.amazon.com'));
    if (defined('ATP_AMAZON_CLIENT_ID') && ATP_AMAZON_CLIENT_ID) { $options['client_id'] = ATP_AMAZON_CLIENT_ID; }
    if (defined('ATP_AMAZON_CLIENT_SECRET') && ATP_AMAZON_CLIENT_SECRET) { $options['secret'] = ATP_AMAZON_CLIENT_SECRET; }
    // Credentials are site-owned configuration; no publisher data ships in this ZIP.
    return $options;
}
function azlinkpro_tbl_marketplace($opt) { return $opt['marketplace'] === 'www.amazon.ca' ? 'www.amazon.ca' : 'www.amazon.com'; }
/** Include the secret in the opaque key so rotated credentials cannot reuse a token.
 *  The legacy client-ID-only key is deliberately not read. */
function azlinkpro_tbl_token_cache_key($opt) {
    return 'atp_tok_' . md5((string)($opt['client_id'] ?? '') . '|' . (string)($opt['secret'] ?? ''));
}
/** A shared, short-lived backoff for catalog requests. Does not affect saved product rendering. */
function azlinkpro_tbl_api_pause_key($opt) {
    return 'atp_api_pause_' . md5((string)($opt['client_id'] ?? '') . '|' . (string)($opt['tag'] ?? '') . '|' . azlinkpro_tbl_marketplace($opt));
}
/** Product cache key is kept byte-for-byte compatible with earlier releases. */
function azlinkpro_tbl_product_cache_key($opt, $asin) {
    return 'atp_it_' . md5(azlinkpro_tbl_marketplace($opt) . '|' . ($opt['tag'] ?? '') . '|' . $asin);
}
function azlinkpro_tbl_token($opt) {
    if (empty($opt['client_id']) || empty($opt['secret'])) { return new WP_Error('missing_creds','Creators API credentials are not configured.'); }
    $key = azlinkpro_tbl_token_cache_key($opt);
    $token = get_transient($key);
    if (is_string($token) && $token !== '') { return $token; }
    $resp = wp_remote_post('https://api.amazon.com/auth/o2/token', array(
        'timeout'=>18,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576,
        'headers'=>array('Content-Type'=>'application/json'),
        'body'=>wp_json_encode(array('grant_type'=>'client_credentials','client_id'=>$opt['client_id'],'client_secret'=>$opt['secret'],'scope'=>'creatorsapi::default')),
    ));
    if (is_wp_error($resp)) { return new WP_Error('token_network','Amazon authentication is temporarily unreachable. Check your connection and try again.'); }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || !is_array($data) || !isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '') {
        return new WP_Error('token_http', 'Amazon authentication rejected the request (HTTP ' . $code . '). Check your Creators API credentials and account approval.');
    }
    // Never cache a token longer than Amazon says it remains valid. A token
    // with an unusually short lifetime can be used for this request, but not
    // reliably reused: the old 60-second minimum could cache an expired token.
    $expires_in = isset($data['expires_in']) && is_numeric($data['expires_in'])
        ? (int) $data['expires_in'] : 0;
    if ($expires_in > 150) {
        $ttl = min(3300, $expires_in - 120);
        set_transient($key, $data['access_token'], $ttl);
    }
    return $data['access_token'];
}
/** Safe public error identifier, never a raw upstream diagnostic or secret. */
function azlinkpro_tbl_api_error_code($data) {
    if (!is_array($data)) { return ''; }
    $candidate = '';
    if (isset($data['errors'][0]) && is_array($data['errors'][0])) {
        $candidate = $data['errors'][0]['code'] ?? '';
    }
    if ($candidate === '' && isset($data['code'])) { $candidate = $data['code']; }
    if (!is_string($candidate) && !is_int($candidate)) { return ''; }
    return substr(sanitize_text_field((string)$candidate), 0, 64);
}
function azlinkpro_tbl_api_call($operation, $body, $opt) {
    if (!in_array($operation, array('getItems','searchItems'), true)) {
        return new WP_Error('api_operation', 'Unsupported Amazon catalog operation.');
    }
    if (empty($opt['tag'])) { return new WP_Error('missing_tag', 'Enter your own Amazon Associates Partner Tag in AzlinkPRO settings.'); }
    if (get_transient(azlinkpro_tbl_api_pause_key($opt))) {
        return new WP_Error('api_rate_limited', 'Amazon is temporarily limiting requests. Wait about a minute before trying again; existing published products are unaffected.');
    }
    $token = azlinkpro_tbl_token($opt);
    if (is_wp_error($token)) { return $token; }
    $marketplace = azlinkpro_tbl_marketplace($opt);
    $body = array_merge(array('partnerTag'=>$opt['tag'],'marketplace'=>$marketplace), $body);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $resp = wp_remote_post('https://creatorsapi.amazon/catalog/v1/' . $operation, array(
            'timeout'=>22, 'redirection'=>0, 'sslverify'=>true,'limit_response_size'=>2097152,
            'headers'=>array('Authorization'=>'Bearer ' . $token,'Content-Type'=>'application/json', 'x-marketplace'=>$marketplace),
            'body'=>wp_json_encode($body),
        ));
        if (is_wp_error($resp)) {
            // A timeout or temporary connectivity problem may affect every
            // storefront request. Back off briefly rather than retrying a
            // failing upstream service for each product on the same page.
            set_transient(azlinkpro_tbl_api_pause_key($opt), 1, 15);
            return new WP_Error('api_network','Amazon catalog is temporarily unreachable. Check your connection and try again shortly.');
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $decoded = json_decode(wp_remote_retrieve_body($resp), true);
        // One renewal only for an expired/revoked access token. Never retry other failures.
        if ($code === 401 && $attempt === 0) {
            delete_transient(azlinkpro_tbl_token_cache_key($opt));
            $token = azlinkpro_tbl_token($opt);
            if (is_wp_error($token)) { return $token; }
            continue;
        }
        if (in_array($code, array(429, 500, 502, 503, 504), true)) {
            set_transient(azlinkpro_tbl_api_pause_key($opt), 1, $code === 429 ? 60 : 30);
            return new WP_Error('api_rate_limited', $code === 429
                ? 'Amazon is limiting catalog requests (HTTP 429). Wait about a minute and try again.'
                : 'Amazon catalog is temporarily unavailable (HTTP ' . $code . '). Try again shortly.');
        }
        if ($code !== 200) {
            $code_name = azlinkpro_tbl_api_error_code($decoded);
            return new WP_Error('api_http','Amazon catalog returned HTTP ' . $code . ($code_name ? ' (' . $code_name . ')' : '') . '. Check API access, tag, marketplace and ASINs.');
        }
        if (!is_array($decoded)) {
            return new WP_Error('api_json','Amazon catalog returned an unrecognized response. Please try again.');
        }
        // HTTP 200 does not guarantee a usable catalog response. A pure
        // error body must not masquerade as a successful zero-result search.
        // Preserve legitimate empty result sets and partial successes.
        $keys = $operation === 'searchItems'
            ? array('searchResult','searchResults')
            : array('itemsResult','itemResults');
        $has_result = false;
        $has_items = false;
        foreach ($keys as $key) {
            if (!isset($decoded[$key]) || !is_array($decoded[$key])) { continue; }
            $has_result = true;
            if (array_key_exists('items', $decoded[$key])) {
                if (!is_array($decoded[$key]['items'])) {
                    return new WP_Error('api_shape','Amazon catalog returned invalid product data. Try again.');
                }
                $has_items = $has_items || count($decoded[$key]['items']) > 0;
            }
        }
        if (!$has_result) {
            $error_code = azlinkpro_tbl_api_error_code($decoded);
            return new WP_Error($error_code ? 'api_result_error' : 'api_shape',
                $error_code
                    ? 'Amazon catalog reported an error (' . $error_code . '). Check API access and try again.'
                    : 'Amazon catalog returned an unexpected result. Try again.');
        }
        if (!$has_items && !empty($decoded['errors'])) {
            $error_code = azlinkpro_tbl_api_error_code($decoded);
            return new WP_Error('api_result_error',
                'Amazon catalog reported an error' . ($error_code ? ' (' . $error_code . ')' : '') . '. Try again.');
        }
        return $decoded;
    }
    return new WP_Error('api_http','Amazon catalog authentication was not accepted. Verify API access.');
}
function azlinkpro_tbl_extract_items($data, $operation) {
    if (!is_array($data)) { return array(); }
    $paths = $operation === 'searchItems'
       ? array($data['searchResult']['items'] ?? null, $data['searchResults']['items'] ?? null)
       : array($data['itemsResult']['items'] ?? null, $data['itemResults']['items'] ?? null);
    foreach ($paths as $items) { if (is_array($items)) { return $items; } }
    return array();
}
function azlinkpro_tbl_safe_amazon_url($url) {
    $parsed = wp_parse_url($url);
    if (!is_array($parsed) || strtolower($parsed['scheme'] ?? '') !== 'https') { return ''; }
    $hostname = strtolower($parsed['host'] ?? '');
    return in_array($hostname, array('m.media-amazon.com','images-na.ssl-images-amazon.com','images-eu.ssl-images-amazon.com'), true) ? esc_url_raw($url) : '';
}
function azlinkpro_tbl_parse_item($item) {
    if (!is_array($item) || !is_string($item['asin'] ?? null)) { return null; }
    $asin = strtoupper(sanitize_text_field($item['asin']));
    if (!preg_match('/^[A-Z0-9]{10}$/D', $asin)) { return null; }
    $images = azlinkpro_amazon_image_choices($item);
    $image = !empty($images) ? $images[0]['url'] : '';
    $title = $item['itemInfo']['title']['displayValue'] ?? '';
    $name = sanitize_text_field(is_string($title) ? $title : '');
    $brand = azlinkpro_amazon_brand($item);
    return array('asin'=>$asin,'name'=>$name,'image'=>$image,'images'=>$images,
        'brand'=>$brand,'short'=>azlinkpro_short_product_name($name, $brand));
}
function azlinkpro_tbl_get_products($asins, $force=false) {
    $ids = array_values(array_unique(array_filter(array_map(function($i){
        $i = strtoupper(trim((string) $i)); return preg_match('/^[A-Z0-9]{10}$/', $i) ? $i : '';
    }, $asins))));
    if (!$ids) { return array(); }
    $opt = azlinkpro_tbl_options();
    $all = array(); $missing = array();
    foreach ($ids as $id) {
        $cache = azlinkpro_tbl_product_cache_key($opt, $id);
        $found = $force ? false : get_transient($cache);
        if (is_array($found)) { $all[$id]=$found; } else { $missing[]=$id; }
    }
    if (!$missing) { return $all; }
    if (empty($opt['secret'])) { return $all; }
    foreach (array_chunk($missing, 10) as $chunk) {
        $res=azlinkpro_tbl_api_call('getItems',array('itemIds'=>$chunk,'itemIdType'=>'ASIN','resources'=>array('images.primary.large','images.primary.medium','images.primary.small','images.variants.large','images.variants.medium','images.variants.small','itemInfo.title','itemInfo.byLineInfo')),$opt);
        if (is_wp_error($res)) { continue; }
        foreach (azlinkpro_tbl_extract_items($res, 'getItems') as $it) {
            $p=azlinkpro_tbl_parse_item($it);
            if (!$p || !in_array($p['asin'], $chunk, true)) { continue; }
            $all[$p['asin']]=$p;
            set_transient(azlinkpro_tbl_product_cache_key($opt, $p['asin']), $p, 12 * HOUR_IN_SECONDS);
        }
    }
    return $all;
}
function azlinkpro_tbl_amazon_url($asin, $opt) {
    $domain = azlinkpro_tbl_marketplace($opt);
    $url = 'https://' . $domain . '/dp/' . rawurlencode($asin);
    $tag = (string) ($opt['tag'] ?? '');
    return $tag === '' ? $url : $url . '?tag=' . rawurlencode($tag);
}
function azlinkpro_tbl_table_header_color($post_id) {
    $stored=get_post_meta($post_id,'_atp_header_color',true);
    return sanitize_hex_color(is_string($stored) ? $stored : '') ?: '#162337';
}
/** Simple luminance-based contrast so light header colors remain readable. */
function azlinkpro_tbl_table_header_text_color($color) {
    $hex=ltrim($color,'#');
    if (strlen($hex)===3) { $hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
    $channels=array();
    foreach (array(0,2,4) as $offset) {
        $c=hexdec(substr($hex,$offset,2))/255;
        $channels[]=$c<=0.04045 ? $c/12.92 : pow(($c+0.055)/1.055,2.4);
    }
    $luminance=$channels[0]*0.2126 + $channels[1]*0.7152 + $channels[2]*0.0722;
    // Pick the highest WCAG contrast of near-black versus white text.
    $dark_luminance=0.0185; // #152238, approximately.
    $contrast_dark=($luminance+0.05)/($dark_luminance+0.05);
    $contrast_white=(1.05)/($luminance+0.05);
    return $contrast_dark >= $contrast_white ? '#152238' : '#ffffff';
}
/** One fingerprint covers both original table settings and Custom Comparison columns.
 * Raw stored values are used so no hidden migration changes the comparison. */
function azlinkpro_table_editor_fingerprint($post_id) {
    $keys = array('_atp_rows', '_atp_header_color', '_atp_ribbon', '_atp_disclosure',
                  '_azp_table_layout_134', '_azp_table_custom_grid_134');
    $snapshot = array();
    foreach ($keys as $key) { $snapshot[$key] = get_post_meta($post_id, $key, true); }
    return hash('sha256', wp_json_encode($snapshot));
}
/** Cache verdict once per save because save_post hooks update different meta keys. */
function azlinkpro_table_editor_revision_matches($post_id) {
    static $verdicts = array();
    $post_id = absint($post_id);
    if (array_key_exists($post_id, $verdicts)) { return $verdicts[$post_id]; }
    // Called only from save handlers that have already verified the editor nonce.
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $revision = isset($_POST['azp_table_revision']) && is_string($_POST['azp_table_revision'])
        ? sanitize_text_field(wp_unslash($_POST['azp_table_revision'])) : '';
    $matches = (bool) preg_match('/^[a-f0-9]{64}$/D', $revision) &&
        hash_equals(azlinkpro_table_editor_fingerprint($post_id), $revision);
    $verdicts[$post_id] = $matches;
    if (!$matches && !empty($_POST['atp_rows_nonce'])) {
        set_transient('azp_table_conflict_' . get_current_user_id() . '_' . $post_id, 1, 5 * MINUTE_IN_SECONDS);
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    return $matches;
}
function azlinkpro_table_editor_conflict_notice() {
    if (!function_exists('get_current_screen')) { return; }
    $screen = get_current_screen();
    if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'atp_table') { return; }
    // Read-only: which post's notice to show.
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (!$post_id || !current_user_can('edit_post', $post_id)) { return; }
    $key = 'azp_table_conflict_' . get_current_user_id() . '_' . $post_id;
    if (!get_transient($key)) { return; }
    delete_transient($key);
    echo '<div class="notice notice-error"><p><strong>AzlinkPRO:</strong> This comparison table changed in another editor. Older product rows, custom columns and table settings were not saved. Reload the table to get the latest version. WordPress may have saved the post title or status separately.</p></div>';
}
add_action('admin_notices', 'azlinkpro_table_editor_conflict_notice');

function azlinkpro_tbl_rows($post_id) { $r = get_post_meta($post_id, '_atp_rows', true); return is_array($r) ? $r : array(); }
function azlinkpro_tbl_clean_rows($raw) {
    if (!is_array($raw)) { return array(); }
    $clean = array();
    foreach (array_slice($raw,0,50) as $row) {
        if (!is_array($row)) { continue; }
        $asin = strtoupper(trim(sanitize_text_field(is_string($row['asin'] ?? null) ? $row['asin'] : '')));
        if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) { continue; }
        $clean[] = array(
            'asin'=>$asin,
            'name'=>sanitize_text_field(is_string($row['name'] ?? null) ? $row['name'] : ''),
            'details'=>sanitize_textarea_field(is_string($row['details'] ?? null) ? $row['details'] : ''),
            'review'=>esc_url_raw(is_string($row['review'] ?? null) ? $row['review'] : ''),
            'featured'=>!empty($row['featured']),
            'image_id'=>absint($row['image_id'] ?? 0),
            'image_size'=>azlinkpro_image_size_key($row['image_size'] ?? 'default'),
            'image_custom_px'=>azlinkpro_image_custom_px($row['image_custom_px'] ?? 180),
            'amazon_image_key'=>isset($row['amazon_image_key']) && is_string($row['amazon_image_key']) && preg_match('/^[a-f0-9]{32}$/D', $row['amazon_image_key']) ? $row['amazon_image_key'] : '',
            'cta'=>sanitize_text_field(is_string($row['cta'] ?? null) ? $row['cta'] : 'Check Price'),
        );
    }
    return $clean;
}
/** Reject invalid table submissions before updating ANY product data. */
/**
 * Require a complete product row from the editor. Older loose validation
 * accepted [{"asin":"..."}] as a valid edit and azlinkpro_tbl_clean_rows() then
 * silently erased the title, notes, image choice and button settings.
 *
 * The browser editor adds missing defaults when opening legacy saved rows.
 * Invalid/incomplete payloads are rejected as a whole, never partially saved.
 */
function azlinkpro_tbl_valid_rows_submission($rows) {
    if (!is_array($rows) || count($rows) > 50) { return false; }
    $seen = array();
    $strings = array('asin','name','details','review','cta','amazon_image_key','image_size');
    $all_fields = array_merge($strings, array('featured','image_id','image_custom_px'));
    foreach ($rows as $row) {
        if (!is_array($row)) { return false; }
        foreach ($all_fields as $field) {
            if (!array_key_exists($field, $row)) { return false; }
        }
        foreach ($strings as $field) {
            if (!is_string($row[$field])) { return false; }
        }
        $asin = strtoupper(trim($row['asin']));
        if (!preg_match('/^[A-Z0-9]{10}$/D', $asin) || isset($seen[$asin])) { return false; }
        $seen[$asin] = true;
        if (strlen($row['name']) > 4000 || strlen($row['details']) > 30000 ||
            strlen($row['review']) > 2048 || strlen($row['cta']) > 1000) { return false; }
        if ($row['amazon_image_key'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $row['amazon_image_key'])) { return false; }
        if (!array_key_exists($row['image_size'], azlinkpro_image_size_options())) { return false; }
        if (!is_bool($row['featured']) && !in_array($row['featured'], array(0, 1, '0', '1'), true)) { return false; }
        foreach (array('image_id','image_custom_px') as $number_field) {
            $value = $row[$number_field];
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,10}$/D', (string)$value)) { return false; }
        }
        if ((int)$row['image_custom_px'] > 10000 || (float)$row['image_id'] > 2147483647) { return false; }
    }
    return true;
}
/** Show a useful warning when malformed table data could not be saved. */
function azlinkpro_table_invalid_save_notice() {
    if (!function_exists('get_current_screen')) { return; }
    $screen = get_current_screen();
    if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'atp_table') { return; }
    // Read-only: which post's notice to show.
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (!$post_id || !current_user_can('edit_post', $post_id)) { return; }
    $key = 'azp_table_invalid_' . get_current_user_id() . '_' . $post_id;
    if (!get_transient($key)) { return; }
    delete_transient($key);
    echo '<div class="notice notice-error" role="alert"><p><strong>AzlinkPRO:</strong> Product settings were not saved because the submitted table data was incomplete or invalid. Your previously saved product rows and layout remain intact. Reload the editor before trying again.</p></div>';
}
add_action('admin_notices', 'azlinkpro_table_invalid_save_notice');
function azlinkpro_table_flag_invalid_submission($post_id) {
    set_transient('azp_table_invalid_' . get_current_user_id() . '_' . absint($post_id), 1, 5 * MINUTE_IN_SECONDS);
}

function azlinkpro_tbl_register_metaboxes() {
    add_meta_box('atp_builder','Product Comparison Builder','azlinkpro_tbl_metabox','atp_table','normal','high');
    add_meta_box('azlinkpro_tbl_shortcode','Shortcode','azlinkpro_tbl_shortcode_metabox','atp_table','side','high');
}
add_action('add_meta_boxes', 'azlinkpro_tbl_register_metaboxes');
function azlinkpro_tbl_shortcode_metabox($post) {
    if (!$post->ID || $post->post_status === 'auto-draft') { echo '<p>Publish or save the table to get its shortcode.</p>'; return; }
    echo '<p>Paste into any post or page:</p>';
    azlinkpro_copy_shortcode_control('[azlinkpro_table id="' . absint($post->ID) . '"]', get_the_title($post->ID), true);
    echo '<p>Changes to this table automatically update every page using the shortcode.</p>';
}
function azlinkpro_tbl_metabox($post) {
    wp_nonce_field('atp_save_rows','atp_rows_nonce');
    echo '<input type="hidden" name="azp_table_revision" value="' . esc_attr(azlinkpro_table_editor_fingerprint($post->ID)) . '">';
    $rows=azlinkpro_tbl_rows($post->ID);
    // Preview existing selections without ever persisting an API image URL.
    $preview_asins = array();
    foreach ($rows as &$r) {
        if (!empty($r['image_id'])) { $r['image_preview'] = wp_get_attachment_image_url((int)$r['image_id'],'thumbnail') ?: ''; }
        elseif (!empty($r['amazon_image_key'])) { $preview_asins[] = $r['asin']; }
    }
    unset($r);
    if ($preview_asins) {
        $previews = azlinkpro_tbl_get_products($preview_asins);
        foreach ($rows as &$r) {
            if (empty($r['image_id']) && !empty($r['amazon_image_key'])) {
                $r['image_preview'] = azlinkpro_amazon_selected_image($previews[$r['asin']] ?? array(), $r['amazon_image_key']);
            }
        }
        unset($r);
    }
    $ribbon=get_post_meta($post->ID,'_atp_ribbon',true) ?: 'Top Pick';
    $header_color=azlinkpro_tbl_table_header_color($post->ID);
    $disclosure=get_post_meta($post->ID,'_atp_disclosure',true);
    $disclosure = azlinkpro_tbl_current_disclosure($disclosure);
    // Show the layout selector at the very top of the existing Table Builder.
    // The Custom Grid is edited here, not in a separate WordPress metabox.
    if (class_exists('AzlinkPRO_Layout_Options_134')) {
        AzlinkPRO_Layout_Options_134::table_box($post);
    }
    ?>
    <div id="azp-standard-product-builder">
    <p><strong>Screenshot-style table:</strong> Image | Details | Check Price. Add as many tables as needed; each has its own shortcode. Use <strong>Choose Amazon photo</strong> to select an official primary or alternate image returned by the Creators API, or upload an image you have permission to use.</p>
    <?php $header_swatches = array('#162337','#1267d2','#0f8b8d','#6f42c1','#d97706','#be123c','#475569','#111827'); ?>
    <p><label for="atp-header-color"><strong>Table header background color:</strong></label>
       <input type="color" id="atp-header-color" name="atp_header_color" value="<?php echo esc_attr($header_color); ?>" />
       <label class="atp-header-hex-label" for="atp-header-hex">Exact HEX <input type="text" class="atp-header-hex" id="atp-header-hex" value="<?php echo esc_attr($header_color); ?>" maxlength="7" inputmode="text" spellcheck="false" placeholder="#162337" /></label>
       <span class="atp-header-color-preview" id="atp-header-color-preview" style="background-color:<?php echo esc_attr($header_color); ?>;"></span>
       <span class="description">Choose any header color. Header text automatically switches between dark and light for contrast. Each table can have its own color.</span></p>
    <div class="atp-header-swatches" role="group" aria-label="Quick header color choices">
      <span class="atp-header-swatches-label">Quick picks:</span>
      <?php foreach ($header_swatches as $swatch) : ?>
        <button type="button" class="button atp-color-swatch<?php echo strtolower($swatch) === strtolower($header_color) ? ' is-active' : ''; ?>" data-color="<?php echo esc_attr($swatch); ?>" aria-label="Choose <?php echo esc_attr($swatch); ?> as the table header color" title="<?php echo esc_attr($swatch); ?>"><span aria-hidden="true" style="background-color:<?php echo esc_attr($swatch); ?>;"></span></button>
      <?php endforeach; ?>
      <span class="description">Use a quick pick or the color box for an exact brand color.</span>
    </div>
    <p><label>Featured corner ribbon text: <input id="atp-ribbon" name="atp_ribbon" value="<?php echo esc_attr($ribbon); ?>" maxlength="24"></label></p>
    <p><label>Affiliate disclosure: <input class="large-text" name="atp_disclosure" value="<?php echo esc_attr($disclosure); ?>"></label></p>
    <?php azlinkpro_width_field('atp_max_width', 'Table width', get_post_meta($post->ID,'_atp_max_width',true), get_post_meta($post->ID,'_atp_max_width_px',true),
        'How wide this table can grow on the page. Default fills your post\'s content area. A narrower table is centered and still shrinks on phones. Works with every table layout. You can also set it in a shortcode, for example <code>[azlinkpro_table id="' . (int) $post->ID . '" width="medium"]</code>.',
        azlinkpro_width_options('Default (full width of the content area)', false)); ?>
    <div class="atp-search-panel">
      <h3>Add products</h3>
      <div class="atp-search-controls"><input type="search" id="atp-query" placeholder="Search Amazon by product name or paste ASIN"><button type="button" class="button button-primary" id="atp-search">Search Amazon</button><button type="button" class="button" id="atp-add-asin">Add ASIN</button></div>
      <div id="atp-search-status" role="status" aria-live="polite"></div>
      <div id="atp-search-results" class="atp-search-results" tabindex="0" aria-label="Scroll through Amazon search results"></div>
      <button type="button" class="button" id="atp-search-more" hidden>Load 10 more Amazon results</button>
      <p class="description">Amazon product search requires an authorized Creators API connection. You can always add an ASIN manually.</p>
    </div>
    <h3>Products in this table <span id="atp-count"></span></h3><p class="description">Drag using the handle or use Move Up / Move Down. Each product has its own image size control, right below the Amazon photo selector. Default preserves the current look.</p>
    <div id="azp-table-transfer" class="azp-table-transfer" role="region" aria-label="Table backup and restore">
      <div class="azp-table-transfer-top"><div><strong>Table backup &amp; restore</strong><p class="description">Download your current unsaved editor contents, or restore an AzlinkPRO backup into this editor. Nothing is published until you click Update / Publish.</p></div>
        <div class="azp-table-transfer-actions">
          <button type="button" id="azp-export-table" class="button">Download table backup (.json)</button>
          <label class="button" for="azp-import-table">Choose backup to restore<input id="azp-import-table" type="file" accept=".json,application/json" hidden></label>
        </div>
      </div>
      <p id="azp-table-transfer-status" role="status" aria-live="polite"></p>
    </div>
    <?php do_action('azlinkpro_table_editor_tools', $post); // Paid builds: spreadsheet import & export. ?>
    <div id="atp-product-navigator" class="atp-product-navigator" role="region" aria-label="Find and manage existing product details">
      <div class="atp-product-navigator-controls">
        <label for="atp-product-find"><strong>Find an existing product</strong><input id="atp-product-find" type="search" placeholder="Type a title or 10-character ASIN" autocomplete="off" aria-describedby="atp-product-find-status"></label>
        <button type="button" class="button" id="atp-find-previous" disabled>Previous match</button>
        <button type="button" class="button" id="atp-find-next" disabled>Next match</button>
        <button type="button" class="button" id="atp-collapse-all">Collapse all details</button>
        <button type="button" class="button" id="atp-expand-all">Expand all details</button>
      </div>
      <div id="atp-product-find-status" class="atp-product-find-status" role="status" aria-live="polite"></div>
      <p class="description">Find, jump to, or collapse product settings without changing the saved table or the product order.</p>
    </div>
    <div id="azp-names" class="azp-names" role="region" aria-label="Product names">
      <strong>Product names</strong>
      <button type="button" class="button" id="azp-names-short">Shorten to Brand | Model</button>
      <button type="button" class="button" id="azp-names-full">Use full Amazon titles</button>
      <p class="description">Renames every product in this table using Amazon&#8217;s brand and product name, for example <em>3D MAXpider | All-Weather Floor Mats</em>. You can still edit each name. Nothing is saved until you click Update / Publish.</p>
      <p id="azp-names-status" class="azp-names-status" role="status" aria-live="polite"></p>
    </div>
    <p id="atp-editor-remove-status" class="atp-editor-remove-status" role="status" aria-live="polite"></p>
    <div id="atp-builder"></div>
    <input type="hidden" name="atp_rows_json" id="atp-rows-json" value="<?php echo esc_attr(wp_json_encode($rows)); ?>">
    <p><button type="button" class="button" id="atp-add-blank">+ Add a product by ASIN</button></p>
    </div>
    <?php
}
function azlinkpro_tbl_save_post($post_id, $post, $update) {
    if (!isset($_POST['atp_rows_nonce']) || !is_string($_POST['atp_rows_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['atp_rows_nonce'])),'atp_save_rows')) { return; }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (!current_user_can('edit_post',$post_id)) { return; }
    // Reject malformed or oversized payloads without touching existing rows.
    // The JSON is checked for shape here and every field is sanitized by azlinkpro_tbl_clean_rows().
    // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    if (!isset($_POST['atp_rows_json']) || !is_string($_POST['atp_rows_json']) || strlen($_POST['atp_rows_json']) > 1200000) {
        azlinkpro_table_flag_invalid_submission($post_id); return;
    }
    $json = wp_unslash($_POST['atp_rows_json']);
    // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    $decoded=json_decode($json,true);
    // JSON objects and malformed row arrays used to be accepted as empty/partial
    // data. Reject the entire submission rather than silently deleting rows.
    if (strpos(ltrim($json), '[') !== 0 || !azlinkpro_tbl_valid_rows_submission($decoded)) {
        azlinkpro_table_flag_invalid_submission($post_id); return;
    }
    // Both callbacks share validation so a damaged custom-grid payload cannot
    // leave new products paired with stale comparison headings or notes.
    if (class_exists('AzlinkPRO_Layout_Options_134') &&
        !AzlinkPRO_Layout_Options_134::valid_table_editor_submission($_POST)) {
        azlinkpro_table_flag_invalid_submission($post_id); return;
    }
    if (!azlinkpro_table_editor_revision_matches($post_id)) { return; }
    update_post_meta($post_id,'_atp_rows',azlinkpro_tbl_clean_rows($decoded));
    $raw_header_color=isset($_POST['atp_header_color']) && is_string($_POST['atp_header_color']) ? sanitize_text_field(wp_unslash($_POST['atp_header_color'])) : '#162337';
    update_post_meta($post_id,'_atp_header_color',sanitize_hex_color($raw_header_color) ?: '#162337');
    $ribbon = isset($_POST['atp_ribbon']) && is_string($_POST['atp_ribbon']) ? sanitize_text_field(wp_unslash($_POST['atp_ribbon'])) : 'Top Pick';
    $disclosure = isset($_POST['atp_disclosure']) && is_string($_POST['atp_disclosure']) ? sanitize_text_field(wp_unslash($_POST['atp_disclosure'])) : '';
    update_post_meta($post_id,'_atp_ribbon',substr($ribbon,0,40));
    update_post_meta($post_id,'_atp_disclosure',azlinkpro_tbl_current_disclosure($disclosure));
    azlinkpro_width_save($post_id,'atp_max_width','_atp_');
}
add_action('save_post_atp_table','azlinkpro_tbl_save_post',10,3);

function azlinkpro_tbl_admin_assets($hook) {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'atp_table' && in_array($screen->base,array('post','post-new'),true)) {
        wp_enqueue_media();
        wp_enqueue_style('atp-admin', AZLINKPRO_TBL_URL . 'assets/admin.css',array(),AZLINKPRO_TBL_VER);
        wp_enqueue_script('atp-admin', AZLINKPRO_TBL_URL . 'assets/admin.js',array('jquery','jquery-ui-sortable'),AZLINKPRO_TBL_VER,true);
        wp_localize_script('atp-admin','atpAdmin',array('ajax'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('atp_api_action')));
        // Read-only unsaved-state warning and shortcut to native WordPress save.
        // Runs after both the product editor and optional custom layout editor.
        wp_enqueue_script('azp-table-save-guard', AZLINKPRO_TBL_URL . 'assets/editor-save-guard.js',
            array('atp-admin','azp-layout-admin'), AZLINKPRO_TBL_VER, true);
        // Unsaved draft recovery uses only browser storage, scoped by user and post.
        // Nothing is sent to an external service and stored Amazon credentials are excluded.
        wp_enqueue_script('azp-table-draft-recovery', AZLINKPRO_TBL_URL . 'assets/editor-draft-recovery.js',
            array('azp-table-save-guard'), AZLINKPRO_TBL_VER, true);
        wp_localize_script('azp-table-draft-recovery','azpDraftRecovery',array(
            'userId'=>get_current_user_id(), 'postId'=>isset($_GET['post']) ? absint($_GET['post']) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only editor context
            'maxAgeMs'=>7 * DAY_IN_SECONDS * 1000,
        ));
        wp_enqueue_script('azp-table-transfer', AZLINKPRO_TBL_URL . 'assets/editor-table-transfer.js',
            array('azp-table-draft-recovery'), AZLINKPRO_TBL_VER, true);
        // "Brand | Model" renaming and the automatic table title.
        wp_enqueue_script('azp-table-names', AZLINKPRO_TBL_URL . 'assets/editor-names.js',
            array('atp-admin', 'azlinkpro-product-names'), AZLINKPRO_TBL_VER, true);
    }
}
add_action('admin_enqueue_scripts','azlinkpro_tbl_admin_assets');
function azlinkpro_tbl_ajax_products() {
    check_ajax_referer('atp_api_action','nonce');
    if (!current_user_can('edit_posts')) { wp_send_json_error(array('message'=>'Insufficient permissions.'),403); }
    $q=isset($_POST['q']) && is_string($_POST['q']) ? trim(sanitize_text_field(wp_unslash($_POST['q']))) : '';
    $page=isset($_POST['page']) && is_scalar($_POST['page']) ? max(1,min(10,absint($_POST['page']))) : 1;
    if ($q === '') { wp_send_json_error(array('message'=>'Enter a search term or ASIN.'),400); }
    $opt=azlinkpro_tbl_options();
    if (preg_match('/^[A-Za-z0-9]{10}$/',$q)) {
        $asin=strtoupper($q);
        $res=azlinkpro_tbl_api_call('getItems',array('itemIds'=>array($asin),'itemIdType'=>'ASIN','resources'=>array('images.primary.large','images.primary.medium','images.primary.small','images.variants.large','images.variants.medium','images.variants.small','itemInfo.title','itemInfo.byLineInfo')),$opt);
        $operation='getItems';
    } else {
        $res=azlinkpro_tbl_api_call('searchItems',array('keywords'=>substr($q,0,120),'itemCount'=>10,'itemPage'=>$page,'resources'=>array('images.primary.medium','images.primary.large','itemInfo.title','itemInfo.byLineInfo')),$opt);
        $operation='searchItems';
    }
    if (is_wp_error($res)) { wp_send_json_error(array('message'=>$res->get_error_message()),400); }
    $items=array();
    foreach(azlinkpro_tbl_extract_items($res,$operation) as $item) {
        $p=azlinkpro_tbl_parse_item($item); if ($p) { $items[]=$p; }
    }
    wp_send_json_success(array('items'=>$items,'page'=>$page,
        'has_more'=>$operation==='searchItems' && $page<10 && count(azlinkpro_tbl_extract_items($res,$operation))===10,
        'message'=>$items ? '' : 'No matching products returned on this page. Try a different search or add the ASIN manually.'));
}
add_action('wp_ajax_atp_search_products','azlinkpro_tbl_ajax_products');
/**
 * Draft concise, editable comparison highlights from an Amazon Creators API
 * item's feature bullets. Deterministic selection: no AI fees, no invented
 * specifications, no price/rating/offer claims, and no change to saved rows.
 * A human editor must review these vendor-supplied claims before publishing.
 */
/** Title Case for an ALL-CAPS heading; mixed-case headings keep their own casing.
 * Words with digits (4K, USB-C) and short joining words are handled sensibly. */
function azlinkpro_tbl_feature_label_case($label) {
    $label = (string) $label;
    $upper = function_exists('mb_strtoupper') ? mb_strtoupper($label, 'UTF-8') : strtoupper($label);
    if ($label !== $upper || !preg_match('/\p{L}{2}/u', $label)) { return $label; }
    $small = array('a','an','and','as','at','by','for','in','of','on','or','the','to','with');
    $words = explode(' ', $label);
    foreach ($words as $i => $word) {
        if ($word === '' || preg_match('/\d/', $word)) { continue; }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
        if ($i > 0 && in_array($lower, $small, true)) { $words[$i] = $lower; continue; }
        $words[$i] = function_exists('mb_convert_case') ? mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8') : ucfirst($lower);
    }
    return implode(' ', $words);
}
/** Create a short, factual label for an Amazon feature without introducing new claims. */
function azlinkpro_tbl_amazon_feature_label($source, $body) {
    $source = trim((string) $source);
    $body = trim((string) $body);
    // Preserve a descriptive source heading when one exists, but not slogans.
    if ($source !== '' && !preg_match('/\b(?:perfect|superior|ultimate|amazing|innovative|best|enjoy|buy|save|deal|price|guarantee)\b/i', $source)) {
        $source = preg_replace('/\s+/u', ' ', $source);
        $source = trim($source, " \t\n\r\0\x0B:-–—");
        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s&\/()+.\-]{2,64}$/u', $source)) {
            return azlinkpro_tbl_feature_label_case($source);
        }
    }
    $rules = array(
        'Safety Certifications' => '/\b(?:NSF|UL 197|ETL|certif(?:ied|ication)|commercial standard)\b/i',
        'Power Settings' => '/\b(?:power levels?|power settings?|wattage|\d+\s*(?:W|watts?)\b|voltage)\b/i',
        'Temperature Control' => '/\b(?:temperature|temperatures|degrees|°[CF]|thermostat|heat settings?)\b/iu',
        'Heating Element' => '/\b(?:magnetic coil|heating coil|coil size|heating element|induction coil)\b/i',
        'Safety Features' => '/\b(?:shut.?off|auto.?off|child lock|overheat|safety|protection)\b/i',
        'Controls & Ease of Use' => '/\b(?:control buttons?|touch controls?|digital controls?|tactile|display|interface|one.touch|push.button|timer|preset buttons?)\b/i',
        'Cooking Surface' => '/\b(?:ceramic glass|glass surface|cook(?:ing)? surface|non.?stick|surface coating)\b/i',
        'Materials & Durability' => '/\b(?:stainless steel|steel housing|ceramic|alloy|construction|durab(?:le|ility))\b/i',
        'Size & Capacity' => '/\b(?:dimensions?|diameter|capacity|width|height|weight|ounces?|lit(?:er|re)s?|cups?)\b/i',
        'Compatibility' => '/\b(?:compatible|compatibility|suitable for|works with)\b/i',
        'Cleaning & Maintenance' => '/\b(?:clean(?:ing)?|washable|removable|maintenance)\b/i',
        'Grind Settings' => '/\b(?:grind settings?|grind sizes?|burrs?)\b/i',
    );
    foreach ($rules as $label => $pattern) { if (preg_match($pattern, $body)) { return $label; } }
    return 'Product Feature';
}
/**
 * Turn vendor-provided Amazon Creators API feature bullets into four editable
 * lines: "Feature Label: details". No external AI service, invented specs,
 * pricing or rating claims. Existing custom table descriptions stay intact.
 */
function azlinkpro_tbl_amazon_summary_highlights($bullets) {
    if (!is_array($bullets)) { return ''; }
    $candidates = array();
    $prohibited = '/\b(?:prices?|pricing|discounts?|coupons?|deals?|special[ -]offers?|free shipping|prime|stars?|ratings?|reviews?|best[ -]selling|award[ -]winning|warrant(?:y|ies)|guarantees?|money[ -]back|buy now|limited[ -]time)\b/i';
    $technical = '/\b(?:temperature|settings?|presets?|increments?|timer|automatic|auto[ -]off|shut[ -]off|safety|controls?|adjustable|capacity|material|ceramic|steel|stainless|glass|watts?|voltage|power|induction|compatible|dimensions?|size|weight|portable|cleaning|removable|grind|brew|modes?|levels?|sensor|cord|speed|heating|cups?|oz|liters?|litres?|degrees?|NSF|UL|[0-9])\b/i';
    foreach (array_slice($bullets, 0, 12) as $position => $raw) {
        if (!is_string($raw) || trim($raw) === '') { continue; }
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if ($text === '' || preg_match($prohibited, $text)) { continue; }
        if (preg_match('/^(?:superior technology|fast and efficient|best quality|the ultimate|unbeatable|industry.leading)\b/i', $text)) { continue; }
        $heading = '';
        // Bullets often open with a bracketed heading such as "【Precision Grinding】".
        // A plain heading becomes the label; a slogan heading is dropped from the text.
        if (preg_match('/^[【\[]\s*([^】\]]{1,80}?)\s*[】\]]\s*(.+)$/u', $text, $parts)) {
            $possible = trim($parts[1]);
            $text = trim($parts[2]);
            if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s&\/()+.\-]{2,64}$/u', $possible)
                && !preg_match('/\b(?:perfect|superior|ultimate|amazing|innovative|best|enjoy|buy|save|deal|price|guarantee)\b/i', $possible)) {
                $heading = $possible;
            }
        }
        // Amazon commonly starts a bullet with a concise heading and a dash.
        if ($heading === '' && preg_match('/^([^:—–]{3,65}?)\s*(?:\s+[—–-]\s+|:)\s*(.+)$/u', $text, $parts)) {
            $possible = trim($parts[1]);
            if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s&\/()+.\-]{2,64}$/u', $possible)
                && !preg_match('/\b(?:perfect|superior|ultimate|amazing|innovative|best|enjoy|buy|save|deal|price|guarantee)\b/i', $possible)) {
                $heading = $possible;
                $text = trim($parts[2]);
            }
        }
        // Prefer concrete, documented product specs to a lengthy sales pitch.
        $specific = '';
        if (preg_match('/\b(\d{1,3})\s+(?:pre[ -]programmed|preset)\s+(?:temps?|temperatures?|settings?)\b/i', $heading . ' ' . $text, $count) &&
            preg_match('/\bfrom\s+(\d+\s*°?[CF])\s+to\s+(\d+\s*°?[CF])\s+in\s+(\d+\s*°?[CF])\s+increments\b/iu', $text, $range)) {
            $specific = $count[1] . ' preset temperatures, ' . $range[1] . '–' . $range[2] . ' in ' . $range[3] . ' increments';
        } elseif (preg_match('/\bshut(?:s)?\s+off\s+automatically\b/i', $text) && preg_match('/\b(?:cook(?:ing)?|time|timer)\b/i', $text)) {
            $specific = 'Automatically shuts off when the selected cooking time finishes';
        } elseif (preg_match('/\bon[ -]the[ -]fly\b/i', $text) && preg_match('/\b(?:temperature|time|heat)\b/i', $text) && preg_match('/\badjust\b/i', $text)) {
            $specific = 'Adjust temperature or cooking time during use';
        }
        if ($specific !== '') { $text = $specific; }
        else {
            // Use the first informative sentence, not an unsupported synthetic claim.
            $sentences = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}0-9])/u', $text);
            if (is_array($sentences)) {
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if ($sentence === '' || substr($sentence, -1) === '?' || preg_match('/^(?:discover|enjoy|imagine|love|introducing)\b/i', $sentence)) { continue; }
                    $text = $sentence;
                    break;
                }
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text), " \t\n\r\0\x0B.!");
        if (strlen($text) < 13) { continue; }
        $label = azlinkpro_tbl_amazon_feature_label($heading, $text);
        // Generic, non-technical marketing text is not a useful table feature.
        if (!preg_match($technical, $heading . ' ' . $text)) { continue; }
        if (strlen($text) > 155) {
            $short = function_exists('mb_substr') ? mb_substr($text, 0, 150, 'UTF-8') : '';
            if ($short === '' && preg_match('/^.{1,150}/us', $text, $chars)) { $short = $chars[0]; }
            $last_space = strrpos($short, ' ');
            if ($last_space !== false && $last_space > 80) { $short = substr($short, 0, $last_space); }
            $text = rtrim($short, ',;: ') . '…';
        }
        $score = 0;
        if (preg_match('/\d/', $text)) { $score += 4; }
        if (preg_match($technical, $text)) { $score += 3; }
        if ($heading !== '') { $score += 2; }
        if (preg_match('/\b(?:adjust|preset|control|material|safety|induction|size|power)\b/i', $text)) { $score += 1; }
        if (preg_match('/\b(?:perfect|best|excellent|amazing|fantastic|superior|world.class|breakthrough)\b/i', $text)) { $score -= 5; }
        $candidates[] = array('label'=>$label, 'text'=>$text, 'score'=>$score, 'order'=>$position);
    }
    usort($candidates, function($a, $b) {
        if ($a['score'] !== $b['score']) { return $b['score'] <=> $a['score']; }
        return $a['order'] <=> $b['order'];
    });
    $chosen = array(); $used_labels = array(); $used_text = array(); $bytes = 0;
    foreach ($candidates as $candidate) {
        $label = $candidate['label'];
        $key = preg_replace('/[^a-z0-9]+/i', '', strtolower($label));
        $text_key = preg_replace('/[^a-z0-9]+/i', '', strtolower(substr($candidate['text'], 0, 90)));
        if (isset($used_labels[$key]) || isset($used_text[$text_key])) { continue; }
        $line = $label . ': ' . $candidate['text'];
        if (count($chosen) >= 4 || $bytes + strlen($line) > 850) { continue; }
        $used_labels[$key] = true;
        $used_text[$text_key] = true;
        $chosen[] = $line;
        $bytes += strlen($line) + 1;
    }
    return implode("\n", $chosen);
}
/** Fetch product details only when an editor actually adds/requests a row.
 * Reuse the existing Showcase API/cache so search results never trigger ten
 * separate billable getItems calls just to populate the result list. */
function azlinkpro_tbl_ajax_product_summary() {
    check_ajax_referer('atp_api_action', 'nonce');
    if (!current_user_can('edit_posts')) { wp_send_json_error(array('message'=>'Insufficient permissions.'), 403); }
    $asin = isset($_POST['asin']) && is_string($_POST['asin']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['asin'])))) : '';
    if (!preg_match('/^[A-Z0-9]{10}$/D', $asin)) { wp_send_json_error(array('message'=>'Enter a valid 10-character ASIN.'), 400); }
    if (!function_exists('azlinkpro_sc_get_item_from_api')) { wp_send_json_error(array('message'=>'Amazon product lookup is unavailable.'), 503); }
    $product = azlinkpro_sc_get_item_from_api($asin);
    if (is_wp_error($product)) { wp_send_json_error(array('message'=>$product->get_error_message()), 400); }
    if (!is_array($product) || ($product['asin'] ?? '') !== $asin) { wp_send_json_error(array('message'=>'Amazon did not return this product.'), 404); }
    $summary = azlinkpro_tbl_amazon_summary_highlights($product['bullets'] ?? array());
    $full_title = sanitize_text_field((string)($product['title'] ?? ''));
    // The name a new row gets: "Brand | Model" or the full title, as chosen in Settings.
    wp_send_json_success(array('asin'=>$asin,'title'=>azlinkpro_product_display_name($full_title, (string)($product['brand'] ?? '')),'full_title'=>$full_title,
        'summary'=>$summary,'message'=>$summary ? 'Labeled Amazon feature highlights added. Review before publishing.' : 'Amazon returned no suitable feature highlights for this product. Add your own details.'));
}
add_action('wp_ajax_atp_product_summary', 'azlinkpro_tbl_ajax_product_summary');

/** Display available Amazon gallery images in the Tables editor only. */
function azlinkpro_tbl_ajax_product_images() {
    check_ajax_referer('atp_api_action', 'nonce');
    if (!current_user_can('edit_posts')) { wp_send_json_error(array('message'=>'Insufficient permissions.'), 403); }
    $asin = isset($_POST['asin']) && is_string($_POST['asin']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['asin'])))) : '';
    if (!preg_match('/^[A-Z0-9]{10}$/D', $asin)) { wp_send_json_error(array('message'=>'Enter a valid 10-character ASIN.'), 400); }
    $opt = azlinkpro_tbl_options();
    // A valid 12-hour cached product already contains the approved image gallery.
    // Reuse it before sending another billable / rate-limited Creators API call.
    $cached = get_transient(azlinkpro_tbl_product_cache_key($opt, $asin));
    $product = is_array($cached) && ($cached['asin'] ?? '') === $asin && !empty($cached['images']) ? $cached : null;
    $from_cache = (bool) $product;
    if (!$product) {
        $result = azlinkpro_tbl_api_call('getItems', array('itemIds'=>array($asin), 'itemIdType'=>'ASIN',
            'resources'=>array('images.primary.large','images.primary.medium','images.primary.small',
                'images.variants.large','images.variants.medium','images.variants.small','itemInfo.title','itemInfo.byLineInfo')), $opt);
        if (is_wp_error($result)) { wp_send_json_error(array('message'=>$result->get_error_message()), 400); }
        foreach (azlinkpro_tbl_extract_items($result,'getItems') as $item) {
            $found = azlinkpro_tbl_parse_item($item);
            if ($found && $found['asin'] === $asin) { $product = $found; break; }
        }
    }
    if (!$product) { wp_send_json_error(array('message'=>'Amazon did not return this ASIN for your current marketplace.'), 404); }
    if (empty($product['images'])) { wp_send_json_error(array('message'=>'No approved product images were returned for this item.'), 404); }
    // Do not extend cached Amazon image TTL merely by opening the picker.
    if (!$from_cache) { set_transient(azlinkpro_tbl_product_cache_key($opt, $asin), $product, 12 * HOUR_IN_SECONDS); }
    wp_send_json_success(array('asin'=>$asin, 'images'=>$product['images']));
}
add_action('wp_ajax_atp_product_images', 'azlinkpro_tbl_ajax_product_images');


function azlinkpro_tbl_shortcode($atts=array()) {
    $atts=shortcode_atts(array('id'=>0), $atts, 'amatablepro');
    $id=absint($atts['id']);
    // No bundled example table or implicit default table is installed.
    $post=get_post($id);
    if (!$post || $post->post_type !== 'atp_table' || $post->post_status !== 'publish') { return ''; }
    $rows=azlinkpro_tbl_rows($id);
    if (!$rows) { return ''; }
    $migration_urls=get_post_meta($id,'_azp_mig_affiliate_urls',true);
    $migration_urls=is_array($migration_urls)?$migration_urls:array();
    $migration_images=get_post_meta($id,'_azp_mig_image_urls',true);
    $migration_images=is_array($migration_images)?$migration_images:array();
    $api=azlinkpro_tbl_get_products(array_column($rows,'asin'));
    $opt=azlinkpro_tbl_options();
    $ribbon=get_post_meta($id,'_atp_ribbon',true) ?: 'Top Pick';
    $disclosure=azlinkpro_tbl_current_disclosure(get_post_meta($id,'_atp_disclosure',true));
    $table_title=get_the_title($id);
    ob_start(); ?>
    <?php $header_color=azlinkpro_tbl_table_header_color($id); $header_text=azlinkpro_tbl_table_header_text_color($header_color); ?>
    <section class="atp-wrap" style="--atp-header-color:<?php echo esc_attr($header_color); ?>;--atp-header-text:<?php echo esc_attr($header_text); ?>;" aria-label="<?php echo esc_attr($table_title); ?>">
      <div class="atp-table-intro">
        <div class="atp-intro-copy">
          <span class="atp-eyebrow"><span class="atp-eyebrow-dot" aria-hidden="true"></span> Product comparison</span>
          <h3 class="atp-intro-title"><?php echo esc_html($table_title); ?></h3>
          <p class="atp-intro-subtitle">Compare the key features, then see current product details and pricing on Amazon.</p>
        </div>
        <span class="atp-count"><?php echo esc_html(count($rows)); ?> models compared</span>
      </div>
      <!-- Grid rather than native HTML table to avoid mobile theme styles hiding it. -->
      <div class="atp-scroll" role="region" tabindex="0" aria-label="Scroll product comparison table horizontally">
        <div class="atp-comparison" role="table" aria-label="<?php echo esc_attr($table_title); ?>">
          <div class="atp-table-head" role="row">
            <div role="columnheader">Product</div><div role="columnheader">Highlights</div><div role="columnheader">Where to buy</div>
          </div>
          <div class="atp-rowgroup" role="rowgroup">
      <?php foreach ($rows as $atp_row_index => $row):
        $asin=$row['asin']; $url=(!empty($migration_urls[$asin]) && function_exists('azlinkpro_mig_amazon_link')) ? (azlinkpro_mig_amazon_link($migration_urls[$asin],$asin)?:azlinkpro_tbl_amazon_url($asin,$opt)) : azlinkpro_tbl_amazon_url($asin,$opt); $image='';
        $image_dimensions=array('width'=>0,'height'=>0);
        if (!empty($row['image_id'])) {
            $local_image=wp_get_attachment_image_src((int)$row['image_id'],'medium_large');
            if (is_array($local_image) && !empty($local_image[0])) {
                $image=(string)$local_image[0];
                $image_dimensions=array('width'=>(int)$local_image[1],'height'=>(int)$local_image[2]);
            }
        }
        if (!$image && !empty($migration_images[$asin]) && function_exists('azlinkpro_mig_image')) { $image=azlinkpro_mig_image($migration_images[$asin]); }
        if (!$image) {
            $product_image=$api[$asin] ?? array();
            $image=azlinkpro_amazon_selected_image($product_image, $row['amazon_image_key'] ?? '');
            $image_dimensions=azlinkpro_intrinsic_dimensions($product_image, $row['amazon_image_key'] ?? '');
        }
        if (!$image && ($shared_image=azlinkpro_asin_photo_src($asin))) {
            $image=(string)$shared_image[0];
            $image_dimensions=array('width'=>(int)$shared_image[1],'height'=>(int)$shared_image[2]);
        }
        $image_max=azlinkpro_image_display_px($row['image_size'] ?? 'default',$row['image_custom_px'] ?? 180);
        $name=trim($row['name']) !== '' ? $row['name'] : ($api[$asin]['name'] ?? $asin);
        $review=$row['review'] ?? '';
        if (function_exists('azpai_table_review_link')) { $review=azpai_table_review_link($id, $atp_row_index, $name, $review); }
        $is_featured=!empty($row['featured']);
        $raw_details=trim((string)($row['details'] ?? ''));
        if (function_exists('azpai_article_table_highlights')) { $raw_details=azpai_article_table_highlights($id,$atp_row_index,$raw_details); }
        $has_segments=strpos($raw_details, '|') !== false;
        // New Amazon summaries are one labeled feature per line. Keep the
        // pre-existing pipe-delimited use-case format fully compatible.
        $feature_lines=preg_split('/\r\n|\r|\n/', $raw_details);
        $feature_lines=is_array($feature_lines) ? array_values(array_filter(array_map('trim', $feature_lines), 'strlen')) : array();
        // Two or more "Label: text" lines render as separate paragraphs with
        // bold labels, like hand-written descriptions. The boxed chip list is
        // kept for the short pipe-delimited format only.
        $has_labeled_lines=!$has_segments && count($feature_lines) > 1;
        foreach ($feature_lines as $feature_line) {
            if (!preg_match('/^[\p{L}\p{N}][^:\r\n]{2,75}:\s+\S/u', $feature_line)) { $has_labeled_lines=false; break; }
        }
        if ($has_labeled_lines) { $raw_details=implode("\n\n", $feature_lines); }
        $segments=$has_segments ? array_values(array_filter(array_map('trim',explode('|',$raw_details)), 'strlen')) : array();
        $summary=$has_segments && count($segments) > 0 ? array_shift($segments) : '';
        $cta_label=trim((string)($row['cta'] ?? ''));
        if ($cta_label==='' || strcasecmp($cta_label,'Check Price on Amazon')===0) { $cta_label='Check Price'; }
        ?>
        <div class="atp-product-row <?php echo $is_featured ? 'atp-featured' : ''; ?>" role="row">
          <div class="atp-cell-image<?php echo $image_max > 0 ? ' atp-image-sized' : ''; ?>" role="cell" data-label="Image"<?php echo $image_max > 0 ? ' style="--atp-image-max:'.(int)$image_max.'px"' : ''; ?>>
            <?php if($is_featured): ?><span class="atp-featured-label"><span aria-hidden="true">★</span> <?php echo esc_html($ribbon); ?></span><?php endif; ?>
            <?php if($image): ?>
              <a class="atp-image-link<?php echo empty($image_dimensions['width']) || empty($image_dimensions['height']) ? ' azp-reserved-image-slot' : ''; ?>" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored nofollow noopener noreferrer" aria-label="<?php echo esc_attr('See ' . $name . ' on Amazon (opens new tab)'); ?>"><img<?php if ($image_max > 0): ?> style="max-width:<?php echo (int)$image_max; ?>px!important;max-height:<?php echo (int)$image_max; ?>px!important;width:auto!important;height:auto!important;object-fit:contain!important;"<?php endif; ?> loading="lazy" decoding="async"<?php if ($image_dimensions['width'] > 0 && $image_dimensions['height'] > 0): ?> width="<?php echo (int)$image_dimensions['width']; ?>" height="<?php echo (int)$image_dimensions['height']; ?>"<?php endif; ?> src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($name); ?>"></a>
            <?php else: ?>
              <div class="atp-no-image" aria-label="Product photo unavailable"><span class="atp-placeholder-dial" aria-hidden="true"></span><span>Image unavailable</span></div>
            <?php endif; ?>
          </div>
          <div class="atp-cell-details" role="cell" data-label="Details">
            <?php if($summary): ?><span class="atp-usecase"><?php echo esc_html($summary); ?></span><?php endif; ?>
            <a class="atp-product-name" target="_blank" rel="sponsored nofollow noopener noreferrer" href="<?php echo esc_url($url); ?>"><?php echo esc_html($name); ?></a>
            <?php if($segments): ?>
              <ul class="atp-features"><?php foreach($segments as $part): ?><li><?php echo wp_kses(azlinkpro_format_feature_labels($part), array('strong' => array(), 'br' => array())); ?></li><?php endforeach; ?></ul>
            <?php elseif($raw_details): ?>
              <p class="atp-description"><?php echo wp_kses(nl2br(azlinkpro_format_feature_labels($raw_details)), array('strong' => array(), 'br' => array())); ?></p>
            <?php endif; ?>
            <?php if($review): ?><a class="atp-review" href="<?php echo esc_url($review); ?>">Read our full review <span aria-hidden="true">→</span></a><?php endif; ?>
          </div>
          <div class="atp-cell-buy" role="cell" data-label="Amazon link">
            <a class="atp-buy" target="_blank" rel="sponsored nofollow noopener noreferrer" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr($cta_label . ' — ' . $name . ' (opens new tab)'); ?>"><span><?php echo esc_html($cta_label); ?></span></a>
            <span class="atp-buy-note">Price &amp; availability on Amazon</span>
          </div>
        </div>
      <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php if($disclosure): ?><p class="atp-disclosure"><?php echo esc_html($disclosure); ?></p><?php endif; ?>
    </section>
    <?php return ob_get_clean();
}
add_shortcode('amatablepro','azlinkpro_tbl_shortcode');
add_shortcode('azlinkpro_table','azlinkpro_tbl_shortcode');



function azlinkpro_tbl_shortcode_column($cols) { $cols['atp_shortcode']='AzlinkPRO - Tables shortcode'; return $cols; }
add_filter('manage_atp_table_posts_columns','azlinkpro_tbl_shortcode_column');
function azlinkpro_tbl_shortcode_col($column,$post_id) {
    if ($column === 'atp_shortcode') {
        azlinkpro_copy_shortcode_control('[azlinkpro_table id="' . absint($post_id) . '"]', get_the_title($post_id));
    }
}
add_action('manage_atp_table_posts_custom_column','azlinkpro_tbl_shortcode_col',10,2);
