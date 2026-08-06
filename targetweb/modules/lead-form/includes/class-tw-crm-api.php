<?php
/**
 * TargetWeb API client — implements the three documented endpoints:
 *
 *   1. POST /api/Shopify/GetDmsSetupId          (store-url -> dmsSetupId)
 *   2. POST /api/Shopify/GetDmsSetupLocations    (dmsSetup-id -> locations)
 *   3. POST /api/CustomerQuotation/AddCustomerQuotation (submit the lead)
 *
 * Source: WordPress-Integration-API-Reference.doc (TargetWeb API Reference).
 *
 * Quirks handled here per that reference doc:
 *   - GetDmsSetupId responds in PascalCase (Entity/Status/Message), not the
 *     camelCase envelope the other two endpoints use, and its Content-Type
 *     header may lie and say text/plain even though the body is JSON.
 *   - GetDmsSetupLocations always returns status: 0 on a normal response —
 *     that field does not signal success/failure there, rely on the HTTP code.
 *   - None of the three endpoints validate required headers before using
 *     them — a missing/blank store-url or dmsSetup-id throws server-side and
 *     comes back as a generic HTTP 500. We avoid ever calling out with an
 *     empty header value; see the guard clauses below.
 *
 * @package TargetWeb_CRM_Lead_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_CRM_Api {

	const ROUTE_GET_DMS_SETUP_ID        = '/api/Shopify/GetDmsSetupId';
	const ROUTE_GET_DMS_SETUP_LOCATIONS = '/api/Shopify/GetDmsSetupLocations';
	const ROUTE_ADD_CUSTOMER_QUOTATION  = '/api/CustomerQuotation/AddCustomerQuotation';

	/** Enum member name required by GetDmsSetupId for this integration. */
	const SETUP_TYPE = 'WordPress';

	const CACHE_SETUP_ID_TTL  = 12 * HOUR_IN_SECONDS;
	const CACHE_LOCATIONS_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Join a base URL with a fixed route path.
	 *
	 * @param string $base_url Environment base URL.
	 * @param string $route    One of the ROUTE_* constants.
	 * @return string Empty string if no base URL is configured.
	 */
	public static function build_url( $base_url, $route ) {
		$base_url = rtrim( trim( (string) $base_url ), '/' );
		if ( '' === $base_url ) {
			return '';
		}
		return $base_url . $route;
	}

	/**
	 * Step 1: resolve the configured store domain to a dmsSetupId GUID.
	 * Cached per environment + store domain, since it rarely changes.
	 *
	 * @param string|null $env           Environment key, defaults to active.
	 * @param bool        $force_refresh Bypass the cache.
	 * @return string|WP_Error GUID string, or WP_Error with a user-safe message.
	 */
	public static function get_dms_setup_id( $env = null, $force_refresh = false ) {
		$env       = ( $env && in_array( $env, TW_CRM_ENVIRONMENTS, true ) ) ? $env : TW_CRM_Settings::get_active_environment();
		$settings  = TW_CRM_Settings::get_settings();
		$config    = TW_CRM_Settings::get_environment_config( $env );
		$base_url  = trim( (string) $config['base_url'] );
		$store_url = trim( (string) $settings['shop_domain'] );

		if ( '' === $base_url ) {
			return new WP_Error( 'tw_crm_no_base_url', __( 'No Base URL is configured for this environment yet.', 'targetweb' ) );
		}
		if ( '' === $store_url ) {
			return new WP_Error( 'tw_crm_no_store_url', __( 'Store identifier / shop domain is not configured yet.', 'targetweb' ) );
		}

		$cache_key = 'tw_crm_setup_id_' . md5( $env . '|' . $store_url );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$url = self::build_url( $base_url, self::ROUTE_GET_DMS_SETUP_ID );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'store-url'    => $store_url,
				),
				'body'    => wp_json_encode( array( 'setupType' => self::SETUP_TYPE ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			TW_CRM_Logger::log( 'GetDmsSetupId transport error', array( 'url' => $url, 'error' => $response->get_error_message() ) );
			return new WP_Error( 'tw_crm_transport', __( 'Could not reach the CRM right now.', 'targetweb' ) );
		}

		// PascalCase-only endpoint; Content-Type may say text/plain even for JSON — parse regardless.
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			TW_CRM_Logger::log( 'GetDmsSetupId unparseable response', array( 'url' => $url, 'raw' => wp_remote_retrieve_body( $response ) ) );
			return new WP_Error( 'tw_crm_bad_response', __( 'Unexpected response while resolving the store setup.', 'targetweb' ) );
		}

		$status  = isset( $body['Status'] ) ? (int) $body['Status'] : 0;
		$entity  = isset( $body['Entity'] ) ? $body['Entity'] : null;
		$message = isset( $body['Message'] ) ? (string) $body['Message'] : '';

		if ( 200 === $status && is_string( $entity ) && '' !== $entity ) {
			set_transient( $cache_key, $entity, self::CACHE_SETUP_ID_TTL );
			return $entity;
		}

		TW_CRM_Logger::log( 'GetDmsSetupId not resolved', array( 'status' => $status, 'message' => $message, 'store_url' => $store_url ) );

		return new WP_Error(
			'tw_crm_setup_not_found',
			$message ? $message : __( 'No TargetWeb setup was found for this store.', 'targetweb' ),
			array( 'status' => $status )
		);
	}

	/**
	 * Step 2: fetch a setup's configured pickup/service locations.
	 * Cached per environment + dmsSetupId.
	 *
	 * @param string      $dms_setup_id  GUID from get_dms_setup_id().
	 * @param string|null $env           Environment key, defaults to active.
	 * @param bool        $force_refresh Bypass the cache.
	 * @return array|WP_Error List of { id, name, city, state, isDefault }.
	 */
	public static function get_dms_setup_locations( $dms_setup_id, $env = null, $force_refresh = false ) {
		$env          = ( $env && in_array( $env, TW_CRM_ENVIRONMENTS, true ) ) ? $env : TW_CRM_Settings::get_active_environment();
		$config       = TW_CRM_Settings::get_environment_config( $env );
		$base_url     = trim( (string) $config['base_url'] );
		$dms_setup_id = trim( (string) $dms_setup_id );

		if ( '' === $dms_setup_id ) {
			return new WP_Error( 'tw_crm_no_setup_id', __( 'Missing dmsSetupId.', 'targetweb' ) );
		}
		if ( '' === $base_url ) {
			return new WP_Error( 'tw_crm_no_base_url', __( 'No Base URL is configured for this environment yet.', 'targetweb' ) );
		}

		$cache_key = 'tw_crm_locations_' . md5( $env . '|' . $dms_setup_id );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$url = self::build_url( $base_url, self::ROUTE_GET_DMS_SETUP_LOCATIONS );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'dmsSetup-id'  => $dms_setup_id,
				),
				'body'    => wp_json_encode( new stdClass() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			TW_CRM_Logger::log( 'GetDmsSetupLocations transport error', array( 'url' => $url, 'error' => $response->get_error_message() ) );
			return new WP_Error( 'tw_crm_transport', __( 'Could not load store locations right now.', 'targetweb' ) );
		}

		// status is always 0 here per the API reference — success/failure is signaled by HTTP code only.
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			TW_CRM_Logger::log( 'GetDmsSetupLocations bad response', array( 'url' => $url, 'code' => $code ) );
			return new WP_Error( 'tw_crm_locations_error', __( 'Could not load store locations right now.', 'targetweb' ) );
		}

		$raw_list  = isset( $body['entityList'] ) && is_array( $body['entityList'] ) ? $body['entityList'] : array();
		$locations = array();
		foreach ( $raw_list as $loc ) {
			if ( ! is_array( $loc ) || empty( $loc['id'] ) ) {
				continue;
			}
			$locations[] = array(
				'id'        => sanitize_text_field( $loc['id'] ),
				'name'      => isset( $loc['name'] ) ? sanitize_text_field( $loc['name'] ) : '',
				'city'      => isset( $loc['city'] ) ? sanitize_text_field( $loc['city'] ) : '',
				'state'     => isset( $loc['stateOrProvince'] ) ? sanitize_text_field( $loc['stateOrProvince'] ) : '',
				'isDefault' => ! empty( $loc['isDefault'] ),
			);
		}

		set_transient( $cache_key, $locations, self::CACHE_LOCATIONS_TTL );

		return $locations;
	}

	/**
	 * Step 3: submit the customer quotation (lead).
	 *
	 * @param array       $payload Prepared payload (firstName, lastName, email,
	 *                              phone, message, dmsSetupId, externalProductId,
	 *                              dmsLocationId?).
	 * @param string|null $env     Environment key, defaults to active.
	 * @return array|WP_Error { message } on success.
	 */
	public static function submit_quotation( $payload, $env = null ) {
		$env      = ( $env && in_array( $env, TW_CRM_ENVIRONMENTS, true ) ) ? $env : TW_CRM_Settings::get_active_environment();
		$config   = TW_CRM_Settings::get_environment_config( $env );
		$base_url = trim( (string) $config['base_url'] );

		if ( '' === $base_url ) {
			return new WP_Error( 'tw_crm_no_base_url', __( 'No Base URL is configured for this environment yet.', 'targetweb' ) );
		}

		$url = self::build_url( $base_url, self::ROUTE_ADD_CUSTOMER_QUOTATION );

		/**
		 * Filter the wp_remote_post() args before AddCustomerQuotation is called.
		 *
		 * @param array $args    Request args.
		 * @param array $payload Payload being sent.
		 * @param array $config  Resolved environment config.
		 */
		$args = apply_filters(
			'tw_crm_request_args',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			),
			$payload,
			$config
		);

		TW_CRM_Logger::log( 'AddCustomerQuotation request', array( 'url' => $url, 'payload' => $payload ) );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			TW_CRM_Logger::log( 'AddCustomerQuotation transport error', array( 'error' => $response->get_error_message() ) );
			return new WP_Error( 'tw_crm_transport', __( 'We could not reach the CRM right now. Please try again shortly.', 'targetweb' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		TW_CRM_Logger::log( 'AddCustomerQuotation response', array( 'code' => $code, 'body' => $body ) );

		$body_status = is_array( $body ) && isset( $body['status'] ) ? (int) $body['status'] : $code;

		if ( $code >= 200 && $code < 300 && 200 === $body_status ) {
			$message = ( is_array( $body ) && ! empty( $body['message'] ) )
				? (string) $body['message']
				: __( 'Quotation Added Successfully', 'targetweb' );
			return array( 'message' => $message );
		}

		$message = '';
		if ( is_array( $body ) ) {
			$message = ! empty( $body['errorMessage'] ) ? (string) $body['errorMessage'] : ( ! empty( $body['message'] ) ? (string) $body['message'] : '' );
		}

		return new WP_Error(
			'tw_crm_quotation_failed',
			$message ? $message : __( 'Sorry, your request could not be submitted. Please try again shortly.', 'targetweb' ),
			array( 'status' => $body_status, 'http_code' => $code )
		);
	}
}
