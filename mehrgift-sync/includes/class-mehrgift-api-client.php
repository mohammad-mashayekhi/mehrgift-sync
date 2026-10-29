<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Api_Client {

	const API_LIST = 'https://www.mehrgift.ir/api/v1/products/';

	/** @var array|null */
	private static $memory_cache = null;

	public static function fetch_all_products() {
		$url      = add_query_arg( 'per_page', 2500, self::API_LIST );
		$response = wp_remote_get( $url, array( 'timeout' => 120 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['result']['data'] ) || ! is_array( $data['result']['data'] ) ) {
			return new WP_Error( 'mehrgift_api', 'فرمت پاسخ API صحیح نیست.' );
		}

		return $data['result']['data'];
	}

	public static function save_local_cache( array $products ) {
		$path = Mehrgift_Settings::data_file_path();
		$json = wp_json_encode( $products, JSON_UNESCAPED_UNICODE );
		if ( false === file_put_contents( $path, $json ) ) {
			return new WP_Error( 'mehrgift_io', 'خطا در ذخیره فایل کش.' );
		}
		self::$memory_cache = $products;
		set_transient( 'mehrgift_products_mem', $products, HOUR_IN_SECONDS );
		return count( $products );
	}

	public static function load_local_cache() {
		$products = self::load_raw_cache();
		if ( is_wp_error( $products ) ) {
			return $products;
		}
		return self::apply_exclusions( $products );
	}

	private static function load_raw_cache() {
		if ( is_array( self::$memory_cache ) ) {
			return self::$memory_cache;
		}

		$cached = get_transient( 'mehrgift_products_mem' );
		if ( is_array( $cached ) ) {
			self::$memory_cache = $cached;
			return $cached;
		}

		$path = Mehrgift_Settings::data_file_path();
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'mehrgift_cache', 'فایل کش یافت نشد. ابتدا لیست را دانلود کنید.' );
		}

		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mehrgift_cache', 'فایل کش معتبر نیست.' );
		}

		self::$memory_cache = $data;
		set_transient( 'mehrgift_products_mem', $data, HOUR_IN_SECONDS );
		return $data;
	}

	public static function apply_exclusions( array $products ) {
		$ids = Mehrgift_Categories::excluded_lookup();
		if ( empty( $ids ) ) {
			return $products;
		}

		return array_values( array_filter( $products, function ( $item ) use ( $ids ) {
			return ! isset( $ids[ (int) ( $item['id'] ?? 0 ) ] );
		} ) );
	}

	public static function find_in_cache( $remote_id ) {
		$products = self::load_local_cache();
		if ( is_wp_error( $products ) ) {
			return $products;
		}

		foreach ( $products as $item ) {
			if ( (int) $item['id'] === (int) $remote_id ) {
				return $item;
			}
		}

		return new WP_Error( 'mehrgift_not_found', 'محصول در کش یافت نشد.' );
	}

	public static function find_in_cache_by_kala_code( $kala_code ) {
		$kala_code = trim( (string) $kala_code );
		if ( '' === $kala_code ) {
			return new WP_Error( 'mehrgift_not_found', 'کد کالا خالی است.' );
		}

		$products = self::load_local_cache();
		if ( is_wp_error( $products ) ) {
			return $products;
		}

		foreach ( $products as $item ) {
			if ( (string) ( $item['barcode_primary'] ?? '' ) === $kala_code ) {
				return $item;
			}
		}

		return new WP_Error( 'mehrgift_not_found', 'محصول با این کد کالا در کش یافت نشد.' );
	}

	public static function compute_hash( array $item ) {
		return md5( wp_json_encode( array(
			'price'   => self::compute_price_hash( $item ),
			'details' => self::compute_details_hash( $item ),
			'images'  => self::compute_images_hash( $item ),
		) ) );
	}

	public static function compute_price_hash( array $item ) {
		$variants = array();
		foreach ( $item['variants'] ?? array() as $v ) {
			$variants[] = array(
				'id'    => $v['id'] ?? 0,
				'price' => $v['price'] ?? 0,
				'qty'   => $v['qty'] ?? 0,
			);
		}
		return md5( wp_json_encode( array(
			'price'    => $item['price'] ?? 0,
			'qty'      => $item['qty'] ?? 0,
			'variants' => $variants,
		) ) );
	}

	public static function compute_details_hash( array $item ) {
		return md5( wp_json_encode( array(
			'title'    => $item['main_title'] ?? '',
			'sub'      => $item['sub_title'] ?? '',
			'desc'     => $item['description'] ?? '',
			'barcode'  => $item['barcode_primary'] ?? '',
			'variants' => $item['variants'] ?? array(),
		) ) );
	}

	public static function compute_images_hash( array $item ) {
		return md5( wp_json_encode( $item['images'] ?? array() ) );
	}
}
