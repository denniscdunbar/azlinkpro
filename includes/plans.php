<?php
/**
 * AzlinkPRO 1.4.0-beta.22 — which Freemius plan unlocks which feature.
 *
 * Free features are not listed. Higher plans include lower ones (Freemius orders
 * Free < Starter < Professional < Agency). Only editors and admin tools are
 * locked: Tables, Showcases and Pros & Cons already published keep displaying
 * when a license lapses or a site moves to a lower plan.
 */
if (!defined('ABSPATH')) { exit; }

/** Feature => the lowest plan that includes it. Move a feature to another tier here. */
function azlinkpro_feature_plans() {
    return array(
        'custom_comparison' => 'starter',
        'spreadsheet' => 'starter',
        'showcase' => 'starter',
        'pros_cons' => 'starter',
        'link_audit' => 'starter',
        'ai_pros_cons' => 'professional',
        'ai_review_builder' => 'agency',
    );
}

function azlinkpro_plan_title($plan) {
    $titles = array('starter' => 'Starter', 'professional' => 'Professional', 'agency' => 'Agency');
    return $titles[$plan] ?? '';
}

/** The plans that include a feature, for messages: "Starter, Professional and Agency". */
function azlinkpro_feature_plans_label($feature) {
    $order = array('starter', 'professional', 'agency');
    $plans = azlinkpro_feature_plans();
    if (!isset($plans[$feature])) { return 'every plan'; }
    $names = array_map('azlinkpro_plan_title', array_slice($order, (int) array_search($plans[$feature], $order, true)));
    $last = array_pop($names);
    return $names ? implode(', ', $names) . ' and ' . $last : $last;
}

/** True when this site may use a feature: free ones always, paid ones on that plan, a higher one, or its trial. */
function azlinkpro_can($feature) {
    $plans = azlinkpro_feature_plans();
    if (!isset($plans[$feature])) { return true; }
    if (!function_exists('azlinkpro_fs')) { return false; }
    $fs = azlinkpro_fs();
    // is_premium() is false in the free build Freemius generates.
    return $fs->is_premium() && $fs->can_use_premium_code() && $fs->is_plan_or_trial($plans[$feature]);
}

/** Freemius's in-dashboard pricing and checkout page. */
function azlinkpro_upgrade_url() {
    return function_exists('azlinkpro_fs') ? azlinkpro_fs()->get_upgrade_url() : 'https://azlinkpro.com/pricing/';
}

/** "Showcases are included in the Starter, Professional and Agency plans." */
function azlinkpro_upgrade_message($feature, $what) {
    $plans = azlinkpro_feature_plans_label($feature);
    return sprintf('%s %s included in the %s %s.', $what, preg_match('/s$/', $what) ? 'are' : 'is', $plans, strpos($plans, ' and ') === false ? 'plan' : 'plans');
}

/** "Professional", "Starter (trial)" or "Free". */
function azlinkpro_current_plan_label() {
    $fs = function_exists('azlinkpro_fs') ? azlinkpro_fs() : null;
    if (!$fs || !$fs->is_premium() || !$fs->can_use_premium_code()) { return 'Free'; }
    $plan = azlinkpro_plan_title((string) $fs->get_plan_name());
    if ($plan === '') { return 'Free'; }
    return $fs->is_trial() ? $plan . ' (trial)' : $plan;
}

/** Settings → Your plan. */
function azlinkpro_render_plan_summary() {
    $fs = function_exists('azlinkpro_fs') ? azlinkpro_fs() : null;
    $plan = azlinkpro_current_plan_label();
    echo '<p><strong>Current plan:</strong> ' . esc_html($plan) . '</p>';
    $rows = array(
        'custom_comparison' => 'Custom Comparison tables',
        'spreadsheet' => 'Spreadsheet import and export',
        'showcase' => 'Showcases',
        'pros_cons' => 'Pros & Cons cards',
        'link_audit' => 'Product Link Audit',
        'ai_pros_cons' => 'AI Pros & Cons generator',
        'ai_review_builder' => 'AI Review Builder',
    );
    echo '<ul class="azp-plan-features">';
    foreach ($rows as $feature => $label) {
        $on = azlinkpro_can($feature);
        echo '<li class="' . ($on ? 'is-on' : 'is-off') . '"><span aria-hidden="true">' . ($on ? '&#10003;' : '&ndash;') . '</span> ' . esc_html($label)
            . ($on ? '' : ' <em>' . esc_html(azlinkpro_feature_plans_label($feature)) . '</em>') . '</li>';
    }
    echo '</ul>';
    if ($fs) {
        $account = $fs->is_registered() ? $fs->get_account_url() : '';
        echo '<p>';
        if (strpos($plan, 'Agency') !== 0) { echo '<a class="button button-primary" href="' . esc_url(azlinkpro_upgrade_url()) . '">See plans and upgrade</a> '; }
        if ($account) { echo '<a class="button" href="' . esc_url($account) . '">Account and license</a>'; }
        echo '</p>';
    }
}
