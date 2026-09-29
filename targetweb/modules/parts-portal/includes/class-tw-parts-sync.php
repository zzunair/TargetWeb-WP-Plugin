<?php
/**
 * Exposes a single REST route that TargetWeb calls directly (on Save/Enable
 * in the Digital Innovation admin) to create/update or hide the storefront
 * "Part Finder" Page + main-menu entry - the WordPress-side equivalent of
 * PartsPortalService.SyncShopifyMenuAsync's GraphQL calls.
 *
 * Security model matches the TargetWeb Locations plugin's existing
 * `/locations` endpoint exactly: no shared secret, no stored credential -
 * the request must simply present a `Site-Url` header equal to this site's
 * own home_url(). Since TargetWeb calls this site directly (by the domain
 * already on file for this dealer), that's enough to know the request is
 * meant for this site, the same way it's enough for /locations.
 *
 * POST /wp-json/targetweb/v1/parts-portal/sync
 *   Headers: Site-Url: https://dealer-site.com
 *   Body:    { "enable": true, "iframeSrc": "https://.../EmbeddedParts?store=..." }
 *   Returns: { "pageId": 123, "pageUrl": "...", "menuItemId": 456 }
 *
 * No cron, no local settings, no stored secret - purely a request handler.
 *
 * @package TargetWeb\PartsPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Parts_Sync {

	const IFRAME_SRC_META_KEY = '_tw_parts_iframe_src';
	const ROUTE_NAMESPACE     = 'targetweb/v1';
	const ROUTE               = '/parts-portal/sync';

	/**
	 * Register the REST route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => '__return_true', // Self-verified below via the Site-Url header, same as the Locations plugin's /locations endpoint.
			)
		);
	}

	/**
	 * REST callback: verify the request is for this site, then create/update
	 * or hide the Part Finder Page + menu item accordingly.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$site_url_header = untrailingslashit( (string) $request->get_header( 'site_url' ) );
		$current_site     = untrailingslashit( home_url( '/' ) );

		if ( '' === $site_url_header || strtolower( $site_url_header ) !== strtolower( $current_site ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid or missing Site-Url header.' ), 403 );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_REST_Response( array( 'message' => 'WooCommerce is not active on this site.' ), 424 );
		}

		$body   = json_decode( $request->get_body(), true );
		$body   = is_array( $body ) ? $body : array();
		$enable = ! empty( $body['enable'] );

		if ( ! $enable ) {
			return self::unpublish();
		}

		$iframe_src = isset( $body['iframeSrc'] ) ? (string) $body['iframeSrc'] : '';
		if ( '' === $iframe_src ) {
			return new WP_REST_Response( array( 'message' => 'Missing iframeSrc.' ), 400 );
		}

		$page = self::ensure_page( $iframe_src );
		if ( is_wp_error( $page ) ) {
			return new WP_REST_Response( array( 'message' => $page->get_error_message() ), 500 );
		}

		$menu_item_id = self::ensure_menu_item( $page['page_id'] );
		if ( is_wp_error( $menu_item_id ) ) {
			// Non-fatal: the Page still exists and is directly reachable, just not menu-linked yet.
			return new WP_REST_Response(
				array(
					'pageId'     => $page['page_id'],
					'pageUrl'    => $page['page_url'],
					'menuItemId' => null,
					'message'    => $menu_item_id->get_error_message(),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'pageId'     => $page['page_id'],
				'pageUrl'    => $page['page_url'],
				'menuItemId' => $menu_item_id,
			),
			200
		);
	}

	/**
	 * Create (once) or update the Page by its known slug, keeping its iframe
	 * src current and making sure it's published. Stateless: identifies the
	 * existing Page (if any) by slug rather than tracking an id in an option.
	 *
	 * @param string $iframe_src Iframe URL to embed.
	 * @return array{page_id:int,page_url:string}|WP_Error
	 */
	private static function ensure_page( $iframe_src ) {
		$existing    = get_page_by_path( TW_PARTS_PAGE_SLUG, OBJECT, 'page' );
		$existing_id = $existing ? (int) $existing->ID : 0;

		$post_args = array(
			'post_title'   => 'Part Finder',
			'post_name'    => TW_PARTS_PAGE_SLUG,
			'post_content' => '[' . TW_PARTS_SHORTCODE . ']',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		if ( $existing_id ) {
			$post_args['ID'] = $existing_id;
			$page_id         = wp_update_post( $post_args, true );
		} else {
			$page_id = wp_insert_post( $post_args, true );
		}

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		update_post_meta( $page_id, self::IFRAME_SRC_META_KEY, $iframe_src );

		return array(
			'page_id'  => (int) $page_id,
			'page_url' => (string) get_permalink( $page_id ),
		);
	}

	/**
	 * Add a menu item pointing at the Page to the site's primary (or first
	 * available) nav menu, unless a link to that Page already exists there.
	 *
	 * @param int $page_id Page to link.
	 * @return int|WP_Error Menu item post ID.
	 */
	private static function ensure_menu_item( $page_id ) {
		$menu_id = self::resolve_menu_id();
		if ( ! $menu_id ) {
			return new WP_Error(
				'tw_parts_no_menu',
				'No nav menu is assigned to a theme location yet — the Part Finder page was created, but add it to a menu manually (Appearance → Menus) once a menu is assigned to a theme location.'
			);
		}

		foreach ( wp_get_nav_menu_items( $menu_id ) as $item ) {
			if ( 'post_type' === $item->type && (int) $item->object_id === (int) $page_id ) {
				return (int) $item->ID; // Already linked (by a previous sync, or manually) - reuse it.
			}
		}

		$menu_item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => 'Part Finder',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page_id,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);

		return is_wp_error( $menu_item_id ) ? $menu_item_id : (int) $menu_item_id;
	}

	/**
	 * Prefer the menu assigned to a common theme location name; fall back to
	 * any registered location, then to any existing menu at all.
	 *
	 * @return int 0 if no menu could be resolved.
	 */
	private static function resolve_menu_id() {
		$locations = get_nav_menu_locations();
		foreach ( array( 'primary', 'main', 'main-menu', 'header', 'top' ) as $preferred ) {
			if ( ! empty( $locations[ $preferred ] ) ) {
				return (int) $locations[ $preferred ];
			}
		}
		if ( ! empty( $locations ) ) {
			return (int) reset( $locations );
		}

		$menus = wp_get_nav_menus();
		return ! empty( $menus ) ? (int) $menus[0]->term_id : 0;
	}

	/**
	 * Hide the Page (draft) and drop the menu item when the portal is
	 * disabled. Never deletes the Page outright, so re-enabling later
	 * doesn't lose anything.
	 *
	 * @return WP_REST_Response
	 */
	private static function unpublish() {
		$existing = get_page_by_path( TW_PARTS_PAGE_SLUG, OBJECT, 'page' );
		if ( ! $existing ) {
			return new WP_REST_Response( array( 'pageId' => null, 'pageUrl' => null, 'menuItemId' => null ), 200 );
		}

		wp_update_post( array( 'ID' => $existing->ID, 'post_status' => 'draft' ) );

		$menu_id = self::resolve_menu_id();
		if ( $menu_id ) {
			foreach ( wp_get_nav_menu_items( $menu_id ) as $item ) {
				if ( 'post_type' === $item->type && (int) $item->object_id === (int) $existing->ID ) {
					wp_delete_post( $item->ID, true );
				}
			}
		}

		return new WP_REST_Response( array( 'pageId' => $existing->ID, 'pageUrl' => null, 'menuItemId' => null ), 200 );
	}
}
