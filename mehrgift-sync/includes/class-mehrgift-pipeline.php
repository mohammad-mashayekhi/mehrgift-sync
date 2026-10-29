<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Pipeline {

	const PHASE_FETCH   = 'fetch';
	const PHASE_CREATE  = 'create';
	const PHASE_PRICE   = 'price';
	const PHASE_DETAILS = 'details';
	const PHASE_IMAGES  = 'images';
	const PHASE_CUSTOM  = 'custom';
	const PHASE_DONE    = 'done';

	public static function start( array $options = array() ) {
		wp_clear_scheduled_hook( Mehrgift_Cron::HOOK );
		Mehrgift_Job::release_lock();

		$filter_ids = ! empty( $options['remote_ids'] ) ? array_map( 'intval', (array) $options['remote_ids'] ) : null;
		$trigger    = sanitize_key( $options['trigger'] ?? 'manual' );

		if ( ! empty( $options['actions'] ) && $filter_ids ) {
			return self::start_custom( $filter_ids, (array) $options['actions'], $trigger );
		}

		Mehrgift_Job::reset_for_start( array(
			'running'     => true,
			'background'  => true,
			'phase'       => self::PHASE_FETCH,
			'offset'      => 0,
			'total'       => 0,
			'phase_total' => 0,
			'trigger'     => $trigger,
			'filter_ids'  => $filter_ids,
			'stopped'     => false,
			'started_at'  => current_time( 'mysql' ),
			'stats'       => self::empty_stats(),
		) );

		Mehrgift_Cron::log( 'شروع pipeline — trigger=' . $trigger );
		self::tick();
		return true;
	}

	private static function start_custom( array $remote_ids, array $actions, $trigger ) {
		$queue = array();
		foreach ( $remote_ids as $rid ) {
			foreach ( $actions as $action ) {
				$action = sanitize_key( $action );
				if ( in_array( $action, array( 'price', 'details', 'images', 'full' ), true ) ) {
					$queue[] = array( 'remote_id' => (int) $rid, 'action' => $action );
				}
			}
		}

		if ( empty( $queue ) ) {
			return new WP_Error( 'mehrgift_queue', 'صف خالی است.' );
		}

		Mehrgift_Job::reset_for_start( array(
			'running'       => true,
			'background'    => true,
			'phase'         => self::PHASE_CUSTOM,
			'custom_queue'  => $queue,
			'custom_index'  => 0,
			'total'         => count( $queue ),
			'phase_total'   => count( $queue ),
			'offset'        => 0,
			'trigger'       => $trigger,
			'stopped'       => false,
			'started_at'    => current_time( 'mysql' ),
			'stats'         => self::empty_stats(),
		) );

		Mehrgift_Cron::log( sprintf( 'شروع انتخابی — %d کار', count( $queue ) ) );
		self::tick();
		return true;
	}

	public static function empty_stats() {
		return array(
			'price_ok'    => 0,
			'price_err'   => 0,
			'details_ok'  => 0,
			'details_err' => 0,
			'images_ok'   => 0,
			'images_err'  => 0,
			'create_ok'   => 0,
			'create_err'  => 0,
			'skipped'     => 0,
		);
	}

	public static function tick() {
		$state = Mehrgift_Job::get_state();
		if ( empty( $state['running'] ) || Mehrgift_Job::is_stopped() ) {
			return false;
		}

		$lock = Mehrgift_Job::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			Mehrgift_Cron::schedule_next( 20 );
			return false;
		}

		$start_mem = memory_get_usage( true );
		$start     = microtime( true );
		$phase     = $state['phase'] ?? self::PHASE_FETCH;
		$continue  = false;

		try {
			switch ( $phase ) {
				case self::PHASE_FETCH:
					$continue = self::tick_fetch( $state );
					break;
				case self::PHASE_CREATE:
					$continue = self::tick_create( $state );
					break;
				case self::PHASE_PRICE:
					$continue = self::tick_price( $state );
					break;
				case self::PHASE_DETAILS:
					$continue = self::tick_details( $state );
					break;
				case self::PHASE_IMAGES:
					$continue = self::tick_images( $state );
					break;
				case self::PHASE_CUSTOM:
					$continue = self::tick_custom( $state );
					break;
				default:
					self::finish();
					return true;
			}
		} catch ( Exception $e ) {
			Mehrgift_Cron::log( 'EXCEPTION: ' . $e->getMessage() );
			$continue = true;
		} finally {
			Mehrgift_Job::release_lock();
		}

		$elapsed = round( ( microtime( true ) - $start ) * 1000 );
		$memory  = round( memory_get_usage( true ) / 1048576, 1 );
		Mehrgift_Job::save_state( array(
			'last_tick_ms' => $elapsed,
			'memory_mb'    => $memory,
			'peak_mb'      => round( memory_get_peak_usage( true ) / 1048576, 1 ),
		) );

		if ( $continue ) {
			$mode = self::pause_mode_for_phase( Mehrgift_Job::get_state()['phase'] ?? $phase );
			Mehrgift_Cron::schedule_next( Mehrgift_Settings::get_batch_pause_for_mode( $mode, 'images' === $mode, true ) );
		}

		return true;
	}

	private static function pause_mode_for_phase( $phase ) {
		$map = array(
			self::PHASE_PRICE   => 'price',
			self::PHASE_DETAILS => 'details',
			self::PHASE_IMAGES  => 'images',
			self::PHASE_CREATE  => 'full',
			self::PHASE_CUSTOM  => 'details',
		);
		return $map[ $phase ] ?? 'details';
	}

	private static function get_products( $state ) {
		$products = Mehrgift_Api_Client::load_local_cache();
		if ( is_wp_error( $products ) ) {
			throw new Exception( $products->get_error_message() );
		}
		if ( ! empty( $state['filter_ids'] ) ) {
			$ids = array_flip( array_map( 'intval', (array) $state['filter_ids'] ) );
			$products = array_values( array_filter( $products, function ( $item ) use ( $ids ) {
				return isset( $ids[ (int) $item['id'] ] );
			} ) );
		}
		return $products;
	}

	private static function tick_fetch( $state ) {
		Mehrgift_Cron::log( 'فاز ۱: دانلود لیست از API...' );
		$products = Mehrgift_Api_Client::fetch_all_products();
		if ( is_wp_error( $products ) ) {
			throw new Exception( $products->get_error_message() );
		}
		$indexed = Mehrgift_Categories::rebuild_index();
		if ( is_wp_error( $indexed ) ) {
			throw new Exception( $indexed->get_error_message() );
		}

		$saved = Mehrgift_Api_Client::save_local_cache( $products );
		if ( is_wp_error( $saved ) ) {
			throw new Exception( $saved->get_error_message() );
		}
		$filtered = Mehrgift_Api_Client::load_local_cache();
		if ( is_wp_error( $filtered ) ) {
			throw new Exception( $filtered->get_error_message() );
		}
		foreach ( $filtered as $item ) {
			Mehrgift_Sync_Registry::mark_fetch( (int) $item['id'], $item );
		}
		Mehrgift_Cron::log( sprintf(
			'کش دانلود شد: %d محصول، %d حذف‌شده.',
			count( $filtered ),
			max( 0, count( $products ) - count( $filtered ) )
		) );

		$create_count = 0;
		foreach ( $filtered as $item ) {
			if ( ! Mehrgift_Product_Sync::find_product_id( (int) $item['id'], $item['barcode_primary'] ?? '' ) ) {
				$create_count++;
			}
		}

		Mehrgift_Job::save_state( array(
			'phase'       => self::PHASE_CREATE,
			'offset'      => 0,
			'total'       => count( $filtered ),
			'phase_total' => $create_count,
		) );
		Mehrgift_Cron::log( sprintf( 'فاز ۲: ایجاد محصولات جدید (%d عدد)...', $create_count ) );
		return true;
	}

	private static function tick_deadline() {
		return microtime( true ) + Mehrgift_Settings::tick_time_budget();
	}

	private static function tick_items_limit() {
		return max( 1, (int) Mehrgift_Settings::throttle_profile()['items_per_tick'] );
	}

	private static function tick_create( $state ) {
		$products = self::get_products( $state );
		$offset   = (int) ( $state['offset'] ?? 0 );
		$deadline = self::tick_deadline();
		$done     = 0;
		$limit    = self::tick_items_limit();

		while ( $offset < count( $products ) && $done < $limit && microtime( true ) < $deadline ) {
			$item = $products[ $offset ];
			$offset++;
			if ( Mehrgift_Product_Sync::find_product_id( (int) $item['id'], $item['barcode_primary'] ?? '' ) ) {
				continue;
			}

			self::process_one( $item, 'full', true, 'create', $offset );
			$done++;
		}

		if ( $offset < count( $products ) ) {
			$remaining = 0;
			for ( $i = $offset; $i < count( $products ); $i++ ) {
				if ( ! Mehrgift_Product_Sync::find_product_id( (int) $products[ $i ]['id'], $products[ $i ]['barcode_primary'] ?? '' ) ) {
					$remaining++;
				}
			}
			if ( $remaining > 0 ) {
				Mehrgift_Job::save_state( array( 'offset' => $offset ) );
				return true;
			}
		}

		$existing = count( array_filter( $products, function ( $item ) {
			return (bool) Mehrgift_Product_Sync::find_product_id( (int) $item['id'], $item['barcode_primary'] ?? '' );
		} ) );

		Mehrgift_Job::save_state( array(
			'phase'       => self::PHASE_PRICE,
			'offset'      => 0,
			'phase_total' => $existing,
		) );
		Mehrgift_Cron::log( sprintf( 'فاز ۳: آپدیت قیمت (%d محصول)...', $existing ) );
		return true;
	}

	private static function tick_price( $state ) {
		@set_time_limit( 300 );

		$products = self::get_products( $state );
		$offset   = (int) ( $state['offset'] ?? 0 );
		$limit    = Mehrgift_Settings::get_batch_limit( 'price', false, true );
		$profile  = Mehrgift_Settings::throttle_profile();
		$deadline = ! empty( $profile['price_burst'] )
			? microtime( true ) + 280
			: self::tick_deadline();
		$stats    = $state['stats'] ?? self::empty_stats();
		$i        = $offset;

		if ( 0 === $offset && ! empty( $profile['price_burst'] ) ) {
			Mehrgift_Cron::log( 'فاز قیمت — حالت سریع (یک‌جا)...' );
		}

		while ( microtime( true ) < $deadline && $i < count( $products ) ) {
			$batch = array();
			for ( ; $i < count( $products ) && count( $batch ) < $limit; $i++ ) {
				$item = $products[ $i ];
				if ( Mehrgift_Product_Sync::find_product_id( (int) $item['id'], $item['barcode_primary'] ?? '' ) ) {
					$batch[] = $item;
				}
			}

			if ( empty( $batch ) ) {
				break;
			}

			$result = Mehrgift_Product_Sync::sync_batch_price_fast( $batch );
			$stats['price_ok']  += (int) ( $result['processed'] ?? 0 );
			$stats['price_err'] += (int) ( $result['skipped'] ?? 0 );
		}

		$done = $i >= count( $products );
		Mehrgift_Job::save_state( array(
			'offset' => $done ? 0 : $i,
			'stats'  => $stats,
		) );

		if ( $done ) {
			Mehrgift_Cron::log( sprintf( 'قیمت تمام شد — %d آپدیت.', $stats['price_ok'] ) );
			$details_count = self::count_needing_details( $products );
			Mehrgift_Job::save_state( array(
				'phase'       => self::PHASE_DETAILS,
				'offset'      => 0,
				'phase_total' => $details_count,
			) );
			Mehrgift_Cron::log( sprintf( 'فاز ۴: آپدیت جزئیات (%d نیازمند)...', $details_count ) );
		} else {
			Mehrgift_Cron::log( sprintf( 'قیمت تا offset=%d — ادامه...', $i ) );
		}
		return true;
	}

	private static function tick_details( $state ) {
		$products = self::get_products( $state );
		$offset   = (int) ( $state['offset'] ?? 0 );
		$deadline = self::tick_deadline();
		$done     = 0;
		$limit    = self::tick_items_limit();

		while ( $offset < count( $products ) && $done < $limit && microtime( true ) < $deadline ) {
			$item = $products[ $offset ];
			$offset++;
			if ( ! self::needs_details( $item ) ) {
				$stats = Mehrgift_Job::get_state()['stats'] ?? self::empty_stats();
				$stats['skipped']++;
				Mehrgift_Job::save_state( array( 'stats' => $stats ) );
				continue;
			}
			self::process_one( $item, 'details', false, 'details', $offset );
			$done++;
		}

		if ( $offset < count( $products ) ) {
			for ( $j = $offset; $j < count( $products ); $j++ ) {
				if ( self::needs_details( $products[ $j ] ) ) {
					Mehrgift_Job::save_state( array( 'offset' => $offset ) );
					return true;
				}
			}
		}

		$images_count = self::count_needing_images( $products );
		Mehrgift_Job::save_state( array(
			'phase'       => self::PHASE_IMAGES,
			'offset'      => 0,
			'phase_total' => $images_count,
		) );
		Mehrgift_Cron::log( sprintf( 'فاز ۵: آپدیت عکس (%d نیازمند)...', $images_count ) );
		return true;
	}

	private static function tick_images( $state ) {
		$products = self::get_products( $state );
		$offset   = (int) ( $state['offset'] ?? 0 );
		$deadline = self::tick_deadline();
		$done     = 0;
		$limit    = min( self::tick_items_limit(), (int) Mehrgift_Settings::get_batch_limit( 'images', true, true ) );

		while ( $offset < count( $products ) && $done < $limit && microtime( true ) < $deadline ) {
			$item = $products[ $offset ];
			$offset++;
			if ( ! self::needs_images( $item ) ) {
				$stats = Mehrgift_Job::get_state()['stats'] ?? self::empty_stats();
				$stats['skipped']++;
				Mehrgift_Job::save_state( array( 'stats' => $stats ) );
				continue;
			}
			self::process_one( $item, 'images', true, 'images', $offset );
			$done++;
		}

		if ( $offset < count( $products ) ) {
			for ( $j = $offset; $j < count( $products ); $j++ ) {
				if ( self::needs_images( $products[ $j ] ) ) {
					Mehrgift_Job::save_state( array( 'offset' => $offset ) );
					return true;
				}
			}
		}

		self::finish();
		return false;
	}

	private static function tick_custom( $state ) {
		$queue    = $state['custom_queue'] ?? array();
		$index    = (int) ( $state['custom_index'] ?? 0 );
		$deadline = self::tick_deadline();
		$limit    = self::tick_items_limit();
		$done     = 0;

		while ( $index < count( $queue ) && $done < $limit && microtime( true ) < $deadline ) {
			$task      = $queue[ $index ];
			$remote_id = (int) ( $task['remote_id'] ?? 0 );
			$action    = $task['action'] ?? 'price';
			$item      = Mehrgift_Api_Client::find_in_cache( $remote_id );
			$index++;

			if ( Mehrgift_Categories::is_excluded( $remote_id ) ) {
				Mehrgift_Cron::log( "SKIP #{$remote_id}: این محصول در دسته حذف‌شده است." );
				continue;
			}

			if ( is_wp_error( $item ) ) {
				Mehrgift_Cron::log( "ERROR #{$remote_id}: " . $item->get_error_message() );
				continue;
			}

			$with_images = in_array( $action, array( 'full', 'images' ), true );
			$mode        = 'full' === $action ? 'full' : $action;
			$stat_key    = 'full' === $action ? 'create' : $action;
			self::process_one( $item, $mode, $with_images, $stat_key, $index, true );
			$done++;
		}

		if ( $index >= count( $queue ) ) {
			self::finish();
			return false;
		}

		Mehrgift_Job::save_state( array( 'custom_index' => $index, 'offset' => $index ) );
		return true;
	}

	private static function process_one( array $item, $mode, $with_images, $stat_prefix, $next_offset, $custom = false ) {
		$state      = Mehrgift_Job::get_state();
		$stats      = $state['stats'] ?? self::empty_stats();
		$remote_id  = (int) $item['id'];
		$title      = $item['main_title'] ?? (string) $remote_id;

		Mehrgift_Job::save_state( array( 'current_product' => $title ) );
		Mehrgift_Cron::log( sprintf( '%s: %s (#%d)', $mode, $title, $remote_id ) );

		$GLOBALS['mehrgift_throttle']    = true;
		$GLOBALS['mehrgift_force_specs'] = (bool) Mehrgift_Settings::get( 'scrape_in_details' );

		Mehrgift_Product_Sync::begin_performance_mode();
		$result = Mehrgift_Product_Sync::sync_item( $item, $mode, $with_images );
		Mehrgift_Product_Sync::end_performance_mode();
		unset( $GLOBALS['mehrgift_throttle'] );

		if ( is_wp_error( $result ) && 'mehrgift_excluded' === $result->get_error_code() ) {
			$stats['skipped'] = ( (int) ( $stats['skipped'] ?? 0 ) ) + 1;
			Mehrgift_Cron::log( 'SKIP: ' . $result->get_error_message() );
		} elseif ( is_wp_error( $result ) ) {
			$stats[ $stat_prefix . '_err' ] = ( (int) ( $stats[ $stat_prefix . '_err' ] ?? 0 ) ) + 1;
			Mehrgift_Cron::log( 'ERROR: ' . $result->get_error_message() );
		} else {
			$stats[ $stat_prefix . '_ok' ] = ( (int) ( $stats[ $stat_prefix . '_ok' ] ?? 0 ) ) + 1;
			Mehrgift_Cron::log( 'OK: ' . ( $result['title'] ?? $title ) );
		}

		$update = array( 'stats' => $stats, 'offset' => $next_offset );
		if ( $custom ) {
			$update['custom_index'] = $next_offset;
		}
		Mehrgift_Job::save_state( $update );
		return true;
	}

	private static function needs_details( array $item ) {
		$remote_id  = (int) $item['id'];
		$product_id = Mehrgift_Product_Sync::find_product_id( $remote_id, $item['barcode_primary'] ?? '' );
		if ( ! $product_id ) {
			return false;
		}
		$row = Mehrgift_Sync_Registry::get_by_remote_id( $remote_id );
		if ( ! $row ) {
			return true;
		}
		return ( $row['details_hash'] ?? '' ) !== Mehrgift_Api_Client::compute_details_hash( $item );
	}

	private static function needs_images( array $item ) {
		$remote_id  = (int) $item['id'];
		$product_id = Mehrgift_Product_Sync::find_product_id( $remote_id, $item['barcode_primary'] ?? '' );
		if ( ! $product_id ) {
			return false;
		}
		$row = Mehrgift_Sync_Registry::get_by_remote_id( $remote_id );
		if ( ! $row || 'ok' !== ( $row['images_status'] ?? '' ) ) {
			return true;
		}
		return ( $row['images_hash'] ?? '' ) !== Mehrgift_Api_Client::compute_images_hash( $item );
	}

	private static function count_needing_details( array $products ) {
		$count = 0;
		foreach ( $products as $item ) {
			if ( self::needs_details( $item ) ) {
				$count++;
			}
		}
		return $count;
	}

	private static function count_needing_images( array $products ) {
		$count = 0;
		foreach ( $products as $item ) {
			if ( self::needs_images( $item ) ) {
				$count++;
			}
		}
		return $count;
	}

	public static function finish() {
		Mehrgift_Job::save_state( array(
			'running'    => false,
			'background' => false,
			'phase'      => self::PHASE_DONE,
			'finished_at'=> current_time( 'mysql' ),
		) );
		Mehrgift_Cron::log( '=== پایان pipeline ===' );
	}

	public static function progress_percent( $state = null ) {
		$state = $state ?: Mehrgift_Job::get_state();
		if ( empty( $state['running'] ) && ( $state['phase'] ?? '' ) === self::PHASE_DONE ) {
			return 100;
		}

		$phase_weights = array(
			self::PHASE_FETCH   => array( 0, 5 ),
			self::PHASE_CREATE  => array( 5, 20 ),
			self::PHASE_PRICE   => array( 20, 45 ),
			self::PHASE_DETAILS => array( 45, 75 ),
			self::PHASE_IMAGES  => array( 75, 100 ),
			self::PHASE_CUSTOM  => array( 0, 100 ),
		);

		$phase = $state['phase'] ?? self::PHASE_FETCH;
		if ( ! isset( $phase_weights[ $phase ] ) ) {
			return 0;
		}

		list( $start, $end ) = $phase_weights[ $phase ];
		$phase_total = max( 1, (int) ( $state['phase_total'] ?? 1 ) );
		$offset      = (int) ( $state['offset'] ?? 0 );
		if ( self::PHASE_CUSTOM === $phase ) {
			$offset = (int) ( $state['custom_index'] ?? 0 );
			$phase_total = max( 1, (int) ( $state['total'] ?? 1 ) );
		}

		$phase_pct = min( 1, $offset / $phase_total );
		return (int) round( $start + ( $end - $start ) * $phase_pct );
	}

	public static function phase_label( $phase ) {
		$labels = array(
			self::PHASE_FETCH   => 'دانلود لیست',
			self::PHASE_CREATE  => 'ایجاد محصولات جدید',
			self::PHASE_PRICE   => 'آپدیت قیمت و موجودی',
			self::PHASE_DETAILS => 'آپدیت جزئیات و واریانت',
			self::PHASE_IMAGES  => 'آپدیت تصاویر',
			self::PHASE_CUSTOM  => 'همگام‌سازی انتخابی',
			self::PHASE_DONE    => 'تمام شد',
		);
		return $labels[ $phase ] ?? $phase;
	}

	public static function server_metrics() {
		$metrics = array(
			'memory_mb'  => round( memory_get_usage( true ) / 1048576, 1 ),
			'peak_mb'    => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'load_avg'   => null,
			'load_label' => 'نامشخص',
		);

		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			if ( is_array( $load ) ) {
				$metrics['load_avg']   = round( $load[0], 2 );
				$metrics['load_label'] = $load[0] < 1 ? 'سبک' : ( $load[0] < 3 ? 'متوسط' : 'سنگین' );
			}
		}

		$state = Mehrgift_Job::get_state();
		if ( ! empty( $state['last_tick_ms'] ) ) {
			$metrics['last_tick_ms'] = (int) $state['last_tick_ms'];
		}
		if ( ! empty( $state['memory_mb'] ) ) {
			$metrics['job_memory_mb'] = (float) $state['memory_mb'];
		}

		return $metrics;
	}
}
