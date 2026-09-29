<?php
/**
 * Settings: environment selector, per-environment Base URL, CTA defaults,
 * shop identifier, and the admin settings page (incl. a "Test Connection"
 * action that exercises the real TargetWeb endpoints).
 *
 * @package TargetWeb_CRM_Lead_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TW_CRM_Settings {

	/**
	 * Hook admin UI.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Default option value.
	 *
	 * Base URLs are intentionally left blank — TODO: fill in per environment
	 * once the CRM team shares real dev/qa/staging/production URLs. Until
	 * then the form fails gracefully (see class-tw-crm-ajax.php).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => 1,
			'environment'    => 'dev',
			'cta_show'       => 1,
			'cta_text'       => 'Request Information',
			'shop_domain'    => '',
			'phone_required' => 0,
			'debug_mode'     => 0,
			'modal_source'       => 'plugin',
			'default_product_id' => '',
			'environments'   => array(
				'dev'        => array( 'base_url' => '' ),
				'qa'         => array( 'base_url' => '' ),
				'staging'    => array( 'base_url' => '' ),
				'production' => array( 'base_url' => '' ),
			),
		);
	}

	/**
	 * Merged, migrated settings.
	 *
	 * Falls back to legacy theme Customizer values the first time so sites
	 * upgrading from the theme-only implementation keep working without
	 * re-entering data. Plugin options always win once set.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( TW_CRM_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings = wp_parse_args( $saved, self::defaults() );

		// Deep-merge the environments sub-array so new keys added in later
		// versions don't get lost when reading an older saved option.
		$default_envs = self::defaults()['environments'];
		$settings['environments'] = wp_parse_args(
			isset( $saved['environments'] ) && is_array( $saved['environments'] ) ? $saved['environments'] : array(),
			$default_envs
		);
		foreach ( $default_envs as $env => $env_defaults ) {
			$settings['environments'][ $env ] = wp_parse_args(
				isset( $settings['environments'][ $env ] ) && is_array( $settings['environments'][ $env ] ) ? $settings['environments'][ $env ] : array(),
				$env_defaults
			);
		}

		// One-time migration convenience: theme Customizer -> plugin options,
		// only used when the plugin option itself was never set.
		if ( ! isset( $saved['cta_text'] ) && function_exists( 'get_theme_mod' ) ) {
			$theme_cta_text = get_theme_mod( 'sp_cta_text' );
			if ( is_string( $theme_cta_text ) && '' !== trim( $theme_cta_text ) ) {
				$settings['cta_text'] = sanitize_text_field( $theme_cta_text );
			}
		}
		if ( ! isset( $saved['cta_show'] ) && function_exists( 'get_theme_mod' ) ) {
			$theme_cta_show = get_theme_mod( 'sp_cta_show', null );
			if ( null !== $theme_cta_show ) {
				$settings['cta_show'] = $theme_cta_show ? 1 : 0;
			}
		}
		if ( ! isset( $saved['shop_domain'] ) && function_exists( 'get_theme_mod' ) ) {
			$theme_shop_domain = get_theme_mod( 'targetcrm_shop_domain' );
			if ( is_string( $theme_shop_domain ) && '' !== trim( $theme_shop_domain ) ) {
				$settings['shop_domain'] = sanitize_text_field( $theme_shop_domain );
			}
		}

		return $settings;
	}

	/**
	 * Currently active environment key.
	 *
	 * @return string
	 */
	public static function get_active_environment() {
		$settings = self::get_settings();
		$env      = $settings['environment'];
		return in_array( $env, TW_CRM_ENVIRONMENTS, true ) ? $env : 'dev';
	}

	/**
	 * Resolve a single environment's config, applying a wp-config.php
	 * constant override and the `tw_crm_environment_config` filter on top
	 * of the saved admin value. The constant wins over the admin UI so ops
	 * can lock the production Base URL outside the database if desired.
	 *
	 * TODO: once real Base URLs are provided, either paste them into the
	 * settings screen (Products > TargetWeb CRM) or define the matching
	 * constant below in wp-config.php, e.g.:
	 *   define( 'TW_CRM_PRODUCTION_BASE_URL', 'https://api.example.com' );
	 *
	 * @param string|null $env Environment key, defaults to the active one.
	 * @return array { base_url }
	 */
	public static function get_environment_config( $env = null ) {
		$settings = self::get_settings();
		$env      = $env && in_array( $env, TW_CRM_ENVIRONMENTS, true ) ? $env : self::get_active_environment();

		$config = isset( $settings['environments'][ $env ] ) ? $settings['environments'][ $env ] : array( 'base_url' => '' );

		$const = 'TW_CRM_' . strtoupper( $env ) . '_BASE_URL';
		if ( defined( $const ) ) {
			$config['base_url'] = constant( $const );
		}

		/**
		 * Filter the resolved environment config (e.g. to pull the Base URL
		 * from a secrets manager instead of the database).
		 *
		 * @param array  $config Resolved config for this environment.
		 * @param string $env    Environment key.
		 */
		return apply_filters( 'tw_crm_environment_config', $config, $env );
	}

	/**
	 * Whether the active environment has enough config to attempt a call:
	 * a Base URL (for all 3 endpoints) and a store domain (required by
	 * GetDmsSetupId's `store-url` header).
	 *
	 * @return bool
	 */
	public static function is_active_endpoint_configured() {
		$settings = self::get_settings();
		$config   = self::get_environment_config();
		return '' !== trim( (string) $config['base_url'] ) && '' !== trim( (string) $settings['shop_domain'] );
	}

	/**
	 * Product ID sent as AddCustomerQuotation.externalProductId.
	 * The CRM requires this field and looks the product up — omitting it
	 * causes a server-side NullReferenceException.
	 *
	 * @param WC_Product|null $product Product from the current request, if any.
	 * @return string
	 */
	public static function resolve_external_product_id( $product = null ) {
		if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id = (int) $product->get_id();
			if ( $id > 0 ) {
				return (string) $id;
			}
		}

		$settings = self::get_settings();
		$default  = isset( $settings['default_product_id'] ) ? trim( (string) $settings['default_product_id'] ) : '';
		if ( '' !== $default ) {
			return $default;
		}

		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				array(
					'limit'  => 1,
					'status' => 'publish',
					'return' => 'ids',
				)
			);
			if ( ! empty( $ids[0] ) ) {
				return (string) $ids[0];
			}
		}

		return '';
	}

	/**
	 * Capability required to manage this module.
	 *
	 * @return string
	 */
	public static function capability() {
		return post_type_exists( 'product' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Add submenu under Products when WooCommerce is present, otherwise
	 * under Settings (homepage-only themes often have no Products menu).
	 */
	public static function register_menu() {
		$cap = self::capability();
		if ( post_type_exists( 'product' ) ) {
			add_submenu_page(
				'edit.php?post_type=product',
				'TargetWeb CRM',
				'TargetWeb CRM',
				$cap,
				'tw-crm-settings',
				array( __CLASS__, 'render_page' )
			);
			return;
		}

		add_options_page(
			'TargetWeb CRM',
			'TargetWeb CRM',
			$cap,
			'tw-crm-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings API.
	 */
	public static function register_settings() {
		register_setting(
			'tw_crm_settings_group',
			TW_CRM_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$out['enabled']        = ! empty( $input['enabled'] ) ? 1 : 0;
		$out['cta_show']       = ! empty( $input['cta_show'] ) ? 1 : 0;
		$out['phone_required'] = ! empty( $input['phone_required'] ) ? 1 : 0;
		$out['debug_mode']     = ! empty( $input['debug_mode'] ) ? 1 : 0;

		$modal_source        = isset( $input['modal_source'] ) ? sanitize_key( $input['modal_source'] ) : 'plugin';
		$out['modal_source'] = in_array( $modal_source, array( 'plugin', 'theme', 'inline' ), true ) ? $modal_source : 'plugin';

		$out['default_product_id'] = isset( $input['default_product_id'] )
			? sanitize_text_field( trim( (string) $input['default_product_id'] ) )
			: '';

		$env                = isset( $input['environment'] ) ? sanitize_key( $input['environment'] ) : 'dev';
		$out['environment'] = in_array( $env, TW_CRM_ENVIRONMENTS, true ) ? $env : 'dev';

		$out['cta_text'] = isset( $input['cta_text'] ) ? sanitize_text_field( $input['cta_text'] ) : $out['cta_text'];
		if ( '' === trim( $out['cta_text'] ) ) {
			$out['cta_text'] = 'Request Information';
		}

		$out['shop_domain'] = isset( $input['shop_domain'] ) ? sanitize_text_field( trim( $input['shop_domain'] ) ) : '';

		foreach ( TW_CRM_ENVIRONMENTS as $env_key ) {
			$raw = isset( $input['environments'][ $env_key ] ) && is_array( $input['environments'][ $env_key ] )
				? $input['environments'][ $env_key ]
				: array();

			$out['environments'][ $env_key ] = array(
				'base_url' => isset( $raw['base_url'] ) ? esc_url_raw( trim( $raw['base_url'] ) ) : '',
			);
		}

		// Endpoint config changed — drop cached dmsSetupId/location lookups so
		// the next request re-resolves against the (possibly new) Base URLs.
		foreach ( TW_CRM_ENVIRONMENTS as $env_key ) {
			delete_transient( 'tw_crm_setup_id_' . md5( $env_key . '|' . $out['shop_domain'] ) );
		}

		return $out;
	}

	/**
	 * Render settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$settings      = self::get_settings();
		$active_env    = self::get_active_environment();
		$active_config = self::get_environment_config( $active_env );
		?>
		<div class="wrap">
			<h1>TargetWeb CRM — Request Information Lead Form</h1>
			<p>Configures the “Request Information” modal on the homepage, product pages, or any public page that includes the trigger button. Binds to <code>#tw-request-info-btn</code> — no theme changes required if that id is already present.</p>

			<div class="notice notice-info" style="padding:10px 12px;">
				<p style="margin:0;">
					<strong>Active environment:</strong> <?php echo esc_html( strtoupper( $active_env ) ); ?>
					&nbsp;|&nbsp;
					<strong>Active Base URL:</strong>
					<?php if ( $active_config['base_url'] ) : ?>
						<code><?php echo esc_html( $active_config['base_url'] ); ?></code>
					<?php else : ?>
						<span style="color:#a00;">Not configured — submissions will fail gracefully until a Base URL is set.</span>
					<?php endif; ?>
					&nbsp;|&nbsp;
					<strong>Store URL header:</strong>
					<?php if ( $settings['shop_domain'] ) : ?>
						<code><?php echo esc_html( $settings['shop_domain'] ); ?></code>
					<?php else : ?>
						<span style="color:#a00;">Not set</span>
					<?php endif; ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'tw_crm_settings_group' ); ?>

				<h2 class="title">General</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable feature</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[enabled]" value="1" <?php checked( (int) $settings['enabled'], 1 ); ?>>
								Enable the Request Information button/modal on the frontend (homepage, product pages, and any other public page)
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tw_crm_environment">Active environment</label></th>
						<td>
							<select name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[environment]" id="tw_crm_environment">
								<?php foreach ( TW_CRM_ENVIRONMENTS as $env_key ) : ?>
									<option value="<?php echo esc_attr( $env_key ); ?>" <?php selected( $settings['environment'], $env_key ); ?>><?php echo esc_html( strtoupper( $env_key ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Frontend and server submission both use this environment's Base URL below.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">CTA show/hide</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[cta_show]" value="1" <?php checked( (int) $settings['cta_show'], 1 ); ?>>
								Show the button when rendered via shortcode / <code>do_action('tw_crm_render_button')</code>
							</label>
							<p class="description">Has no effect on a button the theme already renders directly (e.g. the existing <code>#tw-request-info-btn</code> markup) — only controls the plugin's own renderer.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tw_crm_cta_text">CTA button text</label></th>
						<td>
							<input type="text" class="regular-text" id="tw_crm_cta_text" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[cta_text]" value="<?php echo esc_attr( $settings['cta_text'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">Modal source</th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[modal_source]" value="plugin" <?php checked( $settings['modal_source'], 'plugin' ); ?>>
									Use the <strong>plugin modal</strong> (built and styled by this plugin)
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[modal_source]" value="theme" <?php checked( $settings['modal_source'], 'theme' ); ?>>
									Use the <strong>theme</strong> (plugin injects the form; theme may show it as a modal <em>or</em> as a simple in-page form)
								</label>
								<label style="display:block;">
									<input type="radio" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[modal_source]" value="inline" <?php checked( $settings['modal_source'], 'inline' ); ?>>
									Force <strong>simple in-page form</strong> (never treat it as a popup)
								</label>
							</fieldset>
							<div class="notice notice-info inline" style="margin:12px 0 0;padding:10px 12px;">
								<p style="margin:0 0 8px;"><strong>Theme hook IDs</strong> — add these to any theme to link the form:</p>
								<ol style="margin:0 0 0 1.3em;">
									<li>
										Put this id on the button that should open the form:
										<code><?php echo esc_html( TW_CRM_TRIGGER_ID ); ?></code>
										<pre style="background:#f6f7f7;padding:8px 10px;margin:6px 0 10px;overflow:auto;">&lt;button type="button" id="<?php echo esc_html( TW_CRM_TRIGGER_ID ); ?>" data-product-id="123"&gt;Request Information&lt;/button&gt;</pre>
									</li>
									<li>
										Optional: any element with <code>data-tw-crm-open</code> also opens the form (useful if you already used the id elsewhere).
									</li>
									<li>
										<strong>Theme modal:</strong> keep the button above, plus this popup shell:
										<pre style="background:#f6f7f7;padding:8px 10px;margin:6px 0 10px;overflow:auto;">&lt;div id="<?php echo esc_html( TW_CRM_THEME_MODAL_ID ); ?>"&gt;
  &lt;div id="<?php echo esc_html( TW_CRM_FORM_MOUNT_ID ); ?>"&gt;&lt;/div&gt;
&lt;/div&gt;</pre>
									</li>
									<li>
										<strong>Simple in-page form</strong> (no popup — Lead-Gen-1 style): omit the button and add <code>data-tw-crm-inline</code>:
										<pre style="background:#f6f7f7;padding:8px 10px;margin:6px 0 0;overflow:auto;">&lt;div id="<?php echo esc_html( TW_CRM_THEME_MODAL_ID ); ?>" data-tw-crm-inline&gt;
  &lt;div id="<?php echo esc_html( TW_CRM_FORM_MOUNT_ID ); ?>"&gt;&lt;/div&gt;
&lt;/div&gt;</pre>
										Or output it with <code>[tw_crm_lead_form]</code> / <code>do_action('tw_crm_render_form')</code>.
										If there is no trigger button, the plugin auto-detects the simple form.
									</li>
								</ol>
								<p class="description" style="margin:8px 0 0;">
									<code>data-product-id</code> is optional on the button. The TargetWeb API still <strong>requires</strong> an <code>externalProductId</code>, so set a Default product ID below for homepage / non-product pages.
								</p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tw_crm_default_product_id">Default product ID</label></th>
						<td>
							<input type="text" class="regular-text" id="tw_crm_default_product_id" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[default_product_id]" value="<?php echo esc_attr( isset( $settings['default_product_id'] ) ? $settings['default_product_id'] : '' ); ?>" placeholder="e.g. 123">
							<p class="description">
								Required by the TargetWeb API as <code>externalProductId</code>. Used on the homepage and any page that is not a WooCommerce product.
								Must be a real product ID the CRM can look up on this store. Leaving it blank is what causes <em>“Object reference not set to an instance of an object.”</em> on homepage submits.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tw_crm_shop_domain">Store identifier / shop domain</label></th>
						<td>
							<input type="text" class="regular-text" id="tw_crm_shop_domain" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[shop_domain]" value="<?php echo esc_attr( $settings['shop_domain'] ); ?>" placeholder="e.g. https://your-store.com">
							<p class="description">
								Sent as the <code>store-url</code> header on every lookup. <strong>Must match exactly</strong> what's stored in TargetWeb's setup record for this dealer, or the store won't resolve.
								Migrated automatically from the theme's <code>targetcrm_shop_domain</code> Customizer setting if left blank.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Phone number</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[phone_required]" value="1" <?php checked( (int) $settings['phone_required'], 1 ); ?>>
								Require phone number
							</label>
							<p class="description">The API docs mark phone as "recommended", not required — off by default.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Debug logging</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[debug_mode]" value="1" <?php checked( (int) $settings['debug_mode'], 1 ); ?>>
								Log request/response details to the PHP error log
							</label>
							<p class="description">Only ever logs outside of the <strong>production</strong> environment, even if left enabled.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Environment Base URLs</h2>
				<p class="description">
					<strong>TODO:</strong> Paste in the real Base URL for each environment as they're shared by the CRM team.
					The plugin always calls these three fixed routes under the Base URL — nothing else to configure per route:
					<code>/api/Shopify/GetDmsSetupId</code>, <code>/api/Shopify/GetDmsSetupLocations</code>, <code>/api/CustomerQuotation/AddCustomerQuotation</code>.
					A Base URL can also be locked via a <code>wp-config.php</code> constant like <code>TW_CRM_PRODUCTION_BASE_URL</code> (see plugin README), which overrides whatever is entered here.
				</p>

				<table class="form-table" role="presentation">
					<?php foreach ( TW_CRM_ENVIRONMENTS as $env_key ) : ?>
						<?php $env_settings = $settings['environments'][ $env_key ]; ?>
						<tr>
							<th scope="row">
								<label for="tw_crm_<?php echo esc_attr( $env_key ); ?>_base_url"><?php echo esc_html( strtoupper( $env_key ) ); ?></label>
								<?php if ( $env_key === $settings['environment'] ) : ?>
									<br><span class="badge" style="background:#2271b1;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;">ACTIVE</span>
								<?php endif; ?>
							</th>
							<td>
								<input type="url" class="regular-text" id="tw_crm_<?php echo esc_attr( $env_key ); ?>_base_url"
									name="<?php echo esc_attr( TW_CRM_OPTION ); ?>[environments][<?php echo esc_attr( $env_key ); ?>][base_url]"
									value="<?php echo esc_attr( $env_settings['base_url'] ); ?>"
									placeholder="https://api-<?php echo esc_attr( $env_key ); ?>.example.com">
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr>
			<h2>Test connection</h2>
			<p>Calls <code>GetDmsSetupId</code> (and then <code>GetDmsSetupLocations</code> if that resolves) against the environment picked below, bypassing the cache, so you can verify the store is registered before going live.</p>
			<p>
				<label for="tw-crm-test-env">Environment:&nbsp;</label>
				<select id="tw-crm-test-env">
					<?php foreach ( TW_CRM_ENVIRONMENTS as $env_key ) : ?>
						<option value="<?php echo esc_attr( $env_key ); ?>" <?php selected( $active_env, $env_key ); ?>><?php echo esc_html( strtoupper( $env_key ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button button-secondary" id="tw-crm-test-btn">Test connection</button>
			</p>
			<div id="tw-crm-test-result" style="display:none;padding:10px 12px;border-radius:4px;max-width:760px;"></div>

			<script>
			(function () {
				var btn = document.getElementById('tw-crm-test-btn');
				var envSelect = document.getElementById('tw-crm-test-env');
				var resultBox = document.getElementById('tw-crm-test-result');
				if (!btn) { return; }

				function esc(str) {
					return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
						return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
					});
				}

				// Renders the { url, http_code, wp_error_code, wp_error_message, raw_body, ... }
				// debug payload attached to WP_Error data, so staging/production-only
				// failures (wrong host, TLS/cert issue, WAF block, different response
				// shape, etc.) are visible instead of hidden behind a generic message.
				function renderDebug(title, debug) {
					if (!debug || typeof debug !== 'object') { return ''; }
					var rows = [];
					if (debug.url) { rows.push(['URL called', debug.url]); }
					if (debug.http_code !== undefined) { rows.push(['HTTP status', debug.http_code]); }
					if (debug.wp_error_code) { rows.push(['Transport error code', debug.wp_error_code]); }
					if (debug.wp_error_message) { rows.push(['Transport error message', debug.wp_error_message]); }
					if (debug.status !== undefined) { rows.push(['Response "status" field', debug.status]); }
					if (debug.store_url) { rows.push(['store-url header sent', debug.store_url]); }
					if (debug.base_url) { rows.push(['Base URL', debug.base_url]); }
					if (debug.environment) { rows.push(['Environment', debug.environment]); }
					if (!rows.length && !debug.raw_body) { return ''; }

					var html = '<details style="margin-top:8px;" open><summary style="cursor:pointer;font-weight:600;">' + esc(title) + '</summary>';
					html += '<table style="width:100%;margin-top:6px;font-size:12px;border-collapse:collapse;">';
					rows.forEach(function (r) {
						html += '<tr><td style="padding:3px 8px;color:#646970;white-space:nowrap;vertical-align:top;">' + esc(r[0]) + '</td>' +
							'<td style="padding:3px 8px;word-break:break-all;"><code>' + esc(r[1]) + '</code></td></tr>';
					});
					html += '</table>';
					if (debug.raw_body) {
						html += '<div style="margin-top:6px;color:#646970;font-size:12px;">Raw response body:</div>' +
							'<pre style="background:#1d2327;color:#f0f0f1;padding:8px;border-radius:3px;overflow:auto;max-height:220px;font-size:12px;white-space:pre-wrap;word-break:break-all;">' + esc(debug.raw_body) + '</pre>';
					}
					html += '</details>';
					return html;
				}

				function renderEnvironment(env) {
					if (!env) { return ''; }
					return '<div style="margin-bottom:8px;font-size:12px;color:#646970;">' +
						'Tested <strong>' + esc((env.environment || '').toUpperCase()) + '</strong> — Base URL: <code>' + esc(env.baseUrl || '(not set)') + '</code>' +
						' — store-url: <code>' + esc(env.storeUrl || '(not set)') + '</code></div>';
				}

				btn.addEventListener('click', function () {
					btn.disabled = true;
					btn.textContent = 'Testing…';
					resultBox.style.display = 'block';
					resultBox.style.background = '#f6f7f7';
					resultBox.style.border = '1px solid #dcdcde';
					resultBox.style.color = '#111';
					resultBox.innerHTML = 'Contacting TargetWeb…';

					var body = new URLSearchParams();
					body.append('action', 'tw_crm_admin_test_connection');
					body.append('nonce', '<?php echo esc_js( wp_create_nonce( 'tw_crm_admin_test' ) ); ?>');
					body.append('environment', envSelect.value);

					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (res) { return res.json(); })
						.then(function (json) {
							var d = (json && json.data) || {};
							var html = renderEnvironment(d.environment);

							if (json && json.success) {
								var lines = ['dmsSetupId: ' + (d.dmsSetupId || '(none)')];
								if (d.locationsError) {
									lines.push('Locations: error (' + (d.locationsCode || 'unknown') + ') — ' + d.locationsError);
								} else if (Array.isArray(d.locations)) {
									lines.push('Locations found: ' + d.locations.length);
									d.locations.forEach(function (loc) {
										lines.push('  • ' + (loc.name || '(unnamed)') + (loc.city ? ' — ' + loc.city + (loc.state ? ', ' + loc.state : '') : ''));
									});
								}
								resultBox.style.background = d.locationsError ? '#fff8e5' : '#ecfdf5';
								resultBox.style.border = d.locationsError ? '1px solid #f0c33c' : '1px solid #a7f3d0';
								html += '<pre style="white-space:pre-line;margin:0;font-family:inherit;">' + esc(lines.join('\n')) + '</pre>';
								if (d.locationsError) {
									html += renderDebug('GetDmsSetupLocations error details', d.locationsDebug);
								}
							} else {
								var msg = d.message || 'Connection test failed.';
								resultBox.style.background = '#fef2f2';
								resultBox.style.border = '1px solid #fecaca';
								html += '<div><strong>' + esc(d.errorCode || 'Error') + ':</strong> ' + esc(msg) + '</div>';
								html += renderDebug('GetDmsSetupId error details', d.debug);
							}
							resultBox.innerHTML = html;
						})
						.catch(function (err) {
							resultBox.style.background = '#fef2f2';
							resultBox.style.border = '1px solid #fecaca';
							resultBox.innerHTML = 'Connection test failed (network/JS error) — ' + esc(err && err.message ? err.message : err);
						})
						.finally(function () {
							btn.disabled = false;
							btn.textContent = 'Test connection';
						});
				});
			})();
			</script>

			<hr>
			<h2>Payload sent to <code>AddCustomerQuotation</code></h2>
			<p><code>dmsSetupId</code> and <code>externalProductId</code> are resolved <strong>server-side</strong> (never trusted from the browser):</p>
			<pre style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;max-width:640px;overflow:auto;">{
  "firstName": "Jane",
  "lastName": "Doe",
  "email": "jane@example.com",
  "phone": "5551234567",
  "message": "Optional message",
  "dmsSetupId": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "externalProductId": "123",
  "dmsLocationId": "b1e1a2c3-...-..."
}</pre>
			<p class="description"><code>dmsLocationId</code> is only included when the store has more than one location and the customer picked one; it's omitted when there's exactly one (TargetWeb auto-assigns it).</p>
		</div>
		<?php
	}
}
