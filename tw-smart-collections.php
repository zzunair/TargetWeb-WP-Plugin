<?php
/**
 * Plugin Name: TW Smart Collections
 * Description: Shopify-style smart collections for WooCommerce. Define condition rules (price, stock, tag, brand, etc.) and products are auto-assigned to a category. Membership is kept current via product-save hooks and a scheduled cron re-evaluation.
 * Version: 1.1.0
 * Author: Zivo Digitals
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * MODEL: Physical assignment. Each rule set targets a real product_cat term.
 * Matching products are added to that term (so you get real archive URLs, menus,
 * counts). Non-matching products are removed ONLY from categories this plugin
 * manages (tracked per-product), so manual category assignments are never touched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TWSC_OPTION', 'twsc_rulesets' );
define( 'TWSC_CRON_HOOK', 'twsc_evaluate_all' );
define( 'TWSC_MANAGED_META', '_twsc_managed_cats' ); // per-product: term_ids this plugin assigned

/* -------------------------------------------------------------------------
 * 1. ATTRIBUTE DEFINITIONS  (single source of truth)
 *
 * Maps the full Shopify condition set to WooCommerce. `type` drives which
 * operators show in the UI and how the evaluator compares values.
 * ---------------------------------------------------------------------- */
function twsc_attributes() {
	return array(
		'category'         => array( 'label' => 'Category',          'type' => 'taxonomy', 'taxonomy' => 'product_cat' ),
		'vendor'           => array( 'label' => 'Vendor / Brand',    'type' => 'taxonomy', 'taxonomy' => 'product_brand' ),
		'tag'              => array( 'label' => 'Tag',               'type' => 'taxonomy', 'taxonomy' => 'product_tag' ),
		'price'            => array( 'label' => 'Price',             'type' => 'number' ),
		'compare_at_price' => array( 'label' => 'Compare at price',  'type' => 'number' ),  // Woo regular price
		'inventory_stock'  => array( 'label' => 'Inventory stock',   'type' => 'number' ),
		'weight'           => array( 'label' => 'Weight',            'type' => 'number' ),
		'title'            => array( 'label' => 'Title',             'type' => 'text' ),
		'variant_title'    => array( 'label' => 'Variant title',     'type' => 'text' ),
		'status'           => array( 'label' => 'Status',            'type' => 'select', 'options' => array( 'publish' => 'Active (published)', 'draft' => 'Draft', 'pending' => 'Pending', 'private' => 'Private' ) ),
		'type'             => array( 'label' => 'Type',              'type' => 'select', 'options' => array( 'simple' => 'Simple', 'variable' => 'Variable', 'grouped' => 'Grouped', 'external' => 'External/Affiliate' ) ),
	);
}

function twsc_operators_for( $type ) {
	switch ( $type ) {
		case 'number':
			return array(
				'eq'  => 'is equal to',
				'neq' => 'is not equal to',
				'gt'  => 'is greater than',
				'lt'  => 'is less than',
			);
		case 'text':
			return array(
				'eq'          => 'is equal to',
				'neq'         => 'is not equal to',
				'contains'    => 'contains',
				'ncontains'   => 'does not contain',
				'starts_with' => 'starts with',
				'ends_with'   => 'ends with',
			);
		case 'taxonomy':
		case 'select':
		default:
			return array(
				'eq'  => 'is equal to',
				'neq' => 'is not equal to',
			);
	}
}

/* -------------------------------------------------------------------------
 * 2. ACTIVATION / DEACTIVATION  (cron scheduling)
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( TWSC_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'hourly', TWSC_CRON_HOOK );
	}
} );

register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( TWSC_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, TWSC_CRON_HOOK );
	}
} );

/* -------------------------------------------------------------------------
 * 3. STORAGE
 * ---------------------------------------------------------------------- */
function twsc_get_rulesets() {
	$sets = get_option( TWSC_OPTION, array() );
	return is_array( $sets ) ? $sets : array();
}

function twsc_save_rulesets( $sets ) {
	update_option( TWSC_OPTION, array_values( $sets ) );
}

