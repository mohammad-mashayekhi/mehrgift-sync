<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Scheduler {

	const HOOK = 'mehrgift_daily_sync';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_nightly' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function maybe_schedule() {
		if ( ! Mehrgift_Settings::get( 'auto_sync_enabled' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$hour      = (int) Mehrgift_Settings::get( 'daily_sync_hour' );
		$timestamp = strtotime( sprintf( 'today %02d:00', $hour ) );
		if ( $timestamp <= time() ) {
			$timestamp = strtotime( sprintf( 'tomorrow %02d:00', $hour ) );
		}
		wp_schedule_event( $timestamp, 'daily', self::HOOK );
	}

	public static function next_run() {
		$ts = wp_next_scheduled( self::HOOK );
		return $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : null;
	}

	public static function run_nightly() {
		$state = Mehrgift_Job::get_state();
		if ( ! empty( $state['running'] ) ) {
			Mehrgift_Cron::log( 'شبانه رد شد — یک job در حال اجراست.' );
			return;
		}
		Mehrgift_Cron::log( '=== شروع همگام‌سازی شبانه خودکار ===' );
		Mehrgift_Pipeline::start( array( 'trigger' => 'nightly' ) );
	}
}
