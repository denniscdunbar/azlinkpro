<?php
/**
 * AzlinkPRO 1.5.2: duplicate a Table, Amazon Image, Showcase or Pros & Cons card.
 * The copy is a new draft with the same products and settings, so one table can
 * be published in several layouts (for example Full and Minimal).
 */
if (!defined('ABSPATH')) { exit; }

/** Post type => singular label and the plan feature its editor needs ('' = free). */
function azlinkpro_duplicate_types() {
    return array(
        'atp_table' => array('label' => 'table', 'feature' => ''),
        'azp_product_image' => array('label' => 'image', 'feature' => ''),
        'atps_showcase' => array('label' => 'showcase', 'feature' => 'showcase'),
        'atppc_card' => array('label' => 'Pros & Cons card', 'feature' => 'pros_cons'),
    );
}

/** Meta that belongs to the original only: edit locks, old slugs, migration and AI-article links. */
function azlinkpro_duplicate_skip_meta($key) {
    if (in_array($key, array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
        '_azpai_parent_post', '_azpai_generated', '_azpai_table_id'), true)) { return true; }
    return strpos($key, '_azp_mig_') === 0;
}

function azlinkpro_duplicate_allowed($post) {
    $types = azlinkpro_duplicate_types();
    if (!$post instanceof WP_Post || !isset($types[$post->post_type]) || !post_type_exists($post->post_type)) { return false; }
    if (in_array($post->post_status, array('auto-draft', 'trash'), true)) { return false; }
    $feature = $types[$post->post_type]['feature'];
    if ($feature !== '' && !azlinkpro_can($feature)) { return false; }
    $type = get_post_type_object($post->post_type);
    return $type && current_user_can('edit_post', $post->ID) && current_user_can($type->cap->create_posts);
}

function azlinkpro_duplicate_url($post_id) {
    $post_id = absint($post_id);
    // A raw URL (wp_nonce_url() returns it HTML-escaped, which breaks it in JavaScript); callers escape it with esc_url().
    return add_query_arg(array(
        'action' => 'azlinkpro_duplicate',
        'post' => $post_id,
        '_wpnonce' => wp_create_nonce('azlinkpro_duplicate_' . $post_id),
    ), admin_url('admin-post.php'));
}

/** Creates the copy and returns its ID, or a WP_Error. */
function azlinkpro_duplicate_post($post) {
    if (!azlinkpro_duplicate_allowed($post)) {
        return new WP_Error('not_allowed', 'You cannot duplicate this item.');
    }
    $title = trim((string) $post->post_title);
    $copy_id = wp_insert_post(wp_slash(array(
        'post_type' => $post->post_type,
        'post_status' => 'draft',
        'post_title' => ($title !== '' ? $title : '(Untitled)') . ' (copy)',
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
        'post_author' => get_current_user_id(),
        'menu_order' => $post->menu_order,
    )), true);
    if (is_wp_error($copy_id)) { return $copy_id; }
    foreach (get_post_meta($post->ID) as $key => $values) {
        if (azlinkpro_duplicate_skip_meta($key)) { continue; }
        foreach ($values as $value) {
            // get_post_meta() returns stored values serialized; add_post_meta() expects them slashed and unserialized.
            add_post_meta($copy_id, $key, wp_slash(maybe_unserialize($value)));
        }
    }
    return $copy_id;
}

function azlinkpro_duplicate_handle() {
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line, per post.
    check_admin_referer('azlinkpro_duplicate_' . $post_id);
    $post = get_post($post_id);
    $copy_id = azlinkpro_duplicate_post($post);
    if (is_wp_error($copy_id)) {
        wp_die(esc_html($copy_id->get_error_message()), 'AzlinkPRO', array('response' => 403, 'back_link' => true));
    }
    wp_safe_redirect(add_query_arg('azp_duplicated', $post_id, admin_url('post.php?post=' . $copy_id . '&action=edit')));
    exit;
}
add_action('admin_post_azlinkpro_duplicate', 'azlinkpro_duplicate_handle');

