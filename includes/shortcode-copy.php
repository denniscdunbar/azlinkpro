<?php
/** Accessible shortcode-copy UI used by Tables, Showcases and Pros & Cons. */
if (!defined('ABSPATH')) { exit; }

/** Render a selectable shortcode and a genuine, keyboard-accessible copy button. */
function azlinkpro_copy_shortcode_control($shortcode, $label = '', $is_editor = false) {
    $shortcode = (string) $shortcode;
    $label = sanitize_text_field((string) $label);
    $id = wp_unique_id('azp-shortcode-');
    $button_label = $label !== '' ? 'Copy shortcode for ' . $label : 'Copy shortcode';
    $class = $is_editor ? 'azp-copy-control azp-copy-editor' : 'azp-copy-control azp-copy-list';
    echo '<span class="' . esc_attr($class) . '">';
    if ($is_editor) {
        echo '<input id="' . esc_attr($id) . '" type="text" class="widefat azp-copy-value" value="' . esc_attr($shortcode) . '" readonly aria-label="Shortcode" onclick="this.select()">';
    } else {
        echo '<code class="azp-copy-code">' . esc_html($shortcode) . '</code>';
    }
    echo '<button type="button" class="button button-small azp-copy-button" data-shortcode="' . esc_attr($shortcode) . '" aria-label="' . esc_attr($button_label) . '" title="Copy shortcode">';
    echo '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="screen-reader-text">Copy shortcode</span></button>';
    echo '<span class="azp-copy-status" role="status" aria-live="polite"></span></span>';
}

/** Load only on the three product/card admin listings and editors. */
function azlinkpro_copy_enqueue_assets($hook_suffix) {
    if (!in_array($hook_suffix, array('edit.php','post.php','post-new.php'), true)) { return; }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !in_array($screen->post_type, array('atp_table', 'atps_showcase', 'atppc_card', 'azp_product_image'), true)) { return; }
    wp_enqueue_style('dashicons');
    wp_enqueue_style('azp-shortcode-copy', plugins_url('assets/shortcode-copy.css', dirname(__DIR__) . '/azlinkpro.php'), array(), AZLINKPRO_VERSION);
    wp_enqueue_script('azp-shortcode-copy', plugins_url('assets/shortcode-copy.js', dirname(__DIR__) . '/azlinkpro.php'), array(), AZLINKPRO_VERSION, true);
}
add_action('admin_enqueue_scripts', 'azlinkpro_copy_enqueue_assets');
