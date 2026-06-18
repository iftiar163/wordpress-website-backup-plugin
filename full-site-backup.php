<?php
/**
 * Plugin Name:       Full Site Backup Scheduler
 * Plugin URI:        https://yourwebsite.com/my-awesome-plugin
 * Description:       A simple plugin to [what it does].
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Iftiar Hossain
 * Author URI:        https://www.iftiarhossain.com
 * License:           GPL-2.0-or-later
 * Text Domain:       full-site-backup
 * Domain Path:       /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FSB_VERSION', '1.0.0' );
define( 'FSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load the main class
require_once FSB_PLUGIN_DIR . 'includes/class-full-site-backup.php';

// Initialize the plugin
function run_full_site_backup() {
   Full_Site_Backup::get_instance()->run();
}
add_action( 'plugins_loaded', 'run_full_site_backup' );