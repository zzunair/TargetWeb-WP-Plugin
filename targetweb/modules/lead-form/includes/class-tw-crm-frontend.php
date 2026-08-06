<?php
/**
 * Frontend integration: enqueues, shortcode/action to render the CTA button,
 * and localized config the JS uses to build the modal + form and bind to
 * the existing #tw-request-info-btn button without any theme changes.
 *
 * @package TargetWeb_CRM_Lead_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_CRM_Frontend {

	/**
	 * Hook frontend behavior.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_shortcode( 'tw_crm_request_info_button', array( __CLASS__, 'render_button' ) );
		add_action( 'tw_crm_render_button', array( __CLASS__, 'render_button_action' ) );
	}

	/**
	 * Only run on single WooCommerce product pages while the feature is enabled.
	 *
	 * @return bool
	 */
	private static function should_load() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}
		$settings = TW_CRM_Settings::get_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Cache-busting version for a bundled asset: its mtime when readable,
	 * otherwise the plugin version.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$file = TW_CRM_DIR . $relative_path;
		$time = file_exists( $file ) ? filemtime( $file ) : false;
		return $time ? (string) $time : TW_CRM_VERSION;
	}

	/**
	 * Enqueue CSS/JS + localize config on eligible product pages.
	 */
	public static function enqueue() {
		if ( ! self::should_load() ) {
			return;
		}

		$settings        = TW_CRM_Settings::get_settings();
		$active_env      = TW_CRM_Settings::get_active_environment();
		$endpoint_ready  = TW_CRM_Settings::is_active_endpoint_configured();

		wp_enqueue_style( 'tw-crm-lead-form', TW_CRM_URL . 'assets/css/tw-crm-lead-form.css', array(), self::asset_version( 'assets/css/tw-crm-lead-form.css' ) );
		wp_enqueue_script( 'tw-crm-lead-form', TW_CRM_URL . 'assets/js/tw-crm-lead-form.js', array(), self::asset_version( 'assets/js/tw-crm-lead-form.js' ), true );

		$product_id = get_the_ID();

		wp_localize_script(
			'tw-crm-lead-form',
			'twCrmConfig',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'action'              => TW_CRM_AJAX_ACTION,
				'formDataAction'      => TW_CRM_Ajax::FORM_DATA_ACTION,
				'nonce'               => wp_create_nonce( TW_CRM_NONCE_ACTION ),
				'currentProductId'    => (int) $product_id,
				'environment'         => $active_env,
				'endpointConfigured'  => (bool) $endpoint_ready,
				'phoneRequired'       => ! empty( $settings['phone_required'] ),
				'modalTitle'          => 'Request Information',
				'submitText'          => 'Submit',
				'i18n'                => array(
					'sending'          => __( 'Sending…', 'targetweb' ),
					'success'          => __( 'Thank you! We will be in touch shortly.', 'targetweb' ),
					'genericError'     => __( 'Something went wrong. Please try again.', 'targetweb' ),
					'required'         => __( 'Please fill in all required fields.', 'targetweb' ),
					'invalidEmail'     => __( 'Please enter a valid email address.', 'targetweb' ),
					'notConfigured'    => __( 'This form is not accepting submissions yet. Please contact us directly.', 'targetweb' ),
					'loadingLocations' => __( 'Loading…', 'targetweb' ),
					'firstName'        => __( 'First Name', 'targetweb' ),
					'lastName'         => __( 'Last Name', 'targetweb' ),
					'email'            => __( 'Email', 'targetweb' ),
					'phone'            => __( 'Phone', 'targetweb' ),
					'message'          => __( 'Message', 'targetweb' ),
					'location'         => __( 'Location', 'targetweb' ),
					'selectLocation'   => __( 'Select a location…', 'targetweb' ),
					'close'            => __( 'Close', 'targetweb' ),
				),
			)
		);
	}

	/**
	 * Render the CTA button — matches the theme's existing markup/attributes
	 * so it can be dropped in as a drop-in replacement once the theme
	 * removes its own copy. Used by the shortcode and the action hook.
	 *
	 * @return string
	 */
	public static function render_button() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return '';
		}

		$settings = TW_CRM_Settings::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['cta_show'] ) ) {
			return '';
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product ) {
			return '';
		}

		return sprintf(
			'<button type="button" id="tw-request-info-btn" class="tw-sp-cta" data-product="%1$s" data-product-id="%2$d">%3$s</button>',
			esc_attr( $product->get_name() ),
			(int) $product->get_id(),
			esc_html( $settings['cta_text'] )
		);
	}

	/**
	 * do_action('tw_crm_render_button') convenience wrapper — echoes instead
	 * of returning, since actions don't print their callback's return value.
	 */
	public static function render_button_action() {
		echo self::render_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in render_button().
	}
}
