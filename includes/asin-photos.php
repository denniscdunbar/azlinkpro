<?php
/**
 * One photo per ASIN. When you choose a photo for a product in a Table, Showcase or
 * product image block, AzlinkPRO remembers it for that ASIN. Anywhere else the same
 * ASIN appears without a photo of its own, that photo is shown instead.
 * The map lives in the azlinkpro_asin_photos option (ASIN => attachment ID).
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_asin_key($asin) {
    $asin = strtoupper(trim((string)$asin));
    return preg_match('/^[A-Z0-9]{10}$/', $asin) ? $asin : '';
}

function azlinkpro_asin_photo_map() {
    $map = get_option('azlinkpro_asin_photos', array());
    return is_array($map) ? $map : array();
}

/** The attachment ID saved for an ASIN, or 0. */
function azlinkpro_asin_photo_id($asin) {
    $asin = azlinkpro_asin_key($asin);
    if ($asin === '') { return 0; }
    $map = azlinkpro_asin_photo_map();
    return isset($map[$asin]) ? absint($map[$asin]) : 0;
}

/** Image source array (url, width, height) for an ASIN's shared photo, or false. */
function azlinkpro_asin_photo_src($asin, $size = 'medium_large') {
    $image_id = azlinkpro_asin_photo_id($asin);
    $source = $image_id ? wp_get_attachment_image_src($image_id, $size) : false;
    return (is_array($source) && !empty($source[0])) ? $source : false;
}

/** The product photos a Table, Showcase or product image block has chosen, as ASIN => attachment ID. */
function azlinkpro_asin_photos_in_post($post_id) {
    $found = array();
    switch (get_post_type($post_id)) {
        case 'atp_table':
            foreach ((array)get_post_meta($post_id, '_atp_rows', true) as $row) {
                $asin = azlinkpro_asin_key($row['asin'] ?? '');
                $image_id = absint($row['image_id'] ?? 0);
                if ($asin !== '' && $image_id) { $found[$asin] = $image_id; }
            }
            break;
        case 'atps_showcase':
            $asin = azlinkpro_asin_key(get_post_meta($post_id, '_atps_asin', true));
            $image_id = absint(get_post_meta($post_id, '_atps_image_id', true));
            if ($asin !== '' && $image_id) { $found[$asin] = $image_id; }
            break;
        case 'azp_product_image':
            $asin = azlinkpro_asin_key(get_post_meta($post_id, '_azp_img_asin', true));
            $image_id = absint(get_post_meta($post_id, '_azp_img_image_id', true));
            if ($asin !== '' && $image_id) { $found[$asin] = $image_id; }
            break;
    }
    return $found;
}

/** Remember a post's photos. The most recently saved photo for an ASIN wins. */
function azlinkpro_remember_asin_photos($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
    $found = azlinkpro_asin_photos_in_post($post_id);
    if (!$found) { return; }
    $map = azlinkpro_asin_photo_map();
    $changed = false;
    foreach ($found as $asin => $image_id) {
        if (!isset($map[$asin]) || (int)$map[$asin] !== $image_id) { $map[$asin] = $image_id; $changed = true; }
    }
    if ($changed) { update_option('azlinkpro_asin_photos', $map, false); }
}
// Priority 99 runs after each module has saved its own fields.
foreach (array('atp_table', 'atps_showcase', 'azp_product_image') as $azlinkpro_photo_type) {
    add_action('save_post_' . $azlinkpro_photo_type, 'azlinkpro_remember_asin_photos', 99);
}
unset($azlinkpro_photo_type);

// A deleted Media Library photo is forgotten.
add_action('delete_attachment', function ($attachment_id) {
    $map = azlinkpro_asin_photo_map();
    $kept = array_filter($map, function ($id) use ($attachment_id) { return (int)$id !== (int)$attachment_id; });
    if (count($kept) !== count($map)) { update_option('azlinkpro_asin_photos', $kept, false); }
});

// Once per version, rebuild the map from photos already chosen, oldest edit first.
add_action('admin_init', function () {
    if (get_option('azlinkpro_asin_photos_built') === AZLINKPRO_VERSION) { return; }
    $ids = get_posts(array('post_type' => array('atp_table', 'atps_showcase', 'azp_product_image'),
        'post_status' => array('publish', 'draft', 'pending', 'private', 'future'), 'numberposts' => -1,
        'fields' => 'ids', 'orderby' => 'modified', 'order' => 'ASC'));
    $map = azlinkpro_asin_photo_map();
    foreach ((array)$ids as $post_id) {
        foreach (azlinkpro_asin_photos_in_post($post_id) as $asin => $image_id) { $map[$asin] = $image_id; }
    }
    update_option('azlinkpro_asin_photos', $map, false);
    update_option('azlinkpro_asin_photos_built', AZLINKPRO_VERSION, false);
});
