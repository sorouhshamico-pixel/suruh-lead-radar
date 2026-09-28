<?php
/**
 * Plugin Name:       رادار العملاء (Lead Radar)
 * Description:       تتبع ضغطات واتساب والاتصال وحفظ طلبات الفورم، مع تحديد الصفحة ومكان الزر ومصدر الزيارة وكشف العميل المكرر. يعمل مع Contact Form 7 و WPForms و Elementor و Gravity و Fluent و Ninja Forms.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            محمد خليفة
 * Author URI:        https://mohamedkhalifa.netlify.app
 * Text Domain:       suruh-lead-radar
 */

defined( 'ABSPATH' ) || exit;

define( 'SLR_VERSION', '1.1.0' );
define( 'SLR_DB_VERSION', '1' );
define( 'SLR_FILE', __FILE__ );
define( 'SLR_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLR_URL', plugin_dir_url( __FILE__ ) );

require_once SLR_DIR . 'includes/class-slr-db.php';
require_once SLR_DIR . 'includes/class-slr-helpers.php';
require_once SLR_DIR . 'includes/class-slr-notify.php';
require_once SLR_DIR . 'includes/class-slr-rest.php';
require_once SLR_DIR . 'includes/class-slr-frontend.php';
require_once SLR_DIR . 'includes/class-slr-integrations.php';

if ( is_admin() ) {
	require_once SLR_DIR . 'includes/class-slr-health.php';
	require_once SLR_DIR . 'includes/class-slr-admin.php';
	SLR_Admin::init();
}

register_activation_hook( __FILE__, array( 'SLR_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'SLR_DB', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SLR_DB', 'maybe_upgrade' ) );
add_action( 'plugins_loaded', array( 'SLR_Integrations', 'init' ) );
add_action( 'rest_api_init', array( 'SLR_Rest', 'register_routes' ) );
add_action( 'slr_daily_cleanup', array( 'SLR_DB', 'cleanup' ) );

SLR_Rest::register_ajax();
SLR_Frontend::init();
