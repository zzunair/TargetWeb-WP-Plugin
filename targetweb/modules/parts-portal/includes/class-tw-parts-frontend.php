<?php
/**
 * Frontend integration: the [tw_parts_portal] shortcode that renders the
 * iframe, and the enqueue of tw-parts-portal.js (resize/infinite-scroll
 * passthrough + WooCommerce Store API add-to-cart) on the page that uses it.
 *
 * @package TargetWeb\PartsPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Parts_Frontend {

	/**
	 * Hook frontend behavior.
	 */
	public static function init() {
		add_shortcode( TW_PARTS_SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Renders the iframe. Deliberately done via a shortcode's PHP callback
	 * (rather than raw markup saved in post_content) so this markup/behavior
	 * is never at the mercy of KSES stripping <iframe>/<script> tags from a
	 * Page's saved content.
	 *
	 * @return string
	 */
	public static function render() {
		global $post;
		$iframe_src = $post ? get_post_meta( $post->ID, TW_Parts_Sync::IFRAME_SRC_META_KEY, true ) : '';
		if ( ! $iframe_src ) {
			return '';
		}

		return sprintf(
			'<div style="width:100vw;margin-left:calc(50%% - 50vw);margin-right:calc(50%% - 50vw);"><iframe id="iframId" src="%s" style="width:100%%;border:none;display:block;" height="600"></iframe></div>',
			esc_url( $iframe_src )
		);
	}

	/**
	 * Only enqueue on the page that actually renders the shortcode.
	 *
	 * @return bool
	 */
	private static function should_load() {
		global $post;
		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, TW_PARTS_SHORTCODE );
	}

	/**
	 * Cache-busting version for a bundled asset: its mtime when readable,
	 * otherwise the module version.
	 *
	 * @param string $relative_path Path relative to the module root.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$file = TW_PARTS_DIR . $relative_path;
		$time = file_exists( $file ) ? filemtime( $file ) : false;
		return $time ? (string) $time : TW_PARTS_VERSION;
	}

	/**
	 * Enqueue the add-to-cart/resize/scroll script on eligible pages.
	 */
	public static function maybe_enqueue() {
		if ( ! self::should_load() ) {
			return;
		}

		wp_enqueue_script(
			'tw-parts-portal',
			TW_PARTS_URL . 'assets/js/tw-parts-portal.js',
			array(),
			self::asset_version( 'assets/js/tw-parts-portal.js' ),
			true
		);

		wp_localize_script(
			'tw-parts-portal',
			'twPartsConfig',
			array(
				'cartUrl'    => rest_url( 'wc/store/v1/cart' ),
				'addItemUrl' => rest_url( 'wc/store/v1/cart/add-item' ),
			)
		);
	}
}
