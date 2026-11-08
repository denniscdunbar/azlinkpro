<?php

/**
 * AzlinkPRO core: everything the main plugin file loads once Freemius is ready.
 * Kept out of azlinkpro.php so the free and paid builds can be active together
 * for a moment (Freemius then deactivates the free one) without redeclaring functions.
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
require_once AZLINKPRO_PATH . 'includes/plans.php';
// Paid builds only: Freemius leaves includes/premium/ out of the free version.
foreach ( array(
    'plan-locks',
    'dashboard-ai',
    'setup-ai',
    'link-audit'
) as $azlinkpro_premium_file ) {
    if ( file_exists( AZLINKPRO_PATH . 'includes/premium/' . $azlinkpro_premium_file . '.php' ) ) {
        require_once AZLINKPRO_PATH . 'includes/premium/' . $azlinkpro_premium_file . '.php';
    }
}
unset($azlinkpro_premium_file);
require_once AZLINKPRO_PATH . 'includes/image-dimensions.php';
require_once AZLINKPRO_PATH . 'includes/shortcode-copy.php';
require_once AZLINKPRO_PATH . 'includes/dashboard.php';
require_once AZLINKPRO_PATH . 'includes/frontend-assets.php';
require_once AZLINKPRO_PATH . 'includes/setup-wizard.php';
require_once AZLINKPRO_PATH . 'includes/product-names.php';
require_once AZLINKPRO_PATH . 'includes/asin-photos.php';
require_once AZLINKPRO_PATH . 'includes/duplicate.php';
require_once AZLINKPRO_PATH . 'includes/display-width.php';
require_once AZLINKPRO_PATH . 'addons/layout-options/layout-options.php';
/**
 * Character-safe text trimming. substr() counts bytes and can cut a multi-byte
 * character (em dash, curly quote, ™) in half; sanitize_text_field() then sees
 * invalid UTF-8 and returns an empty string.
 */
function azlinkpro_text_limit(  $text, $max  ) {
    $text = (string) $text;
    $max = max( 0, (int) $max );
    if ( function_exists( 'mb_substr' ) ) {
        return mb_substr(
            $text,
            0,
            $max,
            'UTF-8'
        );
    }
    return ( preg_match( '/^.{0,' . $max . '}/us', $text, $m ) ? $m[0] : substr( $text, 0, $max ) );
}

function azlinkpro_text_length(  $text  ) {
    $text = (string) $text;
    if ( function_exists( 'mb_strlen' ) ) {
        return mb_strlen( $text, 'UTF-8' );
    }
    $count = preg_match_all( '/./us', $text );
    return ( $count === false ? strlen( $text ) : $count );
}

/**
 * Product image display sizing is CSS-only. Amazon image choices and their
 * URLs remain unchanged; only the displayed bounds are adjusted.
 * Older cards use "default" and preserve the original theme/plugin layout.
 */
function azlinkpro_image_size_options() {
    return array(
        'default' => 'Default (current layout)',
        'small'   => 'Small (120 px)',
        'medium'  => 'Medium (180 px)',
        'large'   => 'Large (260 px)',
        'xlarge'  => 'Extra large (360 px)',
        'custom'  => 'Custom size',
    );
}

function azlinkpro_image_size_key(  $value  ) {
    $value = ( is_string( $value ) ? sanitize_key( $value ) : '' );
    return ( array_key_exists( $value, azlinkpro_image_size_options() ) ? $value : 'default' );
}

function azlinkpro_image_custom_px(  $value  ) {
    // Empty/bad values use a safe, predictable preview and frontend value.
    return ( is_scalar( $value ) && preg_match( '/^[0-9]+$/D', trim( (string) $value ) ) ? max( 60, min( 420, absint( $value ) ) ) : 180 );
}

function azlinkpro_image_display_px(  $size, $custom = 180  ) {
    switch ( azlinkpro_image_size_key( $size ) ) {
        case 'small':
            return 120;
        case 'medium':
            return 180;
        case 'large':
            return 260;
        case 'xlarge':
            return 360;
        case 'custom':
            return azlinkpro_image_custom_px( $custom );
        default:
            return 0;
    }
}

/**
 * Escape all feature text, but optionally emphasize a short label ending in the
 * first colon followed by a space. Safe for plain text from Amazon or an editor.
 * No HTML supplied by a product is allowed through unescaped.
 * Multiline descriptions are handled line by line.
 */
