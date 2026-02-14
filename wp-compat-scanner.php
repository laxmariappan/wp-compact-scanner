<?php
/**
 * WP Compat Scanner - WordPress Plugin Compatibility Checker
 *
 * @package     wp-compat-scanner
 * @author      Your Name
 * @copyright   2025
 * @license     MIT
 *
 * @wordpress-plugin
 * Plugin Name:       WP Compat Scanner
 * Plugin URI:        https://github.com/your-username/wp-compat-scanner
 * Description:       Scan WordPress plugins for compatibility issues by checking used functions, classes, methods, and hooks against their @since versions.
 * Requires at least: 5.0
 * Version:           1.1.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://github.com/your-username/wp-compat-scanner
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-compat-scanner
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WP_COMPAT_SCANNER_VERSION', '1.1.0' );
define( 'WP_COMPAT_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_COMPAT_SCANNER_URL', plugin_dir_url( __FILE__ ) );

// Autoload Composer dependencies.
if ( file_exists( WP_COMPAT_SCANNER_DIR . 'vendor/autoload.php' ) ) {
	require_once WP_COMPAT_SCANNER_DIR . 'vendor/autoload.php';
}

// Load WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_COMPAT_SCANNER_DIR . 'includes/class-wp-compat-scanner-cli.php';
	WP_CLI::add_command( 'compat-scanner', 'WP_Compat_Scanner_CLI' );
}

