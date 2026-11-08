<?php
/**
 * AzlinkPRO 1.4.0-beta.22 — the free build has no Showcase or Pros & Cons
 * modules. Posts that still contain their shortcodes (for example after moving
 * from a paid plan to the free plugin) show nothing to visitors instead of the
 * raw [azlinkpro_showcase id="12"] text. Editors see a short note.
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_fallback_shortcodes() {
    $tags = array(
        'amatablepro_showcase' => 'Showcase', 'azlinkpro_showcase' => 'Showcase', 'atp_showcase' => 'Showcase',
        'azlinkpro_showcase_grid' => 'Showcase',
        'amatablepro_pros_cons' => 'Pros & Cons card', 'amatablepro_procon' => 'Pros & Cons card',
        'azlinkpro_pros_cons' => 'Pros & Cons card', 'azlinkpro_procon' => 'Pros & Cons card',
    );
    foreach ($tags as $tag => $label) {
        if (shortcode_exists($tag)) { continue; }
        add_shortcode($tag, function () use ($label) {
            if (!current_user_can('edit_posts')) { return ''; }
            $feature = $label === 'Showcase' ? 'showcase' : 'pros_cons';
            return '<p class="azp-locked-note" style="padding:10px 14px;border:1px dashed #c3ccd6;border-radius:8px;color:#4a5866;font-size:14px">'
                . esc_html('AzlinkPRO ' . $label . ' (only you can see this note): ' . azlinkpro_upgrade_message($feature, $label . 's'))
                . '</p>';
        });
    }
}
// After the modules have registered their own shortcodes.
add_action('init', 'azlinkpro_fallback_shortcodes', 99);
