<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Activator {

	const DB_VERSION = 6;

	public static function activate() {
		require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-settings.php';
		require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-sync-registry.php';
		require_once MEHRGIFT_SYNC_DIR . 'includes/class-mehrgift-attributes.php';

		self::create_table();
		self::cleanup_legacy_jobs();
		update_option( 'mehrgift_db_version', self::DB_VERSION );
		Mehrgift_Settings::maybe_set_defaults();

		if ( class_exists( 'WooCommerce' ) ) {
			Mehrgift_Attributes::ensure_global_attributes();
		}
	}

	public static function maybe_upgrade() {
		$current = (int) get_option( 'mehrgift_db_version', 0 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}
		self::create_table();
		if ( $current > 0 && $current < self::DB_VERSION ) {
			self::cleanup_legacy_jobs();
		}
		update_option( 'mehrgift_db_version', self::DB_VERSION );
	}

	public static function cleanup_legacy_jobs() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'mehrgift_background_tick' );
			wp_clear_scheduled_hook( 'mehrgift_daily_sync' );
		}
		delete_transient( 'mehrgift_batch_lock' );
		delete_option( 'mehrgift_sync_state' );
	}

	private static function create_table() {
		global $wpdb;
		$table   = Mehrgift_Sync_Registry::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			remote_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned DEFAULT NULL,
			barcode varchar(64) DEFAULT '',
			title varchar(500) DEFAULT '',
			last_fetch datetime DEFAULT NULL,
			last_sync datetime DEFAULT NULL,
			data_hash varchar(64) DEFAULT '',
			specs_hash varchar(64) DEFAULT '',
			price_hash varchar(64) DEFAULT '',
			details_hash varchar(64) DEFAULT '',
			images_hash varchar(64) DEFAULT '',
			price_status varchar(16) DEFAULT 'pending',
			details_status varchar(16) DEFAULT 'pending',
			images_status varchar(16) DEFAULT 'pending',
			last_price_sync datetime DEFAULT NULL,
			last_details_sync datetime DEFAULT NULL,
			last_images_sync datetime DEFAULT NULL,
			sync_status varchar(32) DEFAULT 'pending',
			last_mode varchar(32) DEFAULT '',
			error_message text,
			PRIMARY KEY (id),
			UNIQUE KEY remote_id (remote_id),
			KEY product_id (product_id),
			KEY sync_status (sync_status),
			KEY price_status (price_status),
			KEY details_status (details_status),
			KEY images_status (images_status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
