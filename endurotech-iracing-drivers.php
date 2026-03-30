<?php
/**
 * Plugin Name: Endurotech iRacing Drivers
 * Description: Displays iRacing driver stats for the Endurotech Racing team via the [iracing_drivers] shortcode.
 * Version: 2.0.3
 * Author: Endurotech Racing
 * License: GPL v2 or later
 * Text Domain: endurotech-iracing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EDR_IRACING_VERSION', '2.0.3' );
define( 'EDR_IRACING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDR_IRACING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EDR_IRACING_PLUGIN_DIR . 'includes/class-iracing-api.php';
require_once EDR_IRACING_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once EDR_IRACING_PLUGIN_DIR . 'includes/class-driver-display.php';
require_once EDR_IRACING_PLUGIN_DIR . 'includes/class-update-checker.php';

register_activation_hook( __FILE__, 'edr_iracing_activate' );
register_deactivation_hook( __FILE__, 'edr_iracing_deactivate' );

function edr_iracing_activate() {
    if ( false === get_option( 'edr_iracing_settings' ) ) {
        add_option( 'edr_iracing_settings', array(
            'client_id'     => '',
            'client_secret' => '',
            'username'      => '',
            'password'      => '',
            'team_id'       => '',
            'cache_hours'   => 24,
        ) );
    }
    edr_maybe_upgrade();
    edr_schedule_sync();
}

function edr_iracing_deactivate() {
    wp_clear_scheduled_hook( 'edr_cron_sync_drivers' );
    delete_transient( 'edr_iracing_drivers_cache' );
    delete_transient( 'edr_iracing_token' );
    delete_transient( 'edr_iracing_refresh_token' );
}

/**
 * Register the custom cron interval on every load so WordPress recognises it.
 * Must be registered before wp_schedule_event is called.
 */
function edr_cron_schedules( $schedules ) {
    $settings    = get_option( 'edr_iracing_settings', array() );
    $cache_hours = max( 1, intval( isset( $settings['cache_hours'] ) ? $settings['cache_hours'] : 24 ) );
    $schedules['edr_sync_interval'] = array(
        'interval' => $cache_hours * HOUR_IN_SECONDS,
        'display'  => sprintf( 'Every %d hour(s) — EDR iRacing sync', $cache_hours ),
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'edr_cron_schedules' );

/**
 * Schedule the background sync cron event based on the cache_hours setting.
 */
function edr_schedule_sync() {
    wp_clear_scheduled_hook( 'edr_cron_sync_drivers' );
    wp_schedule_event( time(), 'edr_sync_interval', 'edr_cron_sync_drivers' );
}

/**
 * Cron callback — runs the driver sync in the background.
 */
function edr_cron_sync_callback() {
    $api = new EDR_IRacing_API();
    if ( $api->is_configured() ) {
        delete_transient( 'edr_iracing_drivers_cache' );
        $api->get_all_driver_data();
    }
}
add_action( 'edr_cron_sync_drivers', 'edr_cron_sync_callback' );

// If cron was never scheduled, schedule it on init.
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'edr_cron_sync_drivers' ) ) {
        edr_schedule_sync();
    }
} );

/**
 * Runs on every WordPress load via plugins_loaded.
 * Safely merges any new option keys introduced by an update into existing
 * settings — never overwrites values the admin has already configured.
 * Also migrates driver profiles to add new fields without losing existing data.
 */
function edr_maybe_upgrade() {
    $stored = get_option( 'edr_iracing_db_version', '0' );
    if ( version_compare( $stored, EDR_IRACING_VERSION, '>=' ) ) {
        return;
    }

    // Default values for edr_style_settings — only written if the key is missing.
    $style_defaults = array(
        'accent_color'           => '#f0f000',
        'card_bg'                => '#111111',
        'border_radius'          => '0',
        'subtitle_text'          => '',
        'ticker_speed'           => '60',
        'feature_card_flip'      => '1',
        'feature_counters'       => '1',
        'feature_show_trend'     => '1',
        'feature_show_active'    => '1',
        'feature_show_spotlight' => '1',
        'feature_show_ticker'    => '0',
        'feature_show_filter'    => '0',
        'feature_show_summary'   => '1',
        'feature_show_last_race' => '1',
        'feature_show_photo'     => '1',
        'feature_show_gear'      => '1',
    );

    $current_style = get_option( 'edr_style_settings', array() );
    if ( ! is_array( $current_style ) ) {
        $current_style = array();
    }
    // array_merge: existing keys win (not overwritten).
    update_option( 'edr_style_settings', array_merge( $style_defaults, $current_style ) );

    // Ensure every existing driver profile has the new v1.6 keys.
    $profiles = get_option( 'edr_driver_profiles', array() );
    if ( is_array( $profiles ) ) {
        $changed = false;
        foreach ( $profiles as $id => $p ) {
            if ( ! isset( $p['featured'] ) ) {
                $profiles[ $id ]['featured']  = '';
                $changed = true;
            }
            if ( ! isset( $p['flag_code'] ) ) {
                $profiles[ $id ]['flag_code'] = '';
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( 'edr_driver_profiles', $profiles );
        }
    }

    update_option( 'edr_iracing_db_version', EDR_IRACING_VERSION );
}
add_action( 'plugins_loaded', 'edr_maybe_upgrade' );

/** Register a custom image size for driver portrait photos (400 × 500, hard crop). */
add_action( 'after_setup_theme', function () {
    if ( function_exists( 'add_image_size' ) ) {
        add_image_size( 'edr-driver-photo', 400, 500, true );
    }
} );

if ( is_admin() ) {
    new EDR_Admin_Settings();
}

new EDR_Driver_Display();
new EDR_Update_Checker( __FILE__ );
