<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Attributes {

	const COLOR_SLUG = 'pa_rang';
	const SIZE_SLUG  = 'pa_saz';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_attribute_labels' ), 20 );
	}

	public static function ensure_global_attributes() {
		self::create_attribute( 'rang', 'رنگ' );
		self::create_attribute( 'saz', 'سایز' );
	}

	private static function create_attribute( $slug, $label ) {
		$taxonomy = 'pa_' . $slug;
		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}

		if ( function_exists( 'wc_create_attribute' ) ) {
			$id = wc_create_attribute( array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			) );
			if ( is_wp_error( $id ) ) {
				return;
			}
		}

		register_taxonomy( $taxonomy, array( 'product' ), array(
			'labels'       => array( 'name' => $label ),
			'hierarchical' => false,
			'show_ui'      => false,
			'query_var'    => true,
			'rewrite'      => false,
			'public'       => false,
		) );
	}

	public static function register_attribute_labels() {
		// taxonomy labels already set on creation.
	}

	public static function ensure_term( $taxonomy, $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}

		$term = term_exists( $name, $taxonomy );
		if ( $term ) {
			return (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$created = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $created ) ) {
			return 0;
		}
		return (int) $created['term_id'];
	}

	public static function build_variation_attributes( array $item ) {
		$is_variable = count( $item['variants'] ?? array() ) > 1;
		$colors      = array();
		$sizes       = array();

		foreach ( $item['variants'] as $variant ) {
			if ( ! empty( $variant['color'] ) ) {
				$colors[] = $variant['color'];
			}
			if ( ! empty( $variant['size'] ) ) {
				$sizes[] = $variant['size'];
			}
		}

		$colors = array_values( array_unique( $colors ) );
		$sizes  = array_values( array_unique( $sizes ) );
		$attrs  = array();

		if ( ! empty( $colors ) ) {
			$term_ids = array();
			foreach ( $colors as $color ) {
				$term_ids[] = self::ensure_term( self::COLOR_SLUG, $color );
			}
			$attr = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( 'rang' ) );
			$attr->set_name( self::COLOR_SLUG );
			$attr->set_options( array_filter( $term_ids ) );
			$attr->set_visible( true );
			$attr->set_variation( $is_variable );
			$attrs[ self::COLOR_SLUG ] = $attr;
		}

		if ( ! empty( $sizes ) ) {
			$term_ids = array();
			foreach ( $sizes as $size ) {
				$term_ids[] = self::ensure_term( self::SIZE_SLUG, $size );
			}
			$attr = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( 'saz' ) );
			$attr->set_name( self::SIZE_SLUG );
			$attr->set_options( array_filter( $term_ids ) );
			$attr->set_visible( true );
			$attr->set_variation( $is_variable );
			$attrs[ self::SIZE_SLUG ] = $attr;
		}

		return $attrs;
	}

	public static function variant_attribute_map( array $variant ) {
		$map = array();
		if ( ! empty( $variant['color'] ) ) {
			$term_id = self::ensure_term( self::COLOR_SLUG, $variant['color'] );
			$term    = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$map[ 'attribute_' . self::COLOR_SLUG ] = $term->slug;
			}
		}
		if ( ! empty( $variant['size'] ) ) {
			$term_id = self::ensure_term( self::SIZE_SLUG, $variant['size'] );
			$term    = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$map[ 'attribute_' . self::SIZE_SLUG ] = $term->slug;
			}
		}
		return $map;
	}
}