function azlinkpro_format_feature_labels(  $text  ) {
    $text = (string) $text;
    if ( get_option( 'azlinkpro_bold_feature_labels', '1' ) !== '1' ) {
        return esc_html( $text );
    }
    $lines = preg_split( '/\\r\\n|\\r|\\n/', $text );
    if ( !is_array( $lines ) ) {
        return esc_html( $text );
    }
    $output = array();
    foreach ( $lines as $line ) {
        // Only a reasonable-length label followed by body text is formatted.
        // This avoids changing timestamps, URLs, empty labels and prose with
        // colons that are not label separators.
        if ( preg_match( '/^(\\s*)([\\p{L}\\p{N}][^:\\r\\n]{1,95}:)([ \\t]+)(\\S.*)$/u', $line, $match ) ) {
            $output[] = esc_html( $match[1] ) . '<strong>' . esc_html( $match[2] ) . '</strong>' . esc_html( $match[3] . $match[4] );
        } else {
            $output[] = esc_html( $line );
        }
    }
    return implode( "\n", $output );
}

/**
 * Collect Amazon-approved Primary / Variants image URLs from a Creators API
 * response. Store only a stable hashed image identifier in product settings;
 * URLs are obtained from refreshed API data and are never downloaded here.
 *
 * A single URL per gallery photo is kept, preferring large then medium.
 */
function azlinkpro_amazon_image_choices(  $item  ) {
    $source = ( isset( $item['images'] ) && is_array( $item['images'] ) ? $item['images'] : array() );
    $groups = array();
    if ( isset( $source['primary'] ) && is_array( $source['primary'] ) ) {
        $groups[] = $source['primary'];
    }
    if ( isset( $source['variants'] ) && is_array( $source['variants'] ) ) {
        foreach ( $source['variants'] as $variant ) {
            if ( is_array( $variant ) ) {
                $groups[] = $variant;
            }
        }
    }
    $choices = array();
    $seen = array();
    foreach ( $groups as $group ) {
        $url = '';
        $selected_image_size = array();
        foreach ( array('large', 'medium', 'small') as $size ) {
            $candidate = ( isset( $group[$size]['url'] ) && is_string( $group[$size]['url'] ) ? $group[$size]['url'] : '' );
            if ( !$candidate ) {
                continue;
            }
            $parts = wp_parse_url( $candidate );
            if ( !is_array( $parts ) || strtolower( $parts['scheme'] ?? '' ) !== 'https' || !in_array( strtolower( $parts['host'] ?? '' ), array('m.media-amazon.com', 'images-na.ssl-images-amazon.com', 'images-eu.ssl-images-amazon.com'), true ) ) {
                continue;
            }
            $url = esc_url_raw( $candidate );
            if ( $url ) {
                $selected_image_size = $group[$size];
                break;
            }
        }
        if ( !$url ) {
            continue;
        }
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        // Image size suffixes vary between large / medium API images. The
        // underlying Amazon image asset ID provides a stable identity.
        $base = preg_replace( '/\\._[^\\/]*?(?=\\.[a-z0-9]{2,5}$)/i', '', $path );
        $key = md5( ( $base ?: $path ) );
        if ( isset( $seen[$key] ) ) {
            continue;
        }
        $seen[$key] = true;
        $choices[] = array(
            'key'    => $key,
            'url'    => $url,
            'label'  => ( count( $choices ) === 0 ? 'Main Amazon photo' : 'Amazon photo ' . (count( $choices ) + 1) ),
            'width'  => absint( $selected_image_size['width'] ?? 0 ),
            'height' => absint( $selected_image_size['height'] ?? 0 ),
        );
        if ( count( $choices ) >= 25 ) {
            break;
        }
    }
    return $choices;
}

/** Resolve selected photo against current API images (never store stale URLs). */
function azlinkpro_amazon_selected_image(  $product, $key = ''  ) {
    if ( !is_array( $product ) ) {
        return '';
    }
    $choices = ( isset( $product['images'] ) && is_array( $product['images'] ) ? $product['images'] : array() );
    if ( is_string( $key ) && preg_match( '/^[a-f0-9]{32}$/D', $key ) ) {
        foreach ( $choices as $choice ) {
            if ( is_array( $choice ) && isset( $choice['key'], $choice['url'] ) && hash_equals( $key, (string) $choice['key'] ) ) {
                return (string) $choice['url'];
            }
        }
    }
    return ( !empty( $product['image'] ) && is_string( $product['image'] ) ? $product['image'] : '' );
}

