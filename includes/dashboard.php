<?php
/**
 * AzlinkPRO — read-only WordPress admin dashboard.
 * Counts only saved post types; does not send API requests, expose keys, or
 * modify any records during rendering. Paid builds add the AI panels from
 * includes/premium/dashboard-ai.php.
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_dashboard_count($post_type) {
    if (!post_type_exists($post_type)) {
        return array('total'=>0,'publish'=>0,'draft'=>0,'pending'=>0,'private'=>0);
    }
    $registered = wp_count_posts($post_type);
    $counts = array();
    foreach (array('publish','draft','pending','private') as $status) {
        $counts[$status] = isset($registered->{$status}) ? max(0,(int)$registered->{$status}) : 0;
    }
    $counts['total'] = array_sum($counts);
    return $counts;
}

function azlinkpro_dashboard_config_status() {
    $settings = function_exists('azlinkpro_tbl_options') ? azlinkpro_tbl_options() : array();
    $tag = isset($settings['tag']) ? trim((string)$settings['tag']) : '';
    $id = isset($settings['client_id']) ? trim((string)$settings['client_id']) : '';
    $secret = isset($settings['secret']) ? trim((string)$settings['secret']) : '';
    return array(
        'amazon_ready'=> $tag !== '' && $id !== '' && $secret !== '',
        'has_tag' => $tag !== '',
        'has_credentials' => $id !== '' && $secret !== '',
        'conflict' => function_exists('azlinkpro_conflicting_plugins') && (bool)azlinkpro_conflicting_plugins(),
    );
}

function azlinkpro_render_dashboard() {
    if (!current_user_can('edit_posts')) { return; }
    $modules = array(
        'atp_table'=>array('title'=>'Comparison Tables', 'shortcode'=>'azlinkpro_table', 'detail'=>'Side-by-side comparisons', 'feature'=>''),
        'azp_product_image'=>array('title'=>'Amazon Images', 'shortcode'=>'azlinkpro_amazon_image', 'detail'=>'Clickable product photos', 'feature'=>''),
        'atps_showcase'=>array('title'=>'Product Showcases','shortcode'=>'azlinkpro_showcase','detail'=>'Individual product displays', 'feature'=>'showcase'),
        'atppc_card'=>array('title'=>'Pros & Cons','shortcode'=>'azlinkpro_pros_cons','detail'=>'Balanced review cards', 'feature'=>'pros_cons'),
    );
    $summary = array();
    foreach ($modules as $type=>$meta) {
        $summary[$type] = azlinkpro_dashboard_count($type);
    }
    $cfg = azlinkpro_dashboard_config_status();
    $recent = get_posts(array(
        'post_type'=>array_keys($modules), 'post_status'=>array('publish','draft','pending','private'),
        'posts_per_page'=>7, 'orderby'=>'modified','order'=>'DESC',
        'suppress_filters'=>false,
    ));
    ?>
    <div class="wrap azlinkpro-admin-wrap azp-dashboard">
        <div class="azp-dashboard-heading">
            <div>
                <p class="azp-dashboard-eyebrow">Dashboard</p>
                <h1>AzlinkPRO <span class="azp-version">v<?php echo esc_html(AZLINKPRO_VERSION); ?></span></h1>
                <p class="azp-dashboard-subtitle">Your publishing toolkit at a glance. Counts are from saved WordPress content on this site.</p>
            </div>
            <?php if (current_user_can('manage_options')): ?>
                <div class="azp-dashboard-heading-actions">
                    <a class="button button-secondary" href="<?php echo esc_url(azlinkpro_setup_url()); ?>">Setup wizard</a>
                    <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=azlinkpro-settings')); ?>">Plugin settings</a>
                </div>
            <?php endif; ?>
        </div>
        <?php do_action('azlinkpro_dashboard_top'); // Link Audit: the alert on paid plans, a dismissible card on Free. ?>
        <div class="azp-dashboard-metrics" aria-label="Content totals">
            <?php foreach ($modules as $type=>$meta):
                $counts = $summary[$type];
                $percent = $counts['total'] ? (int) round($counts['publish'] * 100 / $counts['total']) : 0;
                ?>
                <section class="azp-dashboard-metric" aria-label="<?php echo esc_attr($meta['title']); ?>">
                    <div class="azp-dashboard-metric-top"><h2><?php echo esc_html($meta['title']); ?></h2><span class="azp-dashboard-metric-number"><?php echo esc_html(number_format_i18n($counts['total'])); ?></span></div>
                    <p><?php echo esc_html($meta['detail']); ?></p>
                    <div class="azp-dashboard-bar" role="img" aria-label="<?php echo esc_attr($percent . '% published'); ?>"><span style="width:<?php echo esc_attr($percent); ?>%"></span></div>
                    <p class="azp-dashboard-tallies"><?php echo esc_html(number_format_i18n($counts['publish'])); ?> published <span aria-hidden="true">·</span> <?php echo esc_html(number_format_i18n($counts['draft'])); ?> drafts<?php if ($counts['pending']): ?> <span aria-hidden="true">·</span> <?php echo esc_html(number_format_i18n($counts['pending'])); ?> pending<?php endif; ?><?php if ($counts['private']): ?> <span aria-hidden="true">·</span> <?php echo esc_html(number_format_i18n($counts['private'])); ?> private<?php endif; ?></p>
                    <?php if (!$meta['feature'] || azlinkpro_can($meta['feature'])): ?>
                    <div class="azp-dashboard-actions"><a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=' . $type)); ?>">Manage</a><a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . $type)); ?>">Add new</a></div>
                    <?php else: ?>
                    <p class="azp-dashboard-locked"><?php echo esc_html('Included in the ' . azlinkpro_feature_plans_label($meta['feature']) . ' plans.'); ?></p>
                    <div class="azp-dashboard-actions"><a class="button" href="<?php echo esc_url(azlinkpro_upgrade_url()); ?>">See plans</a></div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
            <?php if (function_exists('azlinkpro_dashboard_ai_metric')) { azlinkpro_dashboard_ai_metric(); } ?>
        </div>
        <div class="azp-dashboard-columns">
            <section class="azp-dashboard-panel">
                <div class="azp-dashboard-panel-heading"><h2>Recently updated designs</h2><span>Most recent 7</span></div>
                <?php if (!$recent): ?><p>No saved Tables, Amazon Images, Showcases or Pros & Cons yet. Use Add new above to get started.</p>
                <?php else: ?>
                <div class="azp-dashboard-table-scroll"><table class="widefat striped azp-dashboard-recent">
                    <thead><tr><th scope="col">Title</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Shortcode</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $post):
                        if (!isset($modules[$post->post_type]) || !current_user_can('edit_post',$post->ID)) { continue; }
                        $meta = $modules[$post->post_type];
                        $shortcode = '[' . $meta['shortcode'] . ' id="' . (int)$post->ID . '"]';
                        $status_obj = get_post_status_object($post->post_status);
                        $status_label = $status_obj ? $status_obj->label : ucfirst($post->post_status);
                        ?>
                        <tr><td><a href="<?php echo esc_url(get_edit_post_link($post->ID, 'raw')); ?>"><?php echo esc_html(get_the_title($post->ID) ?: '(Untitled)'); ?></a></td>
                            <td><?php echo esc_html($meta['title']); ?></td>
                            <td><?php echo esc_html($status_label); ?></td>
                            <td><div class="azp-dashboard-code"><code><?php echo esc_html($shortcode); ?></code><button type="button" class="button button-small azp-dashboard-copy" data-shortcode="<?php echo esc_attr($shortcode); ?>" aria-label="Copy shortcode for <?php echo esc_attr(get_the_title($post->ID)); ?>">Copy</button></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="description" id="azp-dashboard-copy-status" role="status" aria-live="polite"></p>
                <?php endif; ?>
            </section>
            <section class="azp-dashboard-panel azp-dashboard-health">
                <div class="azp-dashboard-panel-heading"><h2>Configuration checks</h2></div>
                <dl>
                    <div><dt>Amazon Associates tag</dt><dd><?php echo $cfg['has_tag'] ? 'Configured' : 'Not configured'; ?></dd></div>
                    <div><dt>Creators API credentials</dt><dd><?php echo $cfg['has_credentials'] ? 'Configured' : 'Not configured'; ?></dd></div>
                    <?php $amazon_test = azlinkpro_amazon_test_summary(); ?>
                    <div><dt>Amazon live connection</dt><dd<?php echo !empty($amazon_test['when']) ? ' title="' . esc_attr($amazon_test['when']) . '"' : ''; ?>><?php echo esc_html($amazon_test['label']); ?></dd></div>
                    <div><dt>Plugin conflicts</dt><dd><?php echo $cfg['conflict'] ? 'Check legacy plugins' : 'None detected'; ?></dd></div>
                </dl>
                <?php if (current_user_can('manage_options')): ?>
                    <p><strong>Plan:</strong> <?php echo esc_html(azlinkpro_current_plan_label()); ?> · <a href="<?php echo esc_url(admin_url('admin.php?page=azlinkpro-settings')); ?>">Plan and license</a></p>
                <?php endif; ?>
                <p class="description">This page makes no network requests and never shows credentials. Amazon live connection shows the last test run from Settings or the setup wizard; changing your API keys resets it.</p>
                <?php if (current_user_can('manage_options')): ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=azlinkpro-settings')); ?>">Check connection</a>
                <?php endif; ?>
            </section>
        </div>
        <?php if (function_exists('azlinkpro_dashboard_ai_health')) { azlinkpro_dashboard_ai_health(); } ?>
    </div>
    <?php
}

/**
 * 1.5.5: on sites without a paid plan, one small card on the AzlinkPRO Dashboard
 * mentions the Product Link Audit. Shown only on this page, and gone for good
 * once the user dismisses it.
 */
