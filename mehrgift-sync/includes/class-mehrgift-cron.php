<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Cron {

	const HOOK = 'mehrgift_background_tick';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
	}

	public static function log_path() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'mehrgift-sync.log';
	}

	public static function log( $message ) {
		$line = '[' . current_time( 'mysql' ) . '] ' . $message . PHP_EOL;
		file_put_contents( self::log_path(), $line, FILE_APPEND | LOCK_EX );
	}

	public static function tail_log( $lines = 80 ) {
		$path = self::log_path();
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$content = file( $path, FILE_IGNORE_NEW_LINES );
		if ( ! is_array( $content ) ) {
			return '';
		}
		return implode( "\n", array_slice( $content, -1 * max( 1, $lines ) ) );
	}

	public static function start( $mode, $with_images, $offset = 0 ) {
		return Mehrgift_Pipeline::start( array(
			'trigger' => 'manual',
		) );
	}

	public static function start_pipeline( array $options = array() ) {
		return Mehrgift_Pipeline::start( $options );
	}

	public static function stop( $log = true ) {
		wp_clear_scheduled_hook( self::HOOK );
		Mehrgift_Job::force_stop();
		if ( $log ) {
			self::log( 'توقف توسط کاربر.' );
		}
	}

	public static function schedule_next( $delay = 5 ) {
		wp_clear_scheduled_hook( self::HOOK );
		$min = in_array( Mehrgift_Settings::get( 'cpu_throttle' ), array( 'vps3', 'high' ), true ) ? 1 : 2;
		wp_schedule_single_event( time() + max( $min, (int) $delay ), self::HOOK );
		self::spawn_runner();
	}

	public static function spawn_runner() {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
			return;
		}

		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	public static function tick() {
		return Mehrgift_Pipeline::tick();
	}
}
