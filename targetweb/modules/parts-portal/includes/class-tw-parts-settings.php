<?php
/**
 * Read-only status page. This module takes zero configuration - TargetWeb
 * pushes directly to this site's own /wp-json/targetweb/v1/parts-portal/sync
 * route whenever a dealer saves/enables their Parts Portal, so there is
 * nothing to set up here. This page exists purely so a dealer/support can
 * see the current Page/menu state at a glance.
 *
 * @package TargetWeb\PartsPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_Parts_Settings {

	/**
	 * Hook admin UI.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Add submenu under Products, next to the Lead Form module's own page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			'TargetWeb Parts Portal',
			'TargetWeb Parts Portal',
			'manage_woocommerce',
			'tw-parts-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the read-only status page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$page      = class_exists( 'WooCommerce' ) ? get_page_by_path( TW_PARTS_PAGE_SLUG, OBJECT, 'page' ) : null;
		$menu_link = null;

		if ( $page ) {
			$menu_id   = null;
			$locations = get_nav_menu_locations();
			foreach ( array( 'primary', 'main', 'main-menu', 'header', 'top' ) as $preferred ) {
				if ( ! empty( $locations[ $preferred ] ) ) {
					$menu_id = $locations[ $preferred ];
					break;
				}
			}
			if ( ! $menu_id && ! empty( $locations ) ) {
				$menu_id = reset( $locations );
			}
			if ( ! $menu_id ) {
				$menus   = wp_get_nav_menus();
				$menu_id = ! empty( $menus ) ? $menus[0]->term_id : 0;
			}
			if ( $menu_id ) {
				foreach ( wp_get_nav_menu_items( $menu_id ) as $item ) {
					if ( 'post_type' === $item->type && (int) $item->object_id === (int) $page->ID ) {
						$menu_link = $item;
						break;
					}
				}
			}
		}
		?>
		<div class="wrap">
			<h1>TargetWeb — Parts Portal</h1>

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning"><p>WooCommerce is not active — the Part Finder page cannot be created until WooCommerce is active.</p></div>
			<?php endif; ?>

			<p>This module needs no configuration. TargetWeb creates and updates the "Part Finder" page and its menu link automatically whenever a dealer saves or enables Parts Portal — no theme or menu edits needed on your end.</p>

			<table class="widefat" style="max-width:640px;">
				<tbody>
					<tr>
						<th style="width:160px;">Part Finder page</th>
						<td>
							<?php if ( $page ) : ?>
								<?php if ( 'publish' === $page->post_status ) : ?>
									<a href="<?php echo esc_url( get_permalink( $page->ID ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $page->ID ) ); ?></a>
								<?php else : ?>
									<span style="color:#a00;">Created but not published (portal is currently disabled in TargetWeb)</span>
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#6d7175;">Not created yet — appears automatically once Parts Portal is saved/enabled in TargetWeb.</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Menu link</th>
						<td><?php echo $menu_link ? 'Added to your site menu' : 'Not added'; ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
