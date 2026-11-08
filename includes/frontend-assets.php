<?php
/**
 * AzlinkPRO frontend stylesheet strategy.
 * Compatibility is the default so themes, widgets and late shortcodes keep
 * the same early head styles as previous releases. Optimized mode is opt-in.
 * No product, affiliate, Amazon or generated article data is accessed here.
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_asset_mode() {
    return get_option('azlinkpro_css_loading', 'compatibility') === 'optimized'
        ? 'optimized' : 'compatibility';
}

/** Pure content classifier; null deliberately means load everything. */
function azlinkpro_assets_for_content($content) {
    if (!is_string($content)) { return null; }
    // Reusable blocks and page builders may render shortcodes from other posts.
    if (preg_match('/<!--\s*wp:(?:block|pattern|template-part|html)\b/i', $content)) { return null; }
    $groups = array(
        'tables' => array('amatablepro', 'azlinkpro_table'),
        'showcase' => array('amatablepro_showcase', 'azlinkpro_showcase', 'atp_showcase', 'azlinkpro_showcase_grid'),
        'pros' => array('amatablepro_pros_cons', 'amatablepro_procon', 'azlinkpro_pros_cons', 'azlinkpro_procon'),
    );
    $result = array('tables' => false, 'showcase' => false, 'pros' => false, 'layout' => false);
    $matched = array();
    foreach ($groups as $group => $tags) {
        foreach ($tags as $tag) {
            if (has_shortcode($content, $tag)) {
                $result[$group] = true;
                $matched[$tag] = true;
            }
        }
    }
    // Other registered shortcodes may generate an AzlinkPRO component at run
    // time, so an unknown shortcode must retain all early-loading styles.
    if (preg_match_all('/\[(?!\[|\/)([A-Za-z][A-Za-z0-9_-]*)\b/', $content, $m)) {
        foreach ($m[1] as $tag) {
            if (!isset($matched[$tag])) { return null; }
        }
    }
    $result['layout'] = $result['tables'] || $result['pros'];
    return $result;
}

function azlinkpro_frontend_asset_plan() {
    static $resolved = false;
    static $plan = null;
    if ($resolved) { return $plan; }
    $resolved = true;
    if (azlinkpro_asset_mode() !== 'optimized' || !is_singular(array('post', 'page'))) { return null; }
    $post = get_queried_object();
    if (!is_object($post) || !isset($post->ID, $post->post_content) || !is_string($post->post_content)) { return null; }
    // A builder or template can inject shortcodes whose text is not in post_content.
    foreach (array('_elementor_edit_mode', '_fl_builder_enabled', '_et_pb_use_builder', '_bricks_page_content_2') as $meta) {
        if (get_post_meta((int)$post->ID, $meta, true)) { return null; }
    }
    // Dynamic widgets and sidebars may inject a table into an otherwise plain page.
    foreach (array_keys((array) ($GLOBALS['wp_registered_sidebars'] ?? array())) as $sidebar) {
        if (is_active_sidebar($sidebar)) { return null; }
    }
    $plan = azlinkpro_assets_for_content($post->post_content);
    return $plan;
}

function azlinkpro_should_load_asset($module) {
    $plan = azlinkpro_frontend_asset_plan();
    return $plan === null || !empty($plan[$module]);
}

/** Handle a shortcode inserted at render-time by a theme or another plugin. */
function azlinkpro_frontend_late_shortcode_assets($override, $tag, $attrs, $match) {
    if (azlinkpro_asset_mode() !== 'optimized') { return $override; }
    $modules = array(
        'amatablepro' => array('tables', 'layout'),
        'azlinkpro_table' => array('tables', 'layout'),
        'amatablepro_showcase' => array('showcase'),
        'azlinkpro_showcase' => array('showcase'),
        'atp_showcase' => array('showcase'),
        'azlinkpro_showcase_grid' => array('showcase'),
        'amatablepro_pros_cons' => array('pros', 'layout'),
        'amatablepro_procon' => array('pros', 'layout'),
        'azlinkpro_pros_cons' => array('pros', 'layout'),
        'azlinkpro_procon' => array('pros', 'layout'),
    );
    if (empty($modules[$tag])) { return $override; }
    $handles = array(
        'tables' => 'amatablepro-frontend',
        'showcase' => 'amatablepro-showcase',
        'pros' => 'atppc-frontend',
        'layout' => 'azp-layout-public',
    );
    $urls = array(
        'tables' => plugin_dir_url(AZLINKPRO_PATH . 'modules/tables/module-tables.php') . 'assets/frontend.css',
        'showcase' => plugin_dir_url(AZLINKPRO_PATH . 'modules/showcase/module-showcase.php') . 'assets/showcase.css',
        'pros' => plugin_dir_url(AZLINKPRO_PATH . 'modules/pros-cons/module-pros-cons.php') . 'assets/frontend.css',
        'layout' => plugin_dir_url(AZLINKPRO_PATH . 'addons/layout-options/layout-options.php') . 'assets/frontend.css',
    );
    $paths = array('showcase' => 'modules/showcase/module-showcase.php', 'pros' => 'modules/pros-cons/module-pros-cons.php');
    foreach ($modules[$tag] as $module) {
        // The free build has no Showcase or Pros & Cons stylesheet to load.
        if (isset($paths[$module]) && !file_exists(AZLINKPRO_PATH . $paths[$module])) { continue; }
        $handle = $handles[$module];
        if (!wp_style_is($handle, 'enqueued') && !wp_style_is($handle, 'done')) {
            wp_enqueue_style($handle, $urls[$module], array(), AZLINKPRO_VERSION);
            if (did_action('wp_head')) {
                $GLOBALS['azlinkpro_late_css_handles'][$handle] = true;
            }
            // Paid builds keep the comparison and Pros & Cons layouts in a second stylesheet.
            if ($module === 'layout' && class_exists('AzlinkPRO_Layout_Options_134') && ($css = AzlinkPRO_Layout_Options_134::premium_asset('frontend-premium.css'))) {
                wp_enqueue_style($handle . '-premium', $css, array($handle), AZLINKPRO_VERSION);
                if (did_action('wp_head')) { $GLOBALS['azlinkpro_late_css_handles'][$handle . '-premium'] = true; }
            }
        }
    }
    return $override;
}
add_filter('pre_do_shortcode_tag', 'azlinkpro_frontend_late_shortcode_assets', 5, 4);

function azlinkpro_print_late_frontend_assets() {
    if (empty($GLOBALS['azlinkpro_late_css_handles']) || !is_array($GLOBALS['azlinkpro_late_css_handles'])) { return; }
    $handles = array_keys($GLOBALS['azlinkpro_late_css_handles']);
    unset($GLOBALS['azlinkpro_late_css_handles']);
    if (function_exists('wp_print_styles')) { wp_print_styles($handles); }
}
add_action('wp_footer', 'azlinkpro_print_late_frontend_assets', 1);
