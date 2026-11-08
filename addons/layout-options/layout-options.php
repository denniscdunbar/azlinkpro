<?php
/**
 * AzlinkPRO 1.3.37 - optional appearance layouts for bundled Tables.
 * Since 1.4.0-beta.22 Custom Comparison and the Pros & Cons designs live in
 * premium/ (paid build only) and hook in through the filters and actions below.
 * Bundled inside the existing all-in-one plugin; never activate as a separate plugin.
 */
if (!defined('ABSPATH')) { exit; }

final class AzlinkPRO_Layout_Options_134 {
    const VER = AZLINKPRO_VERSION;
    const TABLE_META = '_azp_table_layout_134';

    public static function boot() {
        add_action('save_post_atp_table', array(__CLASS__, 'save_table'), 30, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'public_assets'), 20);
        add_filter('do_shortcode_tag', array(__CLASS__, 'render_layout'), 30, 4);
    }

    public static function table_layouts() {
        // Paid builds add 'custom' (Custom Comparison).
        return apply_filters('azlinkpro_table_layouts', array('full' => 'Full Comparison (original)', 'compact' => 'Compact Comparison', 'minimal' => 'Minimal List'));
    }

    public static function table_layout($id) {
        $layout = get_post_meta($id, self::TABLE_META, true);
        return is_string($layout) && isset(self::table_layouts()[$layout]) ? $layout : 'full';
    }

    public static function table_box($post) {
        $layout = self::table_layout($post->ID);
        wp_nonce_field('azp_layout_134_' . $post->ID, 'azp_layout_134_nonce');
        echo '<div class="azp-layout-admin" data-azp-type="table"><h2 class="azp-layout-editor-heading">Choose Table Layout</h2><p><label for="azp-table-layout"><strong>Table type</strong></label> ';
        echo '<select id="azp-table-layout" name="azp_table_layout_134" class="azp-layout-select">';
        // Paid builds hide Custom Comparison from sites without the plan, unless the table already uses it.
        foreach (apply_filters('azlinkpro_table_layout_choices', self::table_layouts(), $layout) as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($key, $layout, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';
        // Paid builds add the Custom Comparison editor here.
        do_action('azlinkpro_table_layout_editor', $post, $layout);
        self::table_preview();
        // For the original/compact/minimal layouts, show *real selected products* close
        // to the illustrative preview, so removal never requires scrolling to the
        // full product cards below. The shared product editor remains authoritative.
        echo '<div id="azp-quick-remove-panel" class="azp-quick-remove-panel" aria-label="Quick remove products">';
        echo '<div class="azp-quick-remove-heading"><strong>Products in this table</strong><span class="description">Reorder or remove · current table order</span></div>';
        echo '<div id="azp-quick-remove-items" class="azp-quick-remove-items"></div></div>';
        echo '<p id="azp-preview-remove-status" class="azp-preview-remove-status" role="status" aria-live="polite"></p>';
        echo '</div>';
    }

    private static function table_preview() {
        echo '<div class="azp-layout-preview azp-table-preview azp-table-preview--full" aria-label="Illustrative table layout preview">';
        echo '<div class="azp-preview-title">Layout preview <small>(sample content)</small></div>';
        for ($i = 0; $i < 2; $i++) {
            echo '<div class="azp-preview-product"><span class="azp-preview-image" aria-hidden="true"><span class="dashicons dashicons-camera"></span></span>';
            echo '<div class="azp-preview-copy"><strong>' . esc_html($i === 0 ? 'Product One' : 'Product Two') . '</strong><small>Key feature and product highlight</small></div>';
            echo '<span class="azp-preview-cta">Check Price</span></div>';
        }
        echo '<div class="azp-custom-grid-preview" aria-live="polite" hidden></div>';
        echo '</div>';
    }

    public static function can_save($post_id, $post) {
        if (!isset($_POST['azp_layout_134_nonce']) || !is_string($_POST['azp_layout_134_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['azp_layout_134_nonce'])), 'azp_layout_134_' . $post_id)) {
            return false;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return false; }
        if (wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) { return false; }
        return $post && !in_array($post->post_status, array('auto-draft', 'trash'), true);
    }

    /** An incomplete or malformed table payload cannot commit only half its settings. */
    public static function valid_table_editor_submission($input) {
        if (!is_array($input) || !isset($input['azp_table_layout_134']) || !is_string($input['azp_table_layout_134'])) { return false; }
        if (!isset(self::table_layouts()[sanitize_key(wp_unslash($input['azp_table_layout_134']))])) { return false; }
        if (!isset($input['atp_rows_json']) || !is_string($input['atp_rows_json']) || strlen($input['atp_rows_json']) > 1200000) { return false; }
        $json = wp_unslash($input['atp_rows_json']);
        if (strpos(ltrim($json), '[') !== 0 || !function_exists('azlinkpro_tbl_valid_rows_submission') ||
            !azlinkpro_tbl_valid_rows_submission(json_decode($json, true))) { return false; }
        // Paid builds also check the Custom Comparison data when the editor sent it.
        return (bool) apply_filters('azlinkpro_valid_table_editor_submission', true, $input);
    }
    public static function save_table($post_id, $post) {
        // can_save() verifies the nonce; valid_table_editor_submission() checks the field exists.
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        if (!self::can_save($post_id, $post) || !self::valid_table_editor_submission($_POST)) { return; }
        if (!function_exists('azlinkpro_table_editor_revision_matches') || !azlinkpro_table_editor_revision_matches($post_id)) { return; }
        $raw = sanitize_key(wp_unslash($_POST['azp_table_layout_134']));
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        update_post_meta($post_id, self::TABLE_META, $raw);
        do_action('azlinkpro_save_table_layout', $post_id, $raw);
    }

    /** URL of a file in premium/assets, or '' in the free build, which has no premium folder. */
    public static function premium_asset($file) {
        return file_exists(__DIR__ . '/premium/assets/' . $file) ? plugin_dir_url(__FILE__) . 'premium/assets/' . $file : '';
    }

    /** The stylesheets for the paid layouts, after the shared ones. */
    public static function enqueue_premium_styles($public_handle, $admin_handle = '') {
        if ($admin_handle && ($css = self::premium_asset('admin-premium.css'))) { wp_enqueue_style($admin_handle . '-premium', $css, array($admin_handle), self::VER); }
        if ($css = self::premium_asset('frontend-premium.css')) { wp_enqueue_style($public_handle . '-premium', $css, array($public_handle), self::VER); }
    }

    public static function admin_assets($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) { return; }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'atp_table') { return; }
        $url = plugin_dir_url(__FILE__);
        wp_enqueue_style('azp-layout-admin', $url . 'assets/admin.css', array(), self::VER);
        // Reuse the exact public comparison CSS for the editor's live appearance preview.
        wp_enqueue_style('azp-layout-preview-public', $url . 'assets/frontend.css', array(), self::VER);
        self::enqueue_premium_styles('azp-layout-preview-public', 'azp-layout-admin');
        // Paid builds replace the free editor script with the one that also edits comparison tables.
        $premium_js = self::premium_asset('admin.js');
        if ($premium_js) { wp_enqueue_script('azp-custom-table', self::premium_asset('custom-table.js'), array(), self::VER, true); }
        wp_enqueue_script('azp-layout-admin', $premium_js ?: $url . 'assets/admin.js', array(), self::VER, true);
    }

    public static function public_assets() {
        if (!azlinkpro_should_load_asset('layout')) { return; }
        wp_enqueue_style('azp-layout-public', plugin_dir_url(__FILE__) . 'assets/frontend.css', array(), self::VER);
        self::enqueue_premium_styles('azp-layout-public');
    }

    public static function render_layout($output, $tag, $attr, $m) {
        if (!is_string($output) || $output === '' || !is_array($attr)) { return $output; }
        $id = absint($attr['id'] ?? 0);
        if (!$id) { return $output; }
        if (in_array($tag, array('amatablepro', 'azlinkpro_table'), true)) {
            if (get_post_type($id) !== 'atp_table') { return $output; }
            $layout = self::table_layout($id);
            if ($layout === 'full') { return $output; }
            $needle = 'class="atp-wrap"';
            if (strpos($output, $needle) === false) { return $output; }
            return substr_replace($output, 'class="atp-wrap azp-table-layout-' . $layout . '"', strpos($output, $needle), strlen($needle));
        }
        return $output;
    }
}

AzlinkPRO_Layout_Options_134::boot();
