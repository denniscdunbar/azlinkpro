<?php
/**
 * 1.5.12: one "width" setting shared by Showcases, Tables and Pros & Cons cards,
 * so each can be sized to fit the site's theme. A width="..." shortcode value
 * always wins over the saved setting.
 */
if (!defined('ABSPATH')) { exit; }

/** Width choices. Tables already fill the content area, so they hide the separate "Full width" choice. */
function azlinkpro_width_options($default_label = "Default (the design's own size)", $include_full = true) {
    $options = array(
        'default' => $default_label,
        'full' => 'Full width of the content area',
        'wide' => 'Wide (960 px)',
        'medium' => 'Medium (760 px)',
        'narrow' => 'Narrow (560 px)',
        'custom' => 'Custom width',
    );
    if (!$include_full) { unset($options['full']); }
    return $options;
}
function azlinkpro_width_key($value) {
    $value = is_string($value) ? sanitize_key($value) : '';
    return array_key_exists($value, azlinkpro_width_options()) ? $value : 'default';
}
function azlinkpro_width_custom_px($value) {
    return is_scalar($value) && preg_match('/^[0-9]+$/D', trim((string)$value))
        ? max(280, min(1600, absint($value))) : 760;
}
/** CSS max-width, or '' for the design's own. A plain number (width="700") means pixels. */
function azlinkpro_width_css($size, $custom = 760) {
    if (is_scalar($size) && preg_match('/^[0-9]+$/D', trim((string)$size))) { return azlinkpro_width_custom_px($size) . 'px'; }
    switch (azlinkpro_width_key($size)) {
        case 'full': return 'none';
        case 'wide': return '960px';
        case 'medium': return '760px';
        case 'narrow': return '560px';
        case 'custom': return azlinkpro_width_custom_px($custom) . 'px';
        default: return '';
    }
}
/** The width for one shortcode: its own width="..." value, otherwise the saved setting. */
function azlinkpro_width_for($attr, $post_id, $meta_prefix) {
    if (is_array($attr) && isset($attr['width']) && is_string($attr['width']) && trim($attr['width']) !== '') {
        return azlinkpro_width_css(trim($attr['width']));
    }
    return azlinkpro_width_css(get_post_meta($post_id, $meta_prefix . 'max_width', true), get_post_meta($post_id, $meta_prefix . 'max_width_px', true));
}

/** Editor field for Tables and Pros & Cons. $field is the form name, e.g. "atp_max_width". */
function azlinkpro_width_field($field, $label, $value, $px, $description, $options) {
    $value = azlinkpro_width_key($value);
    if (!array_key_exists($value, $options)) { $value = 'default'; }
    $id = str_replace('_', '-', $field);
    ?>
    <div class="azp-width-field" style="margin:14px 0">
      <label for="<?php echo esc_attr($id); ?>"><strong><?php echo esc_html($label); ?></strong></label>
      <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($field); ?>" class="azp-width-select" data-custom="<?php echo esc_attr($id . '-custom'); ?>">
        <?php foreach ($options as $key => $text): ?>
          <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>><?php echo esc_html($text); ?></option>
        <?php endforeach; ?>
      </select>
      <label id="<?php echo esc_attr($id . '-custom'); ?>" for="<?php echo esc_attr($id . '-px'); ?>" <?php if ($value !== 'custom') { echo 'hidden'; } ?>>
        Maximum width (px): <input type="number" id="<?php echo esc_attr($id . '-px'); ?>" name="<?php echo esc_attr($field . '_px'); ?>" min="280" max="1600" step="10" value="<?php echo esc_attr(azlinkpro_width_custom_px($px)); ?>" class="small-text">
      </label>
      <p class="description"><?php echo wp_kses($description, array('code' => array())); ?></p>
    </div>
    <?php
    static $script_printed = false;
    if (!$script_printed) {
        $script_printed = true;
        echo "<script>document.addEventListener('change',function(e){var s=e.target;if(!s||!s.classList||!s.classList.contains('azp-width-select')){return;}var w=document.getElementById(s.getAttribute('data-custom'));if(w){w.hidden=s.value!=='custom';}});</script>";
    }
}
/** Save the field. Callers have already checked the nonce and permissions. */
function azlinkpro_width_save($post_id, $field, $meta_prefix) {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the calling save handler.
    if (!isset($_POST[$field]) || !is_string($_POST[$field])) { return; }
    $key = azlinkpro_width_key(sanitize_text_field(wp_unslash($_POST[$field])));
    $px = isset($_POST[$field . '_px']) && is_string($_POST[$field . '_px']) ? sanitize_text_field(wp_unslash($_POST[$field . '_px'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    update_post_meta($post_id, $meta_prefix . 'max_width', $key);
    update_post_meta($post_id, $meta_prefix . 'max_width_px', azlinkpro_width_custom_px($px));
}

/** Add max-width to the first <section> of a shortcode's output (every table layout and the Pros & Cons card start with one). */
function azlinkpro_width_apply($output, $css) {
    if ($css === '' || !is_string($output) || !preg_match('/<section\b[^>]*>/', $output, $m, PREG_OFFSET_CAPTURE)) { return $output; }
    $tag = $m[0][0];
    $rule = 'max-width:' . $css . '!important;margin-left:auto!important;margin-right:auto!important;';
    if (preg_match('/\sstyle="([^"]*)"/', $tag, $s)) {
        $existing = rtrim($s[1]);
        $new_tag = str_replace($s[0], ' style="' . $existing . ($existing !== '' && substr($existing, -1) !== ';' ? ';' : '') . $rule . '"', $tag);
    } else {
        $new_tag = substr($tag, 0, -1) . ' style="' . $rule . '">';
    }
    return substr_replace($output, $new_tag, $m[0][1], strlen($tag));
}
function azlinkpro_width_shortcode_output($output, $tag, $attr) {
    if (!is_string($output) || $output === '') { return $output; }
    $types = array(
        'amatablepro' => array('atp_table', '_atp_'), 'azlinkpro_table' => array('atp_table', '_atp_'),
        'amatablepro_pros_cons' => array('atppc_card', '_atppc_'), 'amatablepro_procon' => array('atppc_card', '_atppc_'),
        'azlinkpro_pros_cons' => array('atppc_card', '_atppc_'), 'azlinkpro_procon' => array('atppc_card', '_atppc_'),
    );
    if (!isset($types[$tag]) || !is_array($attr)) { return $output; }
    $id = absint($attr['id'] ?? 0);
    if (!$id || get_post_type($id) !== $types[$tag][0]) { return $output; }
    return azlinkpro_width_apply($output, azlinkpro_width_for($attr, $id, $types[$tag][1]));
}
/** Custom Comparison and Side-by-Side tables replace the output through pre_do_shortcode_tag, which skips do_shortcode_tag. */
function azlinkpro_width_shortcode_override($override, $tag, $attr) {
    return is_string($override) && $override !== '' ? azlinkpro_width_shortcode_output($override, $tag, $attr) : $override;
}
// After the layout filters (priority 30 and 20), which also edit the opening tag.
add_filter('do_shortcode_tag', 'azlinkpro_width_shortcode_output', 40, 3);
add_filter('pre_do_shortcode_tag', 'azlinkpro_width_shortcode_override', 40, 3);