/** This suite cannot load beside its separate predecessor plugins (duplicate PHP symbols). */
function azlinkpro_conflicting_plugins() {
    $legacy = array('AmaTablePro/amatablepro.php', 'amatablepro-showcase/amatablepro-showcase.php', 'amatablepro-pros-cons/amatablepro-pros-cons.php');
    $active = (array) get_option( 'active_plugins', array() );
    if ( is_multisite() ) {
        $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
    }
    return array_values( array_intersect( $legacy, $active ) );
}

function azlinkpro_activate() {
    if ( azlinkpro_conflicting_plugins() ) {
        deactivate_plugins( plugin_basename( AZLINKPRO_FILE ) );
        wp_die( esc_html__( 'Deactivate the three separate AzlinkPRO / AmaTablePro plugins before activating the combined AzlinkPRO plugin. Nothing was deleted.', 'azlinkpro' ), esc_html__( 'AzlinkPRO activation conflict', 'azlinkpro' ), array(
            'back_link' => true,
        ) );
    }
    // No product tables, showcases, pros/cons cards, tracking codes or credentials are inserted on activation.
    if ( get_option( 'atp_settings', false ) === false ) {
        add_option(
            'atp_settings',
            array(
                'tag'         => '',
                'client_id'   => '',
                'secret'      => '',
                'marketplace' => 'www.amazon.com',
            ),
            '',
            false
        );
    }
    azlinkpro_setup_flag_redirect();
}

register_activation_hook( AZLINKPRO_FILE, 'azlinkpro_activate' );
function azlinkpro_load_modules() {
    if ( azlinkpro_conflicting_plugins() ) {
        return;
    }
    require_once AZLINKPRO_PATH . 'modules/tables/module-tables.php';
    // Amazon lookups shared by Tables, the image block and Showcase.
    require_once AZLINKPRO_PATH . 'includes/amazon-items.php';
    require_once AZLINKPRO_PATH . 'modules/product-images/module-product-images.php';
    require_once AZLINKPRO_PATH . 'includes/amalinks-importer.php';
    // In the free build, published Showcase and Pros & Cons shortcodes print nothing.
    require_once AZLINKPRO_PATH . 'includes/premium-fallbacks.php';
}

add_action( 'plugins_loaded', 'azlinkpro_load_modules', 12 );
function azlinkpro_conflict_notice() {
    if ( !current_user_can( 'activate_plugins' ) || !azlinkpro_conflicting_plugins() ) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>AzlinkPRO:</strong> The combined plugin is not loading its modules because one or more separate predecessor plugins are still active. Deactivate the old plugins first; their saved content will remain in WordPress.</p></div>';
}

add_action( 'admin_notices', 'azlinkpro_conflict_notice' );
function azlinkpro_register_menu() {
    add_menu_page(
        'AzlinkPRO',
        'AzlinkPRO',
        'edit_posts',
        'azlinkpro',
        'azlinkpro_overview',
        'dashicons-screenoptions',
        31
    );
    add_submenu_page(
        'azlinkpro',
        'Dashboard',
        'Dashboard',
        'edit_posts',
        'azlinkpro',
        'azlinkpro_overview'
    );
    add_submenu_page(
        'azlinkpro',
        'Settings',
        'Settings',
        'manage_options',
        'azlinkpro-settings',
        'azlinkpro_settings'
    );
}

add_action( 'admin_menu', 'azlinkpro_register_menu', 5 );
/** Read-only operational dashboard for the bundled AzlinkPRO modules. */
function azlinkpro_overview() {
    if ( !current_user_can( 'edit_posts' ) ) {
        return;
    }
    azlinkpro_render_dashboard();
}