function twsc_get_ruleset( $id ) {
	foreach ( twsc_get_rulesets() as $set ) {
		if ( $set['id'] === $id ) {
			return $set;
		}
	}
	return null;
}

/* -------------------------------------------------------------------------
 * 4. ADMIN MENU + PAGES
 * ---------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	add_submenu_page(
		'edit.php?post_type=product',
		'Smart Collections',
		'Smart Collections',
		'manage_woocommerce',
		'twsc',
		'twsc_render_admin'
	);
} );

function twsc_render_admin() {
	$action = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';
	echo '<div class="wrap">';
	if ( 'edit' === $action ) {
		twsc_render_edit_form();
	} else {
		twsc_render_list();
	}
	echo '</div>';
}

function twsc_render_list() {
	$sets = twsc_get_rulesets();
	$new_url  = esc_url( admin_url( 'edit.php?post_type=product&page=twsc&view=edit' ) );
	$run_url  = wp_nonce_url( admin_url( 'admin-post.php?action=twsc_run_now' ), 'twsc_run_now' );

	echo '<h1 class="wp-heading-inline">Smart Collections</h1>';
	echo ' <a href="' . $new_url . '" class="page-title-action">Add New</a>';
	echo ' <a href="' . esc_url( $run_url ) . '" class="page-title-action">Re-evaluate all now</a>';

	if ( isset( $_GET['twsc_msg'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['twsc_msg'] ) ) . '</p></div>';
	}

	echo '<p>Each collection maps a set of conditions to a product category. Matching products are added automatically; membership refreshes on product save and hourly via cron.</p>';

	echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
	echo '<th>Collection (category)</th><th>Match</th><th>Conditions</th><th>Products</th><th></th>';
	echo '</tr></thead><tbody>';

	if ( empty( $sets ) ) {
		echo '<tr><td colspan="5">No smart collections yet. Click <strong>Add New</strong> to create one.</td></tr>';
	}

	foreach ( $sets as $set ) {
		$term  = get_term( (int) $set['term_id'], 'product_cat' );
		$count = ( $term && ! is_wp_error( $term ) ) ? $term->count : 0;
		$edit  = esc_url( admin_url( 'edit.php?post_type=product&page=twsc&view=edit&id=' . urlencode( $set['id'] ) ) );
		$del   = wp_nonce_url( admin_url( 'admin-post.php?action=twsc_delete&id=' . urlencode( $set['id'] ) ), 'twsc_delete_' . $set['id'] );
		$view  = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term ) : '';
		echo '<tr>';
		echo '<td><strong>' . esc_html( $set['title'] ) . '</strong></td>';
		echo '<td>' . ( 'any' === $set['match'] ? 'ANY (OR)' : 'ALL (AND)' ) . '</td>';
		echo '<td>' . count( $set['conditions'] ) . '</td>';
		echo '<td>' . (int) $count . '</td>';
		$actions = array();
		if ( $view && ! is_wp_error( $view ) ) {
			$actions[] = '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">View</a>';
		}
		$actions[] = '<a href="' . $edit . '">Edit</a>';
		$actions[] = '<a href="' . esc_url( $del ) . '" onclick="return confirm(\'Delete this smart collection? The category term itself is kept; only the rule is removed.\')">Delete</a>';
		echo '<td>' . implode( ' | ', $actions ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

function twsc_render_edit_form() {
	$id  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
	$set = $id ? twsc_get_ruleset( $id ) : null;
	if ( ! $set ) {
		$set = array( 'id' => '', 'title' => '', 'term_id' => 0, 'match' => 'all', 'conditions' => array( array( 'attribute' => 'price', 'operator' => 'gt', 'value' => '' ) ) );
	}

	$attrs     = twsc_attributes();
	$nonce     = wp_create_nonce( 'twsc_save' );
	$attrs_js  = wp_json_encode( array_map( function ( $a ) { return array( 'type' => $a['type'], 'options' => isset( $a['options'] ) ? $a['options'] : null ); }, $attrs ) );
	$ops_js    = wp_json_encode( array(
		'number'   => twsc_operators_for( 'number' ),
		'text'     => twsc_operators_for( 'text' ),
		'taxonomy' => twsc_operators_for( 'taxonomy' ),
		'select'   => twsc_operators_for( 'select' ),
	) );

	echo '<h1>' . ( $id ? 'Edit' : 'Add' ) . ' Smart Collection</h1>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="twsc_save">';
	echo '<input type="hidden" name="twsc_nonce" value="' . esc_attr( $nonce ) . '">';
	echo '<input type="hidden" name="id" value="' . esc_attr( $set['id'] ) . '">';

	echo '<table class="form-table" role="presentation">';

	// Title (this IS the category — created/reused automatically by name)
	echo '<tr><th scope="row"><label for="twsc_title">Collection name</label></th><td>';
	echo '<input name="title" id="twsc_title" type="text" class="regular-text" required value="' . esc_attr( $set['title'] ) . '">';
	$existing_term = ( (int) $set['term_id'] > 0 ) ? get_term( (int) $set['term_id'], 'product_cat' ) : null;
	if ( $existing_term && ! is_wp_error( $existing_term ) ) {
		echo '<p class="description">Products matching the conditions below are auto-added to the <strong>' . esc_html( $existing_term->name ) . '</strong> product category. Renaming here renames that category.</p>';
	} else {
		echo '<p class="description">A product category with this name is used if it exists, or created automatically. Matching products are added to it.</p>';
	}
	echo '</td></tr>';

	// Match mode
	echo '<tr><th scope="row">Products must match</th><td>';
	echo '<label><input type="radio" name="match" value="all"' . checked( $set['match'], 'all', false ) . '> ALL conditions (AND)</label>&nbsp;&nbsp;';
	echo '<label><input type="radio" name="match" value="any"' . checked( $set['match'], 'any', false ) . '> ANY condition (OR)</label>';
	echo '</td></tr>';

	echo '</table>';

	// Conditions builder
	echo '<h2>Conditions</h2>';
	echo '<div id="twsc-conditions"></div>';
	echo '<p><button type="button" class="button" id="twsc-add">+ Add condition</button></p>';

	echo '<p class="description">Value hints: for Category / Vendor / Tag enter the term <strong>name or slug</strong> (e.g. <code>husqvarna</code>). For numbers use plain digits (e.g. <code>200</code>).</p>';

	submit_button( $id ? 'Save changes' : 'Create smart collection' );
	echo '</form>';

	// Inline JS: dynamic condition rows
	$existing = wp_json_encode( array_values( $set['conditions'] ) );
	?>
	<script>
	(function () {
		var ATTRS = <?php echo $attrs_js; ?>;
		var OPS   = <?php echo $ops_js; ?>;
		var EXISTING = <?php echo $existing; ?>;
		var wrap = document.getElementById('twsc-conditions');

		function attrOptions(selected) {
			var html = '';
			for (var key in ATTRS) {
				html += '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>' + attrLabel(key) + '</option>';
			}
			return html;
		}
		function attrLabel(key) {
			var labels = <?php echo wp_json_encode( array_map( function ( $a ) { return $a['label']; }, $attrs ) ); ?>;
			return labels[key] || key;
		}
		function opOptions(attrKey, selected) {
			var type = ATTRS[attrKey] ? ATTRS[attrKey].type : 'text';
			var ops = OPS[type] || OPS['text'];
			var html = '';
			for (var k in ops) {
				html += '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + ops[k] + '</option>';
			}
			return html;
		}
		function valueField(attrKey, value) {
			var def = ATTRS[attrKey];
			value = (value === undefined || value === null) ? '' : String(value);
			if (def && def.type === 'select' && def.options) {
				var html = '<select name="conditions[__i__][value]">';
				for (var v in def.options) {
					html += '<option value="' + v + '"' + (v === value ? ' selected' : '') + '>' + def.options[v] + '</option>';
				}
				return html + '</select>';
			}
			var t = (def && def.type === 'number') ? 'number' : 'text';
			var step = t === 'number' ? ' step="any"' : '';
			return '<input type="' + t + '"' + step + ' name="conditions[__i__][value]" value="' + value.replace(/"/g, '&quot;') + '">';
		}

		function rowHtml(i, cond) {
			cond = cond || { attribute: 'price', operator: 'gt', value: '' };
			return '<div class="twsc-row" data-i="' + i + '" style="margin:6px 0;display:flex;gap:8px;align-items:center;">' +
				'<select class="twsc-attr" name="conditions[' + i + '][attribute]">' + attrOptions(cond.attribute) + '</select>' +
				'<select class="twsc-op" name="conditions[' + i + '][operator]">' + opOptions(cond.attribute, cond.operator) + '</select>' +
				'<span class="twsc-val">' + valueField(cond.attribute, cond.value).replace(/__i__/g, i) + '</span>' +
				'<button type="button" class="button twsc-del">Remove</button>' +
				'</div>';
		}

		var idx = 0;
		function addRow(cond) {
			var div = document.createElement('div');
			div.innerHTML = rowHtml(idx, cond);
			var node = div.firstChild;
			wrap.appendChild(node);
			idx++;
		}

		// re-render operator + value when attribute changes
		wrap.addEventListener('change', function (e) {
			if (e.target.classList.contains('twsc-attr')) {
				var row = e.target.closest('.twsc-row');
				var i = row.getAttribute('data-i');
				var attrKey = e.target.value;
				row.querySelector('.twsc-op').innerHTML = opOptions(attrKey, '');
				row.querySelector('.twsc-val').innerHTML = valueField(attrKey, '').replace(/__i__/g, i);
			}
		});
		wrap.addEventListener('click', function (e) {
			if (e.target.classList.contains('twsc-del')) {
				e.target.closest('.twsc-row').remove();
			}
		});
		document.getElementById('twsc-add').addEventListener('click', function () { addRow(); });

		if (EXISTING && EXISTING.length) {
			EXISTING.forEach(function (c) { addRow(c); });
		} else {
			addRow();
		}
	})();
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * 5. SAVE / DELETE / RUN handlers
 * ---------------------------------------------------------------------- */
