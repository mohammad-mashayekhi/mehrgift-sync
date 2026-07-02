<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Job {

	const LOCK_KEY  = 'mehrgift_batch_lock';
	const STATE_KEY = 'mehrgift_sync_state';
	const LOCK_TTL  = 600;

	/** @var string|null */
	private static $lock_token = null;

	public static function acquire_lock() {
		$lock = get_transient( self::LOCK_KEY );
		if ( is_array( $lock ) && ! empty( $lock['time'] ) ) {
			$age = time() - (int) $lock['time'];
			// If a previous PHP process died without releasing, steal after 3 minutes.
			if ( $age < 180 ) {
				return new WP_Error(
					'mehrgift_busy',
					sprintf( 'یک پردازش دیگر در حال اجراست (%d ثانیه پیش شروع شده). چند دقیقه صبر کنید یا «توقف» بزنید.', $age ),
					array( 'code' => 'busy', 'age' => $age )
				);
			}
		}

		self::$lock_token = wp_generate_password( 20, false );
		set_transient( self::LOCK_KEY, array(
			'time'  => time(),
			'token' => self::$lock_token,
		), self::LOCK_TTL );

		register_shutdown_function( array( __CLASS__, 'shutdown_release' ) );

		return true;
	}

	public static function release_lock() {
		$lock = get_transient( self::LOCK_KEY );
		if ( is_array( $lock ) && self::$lock_token && ( $lock['token'] ?? '' ) !== self::$lock_token ) {
			return;
		}
		delete_transient( self::LOCK_KEY );
		self::$lock_token = null;
	}

	public static function shutdown_release() {
		self::release_lock();
	}

	public static function force_stop() {
		delete_transient( self::LOCK_KEY );
		self::$lock_token = null;
		self::save_state( array(
			'running'    => false,
			'background' => false,
			'stopped'    => true,
			'stopped_at' => current_time( 'mysql' ),
		) );
	}

	public static function save_state( array $state ) {
		$current = self::get_state();
		update_option( self::STATE_KEY, array_merge( $current, $state ), false );
	}

	public static function reset_for_start( array $state ) {
		update_option( self::STATE_KEY, $state, false );
	}

	public static function get_state() {
		$state = get_option( self::STATE_KEY, array() );
		return is_array( $state ) ? $state : array();
	}

	public static function clear_state() {
		delete_option( self::STATE_KEY );
	}

	public static function is_stopped() {
		$state = self::get_state();
		return ! empty( $state['stopped'] );
	}
}