function azlinkpro_settings() {
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    $options = ( function_exists( 'azlinkpro_tbl_options' ) ? azlinkpro_tbl_options() : array(
        'tag'         => '',
        'client_id'   => '',
        'secret'      => '',
        'marketplace' => 'www.amazon.com',
    ) );
    ?>
    <div class="wrap azlinkpro-admin-wrap"><h1>AzlinkPRO &mdash; Settings</h1>
    <?php 
    // Read-only flags set by this plugin's own redirect after a save; they change nothing.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    ?>
    <?php 
    if ( isset( $_GET['azp_saved'] ) && $_GET['azp_saved'] === '1' ) {
        ?><div class="notice notice-success is-dismissible"><p>Settings saved locally.</p></div><?php 
    }
    ?>
    <?php 
    $azp_form_errors = array(
        'invalid_form'        => 'The settings form was incomplete. No settings were changed. Reload this page and try again.',
        'invalid_tag'         => 'Invalid Amazon tracking ID. No settings were changed.',
        'invalid_marketplace' => 'Choose a supported Amazon marketplace. No settings were changed.',
        'invalid_css_mode'    => 'Choose a valid frontend stylesheet mode. No settings were changed.',
        'invalid_credentials' => 'The Amazon credential fields are invalid or too long. No settings were changed.',
    );
    $azp_form_error = ( isset( $_GET['azp_error'] ) && is_string( $_GET['azp_error'] ) ? sanitize_key( wp_unslash( $_GET['azp_error'] ) ) : '' );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    if ( isset( $azp_form_errors[$azp_form_error] ) ) {
        ?><div class="notice notice-error is-dismissible" role="alert"><p><?php 
        echo esc_html( $azp_form_errors[$azp_form_error] );
        ?></p></div><?php 
    }
    ?>
    <p class="azp-intro">One place to configure every AzlinkPRO module. Your Amazon credentials are stored in this WordPress installation, not in the plugin ZIP. New to AzlinkPRO? <a href="<?php 
    echo esc_url( azlinkpro_setup_url() );
    ?>">Run the setup wizard</a> for a step-by-step walkthrough.</p>
    <div class="azp-settings-card azp-plan-card">
      <h2>Your plan</h2>
      <?php 
    azlinkpro_render_plan_summary();
    ?>
    </div>
    <form method="post" action="<?php 
    echo esc_url( admin_url( 'admin-post.php' ) );
    ?>">
      <input type="hidden" name="action" value="azlinkpro_save_configuration" />
      <input type="hidden" name="azp_settings_form_version" value="1" />
      <?php 
    wp_nonce_field( 'azlinkpro_save_configuration' );
    ?>
      <div class="azp-settings-card"><h2>Amazon Associates &amp; Creators API</h2>
        <p>These optional settings are shared by Tables, Showcase and Pros &amp; Cons. Leave the tracking tag blank for neutral, non-affiliate product URLs until you configure your own account.</p>
        <table class="form-table" role="presentation">
          <tr><th><label for="azp-tag">Associates tracking ID</label></th><td><input type="text" class="regular-text" id="azp-tag" name="azp_tag" value="<?php 
    echo esc_attr( $options['tag'] ?? '' );
    ?>" placeholder="Your own Amazon Partner Tag" autocomplete="off" /><p class="description">No publisher tracking tag ships with this plugin.</p></td></tr>
          <tr><th><label for="azp-marketplace">Marketplace</label></th><td><select name="azp_marketplace" id="azp-marketplace"><option value="www.amazon.com" <?php 
    selected( $options['marketplace'] ?? '', 'www.amazon.com' );
    ?>>Amazon.com (US)</option><option value="www.amazon.ca" <?php 
    selected( $options['marketplace'] ?? '', 'www.amazon.ca' );
    ?>>Amazon.ca (Canada)</option></select></td></tr>
          <tr><th><label for="azp-client">Creators API credential ID</label></th><td><input type="text" class="regular-text" id="azp-client" name="azp_client" value="<?php 
    echo esc_attr( $options['client_id'] ?? '' );
    ?>" autocomplete="off" /></td></tr>
          <tr><th><label for="azp-secret">Creators API secret</label></th><td><input type="password" class="regular-text" id="azp-secret" name="azp_secret" value="" placeholder="<?php 
    echo ( empty( $options['secret'] ) ? esc_attr( 'Enter your API secret' ) : esc_attr( 'Stored securely; leave blank to keep it' ) );
    ?>" autocomplete="new-password" /><p class="description">Leave blank to preserve an existing saved secret. No secret is displayed here.</p></td></tr>
          <tr><th scope="row"><label for="azp-css-loading">Frontend stylesheet loading</label></th><td><select id="azp-css-loading" name="azp_css_loading"><option value="compatibility" <?php 
    selected( azlinkpro_asset_mode(), 'compatibility' );
    ?>>Compatibility (recommended)</option><option value="optimized" <?php 
    selected( azlinkpro_asset_mode(), 'optimized' );
    ?>>Optimized (beta)</option></select><p class="description">Compatibility loads module styles early on every page. Optimized checks ordinary posts/pages for AzlinkPRO shortcodes and skips unused module styles. Dynamic widgets, page builders and unknown contexts retain compatibility loading; a late-shortcode fallback is included. Test with your theme and page cache before enabling site-wide.</p></td></tr>
          <tr><th scope="row"><label for="azp-product-names">Product names</label></th><td><select id="azp-product-names" name="azp_product_names"><option value="short" <?php 
    selected( azlinkpro_product_name_style(), 'short' );
    ?>>Short: Brand | Model (recommended)</option><option value="full" <?php 
    selected( azlinkpro_product_name_style(), 'full' );
    ?>>Full Amazon title</option></select><p class="description">Short names use Amazon&#8217;s brand and the product&#8217;s name, for example <em>3D MAXpider | All-Weather Floor Mats</em> instead of the full Amazon title. This sets the names given to products you add to Tables, Showcase titles, and the titles of new Tables, Showcases and Pros &amp; Cons cards. You can always edit a name. To rename products already in a table, use <strong>Product names</strong> in the table editor.</p></td></tr>
          <tr><th scope="row">Product detail formatting</th><td><label><input type="checkbox" name="azp_bold_colon_labels" value="1" <?php 
    checked( get_option( 'azlinkpro_bold_feature_labels', '1' ), '1' );
    ?> /> Automatically bold the feature label before the first colon (:) in product details.</label><p class="description">Example: <strong>High Image Quality:</strong> Canon EOS R50 features a 24.2 MP sensor. This applies to Tables, Showcase and eligible Pros &amp; Cons detail text. Only output formatting changes; saved text is not modified.</p></td></tr>
        </table>
        <p class="description">Creators API access requires an eligible account, valid credentials and compliance with Amazon's current terms. AzlinkPRO is not Amazon-certified. Images are retrieved from permitted API responses or images you add to WordPress.</p>
        <p><?php 
    submit_button(
        'Save Plugin Settings',
        'primary',
        'submit',
        false
    );
    ?></p>
      </div>
    </form>
    <div class="azp-settings-card">
      <h2>Test Amazon API connection</h2>
      <p>After saving your tag and credentials above, enter a product ASIN for your chosen marketplace. This button attempts a live authenticated <strong>GetItems</strong> request and checks whether Amazon returns the selected product and an image. Credentials are never shown in the result.</p>
      <div class="azp-license-entry"><label for="azp-test-asin"><strong>ASIN to test</strong></label><input id="azp-test-asin" type="text" class="regular-text" maxlength="10" value="B00CH9QWOU" placeholder="10-character product ASIN" autocomplete="off" spellcheck="false" />
        <button type="button" class="button button-secondary" id="azp-test-amazon">Test Amazon Connection</button></div>
      <p id="azp-test-amazon-result" class="azp-test-result" role="status" aria-live="polite">No test performed.</p>
      <p class="description">Example ASIN B00CH9QWOU is prefilled for convenience and can be replaced with any valid 10-character ASIN. Availability depends on your selected marketplace. This requires an active internet connection and valid Amazon Creators API access. It is a real connection test, not a simulated pass.</p>
    </div>
    </div><?php 
}

