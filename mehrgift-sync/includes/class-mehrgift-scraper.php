<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Scraper {

	public static function fetch_specs( $url ) {
		if ( empty( $url ) ) {
			return array();
		}

		$response = wp_remote_get( $url, array(
			'timeout'    => 30,
			'user-agent' => 'Raadino-MehrgiftSync/4.0',
		) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$html = wp_remote_retrieve_body( $response );
		return self::parse_specs_html( $html );
	}

	public static function parse_specs_html( $html ) {
		if ( empty( $html ) ) {
			return array();
		}

		$specs = array();
		$pattern = '/TechnicalSpecsTitle">([^<]+)<\/span>\s*<\/div>\s*<div[^>]*>\s*<span class="TechnicalSpecsValue">([^<]+)<\/span>/u';

		if ( preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$key = trim( html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES, 'UTF-8' ) );
				$val = trim( html_entity_decode( wp_strip_all_tags( $match[2] ), ENT_QUOTES, 'UTF-8' ) );
				if ( $key && $val ) {
					$specs[ $key ] = $val;
				}
			}
		}

		return $specs;
	}

	public static function specs_hash( array $specs ) {
		return md5( wp_json_encode( $specs, JSON_UNESCAPED_UNICODE ) );
	}

	public static function specs_to_html_table( array $specs ) {
		if ( empty( $specs ) ) {
			return '';
		}

		$html = '<table class="shop_attributes shop_attributes_mehrgift"><tbody>';
		foreach ( $specs as $label => $value ) {
			$html .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}

	public static function specs_to_wc_attributes( array $specs ) {
		$attrs = array();
		foreach ( $specs as $label => $value ) {
			$attr = new WC_Product_Attribute();
			$attr->set_id( 0 );
			$attr->set_name( $label );
			$attr->set_options( array( $value ) );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$attrs[ sanitize_title( $label ) ] = $attr;
		}
		return $attrs;
	}
}
