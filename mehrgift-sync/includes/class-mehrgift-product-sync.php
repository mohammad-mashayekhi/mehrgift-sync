<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Product_Sync {

	const MODE_FULL    = 'full';
	const MODE_PRICE   = 'price';
	const MODE_IMAGES  = 'images';
	const MODE_DETAILS = 'details';

	public static function sync_item( array $item, $mode = self::MODE_FULL, $with_images = null ) {
		$mode = in_array( $mode, array( self::MODE_FULL, self::MODE_PRICE, self::MODE_IMAGES, self::MODE_DETAILS ), true )
			? $mode
			: self::MODE_FULL;

		if ( null === $with_images ) {
			$with_images = in_array( $mode, array( self::MODE_FULL, self::MODE_IMAGES ), true );
		}

		$remote_id = (int) ( $item['id'] ?? 0 );
		if ( ! $remote_id ) {
			return new WP_Error( 'mehrgift_sync', 'شناسه محصول نامعتبر است.' );
		}

		Mehrgift_Sync_Registry::mark_fetch( $remote_id, $item );

		$specs = array();
		if ( in_array( $mode, array( self::MODE_FULL, self::MODE_DETAILS ), true ) ) {
			$specs = self::load_specs( $item, ! empty( $GLOBALS['mehrgift_force_specs'] ) );
		}

		$product_id = self::find_product_id( $remote_id, $item['barcode_primary'] ?? '' );
		$created    = false;

		try {
			if ( $product_id && in_array( $mode, array( self::MODE_PRICE, self::MODE_IMAGES ), true ) ) {
				self::partial_update( $product_id, $item, $mode, $with_images );
			} elseif ( $product_id ) {
				self::update_product( $product_id, $item, $with_images, $specs, $mode );
			} else {
				if ( in_array( $mode, array( self::MODE_PRICE, self::MODE_IMAGES ), true ) ) {
					return new WP_Error( 'mehrgift_sync', 'محصول در سایت یافت نشد. ابتدا همگام‌سازی کامل انجام دهید.' );
				}
				$product_id = self::create_product( $item, $with_images, $specs );
				$created    = true;
			}

			self::save_meta( $product_id, $item, $specs );
			self::mark_registry_success( $remote_id, $item, $mode, $with_images, $product_id, $specs );

			return array(
				'product_id' => $product_id,
				'created'    => $created,
				'mode'       => $mode,
				'title'      => $item['main_title'] ?? '',
			);
		} catch ( Exception $e ) {
			self::mark_registry_error( $remote_id, $item, $mode, $e->getMessage(), $product_id );
			return new WP_Error( 'mehrgift_sync', $e->getMessage() );
		}
	}

	private static function mark_registry_success( $remote_id, array $item, $mode, $with_images, $product_id, array $specs ) {
		Mehrgift_Sync_Registry::upsert( array(
			'remote_id'   => $remote_id,
			'product_id'  => $product_id,
			'barcode'     => $item['barcode_primary'] ?? '',
			'title'       => $item['main_title'] ?? '',
			'data_hash'   => Mehrgift_Api_Client::compute_hash( $item ),
			'specs_hash'  => Mehrgift_Scraper::specs_hash( $specs ),
			'last_mode'   => $mode,
		) );

		if ( self::MODE_FULL === $mode ) {
			Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, 'price', 'ok', $product_id );
			Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, 'details', 'ok', $product_id );
			if ( $with_images ) {
				Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, 'images', 'ok', $product_id );
			}
			return;
		}

		if ( self::MODE_PRICE === $mode ) {
			Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, 'price', 'ok', $product_id );
		} elseif ( self::MODE_DETAILS === $mode ) {
			Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, 'details', 'ok', $product_id );
		} elseif ( self::MODE_IMAGES === $mode ) {
			Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, 'images', 'ok', $product_id );
		}
	}

	private static function mark_registry_error( $remote_id, array $item, $mode, $message, $product_id = null ) {
		$dim = self::MODE_FULL === $mode ? 'details' : $mode;
		if ( ! in_array( $dim, array( 'price', 'details', 'images' ), true ) ) {
			$dim = 'details';
		}
		Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, $dim, 'error', $product_id, $message );
	}

	public static function begin_performance_mode() {
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_invalidation( true );
		if ( function_exists( 'wc_deferred_product_sync' ) ) {
			wc_deferred_product_sync( true );
		}
	}

	public static function end_performance_mode() {
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		wp_suspend_cache_invalidation( false );
		if ( function_exists( 'wc_deferred_product_sync' ) ) {
			wc_deferred_product_sync( false );
		}
	}

	public static function sync_batch( array $batch, $mode = self::MODE_FULL, $with_images = false ) {
		if ( self::MODE_PRICE === $mode && ! $with_images ) {
			return self::sync_batch_price_fast( $batch );
		}

		$logs      = '';
		$processed = 0;
		$advanced  = 0;
		if ( ! isset( $GLOBALS['mehrgift_force_specs'] ) ) {
			$GLOBALS['mehrgift_force_specs'] = (bool) Mehrgift_Settings::get( 'scrape_in_details' );
		}

		foreach ( $batch as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}

			$advanced++;
			$result = self::sync_item( $item, $mode, $with_images );
			if ( is_wp_error( $result ) ) {
				$logs .= 'SKIP/ERROR (' . $item['id'] . '): ' . $result->get_error_message() . "\n";
				continue;
			}

			$action = $result['created'] ? 'CREATED' : 'UPDATED';
			$logs  .= "{$action}: {$result['title']} (WC #{$result['product_id']})\n";
			$processed++;

			if ( ! empty( $GLOBALS['mehrgift_throttle'] ) ) {
				$item_pause = Mehrgift_Settings::get_item_pause_sec( $mode, $with_images );
				if ( $item_pause > 0 && $advanced < count( $batch ) ) {
					sleep( $item_pause );
				}
			}
		}

		return array(
			'processed' => $processed,
			'advanced'  => $advanced,
			'logs'      => $logs,
		);
	}

	public static function sync_batch_price_fast( array $batch ) {
		global $wpdb;

		$processed  = 0;
		$skipped    = 0;
		$remote_ids = array();
		foreach ( $batch as $item ) {
			if ( ! empty( $item['id'] ) ) {
				$remote_ids[] = (string) (int) $item['id'];
			}
		}

		$id_map = array();
		if ( ! empty( $remote_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $remote_ids ), '%s' ) );
			$sql          = "SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key='_mehrgift_remote_id' AND meta_value IN ($placeholders)";
			$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $remote_ids ) );
			foreach ( $rows as $row ) {
				$id_map[ (int) $row->meta_value ] = (int) $row->post_id;
			}
		}

		foreach ( $batch as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$remote_id  = (int) $item['id'];
			$product_id = $id_map[ $remote_id ] ?? self::find_product_id( $remote_id, $item['barcode_primary'] ?? '' );
			if ( ! $product_id ) {
				$skipped++;
				continue;
			}
			self::apply_prices( wc_get_product( $product_id ), $item );
			Mehrgift_Sync_Registry::mark_from_item( $remote_id, $item, 'price', 'ok', $product_id );
			$processed++;
		}

		$logs = sprintf( 'قیمت/موجودی: %d آپدیت، %d رد شد.', $processed, $skipped );
		return array(
			'processed' => $processed,
			'advanced'  => count( $batch ),
			'logs'      => $logs . "\n",
		);
	}

	private static function load_specs( array $item, $force_fetch = true ) {
		$product_id = self::find_product_id( (int) $item['id'], $item['barcode_primary'] ?? '' );
		$existing   = array();

		if ( $product_id ) {
			$meta = get_post_meta( $product_id, '_mehrgift_specs', true );
			if ( is_array( $meta ) && ! empty( $meta ) ) {
				$existing = $meta;
				if ( ! $force_fetch ) {
					return $existing;
				}
			}
		}

		if ( ! $force_fetch && ! Mehrgift_Settings::get( 'scrape_in_details' ) ) {
			return $existing;
		}

		$cached = get_transient( 'mehrgift_specs_' . (int) $item['id'] );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$specs = Mehrgift_Scraper::fetch_specs( $item['url'] ?? '' );
		$delay = (int) Mehrgift_Settings::get( 'scrape_delay_sec' );
		if ( $delay > 0 ) {
			sleep( $delay );
		}

		if ( ! empty( $specs ) ) {
			set_transient( 'mehrgift_specs_' . (int) $item['id'], $specs, WEEK_IN_SECONDS );
		}
		return $specs;
	}

	public static function find_product_id( $remote_id, $kala_code = '' ) {
		global $wpdb;

		$remote_id = (int) $remote_id;
		$kala_code = trim( (string) $kala_code );

		if ( $kala_code ) {
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mehrgift_kala_code' AND meta_value=%s LIMIT 1",
				$kala_code
			) );
			if ( $id && self::can_link_product( (int) $id, $remote_id ) ) {
				self::link_product_meta( (int) $id, $remote_id, $kala_code );
				return (int) $id;
			}

			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1",
				$kala_code
			) );
			if ( $id && self::can_link_product( (int) $id, $remote_id ) ) {
				self::link_product_meta( (int) $id, $remote_id, $kala_code );
				return (int) $id;
			}
		}

		if ( $remote_id ) {
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mehrgift_remote_id' AND meta_value=%s LIMIT 1",
				(string) $remote_id
			) );
			if ( $id ) {
				if ( $kala_code ) {
					update_post_meta( (int) $id, '_mehrgift_kala_code', $kala_code );
				}
				return (int) $id;
			}
		}

		return 0;
	}

	private static function link_product_meta( $product_id, $remote_id, $kala_code ) {
		update_post_meta( $product_id, '_mehrgift_remote_id', (int) $remote_id );
		update_post_meta( $product_id, '_mehrgift_kala_code', $kala_code );

		$product = wc_get_product( $product_id );
		if ( $product && $kala_code ) {
			try {
				$product->set_sku( $kala_code );
				$product->save();
			} catch ( Exception $e ) {
				// SKU تکراری — فقط متای مهرگیفت را به‌روز می‌کنیم.
			}
		}
	}

	private static function can_link_product( $product_id, $remote_id ) {
		$existing = (int) get_post_meta( $product_id, '_mehrgift_remote_id', true );
		return ! $existing || $existing === (int) $remote_id;
	}

	public static function is_available( array $item, array $variant = null ) {
		if ( $variant ) {
			if ( array_key_exists( 'availability', $variant ) && ! $variant['availability'] ) {
				return false;
			}
			return (int) ( $variant['qty'] ?? 0 ) > 0;
		}

		if ( array_key_exists( 'availability', $item ) && ! $item['availability'] ) {
			return false;
		}
		return (int) ( $item['qty'] ?? 0 ) > 0;
	}

	public static function is_product_available( array $item ) {
		$variants = $item['variants'] ?? array();
		if ( count( $variants ) > 1 ) {
			foreach ( $variants as $variant ) {
				if ( self::is_available( $item, $variant ) ) {
					return true;
				}
			}
			return false;
		}
		return self::is_available( $item, $variants[0] ?? null );
	}

	private static function resolve_sale_price( $regular_price, array $variant, array $item ) {
		$regular_price = (float) $regular_price;
		if ( $regular_price <= 0 ) {
			return 0;
		}

		$api_discount = isset( $variant['discount'] )
			? (float) $variant['discount']
			: (float) ( $item['discount'] ?? 0 );

		if ( $api_discount > 0 ) {
			return round( $regular_price * ( 100 - $api_discount ) / 100 );
		}

		return round( $regular_price * Mehrgift_Settings::sale_multiplier() );
	}

	private static function partial_update( $product_id, array $item, $mode, $with_images ) {
		if ( self::MODE_PRICE === $mode ) {
			self::apply_prices( wc_get_product( $product_id ), $item );
			return;
		}
		if ( self::MODE_IMAGES === $mode && $with_images ) {
			Mehrgift_Image_Processor::sync_product_images( $product_id, $item, true );
		}
	}

	private static function create_product( array $item, $with_images, array $specs ) {
		$is_variable = count( $item['variants'] ?? array() ) > 1;
		$product     = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();

		self::set_base_data( $product, $item );
		$product->update_meta_data( '_mehrgift_remote_id', (int) $item['id'], true );
		$product->save();

		$product_id = $product->get_id();
		self::apply_attributes( $product_id, $item, $specs );
		self::sync_variations( $product_id, $item );
		self::apply_prices( wc_get_product( $product_id ), $item );

		if ( $with_images ) {
			Mehrgift_Image_Processor::sync_product_images( $product_id, $item, false );
		}

		return $product_id;
	}

	private static function update_product( $product_id, array $item, $with_images, array $specs, $mode ) {
		$product     = wc_get_product( $product_id );
		$is_variable = count( $item['variants'] ?? array() ) > 1;
		$target_type = $is_variable ? 'variable' : 'simple';

		if ( $product->get_type() !== $target_type ) {
			wp_delete_object_term_relationships( $product_id, 'product_type' );
			wp_set_object_terms( $product_id, $target_type, 'product_type' );
			$product = wc_get_product( $product_id );
		}

		if ( self::MODE_FULL === $mode || self::MODE_DETAILS === $mode ) {
			self::set_base_data( $product, $item );
			$product->save();
			self::apply_attributes( $product_id, $item, $specs );
			self::sync_variations( $product_id, $item );
		}

		if ( self::MODE_FULL === $mode || self::MODE_PRICE === $mode ) {
			self::apply_prices( wc_get_product( $product_id ), $item );
		}

		if ( ( self::MODE_FULL === $mode || self::MODE_IMAGES === $mode ) && $with_images ) {
			Mehrgift_Image_Processor::sync_product_images( $product_id, $item, true );
		}
	}

	private static function set_base_data( WC_Product $product, array $item ) {
		$product->set_name( $item['main_title'] ?? '' );
		if ( in_array( $product->get_status(), array( 'auto-draft', 'pending' ), true ) ) {
			$product->set_status( 'publish' );
		}

		$sku = $item['barcode_primary'] ?? (string) $item['id'];
		try {
			$product->set_sku( $sku );
		} catch ( Exception $e ) {
			$product->set_sku( $sku . '-' . $item['id'] );
		}

		$desc = trim( ( $item['description'] ?? '' ) . "\n" . ( $item['sub_title'] ?? '' ) );
		$product->set_description( $desc );
		$product->set_short_description( $item['sub_title'] ?? '' );
	}

	private static function apply_attributes( $product_id, array $item, array $specs ) {
		$product = wc_get_product( $product_id );
		$attrs   = Mehrgift_Attributes::build_variation_attributes( $item );

		if ( ! empty( $specs ) ) {
			$spec_attrs = Mehrgift_Scraper::specs_to_wc_attributes( $specs );
			foreach ( $spec_attrs as $key => $attr ) {
				$attrs[ $key ] = $attr;
			}
		}

		$product->set_attributes( $attrs );
		$product->save();
	}

	private static function sync_variations( $product_id, array $item ) {
		$variants = $item['variants'] ?? array();
		if ( count( $variants ) <= 1 ) {
			self::remove_child_variations( $product_id );
			return;
		}

		$existing = self::map_existing_variations( $product_id );
		$seen     = array();

		foreach ( $variants as $variant ) {
			$variant_id = (int) ( $variant['id'] ?? 0 );
			$variation  = isset( $existing[ $variant_id ] ) ? wc_get_product( $existing[ $variant_id ] ) : new WC_Product_Variation();

			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
			}

			$variation->set_parent_id( $product_id );
			$variation->update_meta_data( '_mehrgift_variant_id', $variant_id, true );
			$variation->set_attributes( Mehrgift_Attributes::variant_attribute_map( $variant ) );
			self::set_variation_prices( $variation, $variant, null, $item );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( (int) ( $variant['qty'] ?? 0 ) );
			$variation->set_stock_status( self::is_available( $item, $variant ) ? 'instock' : 'outofstock' );
			$variation->save();

			$seen[] = $variation->get_id();
		}

		foreach ( $existing as $remote_variant_id => $wc_variation_id ) {
			if ( ! in_array( $wc_variation_id, $seen, true ) ) {
				wp_delete_post( $wc_variation_id, true );
			}
		}

		$parent = wc_get_product( $product_id );
		if ( $parent ) {
			$parent->set_manage_stock( false );
			$parent->set_stock_status( self::is_product_available( $item ) ? 'instock' : 'outofstock' );
			$parent->save();
		}

		WC_Product_Variable::sync( $product_id );
	}

	private static function map_existing_variations( $product_id ) {
		$map  = array();
		$kids = get_posts( array(
			'post_type'      => 'product_variation',
			'post_parent'    => $product_id,
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'private' ),
			'fields'         => 'ids',
		) );

		foreach ( $kids as $vid ) {
			$remote = (int) get_post_meta( $vid, '_mehrgift_variant_id', true );
			if ( $remote ) {
				$map[ $remote ] = (int) $vid;
			}
		}
		return $map;
	}

	private static function remove_child_variations( $product_id ) {
		$kids = get_posts( array(
			'post_type'      => 'product_variation',
			'post_parent'    => $product_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		foreach ( $kids as $vid ) {
			wp_delete_post( $vid, true );
		}
	}

	private static function apply_prices( $product, array $item ) {
		if ( ! $product ) {
			return;
		}

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $item['variants'] as $variant ) {
				$vid = self::find_variation_by_remote( $product->get_id(), (int) $variant['id'] );
				if ( ! $vid ) {
					continue;
				}
				$variation = wc_get_product( $vid );
				if ( $variation ) {
					self::set_variation_prices( $variation, $variant, null, $item );
					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( (int) ( $variant['qty'] ?? 0 ) );
					$variation->set_stock_status( self::is_available( $item, $variant ) ? 'instock' : 'outofstock' );
					$variation->save();
				}
			}
			$product->set_manage_stock( false );
			$product->set_stock_status( self::is_product_available( $item ) ? 'instock' : 'outofstock' );
			$product->save();
			WC_Product_Variable::sync( $product->get_id() );
			return;
		}

		$variant   = $item['variants'][0] ?? array();
		$price     = (float) ( $variant['price'] ?? $item['price'] ?? 0 );
		$available = self::is_available( $item, $variant );
		$sale      = self::resolve_sale_price( $price, $variant, $item );

		if ( $price > 0 ) {
			$product->set_regular_price( $price );
			$product->set_sale_price( $sale );
			$product->set_price( $sale );
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( (int) ( $variant['qty'] ?? $item['qty'] ?? 0 ) );
		$product->set_stock_status( $available ? 'instock' : 'outofstock' );
		$product->save();
	}

	private static function set_variation_prices( WC_Product_Variation $variation, array $variant, $multiplier = null, array $item = array() ) {
		unset( $multiplier );
		$price = (float) ( $variant['price'] ?? 0 );
		$sale  = self::resolve_sale_price( $price, $variant, $item );

		if ( $price > 0 ) {
			$variation->set_regular_price( $price );
			$variation->set_sale_price( $sale );
			$variation->set_price( $sale );
		}
	}

	private static function find_variation_by_remote( $product_id, $remote_variant_id ) {
		$map = self::map_existing_variations( $product_id );
		return $map[ $remote_variant_id ] ?? 0;
	}

	private static function save_meta( $product_id, array $item, array $specs ) {
		$remote_id = (int) ( $item['id'] ?? 0 );
		$kala_code = sanitize_text_field( $item['barcode_primary'] ?? '' );

		update_post_meta( $product_id, '_mehrgift_remote_id', $remote_id );
		update_post_meta( $product_id, '_mehrgift_remote_url', esc_url_raw( $item['url'] ?? '' ) );
		update_post_meta( $product_id, '_mehrgift_kala_code', $kala_code );

		if ( $kala_code ) {
			$product = wc_get_product( $product_id );
			if ( $product && $product->get_sku() !== $kala_code ) {
				try {
					$product->set_sku( $kala_code );
					$product->save();
				} catch ( Exception $e ) {
					// نادیده — SKU تکراری.
				}
			}
		}
		update_post_meta( $product_id, '_mehrgift_last_sync', current_time( 'mysql' ) );
		update_post_meta( $product_id, '_mehrgift_data_hash', Mehrgift_Api_Client::compute_hash( $item ) );

		$guarantee = $item['variants'][0]['guarantee'] ?? '';
		if ( $guarantee ) {
			update_post_meta( $product_id, '_mehrgift_guarantee', sanitize_text_field( $guarantee ) );
		}

		if ( ! empty( $specs ) ) {
			update_post_meta( $product_id, '_mehrgift_specs', $specs );
			update_post_meta( $product_id, '_mehrgift_specs_html', Mehrgift_Scraper::specs_to_html_table( $specs ) );
		}
	}
}