/**
 * Validate the entire settings payload before changing any option or transient.
 * A damaged/partial POST must never clear affiliate credentials or CSS settings.
 * Do not sanitize_text_field() API secrets: it can alter valid opaque bytes.
 */
function azlinkpro_validated_configuration(  $input, $existing  ) {
    if ( !is_array( $input ) || !is_array( $existing ) || !isset( $input['azp_settings_form_version'] ) || $input['azp_settings_form_version'] !== '1' ) {
        return new WP_Error('invalid_form');
    }
    $required = array(
        'azp_tag',
        'azp_marketplace',
        'azp_client',
        'azp_secret',
        'azp_css_loading'
    );
    foreach ( $required as $key ) {
        if ( !array_key_exists( $key, $input ) || !is_string( $input[$key] ) ) {
            return new WP_Error('invalid_form');
        }
    }
    if ( isset( $input['azp_bold_colon_labels'] ) && (!is_string( $input['azp_bold_colon_labels'] ) || $input['azp_bold_colon_labels'] !== '1') ) {
        return new WP_Error('invalid_form');
    }
    // Do not pass raw credential values to error strings, URLs, logs or responses.
    foreach ( array(
        'azp_tag'    => 70,
        'azp_client' => 512,
        'azp_secret' => 2048,
    ) as $key => $limit ) {
        if ( strlen( $input[$key] ) > $limit ) {
            return new WP_Error(( $key === 'azp_tag' ? 'invalid_tag' : 'invalid_credentials' ));
        }
    }
    $tag = trim( sanitize_text_field( wp_unslash( $input['azp_tag'] ) ) );
    if ( $tag !== '' && !preg_match( '/^[A-Za-z0-9_-]{4,70}$/D', $tag ) ) {
        return new WP_Error('invalid_tag');
    }
    $market = wp_unslash( $input['azp_marketplace'] );
    if ( !in_array( $market, array('www.amazon.com', 'www.amazon.ca'), true ) ) {
        return new WP_Error('invalid_marketplace');
    }
    $css_mode = wp_unslash( $input['azp_css_loading'] );
    if ( !in_array( $css_mode, array('compatibility', 'optimized'), true ) ) {
        return new WP_Error('invalid_css_mode');
    }
    $client = trim( sanitize_text_field( wp_unslash( $input['azp_client'] ) ) );
    $secret_input = trim( wp_unslash( $input['azp_secret'] ) );
    if ( preg_match( '/[\\x00-\\x1F\\x7F]/', $client ) || preg_match( '/[\\x00-\\x1F\\x7F]/', $secret_input ) ) {
        return new WP_Error('invalid_credentials');
    }
    $secret = ( $secret_input !== '' ? $secret_input : (string) ($existing['secret'] ?? '') );
    $old_client = (string) ($existing['client_id'] ?? '');
    $old_secret = (string) ($existing['secret'] ?? '');
    $old_market = (string) ($existing['marketplace'] ?? 'www.amazon.com');
    return array(
        'amazon'        => array(
            'tag'         => $tag,
            'client_id'   => $client,
            'secret'      => $secret,
            'marketplace' => $market,
        ),
        'css_mode'      => $css_mode,
        'bold_labels'   => ( !empty( $input['azp_bold_colon_labels'] ) ? '1' : '0' ),
        'product_names' => ( isset( $input['azp_product_names'] ) && $input['azp_product_names'] === 'full' ? 'full' : (( isset( $input['azp_product_names'] ) ? 'short' : azlinkpro_product_name_style() )) ),
        'auth_changed'  => $old_client !== $client || $old_secret !== $secret || $old_market !== $market,
    );
}

