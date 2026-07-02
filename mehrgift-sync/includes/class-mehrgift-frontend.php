<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Frontend {

	public static function init() {
		if ( ! Mehrgift_Settings::get( 'show_specs_tab' ) ) {
			return;
		}

		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'add_specs_tab' ), 20 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'show_kala_code' ), 6 );
	}

	public static function add_specs_tab( $tabs ) {
		global $product;
		if ( ! $product ) {
			return $tabs;
		}

		$specs = get_post_meta( $product->get_id(), '_mehrgift_specs', true );
		if ( empty( $specs ) || ! is_array( $specs ) ) {
			return $tabs;
		}

		$tabs['mehrgift_specs'] = array(
			'title'    => 'مشخصات فنی',
			'priority' => 22,
			'callback' => array( __CLASS__, 'render_specs_tab' ),
		);
		return $tabs;
	}

	public static function render_specs_tab() {
		global $product;
		$html = get_post_meta( $product->get_id(), '_mehrgift_specs_html', true );
		if ( $html ) {
			echo '<div class="mehrgift-specs-tab">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$specs = get_post_meta( $product->get_id(), '_mehrgift_specs', true );
		if ( is_array( $specs ) ) {
			echo '<div class="mehrgift-specs-tab">' . Mehrgift_Scraper::specs_to_html_table( $specs ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function show_kala_code() {
		if ( ! Mehrgift_Settings::get( 'show_kala_code' ) ) {
			return;
		}
		global $product;
		$code = get_post_meta( $product->get_id(), '_mehrgift_kala_code', true );
		if ( $code ) {
			echo '<p class="mehrgift-kala-code"><strong>کد کالا:</strong> ' . esc_html( $code ) . '</p>';
		}
	}
}
