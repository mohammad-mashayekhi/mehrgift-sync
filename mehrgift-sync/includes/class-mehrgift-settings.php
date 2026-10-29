<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Settings {

	const OPTION_KEY = 'mehrgift_sync_settings';

	public static function maybe_set_defaults() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, self::defaults() );
		}
	}

	public static function defaults() {
		return array(
			'discount_percent'        => 20,
			'discount_threshold'      => 500000,
			'discount_percent_above' => 20,
			'discount_percent_below' => 10,
			'crop_percent'        => 14,
			'output_size'         => 800,
			'jpeg_quality'        => 88,
			'batch_no_images'     => 15,
			'batch_with_images'   => 1,
			'batch_details'       => 1,
			'batch_price'         => 50,
			'batch_pause_sec'     => 8,
			'batch_pause_price'   => 1,
			'cpu_throttle'        => 'vps3',
			'server_cores'        => 3,
			'time_budget_sec'     => 28,
			'scrape_in_details'   => 0,
			'scrape_delay_sec'    => 2,
			'use_background'      => 0,
			'auto_sync_enabled'   => 0,
			'daily_sync_hour'     => 0,
			'fetch_specs_bulk'     => 0,
			'show_specs_tab'       => 1,
			'show_kala_code'       => 1,
			'excluded_categories'  => array(),
		);
	}

	public static function get( $key = null ) {
		self::maybe_set_defaults();
		$stored   = get_option( self::OPTION_KEY, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = wp_parse_args( $stored, self::defaults() );
		if ( ! array_key_exists( 'discount_percent_above', $stored ) ) {
			$settings['discount_percent_above'] = (int) $settings['discount_percent'];
		}
		if ( null === $key ) {
			return $settings;
		}
		return $settings[ $key ] ?? null;
	}

	public static function save( array $input ) {
		$current  = self::get();
		$defaults = self::defaults();

		$settings = array(
			'discount_percent'        => max( 0, min( 99, (int) ( $input['discount_percent_above'] ?? $input['discount_percent'] ?? $current['discount_percent'] ) ) ),
			'discount_threshold'      => max( 0, (int) ( $input['discount_threshold'] ?? $current['discount_threshold'] ?? 500000 ) ),
			'discount_percent_above' => max( 0, min( 99, (int) ( $input['discount_percent_above'] ?? $current['discount_percent_above'] ?? $current['discount_percent'] ) ) ),
			'discount_percent_below' => max( 0, min( 99, (int) ( $input['discount_percent_below'] ?? $current['discount_percent_below'] ?? 10 ) ) ),
			'crop_percent'      => max( 0, min( 40, (float) ( $input['crop_percent'] ?? $current['crop_percent'] ) ) ),
			'output_size'       => max( 400, min( 2000, (int) ( $input['output_size'] ?? $current['output_size'] ) ) ),
			'jpeg_quality'      => max( 60, min( 100, (int) ( $input['jpeg_quality'] ?? $current['jpeg_quality'] ) ) ),
			'batch_no_images'   => max( 1, min( 100, (int) ( $input['batch_no_images'] ?? $current['batch_no_images'] ) ) ),
			'batch_with_images' => max( 1, min( 5, (int) ( $input['batch_with_images'] ?? $current['batch_with_images'] ) ) ),
			'batch_details'     => max( 1, min( 10, (int) ( $input['batch_details'] ?? $current['batch_details'] ) ) ),
			'batch_price'       => max( 1, min( 150, (int) ( $input['batch_price'] ?? $current['batch_price'] ) ) ),
			'batch_pause_sec'   => max( 2, min( 60, (int) ( $input['batch_pause_sec'] ?? $current['batch_pause_sec'] ) ) ),
			'batch_pause_price' => max( 0, min( 10, (int) ( $input['batch_pause_price'] ?? $current['batch_pause_price'] ) ) ),
			'cpu_throttle'      => in_array( $input['cpu_throttle'] ?? '', array( 'low', 'medium', 'high', 'vps3' ), true )
				? $input['cpu_throttle']
				: ( $current['cpu_throttle'] ?? 'vps3' ),
			'server_cores'      => max( 1, min( 8, (int) ( $input['server_cores'] ?? $current['server_cores'] ?? 3 ) ) ),
			'time_budget_sec'   => max( 10, min( 60, (int) ( $input['time_budget_sec'] ?? $current['time_budget_sec'] ?? 28 ) ) ),
			'scrape_in_details' => ! empty( $input['scrape_in_details'] ) ? 1 : 0,
			'scrape_delay_sec'  => max( 1, min( 10, (int) ( $input['scrape_delay_sec'] ?? $current['scrape_delay_sec'] ) ) ),
			'use_background'    => ! empty( $input['use_background'] ) ? 1 : 0,
			'auto_sync_enabled' => ! empty( $input['auto_sync_enabled'] ) ? 1 : 0,
			'daily_sync_hour'   => max( 0, min( 23, (int) ( $input['daily_sync_hour'] ?? $current['daily_sync_hour'] ?? 0 ) ) ),
			'fetch_specs_bulk'  => ! empty( $input['fetch_specs_bulk'] ) ? 1 : 0,
			'show_specs_tab'       => ! empty( $input['show_specs_tab'] ) ? 1 : 0,
			'show_kala_code'       => ! empty( $input['show_kala_code'] ) ? 1 : 0,
			'excluded_categories'  => empty( Mehrgift_Categories::catalog() )
				? self::excluded_categories()
				: Mehrgift_Categories::expand_slugs( self::sanitize_category_slug_list( $input['excluded_categories'] ?? array() ) ),
		);

		update_option( self::OPTION_KEY, $settings );
		return $settings;
	}

	public static function sanitize_category_slug( $slug ) {
		$slug = rawurldecode( wp_unslash( (string) $slug ) );
		$slug = trim( $slug, " \t\n\r\0\x0B/" );
		if ( '' === $slug || false !== strpos( $slug, '/' ) || false !== strpos( $slug, '..' ) ) {
			return '';
		}
		if ( ! preg_match( '/^(?:cat-|category-).+/u', $slug ) ) {
			return '';
		}
		return $slug;
	}

	public static function sanitize_category_slug_list( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $slug ) {
			$slug = self::sanitize_category_slug( $slug );
			if ( '' !== $slug ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function excluded_categories() {
		return self::sanitize_category_slug_list( self::get( 'excluded_categories' ) );
	}

	public static function discount_percent_for( $price ) {
		$price     = (float) $price;
		$threshold = (float) self::get( 'discount_threshold' );
		if ( $threshold > 0 && $price < $threshold ) {
			return (float) self::get( 'discount_percent_below' );
		}
		return (float) self::get( 'discount_percent_above' );
	}

	public static function sale_multiplier( $price = null ) {
		$discount = null === $price
			? (float) self::get( 'discount_percent_above' )
			: self::discount_percent_for( $price );
		return max( 0.01, ( 100 - $discount ) / 100 );
	}

	public static function data_file_path() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'mehrgift_data.json';
	}

	public static function get_batch_limit( $mode, $with_images = false, $background = false ) {
		$limit = 1;
		if ( $with_images || 'images' === $mode ) {
			$limit = (int) self::get( 'batch_with_images' );
		} elseif ( 'details' === $mode ) {
			$limit = (int) self::get( 'batch_details' );
		} elseif ( 'price' === $mode ) {
			$limit = (int) self::get( 'batch_price' );
		} elseif ( 'full' === $mode ) {
			$limit = min( (int) self::get( 'batch_details' ), (int) self::get( 'batch_no_images' ) );
		} else {
			$limit = (int) self::get( 'batch_no_images' );
		}

		if ( $background && 'price' !== $mode ) {
			$profile = self::throttle_profile();
			if ( $with_images || 'images' === $mode ) {
				$limit = min( $limit, (int) $profile['max_batch_images'] );
			} elseif ( in_array( $mode, array( 'details', 'full', 'create' ), true ) ) {
				$limit = min( $limit, (int) $profile['max_batch_details'] );
			}
		}

		return max( 1, $limit );
	}

	public static function throttle_profile() {
		$level = self::get( 'cpu_throttle' );
		$profiles = array(
			'low' => array(
				'max_batch_details' => 1,
				'max_batch_images'  => 1,
				'item_pause_sec'    => 3,
				'pause_details'     => 12,
				'pause_images'      => 15,
				'pause_full'        => 10,
				'pause_price'       => 1,
				'time_budget_sec'   => 18,
				'price_burst'       => 0,
				'items_per_tick'    => 1,
			),
			'medium' => array(
				'max_batch_details' => 2,
				'max_batch_images'  => 1,
				'item_pause_sec'    => 1,
				'pause_details'     => 6,
				'pause_images'      => 8,
				'pause_full'        => 5,
				'pause_price'       => 1,
				'time_budget_sec'   => 22,
				'price_burst'       => 0,
				'items_per_tick'    => 2,
			),
			'high' => array(
				'max_batch_details' => 5,
				'max_batch_images'  => 2,
				'item_pause_sec'    => 0,
				'pause_details'     => 3,
				'pause_images'      => 4,
				'pause_full'        => 3,
				'pause_price'       => 0,
				'time_budget_sec'   => 30,
				'price_burst'       => 1,
				'items_per_tick'    => 4,
			),
			'vps3' => array(
				'max_batch_details' => 3,
				'max_batch_images'  => 2,
				'item_pause_sec'    => 0,
				'pause_details'     => 2,
				'pause_images'      => 3,
				'pause_full'        => 2,
				'pause_price'       => 0,
				'time_budget_sec'   => 28,
				'price_burst'       => 1,
				'items_per_tick'    => 3,
			),
		);

		$profile = $profiles[ $level ] ?? $profiles['vps3'];
		$profile['time_budget_sec'] = (int) self::get( 'time_budget_sec' );
		return $profile;
	}

	public static function apply_preset_for_cores( $cores = 3 ) {
		$cores = max( 1, min( 8, (int) $cores ) );
		$presets = array(
			1 => array(
				'cpu_throttle' => 'low', 'batch_price' => 40, 'batch_details' => 1,
				'batch_with_images' => 1, 'batch_pause_sec' => 10, 'time_budget_sec' => 18,
			),
			2 => array(
				'cpu_throttle' => 'medium', 'batch_price' => 60, 'batch_details' => 2,
				'batch_with_images' => 1, 'batch_pause_sec' => 6, 'time_budget_sec' => 22,
			),
			3 => array(
				'cpu_throttle' => 'vps3', 'batch_price' => 100, 'batch_details' => 3,
				'batch_with_images' => 2, 'batch_pause_sec' => 2, 'batch_pause_price' => 0,
				'time_budget_sec' => 28, 'server_cores' => 3,
			),
			4 => array(
				'cpu_throttle' => 'high', 'batch_price' => 120, 'batch_details' => 4,
				'batch_with_images' => 2, 'batch_pause_sec' => 2, 'batch_pause_price' => 0,
				'time_budget_sec' => 30, 'server_cores' => 4,
			),
		);
		$key = $cores >= 4 ? 4 : $cores;
		$current = self::get();
		update_option( self::OPTION_KEY, array_merge( $current, $presets[ $key ] ) );
		return self::get();
	}

	public static function tick_time_budget() {
		return (int) self::throttle_profile()['time_budget_sec'];
	}

	public static function get_batch_pause_for_mode( $mode, $with_images = false, $background = false ) {
		if ( ! $background && 'price' === $mode && ! $with_images ) {
			return (int) self::get( 'batch_pause_price' );
		}

		$profile = self::throttle_profile();
		if ( $with_images || 'images' === $mode ) {
			return max( (int) self::get( 'batch_pause_sec' ), (int) $profile['pause_images'] );
		}
		if ( 'details' === $mode ) {
			return max( (int) self::get( 'batch_pause_sec' ), (int) $profile['pause_details'] );
		}
		if ( 'full' === $mode ) {
			return max( (int) self::get( 'batch_pause_sec' ), (int) $profile['pause_full'] );
		}
		if ( 'price' === $mode ) {
			return max( (int) self::get( 'batch_pause_price' ), (int) $profile['pause_price'] );
		}
		return (int) self::get( 'batch_pause_sec' );
	}

	public static function get_item_pause_sec( $mode, $with_images = false ) {
		$profile = self::throttle_profile();
		if ( 'price' === $mode && ! $with_images ) {
			return 0;
		}
		return (int) $profile['item_pause_sec'];
	}

	public static function is_heavy_mode( $mode, $with_images = false ) {
		return $with_images || in_array( $mode, array( 'images', 'details', 'full' ), true );
	}
}
