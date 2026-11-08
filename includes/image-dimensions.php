<?php
/**
 * AzlinkPRO: image size helpers for Amazon and Media Library photos.
 */
if (!defined('ABSPATH')) { exit; }

if (!function_exists('azlinkpro_intrinsic_dimensions')) {
    /**
     * Read intrinsic dimensions already supplied by Amazon Creators API.
     * No network request, URL rewriting, or guessed aspect ratio.
     * For pre-1.3.13 transients without dimensions, returns zeroes safely.
     */
    function azlinkpro_intrinsic_dimensions($product, $selected_key = '') {
        $result = array('width' => 0, 'height' => 0);
        $choices = is_array($product) && isset($product['images']) && is_array($product['images'])
            ? $product['images'] : array();

        $selected = null;
        if (is_string($selected_key) && preg_match('/^[a-f0-9]{32}$/D', $selected_key)) {
            foreach ($choices as $choice) {
                if (!is_array($choice) || !isset($choice['key']) || !is_string($choice['key'])) {
                    continue;
                }
                if (hash_equals($selected_key, $choice['key'])) {
                    $selected = $choice;
                    break;
                }
            }
        }
        if ($selected === null) {
            $image_url = is_array($product) ? ($product['image'] ?? '') : '';
            foreach ($choices as $choice) {
                if (is_array($choice) && isset($choice['url']) && $choice['url'] === $image_url) {
                    $selected = $choice;
                    break;
                }
            }
        }
        if ($selected === null && isset($choices[0]) && is_array($choices[0])) {
            $selected = $choices[0];
        }
        if (!is_array($selected)) {
            return $result;
        }
        $w = filter_var($selected['width'] ?? null, FILTER_VALIDATE_INT);
        $h = filter_var($selected['height'] ?? null, FILTER_VALIDATE_INT);
        if ($w !== false && $h !== false && $w > 0 && $h > 0 && $w <= 10000 && $h <= 10000) {
            return array('width' => $w, 'height' => $h);
        }
        return $result;
    }
}
