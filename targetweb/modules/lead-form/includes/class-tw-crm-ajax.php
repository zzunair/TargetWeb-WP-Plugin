<?php
/**
 * AJAX handlers:
 *  - tw_crm_get_form_data  : lazy-loaded when the modal opens; resolves the
 *                            dmsSetupId + location list so the form can show
 *                            a location picker (or none, if there's one/zero).
 *  - tw_crm_submit_lead    : validates/sanitizes everything server-side,
 *                            resolves product + dmsSetupId from trusted
 *                            server state, and calls AddCustomerQuotation.
 *  - tw_crm_admin_test_connection : admin-only, used by the settings page's
 *                            "Test connection" button.
 *
 * @package TargetWeb_CRM_Lead_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_CRM_Ajax {

	const FORM_DATA_ACTION = 'tw_crm_get_form_data';

	/**
	 * Hook the AJAX actions.
	 */
	public static function init() {
		add_action( 'wp_ajax_' . TW_CRM_AJAX_ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . TW_CRM_AJAX_ACTION, array( __CLASS__, 'handle_submit' ) );

		add_action( 'wp_ajax_' . self::FORM_DATA_ACTION, array( __CLASS__, 'handle_form_data' ) );
		add_action( 'wp_ajax_nopriv_' . self::FORM_DATA_ACTION, array( __CLASS__, 'handle_form_data' ) );

		add_action( 'wp_ajax_tw_crm_admin_test_connection', array( __CLASS__, 'handle_admin_test_connection' ) );
	}

	/**
	 * Lazy-loaded by the modal on first open: resolves the dmsSetupId and
	 * its location list so the frontend can render (or skip) a picker.
	 * Never trusts/accepts a dmsSetupId from the client — always re-derives
	 * it server-side from the configured store domain.
	 */
	public static function handle_form_data() {
		check_ajax_referer( TW_CRM_NONCE_ACTION, 'nonce' );

		$settings = TW_CRM_Settings::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This form is currently disabled.', 'targetweb' ) ), 403 );
		}

		if ( ! TW_CRM_Settings::is_active_endpoint_configured() ) {
			wp_send_json_error( array( 'message' => __( 'This form is not accepting submissions yet. Please contact us directly.', 'targetweb' ) ), 503 );
		}

		$dms_setup_id = TW_CRM_Api::get_dms_setup_id();
		if ( is_wp_error( $dms_setup_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This form is not accepting submissions yet. Please contact us directly.', 'targetweb' ) ), 503 );
		}

		$locations = TW_CRM_Api::get_dms_setup_locations( $dms_setup_id );
		if ( is_wp_error( $locations ) ) {
			// Locations are a UX nicety (picker), not a hard requirement —
			// still let the customer submit; the location is simply omitted.
			$locations = array();
		}

		wp_send_json_success(
			array(
				'locations' => $locations,
			)
		);
	}

	/**
	 * Handle the lead form submission -> AddCustomerQuotation.
	 */
	public static function handle_submit() {
		check_ajax_referer( TW_CRM_NONCE_ACTION, 'nonce' );

		$settings = TW_CRM_Settings::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This form is currently disabled.', 'targetweb' ) ), 403 );
		}

		// ---- Server-side validation / sanitation of user-entered fields ----
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone_raw  = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$message    = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$location_id = isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '';

		if ( '' === $first_name || '' === $email ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all required fields.', 'targetweb' ) ), 422 );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'targetweb' ) ), 422 );
		}
		if ( ! empty( $settings['phone_required'] ) && '' === $phone_raw ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a phone number.', 'targetweb' ) ), 422 );
		}

		// API docs: phone is "digits only, no formatting needed" — normalize.
		$phone_digits = preg_replace( '/\D+/', '', $phone_raw );

		// ---- Resolve product server-side; never trust client for title/etc ----
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'targetweb' ) ), 422 );
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'targetweb' ) ), 422 );
		}

		if ( ! TW_CRM_Settings::is_active_endpoint_configured() ) {
			TW_CRM_Logger::log( 'Submit blocked: environment not fully configured.' );
			wp_send_json_error( array( 'message' => __( 'This form is not accepting submissions yet. Please contact us directly.', 'targetweb' ) ), 503 );
		}

		// ---- Step 1: resolve dmsSetupId server-side (cached) ----
		$dms_setup_id = TW_CRM_Api::get_dms_setup_id();
		if ( is_wp_error( $dms_setup_id ) ) {
			TW_CRM_Logger::log( 'Submit blocked: could not resolve dmsSetupId', array( 'error' => $dms_setup_id->get_error_message() ) );
			wp_send_json_error( array( 'message' => __( 'This form is not accepting submissions yet. Please contact us directly.', 'targetweb' ) ), 503 );
		}

		// ---- Build the AddCustomerQuotation payload ----
		$payload = array(
			'firstName'         => $first_name,
			'lastName'          => $last_name,
			'email'             => $email,
			'phone'             => $phone_digits,
			'message'           => $message,
			'dmsSetupId'        => $dms_setup_id,
			'externalProductId' => (string) $product->get_id(),
		);
		if ( '' !== $location_id ) {
			$payload['dmsLocationId'] = $location_id;
		}

		/**
		 * Filter the outgoing AddCustomerQuotation payload just before it is sent.
		 *
		 * @param array   $payload Payload data.
		 * @param WC_Product $product Resolved product.
		 * @param array   $settings Full plugin settings.
		 */
		$payload = apply_filters( 'tw_crm_lead_payload', $payload, $product, $settings );

		// ---- Step 3: submit ----
		$result = TW_CRM_Api::submit_quotation( $payload );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$http = ( is_array( $data ) && ! empty( $data['http_code'] ) ) ? (int) $data['http_code'] : 502;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $http >= 400 ? $http : 502 );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * Admin-only: exercise GetDmsSetupId (+ GetDmsSetupLocations) for the
	 * settings page's "Test connection" button. Always bypasses the cache.
	 */
	public static function handle_admin_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'targetweb' ) ), 403 );
		}
		check_ajax_referer( 'tw_crm_admin_test', 'nonce' );

		$env = isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : '';
		if ( ! in_array( $env, TW_CRM_ENVIRONMENTS, true ) ) {
			$env = TW_CRM_Settings::get_active_environment();
		}

		$dms_setup_id = TW_CRM_Api::get_dms_setup_id( $env, true );
		if ( is_wp_error( $dms_setup_id ) ) {
			wp_send_json_error( array( 'message' => $dms_setup_id->get_error_message() ) );
		}

		$locations = TW_CRM_Api::get_dms_setup_locations( $dms_setup_id, $env, true );
		if ( is_wp_error( $locations ) ) {
			wp_send_json_success(
				array(
					'dmsSetupId'     => $dms_setup_id,
					'locationsError' => $locations->get_error_message(),
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'dmsSetupId' => $dms_setup_id,
				'locations'  => $locations,
			)
		);
	}
}