add_action( 'admin_post_twsc_save', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['twsc_nonce'] ) || ! wp_verify_nonce( $_POST['twsc_nonce'], 'twsc_save' ) ) {
		wp_die( 'Permission denied.' );
	}

	$id    = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
	$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
	$match = ( isset( $_POST['match'] ) && 'any' === $_POST['match'] ) ? 'any' : 'all';

	$valid_attrs = array_keys( twsc_attributes() );
	$conditions  = array();
	if ( ! empty( $_POST['conditions'] ) && is_array( $_POST['conditions'] ) ) {
		foreach ( wp_unslash( $_POST['conditions'] ) as $c ) {
			$attr = sanitize_key( $c['attribute'] ?? '' );
			if ( ! in_array( $attr, $valid_attrs, true ) ) {
				continue;
			}
			$conditions[] = array(
				'attribute' => $attr,
				'operator'  => sanitize_key( $c['operator'] ?? 'eq' ),
				'value'     => sanitize_text_field( $c['value'] ?? '' ),
			);
		}
	}

	if ( '' === $title || empty( $conditions ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=twsc&view=edit&id=' . urlencode( $id ) . '&twsc_msg=' . rawurlencode( 'A collection name and at least one condition are required.' ) ) );
		exit;
	}

	// The collection name IS the category. Resolve it:
	// - editing with an existing term -> rename that term if the name changed (keeps membership/URL)
	// - otherwise -> reuse a category with this name, or create one.
	$existing_set = $id ? twsc_get_ruleset( $id ) : null;
	$term         = 0;
	if ( $existing_set && (int) $existing_set['term_id'] > 0 && get_term( (int) $existing_set['term_id'], 'product_cat' ) ) {
		$term = (int) $existing_set['term_id'];
		$cur  = get_term( $term, 'product_cat' );
		if ( $cur && ! is_wp_error( $cur ) && $cur->name !== $title ) {
			wp_update_term( $term, 'product_cat', array( 'name' => $title ) );
		}
	} else {
		$byname = get_term_by( 'name', $title, 'product_cat' );
		if ( $byname && ! is_wp_error( $byname ) ) {
			$term = (int) $byname->term_id;
		} else {
			$created = wp_insert_term( $title, 'product_cat' );
			if ( is_wp_error( $created ) ) {
				wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=twsc&view=edit&id=' . urlencode( $id ) . '&twsc_msg=' . rawurlencode( 'Could not create the category: ' . $created->get_error_message() ) ) );
				exit;
			}
			$term = (int) $created['term_id'];
		}
	}

	$sets = twsc_get_rulesets();
	if ( '' === $id ) {
		$id  = 'set_' . wp_generate_uuid4();
		$sets[] = array();
	}
	$found = false;
	foreach ( $sets as &$set ) {
		if ( ( $set['id'] ?? '' ) === $id ) {
			$set = array( 'id' => $id, 'title' => $title, 'term_id' => $term, 'match' => $match, 'conditions' => $conditions );
			$found = true;
			break;
		}
	}
	unset( $set );
	if ( ! $found ) {
		// the empty placeholder we pushed
		$sets[ count( $sets ) - 1 ] = array( 'id' => $id, 'title' => $title, 'term_id' => $term, 'match' => $match, 'conditions' => $conditions );
	}
	twsc_save_rulesets( $sets );

	// Immediately evaluate the whole catalog against the new/changed rule.
	twsc_sync_all();

	wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=twsc&twsc_msg=' . rawurlencode( 'Saved and applied. "' . $title . '" is live.' ) ) );
	exit;
} );