function azlinkpro_save_configuration() {
    if ( !current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not authorized.', 'azlinkpro' ) );
    }
    check_admin_referer( 'azlinkpro_save_configuration' );
    $existing = (array) get_option( 'atp_settings', array() );
    $input = azlinkpro_validated_configuration( $_POST, $existing );
    if ( is_wp_error( $input ) ) {
        $code = $input->get_error_code();
        $allowed = array(
            'invalid_form',
            'invalid_tag',
            'invalid_marketplace',
            'invalid_css_mode',
            'invalid_credentials'
        );
        if ( !in_array( $code, $allowed, true ) ) {
            $code = 'invalid_form';
        }
        wp_safe_redirect( add_query_arg( 'azp_error', $code, admin_url( 'admin.php?page=azlinkpro-settings' ) ) );
        exit;
    }
    azlinkpro_apply_configuration( $input, $existing );
    wp_safe_redirect( add_query_arg( 'azp_saved', '1', admin_url( 'admin.php?page=azlinkpro-settings' ) ) );
    exit;
}

add_action( 'admin_post_azlinkpro_save_configuration', 'azlinkpro_save_configuration' );
/** Save a payload from azlinkpro_validated_configuration(). Shared with the setup wizard. */
function azlinkpro_apply_configuration(  $input, $existing  ) {
    update_option( 'atp_settings', $input['amazon'], false );
    update_option( 'azlinkpro_bold_feature_labels', $input['bold_labels'], false );
    update_option( 'azlinkpro_css_loading', $input['css_mode'], false );
    if ( isset( $input['product_names'] ) ) {
        update_option( 'azlinkpro_product_names', $input['product_names'], false );
    }
    // Styling and presentation-only saves must not trigger another Amazon login.
    if ( $input['auth_changed'] ) {
        $new = $input['amazon'];
        // Legacy token entry (prior versions) and credential-scoped keys.
        foreach ( array((string) ($existing['client_id'] ?? ''), (string) $new['client_id']) as $id ) {
            if ( $id !== '' ) {
                delete_transient( 'atp_tok_' . md5( $id ) );
            }
            foreach ( array('www.amazon.com', 'www.amazon.ca') as $region ) {
                if ( $id !== '' ) {
                    delete_transient( 'atps_token_' . md5( $id . ':' . $region ) );
                }
            }
        }
        if ( function_exists( 'azlinkpro_tbl_token_cache_key' ) ) {
            delete_transient( azlinkpro_tbl_token_cache_key( $existing ) );
            delete_transient( azlinkpro_tbl_token_cache_key( $new ) );
        }
        // A test of the old keys says nothing about the new ones.
        delete_option( 'azlinkpro_amazon_test' );
    }
}

