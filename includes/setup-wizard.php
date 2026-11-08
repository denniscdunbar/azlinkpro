<?php
/**
 * First-run setup wizard: Amazon Associates tag, Creators API keys and optional AI keys.
 * It saves to the same options as Settings and the AI Review Builder,
 * so nothing is stored twice and those pages keep working as before.
 */
if (!defined('ABSPATH')) { exit; }

function azlinkpro_setup_url() {
    return admin_url('admin.php?page=azlinkpro-setup');
}

function azlinkpro_setup_amazon_options() {
    $options = function_exists('azlinkpro_tbl_options') ? azlinkpro_tbl_options() : get_option('atp_settings', array());
    return wp_parse_args(is_array($options) ? $options : array(),
        array('tag'=>'','client_id'=>'','secret'=>'','marketplace'=>'www.amazon.com'));
}

/** A site counts as set up once it has an Amazon tag or Creators API credentials. */
function azlinkpro_setup_configured() {
    $options = azlinkpro_setup_amazon_options();
    return $options['tag'] !== '' || $options['client_id'] !== '';
}

function azlinkpro_setup_status() {
    $options = azlinkpro_setup_amazon_options();
    // Paid builds add the AI keys (includes/premium/setup-ai.php).
    return apply_filters('azlinkpro_setup_status', array(
        'tag' => $options['tag'] !== '',
        'api' => $options['client_id'] !== '' && $options['secret'] !== '',
    ));
}

/** Called on activation: open the wizard once, only on sites that are not set up yet. */
function azlinkpro_setup_flag_redirect() {
    if (get_option('azlinkpro_setup_state', '') !== '' || azlinkpro_setup_configured()) { return; }
    set_transient('azlinkpro_setup_redirect', 1, 5 * MINUTE_IN_SECONDS);
}
function azlinkpro_setup_maybe_redirect() {
    if (!get_transient('azlinkpro_setup_redirect')) { return; }
    // Freemius shows its opt-in or license screen first, then opens the Dashboard; the wizard follows.
    if (function_exists('azlinkpro_fs') && azlinkpro_fs()->is_activation_mode()) { return; }
    delete_transient('azlinkpro_setup_redirect');
    if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi']) || !current_user_can('manage_options')) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress's own bulk-activation flag
    wp_safe_redirect(azlinkpro_setup_url());
    exit;
}
add_action('admin_init', 'azlinkpro_setup_maybe_redirect');

function azlinkpro_setup_menu() {
    add_submenu_page('azlinkpro', 'AzlinkPRO Setup', 'Setup Wizard', 'manage_options', 'azlinkpro-setup', 'azlinkpro_setup_page');
}
add_action('admin_menu', 'azlinkpro_setup_menu', 20);
/** Opened from the Dashboard and Settings, so it stays out of the menu. Removed after the page title is set. */
function azlinkpro_setup_hide_menu() {
    remove_submenu_page('azlinkpro', 'azlinkpro-setup');
}
add_action('admin_head', 'azlinkpro_setup_hide_menu');

function azlinkpro_setup_notice() {
    if (!current_user_can('manage_options') || get_option('azlinkpro_setup_state', '') !== '' || azlinkpro_setup_configured()) { return; }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || strpos((string) $screen->id, 'azlinkpro') === false || $screen->id === 'azlinkpro_page_azlinkpro-setup') { return; }
    echo '<div class="notice notice-info"><p><strong>Welcome to AzlinkPRO.</strong> Connect your Amazon account in a few short steps. <a class="button button-primary" href="' . esc_url(azlinkpro_setup_url()) . '">Run the setup wizard</a></p></div>';
}
add_action('admin_notices', 'azlinkpro_setup_notice');

function azlinkpro_setup_assets($hook) {
    if ($hook !== 'azlinkpro_page_azlinkpro-setup') { return; }
    $main = AZLINKPRO_PATH . 'azlinkpro.php';
    wp_enqueue_style('azlinkpro-setup', plugins_url('assets/setup-wizard.css', $main), array(), AZLINKPRO_VERSION);
    wp_enqueue_script('azlinkpro-setup', plugins_url('assets/setup-wizard.js', $main), array('jquery'), AZLINKPRO_VERSION, true);
    wp_localize_script('azlinkpro-setup', 'azpSetup', array(
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('azlinkpro_setup'),
        // The Amazon connection test reuses the Settings handler.
        'amazonNonce' => wp_create_nonce('azlinkpro_amazon_test'),
        'status' => azlinkpro_setup_status(),
        'overview' => admin_url('admin.php?page=azlinkpro'),
    ));
}
add_action('admin_enqueue_scripts', 'azlinkpro_setup_assets');