add_action( 'admin_post_twsc_delete', function () {
	$id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
	if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'twsc_delete_' . $id ) ) {
		wp_die( 'Permission denied.' );
	}
	$sets = array_filter( twsc_get_rulesets(), function ( $s ) use ( $id ) { return ( $s['id'] ?? '' ) !== $id; } );
	twsc_save_rulesets( $sets );
	wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=twsc&twsc_msg=' . rawurlencode( 'Smart collection deleted (category term kept).' ) ) );
	exit;
} );

add_action( 'admin_post_twsc_run_now', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'twsc_run_now' ) ) {
		wp_die( 'Permission denied.' );
	}
	twsc_sync_all();
	wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=twsc&twsc_msg=' . rawurlencode( 'Re-evaluation complete.' ) ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * 6. EVALUATOR
 * ---------------------------------------------------------------------- */
function twsc_product_matches( $product, $ruleset ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	$results = array();
	foreach ( $ruleset['conditions'] as $cond ) {
		$results[] = twsc_check_condition( $product, $cond );
	}
	if ( empty( $results ) ) {
		return false;
	}
	return ( 'any' === $ruleset['match'] ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );
}

function twsc_check_condition( $product, $cond ) {
	$attrs = twsc_attributes();
	$attr  = $cond['attribute'];
	$op    = $cond['operator'];
	$val   = $cond['value'];
	if ( ! isset( $attrs[ $attr ] ) ) {
		return false;
	}
	$type = $attrs[ $attr ]['type'];
	$pid  = $product->get_id();

	// Taxonomy attributes -> has_term (by slug or name)
	if ( 'taxonomy' === $type ) {
		$tax = $attrs[ $attr ]['taxonomy'];
		$has = twsc_has_term_by_name_or_slug( $pid, $tax, $val );
		return ( 'neq' === $op ) ? ! $has : $has;
	}

	// Resolve the actual product value
	switch ( $attr ) {
		case 'price':            $actual = $product->get_price(); break;
		case 'compare_at_price': $actual = $product->get_regular_price(); break;
		case 'inventory_stock':  $actual = $product->get_stock_quantity(); break;
		case 'weight':           $actual = $product->get_weight(); break;
		case 'title':            $actual = $product->get_name(); break;
		case 'status':           $actual = get_post_status( $pid ); break;
		case 'type':             $actual = $product->get_type(); break;
		case 'variant_title':    return twsc_check_variant_title( $product, $op, $val );
		default:                 $actual = '';
	}

	if ( 'number' === $type ) {
		if ( '' === $actual || null === $actual ) {
			return false; // no value -> cannot satisfy a numeric rule
		}
		$a = (float) $actual;
		$b = (float) $val;
		switch ( $op ) {
			case 'eq':  return $a === $b;
			case 'neq': return $a !== $b;
			case 'gt':  return $a > $b;
			case 'lt':  return $a < $b;
		}
		return false;
	}

	// text + select
	return twsc_text_compare( (string) $actual, $op, (string) $val );
}