/** Test the real Amazon Creators API without exposing user credentials. */
function azlinkpro_ajax_test_amazon() {
    check_ajax_referer( 'azlinkpro_amazon_test', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => 'Administrator permissions required.',
        ), 403 );
    }
    $asin = ( isset( $_POST['asin'] ) && is_string( $_POST['asin'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['asin'] ) ) ) ) : '' );
    if ( !preg_match( '/^[A-Z0-9]{10}$/D', $asin ) ) {
        wp_send_json_error( array(
            'message' => 'Enter a valid 10-character ASIN before testing.',
        ), 400 );
    }
    if ( !function_exists( 'azlinkpro_tbl_api_call' ) ) {
        wp_send_json_error( array(
            'message' => 'Modules did not load; check for conflicting separate plugins.',
        ), 400 );
    }
    $result = azlinkpro_tbl_api_call( 'getItems', array(
        'itemIds'    => array($asin),
        'itemIdType' => 'ASIN',
        'resources'  => array(
            'images.primary.large',
            'images.primary.medium',
            'images.primary.small',
            'itemInfo.title'
        ),
    ), azlinkpro_tbl_options() );
    if ( is_wp_error( $result ) ) {
        azlinkpro_record_amazon_test( 'failed', $asin );
        wp_send_json_error( array(
            'message' => $result->get_error_message(),
        ), 400 );
    }
    $products = array();
    foreach ( azlinkpro_tbl_extract_items( $result, 'getItems' ) as $item ) {
        $product = azlinkpro_tbl_parse_item( $item );
        if ( $product && $product['asin'] === $asin ) {
            $products[] = $product;
        }
    }
    if ( !$products ) {
        azlinkpro_record_amazon_test( 'no_product', $asin );
        wp_send_json_error( array(
            'message' => 'Amazon authenticated, but returned no matching product for that ASIN/marketplace. Try another valid ASIN.',
        ), 200 );
    }
    azlinkpro_record_amazon_test( 'passed', $asin );
    $p = $products[0];
    wp_send_json_success( array(
        'message' => 'Connection successful. Amazon returned the requested product' . (( !empty( $p['image'] ) ? ' and a product image.' : ', but no usable image for this ASIN.' )),
        'asin'    => $asin,
        'title'   => $p['name'],
        'image'   => $p['image'],
    ) );
}

add_action( 'wp_ajax_azlinkpro_test_amazon', 'azlinkpro_ajax_test_amazon' );
/** Remember the last live test (from Settings or the setup wizard) for the Dashboard page. */
function azlinkpro_record_amazon_test(  $result, $asin  ) {
    update_option( 'azlinkpro_amazon_test', array(
        'result' => $result,
        'asin'   => $asin,
        'time'   => time(),
    ), false );
}