function azlinkpro_setup_page() {
    if (!current_user_can('manage_options')) { return; }
    $options = azlinkpro_setup_amazon_options();
    $status = azlinkpro_setup_status();
    $client_fixed = defined('ATP_AMAZON_CLIENT_ID') && ATP_AMAZON_CLIENT_ID;
    $secret_fixed = defined('ATP_AMAZON_CLIENT_SECRET') && ATP_AMAZON_CLIENT_SECRET;
    // Paid builds add an AI writing step (includes/premium/setup-ai.php); the free build goes from the API keys to Finish.
    $has_ai = function_exists('azlinkpro_setup_ai_step');
    $steps = $has_ai ? array(1 => 'Welcome', 2 => 'Amazon Associates', 3 => 'Creators API', 4 => 'AI writing', 5 => 'Finish')
        : array(1 => 'Welcome', 2 => 'Amazon Associates', 3 => 'Creators API', 5 => 'Finish');
    $ai_allowed = $has_ai && azlinkpro_can('ai_pros_cons');
    $after_api = $has_ai ? 4 : 5;
    $start = array_filter(array(
        array('Comparison table', 'Compare several products side by side.', admin_url('post-new.php?post_type=atp_table'), ''),
        array('Product Showcase', 'Feature one product with its photo and highlights.', admin_url('post-new.php?post_type=atps_showcase'), 'showcase'),
        array('Product image', 'A clickable Amazon photo with a Check Price button.', admin_url('post-new.php?post_type=azp_product_image'), ''),
        array('Pros & Cons card', 'Balanced pros and cons, written by hand or with AI.', admin_url('post-new.php?post_type=atppc_card'), 'pros_cons'),
        array('AI review article', 'Turn a comparison table into a full review post.', admin_url('admin.php?page=azlinkpro-ai-builder'), 'ai_review_builder'),
    ), function ($item) { return azlinkpro_can($item[3]); });
    ?>
    <div class="wrap azp-setup">
      <h1 class="azp-setup-brand">AzlinkPRO <span>Setup</span></h1>
      <ol class="azp-setup-progress">
        <?php foreach ($steps as $number => $label): ?>
          <li data-step="<?php echo (int) $number; ?>"><span class="azp-setup-dot" aria-hidden="true"><?php echo (int) $number; ?></span><span class="azp-setup-label"><?php echo esc_html($label); ?></span></li>
        <?php endforeach; ?>
      </ol>
      <div class="azp-setup-card">

        <section class="azp-setup-step" data-step="1">
          <h2 tabindex="-1">Welcome to AzlinkPRO</h2>
          <?php if ($has_ai): ?>
          <p class="azp-setup-lead">AzlinkPRO builds Amazon comparison tables, product Showcases, clickable product images and pros &amp; cons cards, and can write full review articles with AI. Setup takes about five minutes.</p>
          <?php else: ?>
          <p class="azp-setup-lead">AzlinkPRO builds Amazon comparison tables and clickable product images. Setup takes about five minutes.</p>
          <?php endif; ?>
          <p><strong>You'll need:</strong></p>
          <ul class="azp-setup-needs">
            <li>Your Amazon Associates account and its tracking ID.</li>
            <li>Creators API keys from Associates Central, so AzlinkPRO can load product titles, photos and features.</li>
            <?php if ($has_ai): ?><li>Optional: an OpenAI (ChatGPT) or Anthropic (Claude) API key for AI-written reviews and pros &amp; cons.</li><?php endif; ?>
          </ul>
          <p class="description">You can change anything later under AzlinkPRO → Settings.</p>
          <div class="azp-setup-actions">
            <button type="button" class="button-link azp-setup-skip">Skip setup for now</button>
            <button type="button" class="button button-primary button-hero" data-go="2">Get started</button>
          </div>
        </section>

        <section class="azp-setup-step" data-step="2" hidden>
          <h2 tabindex="-1">Connect your Amazon Associates account</h2>
          <p class="azp-setup-lead">Your tracking ID is added to every Amazon link AzlinkPRO creates, so qualifying purchases are credited to you.</p>
          <div class="azp-setup-field">
            <label for="azp-setup-marketplace">Amazon store</label>
            <select id="azp-setup-marketplace">
              <option value="www.amazon.com" <?php selected($options['marketplace'], 'www.amazon.com'); ?>>Amazon.com (United States)</option>
              <option value="www.amazon.ca" <?php selected($options['marketplace'], 'www.amazon.ca'); ?>>Amazon.ca (Canada)</option>
            </select>
          </div>
          <div class="azp-setup-field">
            <label for="azp-setup-tag">Tracking ID</label>
            <input type="text" id="azp-setup-tag" class="regular-text" maxlength="70" autocomplete="off" spellcheck="false" value="<?php echo esc_attr($options['tag']); ?>" placeholder="yourstore-20">
            <p class="description">Leave it empty to use plain Amazon links that don't earn commission.</p>
          </div>
          <details class="azp-setup-help" open>
            <summary>Where to find your tracking ID</summary>
            <ol>
              <li>Sign in to Associates Central at <a class="azp-setup-associates" href="https://affiliate-program.amazon.com/" target="_blank" rel="noopener noreferrer" data-ca="https://associates.amazon.ca/" data-us="https://affiliate-program.amazon.com/">affiliate-program.amazon.com</a>.</li>
              <li>Your tracking ID appears at the top right, next to your email address. It looks like <code>yourstore-20</code>.</li>
              <li>To see all of them, open your account menu and choose <strong>Manage Your Tracking IDs</strong>.</li>
            </ol>
            <p class="description">No account yet? Sign up at the same address. Amazon finishes reviewing new accounts after their first qualifying sales.</p>
          </details>
          <p class="azp-setup-message" role="status" aria-live="polite"></p>
          <div class="azp-setup-actions">
            <button type="button" class="button" data-go="1">Back</button>
            <button type="button" class="button button-primary" data-save="amazon-tag" data-next="3">Save and continue</button>
          </div>
        </section>

        <section class="azp-setup-step" data-step="3" hidden>
          <h2 tabindex="-1">Add your Creators API keys</h2>
          <p class="azp-setup-lead">AzlinkPRO uses Amazon's Creators API to load product titles, photos and feature bullets. Without it, you can still enter product details by hand.</p>
          <details class="azp-setup-help" open>
            <summary>How to get your Creators API keys</summary>
            <ol>
              <li>Sign in to <a class="azp-setup-associates" href="https://affiliate-program.amazon.com/" target="_blank" rel="noopener noreferrer" data-ca="https://associates.amazon.ca/" data-us="https://affiliate-program.amazon.com/">Associates Central</a> as the account's primary owner. Only the owner can create API keys.</li>
              <li>Open <strong>Tools → Creators API</strong> and create an application.</li>
              <li>Click <strong>Create Credential</strong>, then copy the <strong>Credential ID</strong> and <strong>Secret</strong>. Amazon shows the secret only once.</li>
              <li>Paste both below, then click <strong>Save and test connection</strong>.</li>
            </ol>
            <p class="description">Amazon gives API access only to approved Associates accounts with recent qualifying sales. If you don't see Creators API under Tools, check Amazon's current requirements in Associates Central.</p>
          </details>
          <div class="azp-setup-field">
            <label for="azp-setup-client">Credential ID</label>
            <?php if ($client_fixed): ?>
              <p class="description">Set in wp-config.php.</p>
            <?php else: ?>
              <input type="text" id="azp-setup-client" class="regular-text" maxlength="512" autocomplete="off" spellcheck="false" value="<?php echo esc_attr($options['client_id']); ?>">
            <?php endif; ?>
          </div>
          <div class="azp-setup-field">
            <label for="azp-setup-secret">Secret</label>
            <?php if ($secret_fixed): ?>
              <p class="description">Set in wp-config.php.</p>
            <?php else: ?>
              <input type="password" id="azp-setup-secret" class="regular-text" maxlength="2048" autocomplete="new-password" value="" placeholder="<?php echo esc_attr($options['secret'] !== '' ? 'Saved. Leave empty to keep it' : 'Paste your secret'); ?>">
            <?php endif; ?>
          </div>
          <div class="azp-setup-field">
            <label for="azp-setup-asin">Test with a product ASIN</label>
            <div class="azp-setup-inline">
              <input type="text" id="azp-setup-asin" maxlength="10" autocomplete="off" spellcheck="false" value="B00CH9QWOU">
              <button type="button" class="button button-secondary" id="azp-setup-test-amazon">Save and test connection</button>
            </div>
            <p class="description">Any product from the Amazon store you chose works.</p>
          </div>
          <div class="azp-test-result" id="azp-setup-amazon-result" role="status" aria-live="polite" hidden></div>
          <p class="azp-setup-message" role="status" aria-live="polite"></p>
          <div class="azp-setup-actions">
            <button type="button" class="button" data-go="2">Back</button>
            <button type="button" class="button-link" data-go="<?php echo (int) $after_api; ?>">Skip for now</button>
            <button type="button" class="button button-primary" data-save="amazon-keys" data-next="<?php echo (int) $after_api; ?>">Save and continue</button>
          </div>
        </section>

        <?php if ($has_ai) { azlinkpro_setup_ai_step($status); } ?>

        <section class="azp-setup-step" data-step="5" hidden>
          <h2 tabindex="-1">You're ready to go</h2>
          <ul class="azp-setup-summary">
            <li data-key="tag"><span class="azp-setup-check" aria-hidden="true"></span><span>Amazon tracking ID</span><em></em></li>
            <li data-key="api"><span class="azp-setup-check" aria-hidden="true"></span><span>Creators API keys</span><em></em></li>
            <?php if ($ai_allowed): ?><li data-key="ai"><span class="azp-setup-check" aria-hidden="true"></span><span>AI provider</span><em></em></li><?php endif; ?>
          </ul>
          <p class="description">Anything not set up yet can be added later in Settings<?php echo azlinkpro_can('ai_review_builder') ? ' or the AI Review Builder' : ''; ?>.</p>
          <h3>Create your first</h3>
          <div class="azp-setup-start">
            <?php foreach ($start as $item): ?>
              <a class="azp-setup-start-card" href="<?php echo esc_url($item[2]); ?>"><strong><?php echo esc_html($item[0]); ?></strong><span><?php echo esc_html($item[1]); ?></span></a>
            <?php endforeach; ?>
          </div>
          <div class="azp-setup-actions">
            <button type="button" class="button" data-go="<?php echo $has_ai ? 4 : 3; ?>">Back</button>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=azlinkpro')); ?>">Go to the Dashboard</a>
          </div>
        </section>

      </div>
    </div>
    <?php
}

