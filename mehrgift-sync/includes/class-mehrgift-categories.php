<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Categories {

	const CATALOG_OPTION = 'mehrgift_source_categories';
	const INDEX_OPTION   = 'mehrgift_excluded_ids';
	const PAGE_CAP       = 100;
	const HOME_URL       = 'https://www.mehrgift.ir/';

	public static function catalog() {
		$catalog = get_option( self::CATALOG_OPTION, array() );
		return is_array( $catalog ) ? $catalog : array();
	}

	public static function refresh_catalog() {
		$html = self::fetch_html( self::HOME_URL, false );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$catalog = self::parse_menu_html( $html );
		if ( empty( $catalog ) ) {
			return new WP_Error( 'mehrgift_category', 'دسته‌ای در منوی مهرگیفت پیدا نشد.' );
		}

		update_option( self::CATALOG_OPTION, $catalog, false );
		return $catalog;
	}

	public static function expand_slugs( array $slugs ) {
		$slugs    = Mehrgift_Settings::sanitize_category_slug_list( $slugs );
		$catalog  = self::catalog();
		$known    = array();
		$children = array();

		foreach ( $catalog as $cat ) {
			if ( empty( $cat['slug'] ) ) {
				continue;
			}
			$known[ $cat['slug'] ] = true;
		}

		foreach ( $catalog as $cat ) {
			if ( empty( $cat['slug'] ) ) {
				continue;
			}
			$parent = (string) ( $cat['parent'] ?? '' );
			if ( '' === $parent || empty( $known[ $parent ] ) ) {
				$parent = '';
			}
			$children[ $parent ][] = $cat['slug'];
		}

		$out   = array();
		$seen  = array();
		$stack = $slugs;
		while ( $stack ) {
			$slug = array_pop( $stack );
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$out[]         = $slug;
			if ( ! empty( $children[ $slug ] ) ) {
				foreach ( $children[ $slug ] as $child ) {
					$stack[] = $child;
				}
			}
		}

		sort( $out, SORT_STRING );
		return $out;
	}

	public static function fingerprint( array $slugs ) {
		$slugs = Mehrgift_Settings::sanitize_category_slug_list( $slugs );
		sort( $slugs, SORT_STRING );
		return md5( wp_json_encode( $slugs ) );
	}

	public static function rebuild_index() {
		$slugs       = Mehrgift_Settings::excluded_categories();
		$fingerprint = self::fingerprint( $slugs );

		if ( empty( $slugs ) ) {
			self::store_index( $fingerprint, array() );
			return 0;
		}

		@set_time_limit( 300 );

		$ids = array();
		foreach ( $slugs as $slug ) {
			$found = self::collect_product_ids( $slug );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			foreach ( $found as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$list = array_map( 'intval', array_keys( $ids ) );
		sort( $list, SORT_NUMERIC );
		self::store_index( $fingerprint, $list );
		return count( $list );
	}

	public static function ensure_index() {
		$slugs       = Mehrgift_Settings::excluded_categories();
		$fingerprint = self::fingerprint( $slugs );
		$index       = get_option( self::INDEX_OPTION, array() );
		if ( is_array( $index ) && ( $index['fingerprint'] ?? '' ) === $fingerprint ) {
			return (int) ( $index['count'] ?? count( (array) ( $index['ids'] ?? array() ) ) );
		}
		return self::rebuild_index();
	}

	public static function excluded_lookup() {
		$slugs = Mehrgift_Settings::excluded_categories();
		if ( empty( $slugs ) ) {
			return array();
		}

		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) || empty( $index['ids'] ) || ! is_array( $index['ids'] ) ) {
			return array();
		}

		$lookup = array();
		foreach ( $index['ids'] as $id ) {
			$lookup[ (int) $id ] = true;
		}
		return $lookup;
	}

	public static function is_excluded( $remote_id ) {
		$lookup = self::excluded_lookup();
		return isset( $lookup[ (int) $remote_id ] );
	}

	public static function index_summary() {
		$index = get_option( self::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		$slugs = Mehrgift_Settings::excluded_categories();
		return array(
			'count'    => (int) ( $index['count'] ?? 0 ),
			'built_at' => (string) ( $index['built_at'] ?? '' ),
			'stale'    => ! empty( $slugs ) && ( $index['fingerprint'] ?? '' ) !== self::fingerprint( $slugs ),
		);
	}

	public static function parse_menu_html( $html ) {
		$start = strpos( $html, '<ul class="CatList nav navbar-nav">' );
		if ( false === $start ) {
			return array();
		}

		$depth = 0;
		$i     = $start;
		$len   = strlen( $html );
		$end   = null;
		while ( $i < $len ) {
			$next_open  = strpos( $html, '<ul', $i );
			$next_close = strpos( $html, '</ul>', $i );
			if ( false === $next_close ) {
				break;
			}
			if ( false !== $next_open && $next_open < $next_close ) {
				$depth++;
				$i = $next_open + 3;
			} else {
				$depth--;
				$i = $next_close + 5;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}
		if ( null === $end ) {
			return array();
		}

		$chunk = substr( $html, $start, $end - $start );
		if ( ! preg_match_all( '/<ul|<\/ul>|<a\b[^>]*>/u', $chunk, $matches ) ) {
			return array();
		}

		$stack    = array();
		$last     = '';
		$ul_depth = 0;
		$rows     = array();
		$seen     = array();

		foreach ( $matches[0] as $tok ) {
			if ( 0 === strpos( $tok, '<ul' ) ) {
				$ul_depth++;
				if ( $ul_depth > 1 ) {
					$stack[] = $last;
				}
				continue;
			}
			if ( 0 === strpos( $tok, '</ul' ) ) {
				$ul_depth--;
				if ( $ul_depth >= 1 && ! empty( $stack ) ) {
					array_pop( $stack );
				}
				continue;
			}
			if ( ! preg_match( '/href=([\'"])(https:\/\/www\.mehrgift\.ir\/(?:cat-|category-)[^\'"]+)\1/u', $tok, $href ) ) {
				continue;
			}
			if ( ! preg_match( '/title=([\'"])([^\'"]+)\1/u', $tok, $title ) ) {
				continue;
			}

			$href_url = html_entity_decode( $href[2], ENT_QUOTES, 'UTF-8' );
			if ( ! preg_match( '#/(cat-[^/?\#]+|category-\d+)/?#u', $href_url, $slug_match ) ) {
				continue;
			}
			$slug = Mehrgift_Settings::sanitize_category_slug( rawurldecode( $slug_match[1] ) );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}

			$parent = empty( $stack ) ? '' : (string) end( $stack );
			$rows[] = array(
				'slug'   => $slug,
				'title'  => trim( html_entity_decode( $title[2], ENT_QUOTES, 'UTF-8' ) ),
				'parent' => $parent,
			);
			$seen[ $slug ] = true;
			$last          = $slug;
		}

		return $rows;
	}

	private static function collect_product_ids( $slug ) {
		$ids = array();
		for ( $page = 1; $page <= self::PAGE_CAP; $page++ ) {
			$html = self::fetch_html( self::page_url( $slug, $page ), $page > 1 );
			if ( is_wp_error( $html ) ) {
				return $html;
			}
			if ( '' === $html ) {
				break;
			}

			$found = self::extract_product_ids( $html );
			if ( empty( $found ) ) {
				break;
			}

			$before = count( $ids );
			foreach ( $found as $id ) {
				$ids[ $id ] = true;
			}
			if ( count( $ids ) === $before ) {
				break;
			}
		}

		return array_map( 'intval', array_keys( $ids ) );
	}

	private static function extract_product_ids( $html ) {
		if ( ! preg_match_all( '#/product-(\d+)/#', $html, $matches ) ) {
			return array();
		}
		$ids = array();
		foreach ( $matches[1] as $id ) {
			$ids[ (int) $id ] = (int) $id;
		}
		return array_values( $ids );
	}

	private static function page_url( $slug, $page ) {
		$url = self::HOME_URL . rawurlencode( $slug ) . '/';
		if ( $page > 1 ) {
			$url .= 'page-' . (int) $page . '/';
		}
		return $url;
	}

	private static function fetch_html( $url, $missing_ok ) {
		$response = wp_remote_get( $url, array(
			'timeout'     => 30,
			'redirection' => 5,
			'user-agent'  => 'Raadino-MehrgiftSync/' . ( defined( 'MEHRGIFT_SYNC_VERSION' ) ? MEHRGIFT_SYNC_VERSION : '5' ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			if ( $missing_ok ) {
				return '';
			}
			return new WP_Error( 'mehrgift_category', 'خطا در دریافت دسته (' . $code . ').' );
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	private static function store_index( $fingerprint, array $ids ) {
		update_option( self::INDEX_OPTION, array(
			'fingerprint' => $fingerprint,
			'ids'         => array_values( $ids ),
			'count'       => count( $ids ),
			'built_at'    => current_time( 'mysql' ),
		), false );
	}
}