/** Dashboard label for the last live test, e.g. "Passed 5 minutes ago". */
function azlinkpro_amazon_test_summary() {
    $test = get_option( 'azlinkpro_amazon_test', array() );
    $labels = array(
        'passed'     => 'Passed',
        'no_product' => 'Connected, but the test product was not found',
        'failed'     => 'Failed',
    );
    if ( !is_array( $test ) || !isset( $labels[$test['result'] ?? ''] ) || empty( $test['time'] ) ) {
        return array(
            'label'  => 'Not tested yet',
            'result' => '',
        );
    }
    return array(
        'label'  => $labels[$test['result']] . ' ' . sprintf( '%s ago', human_time_diff( (int) $test['time'] ) ),
        'result' => $test['result'],
        'when'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $test['time'] ),
    );
}

function azlinkpro_admin_style(  $hook  ) {
    if ( strpos( (string) $hook, 'azlinkpro' ) === false ) {
        return;
    }
    if ( $hook === 'toplevel_page_azlinkpro' ) {
        wp_enqueue_style(
            'azlinkpro-dashboard',
            plugin_dir_url( AZLINKPRO_FILE ) . 'assets/dashboard.css',
            array(),
            AZLINKPRO_VERSION
        );
        wp_enqueue_script(
            'azlinkpro-dashboard',
            plugin_dir_url( AZLINKPRO_FILE ) . 'assets/dashboard.js',
            array(),
            AZLINKPRO_VERSION,
            true
        );
    }
    wp_register_style(
        'azlinkpro-admin-ui',
        false,
        array(),
        AZLINKPRO_VERSION
    );
    wp_enqueue_style( 'azlinkpro-admin-ui' );
    wp_add_inline_style( 'azlinkpro-admin-ui', '.azlinkpro-admin-wrap{max-width:1190px}.azlinkpro-admin-wrap h1{font-weight:760;margin:24px 0}.azp-version{font-size:12px;background:#e5f2ff;color:#1b65b1;padding:5px 9px;border-radius:15px;vertical-align:middle}.azp-intro{color:#586e82;font-size:15px;max-width:800px;margin-bottom:25px}.azp-admin-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin:24px 0}.azp-admin-card,.azp-settings-card{background:#fff;border:1px solid #dbe6f2;border-radius:13px;box-shadow:0 4px 20px #1736550b;padding:24px}.azp-admin-card h2{font-size:22px;color:#1c4064;margin:0 0 10px}.azp-admin-card p{min-height:40px;color:#536981}.azp-admin-card .button{margin:5px 5px 0 0}.azp-settings-card{margin:22px 0 24px}.azp-settings-card h2{margin:0 0 10px;font-size:22px;color:#1c4064}.azp-settings-card>p{color:#546879}@media(max-width:1000px){.azp-admin-grid{grid-template-columns:1fr}}' );
    wp_add_inline_style( 'azlinkpro-admin-ui', '.azp-license-entry{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin:10px 0}.azp-license-entry input{width:min(380px,100%)}.azp-license-state{padding:7px 12px;border-radius:20px;display:inline-block;font-weight:700}.azp-license-state.is-active{background:#e7f7ee;color:#136643}.azp-license-state.is-inactive{background:#f1f3f7;color:#596476}.azp-license-test-key{padding:12px 0}.azp-test-result{padding:13px 16px;border-radius:7px;background:#f4f7fb;color:#3e5369}.azp-test-result.is-success{background:#e7f7ee;color:#136643}.azp-test-result.is-error{background:#fceced;color:#9b2929}.azp-test-product{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:9px 0}.azp-test-product img{width:66px;height:66px;object-fit:contain;background:#fff}@media(max-width:580px){.azp-license-entry{align-items:stretch;flex-direction:column}.azp-license-entry input{width:100%}}' );
    if ( $hook === 'azlinkpro_page_azlinkpro-settings' ) {
        wp_enqueue_script(
            'azlinkpro-settings-test',
            plugin_dir_url( AZLINKPRO_FILE ) . 'assets/settings.js',
            array('jquery'),
            AZLINKPRO_VERSION,
            true
        );
        wp_localize_script( 'azlinkpro-settings-test', 'azlinkproTest', array(
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'azlinkpro_amazon_test' ),
        ) );
    }
}

add_action( 'admin_enqueue_scripts', 'azlinkpro_admin_style' );