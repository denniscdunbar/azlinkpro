<?php

/**
 * Plugin Name: AzlinkPRO
 * Plugin URI: https://azlinkpro.com/
 * Description: Amazon affiliate comparison tables and clickable product images, with Showcases, Pros & Cons cards and AI review tools on paid plans.
 * Version: 1.5.12
 * Author: AzlinkPRO
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: azlinkpro
 *
 * Freemius leaves these out of the free build it generates from this code.
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( function_exists( 'azlinkpro_fs' ) ) {
    // The other build (free or paid) is already loaded. Freemius deactivates the
    // free one, so both can never run together.
    azlinkpro_fs()->set_basename( false, __FILE__ );
} else {
    if ( !function_exists( 'azlinkpro_plugin_icon' ) ) {
        /** Absolute path of the AZ icon that Freemius shows next to AzlinkPRO updates. */
        function azlinkpro_plugin_icon() {
            return dirname( __FILE__ ) . '/assets/azlinkpro-icon.png';
        }

    }
    if ( !function_exists( 'azlinkpro_fs' ) ) {
        /** The Freemius instance: licenses, checkout, opt-in and automatic updates. */
        function azlinkpro_fs() {
            global $azlinkpro_fs;
            if ( !isset( $azlinkpro_fs ) ) {
                require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
                $azlinkpro_fs = fs_dynamic_init( array(
                    'id'               => '43807',
                    'slug'             => 'azlinkpro',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_689a68b5b465505196fc53131edf3',
                    'is_premium'       => false,
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'menu'             => array(
                        'slug'       => 'azlinkpro',
                        'first-path' => 'admin.php?page=azlinkpro',
                        'support'    => false,
                    ),
                    'is_live'          => true,
                ) );
            }
            return $azlinkpro_fs;
        }

        azlinkpro_fs();
        // 1.5.10: hand Freemius our icon file directly for the WordPress update lists. Its own lookup can search
        // another plugin's Freemius folder (when that plugin loads a newer SDK) and then falls back to a grey plug.
        azlinkpro_fs()->add_filter( 'plugin_icon', 'azlinkpro_plugin_icon' );
        do_action( 'azlinkpro_fs_loaded' );
    }
    if ( !defined( 'AZLINKPRO_VERSION' ) ) {
        define( 'AZLINKPRO_VERSION', '1.5.12' );
    }
    if ( !defined( 'AZLINKPRO_FILE' ) ) {
        define( 'AZLINKPRO_FILE', __FILE__ );
    }
    if ( !defined( 'AZLINKPRO_PATH' ) ) {
        define( 'AZLINKPRO_PATH', plugin_dir_path( __FILE__ ) );
    }
    require_once AZLINKPRO_PATH . 'includes/core.php';
}