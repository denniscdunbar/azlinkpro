<?php
/**
 * AzlinkPRO 1.4.0-beta.21 — short "Brand | Model" product names.
 *
 * Amazon titles are long ("3D MAXpider All-Weather Floor Mats for Tesla Model Y
 * 2020-2025, Custom Fit…"). The short name is Amazon's brand plus the product's
 * own name from the title: "3D MAXpider | All-Weather Floor Mats". Amazon's
 * separate model field is not used: it is usually a part number like L1TL04501509.
 */
if (!defined('ABSPATH')) { exit; }

/** 'short' (Brand | Model, the default) or 'full' (Amazon's whole title). */
function azlinkpro_product_name_style() {
    return get_option('azlinkpro_product_names', 'short') === 'full' ? 'full' : 'short';
}

/** Amazon's brand for a Creators API item (itemInfo.byLineInfo), or ''. */
function azlinkpro_amazon_brand($item) {
    $brand = is_array($item) ? ($item['itemInfo']['byLineInfo']['brand']['displayValue'] ?? '') : '';
    $brand = is_string($brand) ? trim(preg_replace('/\s+/u', ' ', sanitize_text_field($brand))) : '';
    // Placeholder brands say nothing about the product.
    if ($brand === '' || preg_match('/^(?:generic|unbranded|unknown|no brand|none|does not apply|n\/?a)$/iu', $brand)) { return ''; }
    return azlinkpro_text_limit($brand, 60);
}

/** "Brand | Model" from an Amazon title and brand. Without a brand, a shortened title. */
function azlinkpro_short_product_name($title, $brand = '') {
    $title = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string)$title)));
    $brand = trim(preg_replace('/\s+/u', ' ', (string)$brand));
    if ($title === '') { return $brand; }
    // Leading tags such as [Upgraded] or 【2025 New】.
    $rest = preg_replace('/^(?:[\[【(][^\]】)]{0,40}[\]】)]\s*)+/u', '', $title);
    if ($brand !== '') {
        // Usually the first words of the title, but remove it wherever Amazon put it.
        $rest = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($brand, '/') . '(?![\p{L}\p{N}])/iu', ' ', $rest, 1);
    }
    $rest = trim(preg_replace('/\s+/u', ' ', $rest), " \t-–—|:,;/.&+");
    // Marketing words and year ranges are not part of a product's name.
    $rest = preg_replace('/^(?:(?:19|20)\d{2}\s+)?(?:new|newest|latest|upgraded|upgrade|improved|updated)\b[\s:,-]*/iu', '', $rest);
    $rest = preg_replace('/\b(?:19|20)\d{2}(?:\s*[-–\/&,]\s*(?:19|20)\d{2})+\b/u', ' ', $rest);
    // The name ends at the first comma, dash, bracket or colon …
    $parts = preg_split('/\s*(?:,|;|:|\||\s[-–—]\s|[(\[【]|\s\/\s)\s*/u', $rest, 2);
    $rest = trim($parts[0]);
    // … or before "for Tesla Model Y", "with …", "compatible with …" when a name is left.
    $cut = preg_split('/\s+(?:for|fits?|fit for|compatible with|compatible|designed for|made for|with|w\/)\s+/iu', $rest, 2);
    if (count($cut) === 2 && count(preg_split('/\s+/u', trim($cut[0]), -1, PREG_SPLIT_NO_EMPTY)) >= 2) { $rest = $cut[0]; }
    $words = array_slice(preg_split('/\s+/u', trim($rest), -1, PREG_SPLIT_NO_EMPTY), 0, 7);
    // Do not end on a joining word, or on a bare number cut from "15 Inch".
    while ($words && (preg_match('/^(?:and|&|or|with|for|by|the|of|a|an|in|to|plus|\+|-|–|—)$/iu', end($words))
        || (count($words) > 2 && preg_match('/^\d+(?:[.,]\d+)?$/u', end($words))))) { array_pop($words); }
    $model = azlinkpro_text_limit(trim(implode(' ', $words), " \t-–—|:,;/.&+"), 60);
    if ($brand === '') { return $model !== '' ? $model : azlinkpro_text_limit($title, 80); }
    return $model === '' ? $brand : $brand . ' | ' . $model;
}

/** The name to show for a product under the current Settings choice. */
function azlinkpro_product_display_name($title, $brand = '') {
    return azlinkpro_product_name_style() === 'short' ? azlinkpro_short_product_name($title, $brand) : trim((string)$title);
}

/** Short and full names for up to 50 ASINs, for the editors' "Shorten names" and title tools. */
function azlinkpro_ajax_product_names() {
    check_ajax_referer('azlinkpro_product_names', 'nonce');
    if (!current_user_can('edit_posts')) { wp_send_json_error(array('message'=>'You cannot edit products.'), 403); }
    if (!function_exists('azlinkpro_tbl_get_products')) { wp_send_json_error(array('message'=>'Amazon product lookup is unavailable.'), 503); }
    $raw = isset($_POST['asins']) && is_array($_POST['asins']) ? array_map('sanitize_text_field', wp_unslash($_POST['asins'])) : array();
    $asins = array();
    foreach (array_slice($raw, 0, 50) as $asin) {
        $asin = is_string($asin) ? strtoupper(trim($asin)) : '';
        if (preg_match('/^[A-Z0-9]{10}$/D', $asin)) { $asins[$asin] = true; }
    }
    $asins = array_keys($asins);
    if (!$asins) { wp_send_json_error(array('message'=>'Add an Amazon product first.'), 400); }
    $products = azlinkpro_tbl_get_products($asins);
    // Products cached before beta.21 have no brand yet. Fetch those again, 10 per request.
    $stale = array();
    foreach ($asins as $asin) {
        if (isset($products[$asin]) && !array_key_exists('brand', $products[$asin])) { $stale[] = $asin; }
    }
    // "+" keeps ASIN keys; array_merge would renumber all-digit ASINs (ISBNs).
    if ($stale) { $products = azlinkpro_tbl_get_products($stale, true) + $products; }
    $names = array();
    foreach ($asins as $asin) {
        $full = trim((string)($products[$asin]['name'] ?? ''));
        if ($full === '') { continue; }
        $brand = (string)($products[$asin]['brand'] ?? '');
        $names[$asin] = array('full'=>$full, 'short'=>azlinkpro_short_product_name($full, $brand), 'brand'=>$brand);
    }
    wp_send_json_success(array('names'=>$names, 'missing'=>array_values(array_diff($asins, array_keys($names)))));
}
add_action('wp_ajax_azlinkpro_product_names', 'azlinkpro_ajax_product_names');

/** Shared editor helpers (lookup, title filling) for Tables, Showcases and Pros & Cons. */
function azlinkpro_product_names_assets($hook) {
    if (!in_array($hook, array('post.php', 'post-new.php'), true)) { return; }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !in_array($screen->post_type, array('atp_table', 'atps_showcase', 'atppc_card'), true)) { return; }
    $main = AZLINKPRO_PATH . 'azlinkpro.php';
    wp_enqueue_script('azlinkpro-product-names', plugins_url('assets/product-names.js', $main), array(), AZLINKPRO_VERSION, true);
    wp_localize_script('azlinkpro-product-names', 'azpProductNamesConfig', array(
        'ajax'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('azlinkpro_product_names'),
        'style'=>azlinkpro_product_name_style(), 'postType'=>$screen->post_type,
    ));
}
add_action('admin_enqueue_scripts', 'azlinkpro_product_names_assets');
