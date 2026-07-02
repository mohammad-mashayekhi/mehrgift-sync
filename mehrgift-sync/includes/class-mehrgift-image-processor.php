<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mehrgift_Image_Processor {

	public static function sync_product_images( $product_id, array $item, $replace = true ) {
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'skip_thumbnails' ), 99 );

		try {
			if ( empty( $item['images'] ) || ! is_array( $item['images'] ) ) {
				if ( ! empty( $item['image'] ) ) {
					$item['images'] = array( $item['image'] );
				} else {
					return array( 'attached' => 0, 'errors' => array( 'تصویری در API نیست.' ) );
				}
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return array( 'attached' => 0, 'errors' => array( 'محصول یافت نشد.' ) );
			}

			if ( $replace ) {
				self::delete_product_images( $product );
			}

			$gallery_ids = array();
			$errors      = array();
			$urls        = array_values( array_unique( array_filter( $item['images'] ) ) );

			foreach ( $urls as $index => $url ) {
				$attachment_id = self::import_cropped_image( $url, $product_id );
				if ( is_wp_error( $attachment_id ) ) {
					$errors[] = $attachment_id->get_error_message();
					continue;
				}
				if ( 0 === $index ) {
					$product->set_image_id( $attachment_id );
				} else {
					$gallery_ids[] = $attachment_id;
				}
			}

			$product->set_gallery_image_ids( $gallery_ids );
			$product->save();

			return array(
				'attached' => 1 + count( $gallery_ids ),
				'errors'   => $errors,
			);
		} finally {
			remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'skip_thumbnails' ), 99 );
		}
	}

	public static function import_cropped_image( $url, $parent_id = 0 ) {
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$processed = self::center_crop_file( $tmp );
		if ( is_wp_error( $processed ) ) {
			@unlink( $tmp );
			return $processed;
		}

		$filename = 'mehrgift-' . md5( $url ) . '.jpg';
		$file     = array(
			'name'     => $filename,
			'tmp_name' => $processed,
		);

		$attachment_id = media_handle_sideload( $file, $parent_id );
		@unlink( $processed );
		if ( file_exists( $tmp ) ) {
			@unlink( $tmp );
		}

		return $attachment_id;
	}

	public static function center_crop_file( $file_path ) {
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$size = $editor->get_size();
		if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
			return new WP_Error( 'mehrgift_image', 'ابعاد تصویر خوانده نشد.' );
		}

		$crop_pct    = (float) Mehrgift_Settings::get( 'crop_percent' );
		$out_size    = (int) Mehrgift_Settings::get( 'output_size' );
		$quality     = (int) Mehrgift_Settings::get( 'jpeg_quality' );
		$margin_x    = (int) round( $size['width'] * $crop_pct / 100 );
		$margin_y    = (int) round( $size['height'] * $crop_pct / 100 );
		$crop_width  = $size['width'] - ( 2 * $margin_x );
		$crop_height = $size['height'] - ( 2 * $margin_y );

		if ( $crop_width < 50 || $crop_height < 50 ) {
			return new WP_Error( 'mehrgift_image', 'درصد کراپ بیش از حد است.' );
		}

		$cropped = $editor->crop( $margin_x, $margin_y, $crop_width, $crop_height, $out_size, $out_size );
		if ( is_wp_error( $cropped ) ) {
			return $cropped;
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( null, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $saved['path'];
	}

	public static function skip_thumbnails( $sizes ) {
		return array();
	}

	private static function delete_product_images( WC_Product $product ) {
		$ids = array_filter( array_merge(
			array( $product->get_image_id() ),
			$product->get_gallery_image_ids()
		) );

		foreach ( $ids as $id ) {
			if ( $id ) {
				wp_delete_attachment( (int) $id, true );
			}
		}

		$product->set_image_id( 0 );
		$product->set_gallery_image_ids( array() );
	}
}