function azlinkpro_audit_teaser() {
    if (azlinkpro_can('link_audit') || get_user_meta(get_current_user_id(), 'azlinkpro_hide_audit_teaser', true)) { return; }
    $hide = wp_nonce_url(admin_url('admin-post.php?action=azlinkpro_hide_audit_teaser'), 'azlinkpro_hide_audit_teaser');
    echo '<div class="azp-dashboard-audit is-new azp-dashboard-teaser"><span class="dashicons dashicons-search" aria-hidden="true"></span>'
        . '<p><strong>Product Link Audit:</strong> find the products in your tables and images that Amazon no longer sells or has out of stock, with a check every week. Included in the '
        . esc_html(azlinkpro_feature_plans_label('link_audit')) . ' plans.</p>'
        . '<a class="button" href="' . esc_url(azlinkpro_upgrade_url()) . '">See plans</a>'
        . '<a class="azp-dashboard-teaser-hide" href="' . esc_url($hide) . '">Dismiss<span class="screen-reader-text"> this message</span></a></div>';
}
add_action('azlinkpro_dashboard_top', 'azlinkpro_audit_teaser');

function azlinkpro_hide_audit_teaser() {
    check_admin_referer('azlinkpro_hide_audit_teaser');
    if (current_user_can('edit_posts')) { update_user_meta(get_current_user_id(), 'azlinkpro_hide_audit_teaser', 1); }
    wp_safe_redirect(admin_url('admin.php?page=azlinkpro'));
    exit;
}
add_action('admin_post_azlinkpro_hide_audit_teaser', 'azlinkpro_hide_audit_teaser');
