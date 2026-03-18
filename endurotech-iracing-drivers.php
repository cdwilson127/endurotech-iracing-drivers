<?php
/**
 * Plugin Name: Endurotech iRacing Drivers
 * Description: Displays iRacing driver stats for the Endurotech Racing team via the [iracing_drivers] shortcode.
 * Version: 1.4.0
 * Author: Endurotech Racing
 * License: GPL v2 or later
 * Text Domain: endurotech-iracing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EDR_IRACING_VERSION', '1.4.0' );
define( 'EDR_IRACING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDR_IRACING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EDR_IRACING_PLUGIN_DIR . 'includes/class-iracing-api.php';
require_once EDR_IRACING_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once EDR_IRACING_PLUGIN_DIR . 'includes/class-driver-display.php';

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
            'cache_hours'   => 1,
        ) );
    }
}

function edr_iracing_deactivate() {
    delete_transient( 'edr_iracing_drivers_cache' );
    delete_transient( 'edr_iracing_token' );
}

if ( is_admin() ) {
    new EDR_Admin_Settings();
}

new EDR_Driver_Display();