/** Each step saves through the same validation as Settings and the AI Review Builder. */
function azlinkpro_setup_ajax() {
    check_ajax_referer('azlinkpro_setup', 'nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(array('message' => 'Administrator permission required.'), 403); }
    $op = isset($_POST['op']) && is_string($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

    if ($op === 'amazon') {
        $existing = get_option('atp_settings', array());
        $existing = is_array($existing) ? $existing : array();
        // Fields a step does not send keep their saved value. Saved values are re-slashed
        // because the shared validator unslashes everything, like a normal form post.
        // The nonce is checked at the top of this handler. Values stay slashed here because
        // azlinkpro_validated_configuration() unslashes, validates and sanitizes every field.
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $field = function ($name, $saved) {
            return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : wp_slash((string) $saved);
        };
        $input = array(
            'azp_settings_form_version' => '1',
            'azp_tag' => $field('tag', $existing['tag'] ?? ''),
            'azp_marketplace' => $field('marketplace', $existing['marketplace'] ?? 'www.amazon.com'),
            'azp_client' => $field('client', $existing['client_id'] ?? ''),
            // An empty secret keeps the saved one, as on the Settings page.
            'azp_secret' => isset($_POST['secret']) && is_string($_POST['secret']) ? $_POST['secret'] : '',
            'azp_css_loading' => azlinkpro_asset_mode(),
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        if (get_option('azlinkpro_bold_feature_labels', '1') === '1') { $input['azp_bold_colon_labels'] = '1'; }
        $result = azlinkpro_validated_configuration($input, $existing);
        if (is_wp_error($result)) {
            $messages = array(
                'invalid_tag' => "That tracking ID doesn't look right. It uses letters, numbers and dashes, like yourstore-20.",
                'invalid_marketplace' => 'Choose Amazon.com or Amazon.ca.',
                'invalid_credentials' => 'The Credential ID or Secret is too long or contains characters Amazon does not use. Copy them again from Associates Central.',
            );
            $code = $result->get_error_code();
            wp_send_json_error(array('message' => $messages[$code] ?? 'Those details could not be saved. Reload the page and try again.'));
        }
        azlinkpro_apply_configuration($result, $existing);
        wp_send_json_success(array('message' => 'Saved.', 'status' => azlinkpro_setup_status()));
    }

    if ($op === 'ai' && function_exists('azlinkpro_setup_ai_save')) { azlinkpro_setup_ai_save(); }

    if ($op === 'finish') {
        $state = isset($_POST['state']) && $_POST['state'] === 'skipped' ? 'skipped' : 'done';
        update_option('azlinkpro_setup_state', $state, false);
        wp_send_json_success(array('status' => azlinkpro_setup_status()));
    }

    wp_send_json_error(array('message' => 'Unknown setup step.'), 400);
}
add_action('wp_ajax_azlinkpro_setup', 'azlinkpro_setup_ajax');