function twsc_text_compare( $actual, $op, $val ) {
	$a = mb_strtolower( trim( $actual ) );
	$b = mb_strtolower( trim( $val ) );
	switch ( $op ) {
		case 'eq':          return $a === $b;
		case 'neq':         return $a !== $b;
		case 'contains':    return '' !== $b && false !== mb_strpos( $a, $b );
		case 'ncontains':   return '' === $b || false === mb_strpos( $a, $b );
		case 'starts_with': return '' !== $b && 0 === mb_strpos( $a, $b );
		case 'ends_with':   return '' !== $b && mb_substr( $a, - mb_strlen( $b ) ) === $b;
	}
	return false;
}

function twsc_check_variant_title( $product, $op, $val ) {
	if ( ! $product->is_type( 'variable' ) ) {
		return false;
	}
	foreach ( $product->get_children() as $vid ) {
		$variation = wc_get_product( $vid );
		if ( ! $variation ) {
			continue;
		}
		$name = implode( ' ', $variation->get_variation_attributes() );
		if ( twsc_text_compare( $name, $op, $val ) ) {
			return true;
		}
	}
	return false;
}

function twsc_has_term_by_name_or_slug( $pid, $taxonomy, $val ) {
	$val = trim( $val );
	if ( '' === $val ) {
		return false;
	}
	// has_term accepts slug, name, or id; try slug/name directly
	if ( has_term( $val, $taxonomy, $pid ) ) {
		return true;
	}
	// fall back: resolve by name -> slug
	$term = get_term_by( 'name', $val, $taxonomy );
	if ( $term && has_term( $term->slug, $taxonomy, $pid ) ) {
		return true;
	}
	return false;
}

