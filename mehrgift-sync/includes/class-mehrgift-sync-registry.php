<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Sync_Registry {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mehrgift_sync';
	}

	public static function upsert( array $row ) {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE remote_id = %d", (int) $row['remote_id'] ),
			ARRAY_A
		);

		$data = array(
			'remote_id'  => (int) $row['remote_id'],
			'barcode'    => $row['barcode'] ?? '',
			'title'      => $row['title'] ?? '',
			'last_fetch' => $row['last_fetch'] ?? $now,
		);

		$optional = array(
			'product_id', 'last_sync', 'data_hash', 'specs_hash', 'price_hash', 'details_hash',
			'images_hash', 'price_status', 'details_status', 'images_status', 'sync_status',
			'last_mode', 'error_message', 'last_price_sync', 'last_details_sync', 'last_images_sync',
		);
		foreach ( $optional as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$data[ $key ] = $row[ $key ];
			}
		}

		if ( $existing ) {
			if ( ! isset( $data['product_id'] ) && ! empty( $existing['product_id'] ) ) {
				$data['product_id'] = (int) $existing['product_id'];
			}
			$wpdb->update( $table, $data, array( 'remote_id' => $data['remote_id'] ) );
			return (int) $existing['id'];
		}

		$data += array(
			'price_status'   => $row['price_status'] ?? 'pending',
			'details_status' => $row['details_status'] ?? 'pending',
			'images_status'  => $row['images_status'] ?? 'pending',
			'sync_status'    => $row['sync_status'] ?? 'pending',
		);
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	public static function mark_fetch( $remote_id, array $item ) {
		return self::upsert( array(
			'remote_id'    => $remote_id,
			'barcode'      => $item['barcode_primary'] ?? '',
			'title'        => $item['main_title'] ?? '',
			'last_fetch'   => current_time( 'mysql' ),
			'data_hash'    => Mehrgift_Api_Client::compute_hash( $item ),
			'price_hash'   => Mehrgift_Api_Client::compute_price_hash( $item ),
			'details_hash' => Mehrgift_Api_Client::compute_details_hash( $item ),
			'images_hash'  => Mehrgift_Api_Client::compute_images_hash( $item ),
			'sync_status'  => 'fetched',
		) );
	}

	public static function mark_dimension( $remote_id, $dimension, $status, array $extra = array() ) {
		global $wpdb;
		$allowed = array( 'price', 'details', 'images' );
		if ( ! in_array( $dimension, $allowed, true ) ) {
			return false;
		}

		$now  = current_time( 'mysql' );
		$data = array(
			"{$dimension}_status"     => $status,
			"last_{$dimension}_sync"  => $now,
		);

		if ( isset( $extra[ "{$dimension}_hash" ] ) ) {
			$data[ "{$dimension}_hash" ] = $extra[ "{$dimension}_hash" ];
		}
		if ( isset( $extra['product_id'] ) ) {
			$data['product_id'] = (int) $extra['product_id'];
		}
		if ( 'ok' === $status ) {
			$data['last_sync']     = $now;
			$data['sync_status']   = 'synced';
			$data['error_message'] = '';
		} elseif ( 'error' === $status ) {
			$data['sync_status']   = 'error';
			$data['error_message'] = $extra['error_message'] ?? '';
		}

		return (bool) $wpdb->update(
			self::table_name(),
			$data,
			array( 'remote_id' => (int) $remote_id )
		);
	}

	public static function mark_from_item( $remote_id, array $item, $dimension, $status, $product_id = null, $error = '' ) {
		$hash_map = array(
			'price'   => 'compute_price_hash',
			'details' => 'compute_details_hash',
			'images'  => 'compute_images_hash',
		);
		$method = $hash_map[ $dimension ] ?? '';
		$hash   = $method ? Mehrgift_Api_Client::$method( $item ) : '';

		return self::mark_dimension( $remote_id, $dimension, $status, array(
			"{$dimension}_hash" => $hash,
			'product_id'        => $product_id,
			'error_message'     => $error,
		) );
	}

	public static function get_by_remote_id( $remote_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE remote_id = %d', (int) $remote_id ),
			ARRAY_A
		);
	}

	public static function get_by_product_id( $product_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE product_id = %d', (int) $product_id ),
			ARRAY_A
		);
	}

	public static function get_stats() {
		global $wpdb;
		$table = self::table_name();
		$stats = array( 'total' => 0 );

		$stats['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$stats['no_wc'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE product_id IS NULL OR product_id = 0" );

		foreach ( array( 'price', 'details', 'images' ) as $dim ) {
			foreach ( array( 'ok', 'pending', 'error' ) as $st ) {
				$stats[ "{$dim}_{$st}" ] = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$dim}_status = %s", $st )
				);
			}
		}

		return $stats;
	}

	public static function list_products( $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'search'   => '',
			'status'   => '',
			'paged'    => 1,
			'per_page' => 25,
		);
		$args  = wp_parse_args( $args, $defaults );
		$where = array( '1=1' );
		$vals  = array();

		if ( $args['search'] ) {
			$where[] = '(title LIKE %s OR barcode LIKE %s OR CAST(remote_id AS CHAR) LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$vals[]  = $like;
			$vals[]  = $like;
			$vals[]  = $like;
		}

		if ( $args['status'] ) {
			$where[] = 'sync_status = %s';
			$vals[]  = $args['status'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( (int) $args['paged'] - 1 ) * (int) $args['per_page'] );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY last_sync DESC, id DESC LIMIT %d OFFSET %d";
		$params   = array_merge( $vals, array( (int) $args['per_page'], $offset ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A );

		return array(
			'items' => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / max( 1, (int) $args['per_page'] ) ),
		);
	}

	public static function status_badge( $status ) {
		$labels = array(
			'ok'      => '✓',
			'pending' => '…',
			'error'   => '✗',
		);
		return $labels[ $status ] ?? '—';
	}

	public static function is_outdated( $remote_id, array $item ) {
		$row = self::get_by_remote_id( $remote_id );
		if ( ! $row ) {
			return true;
		}
		return $row['data_hash'] !== Mehrgift_Api_Client::compute_hash( $item );
	}
}
