<?php
/**
 * Frontend integration: enqueues, shortcode/action to render the CTA button,
 * and localized config the JS uses to bind #tw-request-info-btn and either
 * build the plugin modal or inject the form into the theme modal.
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
		add_shortcode( 'tw_crm_lead_form', array( __CLASS__, 'render_form_mount' ) );
		add_action( 'tw_crm_render_button', array( __CLASS__, 'render_button_action' ) );
		add_action( 'tw_crm_render_form', array( __CLASS__, 'render_form_mount_action' ) );
	}

	/**
	 * Load on any public frontend page while the feature is enabled.
	 * Homepage / landing pages are first-class — this is not product-only.
	 *
	 * @return bool
	 */
	private static function should_load() {
		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return false;
		}
		$settings = TW_CRM_Settings::get_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Current WooCommerce product, if this request is actually a product page.
	 *
	 * @return WC_Product|null
	 */
	private static function current_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		global $product;
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		$resolved = wc_get_product( get_the_ID() );
		return $resolved ? $resolved : null;
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
	 * Enqueue CSS/JS + localize config on public frontend pages.
	 */
	public static function enqueue() {
		if ( ! self::should_load() ) {
			return;
		}

		$settings        = TW_CRM_Settings::get_settings();
		$active_env      = TW_CRM_Settings::get_active_environment();
		$endpoint_ready  = TW_CRM_Settings::is_active_endpoint_configured();
		$product         = self::current_product();

		wp_enqueue_style( 'tw-crm-lead-form', TW_CRM_URL . 'assets/css/tw-crm-lead-form.css', array(), self::asset_version( 'assets/css/tw-crm-lead-form.css' ) );
		wp_enqueue_script( 'tw-crm-lead-form', TW_CRM_URL . 'assets/js/tw-crm-lead-form.js', array(), self::asset_version( 'assets/js/tw-crm-lead-form.js' ), true );

		$product_id = $product ? (int) $product->get_id() : 0;

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
				'modalSource'         => ( isset( $settings['modal_source'] ) && in_array( $settings['modal_source'], array( 'theme', 'inline' ), true ) ) ? $settings['modal_source'] : 'plugin',
				'defaultProductId'    => TW_CRM_Settings::resolve_external_product_id( $product ),
				'triggerId'           => TW_CRM_TRIGGER_ID,
				'themeModalId'        => TW_CRM_THEME_MODAL_ID,
				'pluginModalId'       => TW_CRM_PLUGIN_MODAL_ID,
				'formMountId'         => TW_CRM_FORM_MOUNT_ID,
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
		$settings = TW_CRM_Settings::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['cta_show'] ) ) {
			return '';
		}

		$product = self::current_product();
		if ( $product ) {
			return sprintf(
				'<button type="button" id="%1$s" class="tw-sp-cta" data-tw-crm-open data-product="%2$s" data-product-id="%3$d">%4$s</button>',
				esc_attr( TW_CRM_TRIGGER_ID ),
				esc_attr( $product->get_name() ),
				(int) $product->get_id(),
				esc_html( $settings['cta_text'] )
			);
		}

		return sprintf(
			'<button type="button" id="%1$s" class="tw-sp-cta" data-tw-crm-open>%2$s</button>',
			esc_attr( TW_CRM_TRIGGER_ID ),
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

	/**
	 * Mount point for a simple (non-modal) form. Themes like Lead-Gen-1
	 * output this on the homepage; the plugin injects the fields into
	 * #leads-form-div. data-tw-crm-inline tells the JS this is not a popup.
	 *
	 * @return string
	 */
	public static function render_form_mount() {
		$settings = TW_CRM_Settings::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return '';
		}

		return sprintf(
			'<div id="%1$s" data-tw-crm-inline><div id="%2$s"></div></div>',
			esc_attr( TW_CRM_THEME_MODAL_ID ),
			esc_attr( TW_CRM_FORM_MOUNT_ID )
		);
	}

	/**
	 * do_action( 'tw_crm_render_form' ) convenience wrapper.
	 */
	public static function render_form_mount_action() {
		echo self::render_form_mount(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in render_form_mount().
	}
}