/* -------------------------------------------------------------------------
 * 7. APPLY  (physical assignment + managed-term tracking)
 * ---------------------------------------------------------------------- */

/** Sync one product against every rule set. */
function twsc_sync_product( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return;
	}
	$sets    = twsc_get_rulesets();
	$managed = array_map( 'intval', (array) get_post_meta( $product_id, TWSC_MANAGED_META, true ) );
	$now_managed = array();

	foreach ( $sets as $set ) {
		$term_id = (int) $set['term_id'];
		if ( $term_id <= 0 ) {
			continue;
		}
		if ( twsc_product_matches( $product, $set ) ) {
			wp_set_object_terms( $product_id, array( $term_id ), 'product_cat', true ); // append
			$now_managed[] = $term_id;
		}
	}

	// Remove product from any category WE previously managed but it no longer matches.
	$to_remove = array_diff( $managed, $now_managed );
	foreach ( $to_remove as $term_id ) {
		wp_remove_object_terms( $product_id, (int) $term_id, 'product_cat' );
	}

	update_post_meta( $product_id, TWSC_MANAGED_META, array_values( array_unique( $now_managed ) ) );
}

/** Sync the whole catalog. Batched for large stores. */
function twsc_sync_all() {
	$paged = 1;
	do {
		$q = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 100,
			'paged'          => $paged,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );
		foreach ( $q->posts as $pid ) {
			twsc_sync_product( $pid );
		}
		$paged++;
	} while ( $paged <= $q->max_num_pages );
}

/* -------------------------------------------------------------------------
 * 8. HOOKS  (product save + cron)
 * ---------------------------------------------------------------------- */
add_action( 'woocommerce_update_product', 'twsc_sync_product', 20, 1 );
add_action( 'woocommerce_new_product',    'twsc_sync_product', 20, 1 );
add_action( TWSC_CRON_HOOK,               'twsc_sync_all' );