/** "Duplicate" under each title in AZ Tables, AZ Images, AZ Showcases and AZ Pros & Cons. */
function azlinkpro_duplicate_row_action($actions, $post) {
    if (azlinkpro_duplicate_allowed($post)) {
        $label = azlinkpro_duplicate_types()[$post->post_type]['label'];
        $actions['azp_duplicate'] = '<a href="' . esc_url(azlinkpro_duplicate_url($post->ID)) . '" aria-label="'
            . esc_attr('Duplicate this ' . $label . ': ' . get_the_title($post)) . '">Duplicate</a>';
    }
    return $actions;
}
add_filter('post_row_actions', 'azlinkpro_duplicate_row_action', 10, 2);

/** A Duplicate button in the editor sidebar, in the classic and block editors. */
function azlinkpro_duplicate_add_box($post_type, $post = null) {
    if (!isset(azlinkpro_duplicate_types()[$post_type]) || !$post instanceof WP_Post || !azlinkpro_duplicate_allowed($post)) { return; }
    add_meta_box('azp-duplicate', 'Duplicate', 'azlinkpro_duplicate_box', $post_type, 'side', 'default');
}
add_action('add_meta_boxes', 'azlinkpro_duplicate_add_box', 20, 2);

function azlinkpro_duplicate_box($post) {
    $label = azlinkpro_duplicate_types()[$post->post_type]['label'];
    echo '<p>Make a draft copy of this ' . esc_html($label) . ' with the same products and settings.'
        . ($post->post_type === 'atp_table' ? ' Then pick another layout, such as Minimal or Compact, for the copy.' : '') . '</p>';
    echo '<p><a class="button" href="' . esc_url(azlinkpro_duplicate_url($post->ID)) . '">Duplicate this ' . esc_html($label) . '</a></p>';
    echo '<p class="description">Save your changes first: the copy is made from the last saved version.</p>';
}

/** 1.5.8: a "Duplicate" button beside "Add New" / "Create ..." at the top of the editor. */
function azlinkpro_duplicate_title_button($hook) {
    if ($hook !== 'post.php') { return; }
    $post = get_post();
    if (!$post instanceof WP_Post || !isset(azlinkpro_duplicate_types()[$post->post_type]) || !azlinkpro_duplicate_allowed($post)) { return; }
    $label = azlinkpro_duplicate_types()[$post->post_type]['label'];
    $data = array(
        'url' => azlinkpro_duplicate_url($post->ID),
        'text' => 'Duplicate ' . ucfirst($label),
        'title' => 'Make a draft copy of this ' . $label . ' (from the last saved version)',
    );
    wp_register_script('azlinkpro-duplicate-button', false, array(), AZLINKPRO_VERSION, true);
    wp_enqueue_script('azlinkpro-duplicate-button');
    wp_add_inline_script('azlinkpro-duplicate-button', '(function(d){var a=document.querySelector(".wrap .page-title-action");if(!a||document.getElementById("azp-duplicate-top")){return;}'
        . 'var b=document.createElement("a");b.id="azp-duplicate-top";b.className="page-title-action";b.href=d.url;b.textContent=d.text;b.title=d.title;'
        . 'a.parentNode.insertBefore(b,a.nextSibling);a.parentNode.insertBefore(document.createTextNode(" "),b);})(' . wp_json_encode($data) . ');');
}
add_action('admin_enqueue_scripts', 'azlinkpro_duplicate_title_button');

/** Shown on the copy right after it is made. */
function azlinkpro_duplicate_notice() {
    $source = isset($_GET['azp_duplicated']) ? absint($_GET['azp_duplicated']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (!$source || !function_exists('get_current_screen')) { return; }
    $screen = get_current_screen();
    if (!$screen || $screen->base !== 'post' || !isset(azlinkpro_duplicate_types()[$screen->post_type])) { return; }
    $label = azlinkpro_duplicate_types()[$screen->post_type]['label'];
    $original = current_user_can('edit_post', $source) ? get_the_title($source) : '';
    echo '<div class="notice notice-success is-dismissible"><p><strong>AzlinkPRO:</strong> This is a draft copy'
        . ($original !== '' ? ' of “' . esc_html($original) . '”' : '') . '. Rename it and change what you like, then click Save Draft or Publish. The new '
        . esc_html($label) . ' gets its own shortcode once saved.</p></div>';
}
add_action('admin_notices', 'azlinkpro_duplicate_notice');
