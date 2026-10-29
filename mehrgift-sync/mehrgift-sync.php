<?php
/**
 * Plugin Name: همگام‌ساز مهرگیفت
 * Description: همگام‌سازی مهرگیفت — AJAX مستقیم مثل نسخه ۴، با ردیابی قیمت/جزئیات/عکس و تطبیق کد کالا.
 * Version: 5.2.2
 * Author: تیم رادینو / Raadino
 * Author URI: https://raadino.com/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEHRGIFT_SYNC_VERSION', '5.2.2' );
define( 'MEHRGIFT_SYNC_FILE', __FILE__ );
define( 'MEHRGIFT_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEHRGIFT_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-activator.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-settings.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-categories.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-api-client.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-scraper.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-image-processor.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-attributes.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-sync-registry.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-job.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-cron.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-pipeline.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-scheduler.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-product-sync.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-admin.php';
require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-frontend.php';

register_activation_hook( __FILE__, array( 'Mehrgift_Activator', 'activate' ) );

add_action( 'plugins_loaded', 'mehrgift_sync_init', 20 );

function mehrgift_sync_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>افزونه همگام‌ساز مهرگیفت به ووکامرس نیاز دارد.</p></div>';
		} );
		return;
	}

	Mehrgift_Activator::maybe_upgrade();
	Mehrgift_Attributes::init();
	Mehrgift_Cron::init();
	Mehrgift_Scheduler::init();
	Mehrgift_Admin::init();
	Mehrgift_Frontend::init();
}
