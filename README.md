# TW Smart Collections

Shopify-style smart collections for WooCommerce. Define condition rules and products
auto-assign to a category — with real archive URLs, menus and counts (physical assignment).

## Install

1. Zip the `tw-smart-collections` folder (or upload the folder to `wp-content/plugins/`).
2. In WP admin → **Plugins**, activate **TW Smart Collections**.
3. Go to **Products → Smart Collections**.

## Create a collection

1. **Add New**.
2. Name it, pick the **target category** (matching products land here).
3. Choose **ALL (AND)** or **ANY (OR)**.
4. Add conditions. Full Shopify-equivalent attribute set is supported:

| Condition | Maps to in WooCommerce |
|---|---|
| Category | `product_cat` term |
| Vendor / Brand | `product_brand` term |
| Tag | `product_tag` term |
| Price | active price (`get_price`) |
| Compare at price | regular price (`get_regular_price`) |
| Inventory stock | stock quantity |
| Weight | product weight |
| Title | product name |
| Variant title | variation attribute values (variable products) |
| Status | Active / Draft / Pending / Private |
| Type | Simple / Variable / Grouped / External |

For Category / Vendor / Tag, enter the term **name or slug** (e.g. `husqvarna`).

5. Save — the whole catalog is evaluated immediately.

## How membership stays current

- **On product save** — that product is re-evaluated (`woocommerce_update_product` / `_new_product`).
- **Hourly cron** — the full catalog is re-evaluated, so changes made outside a normal
  save (price synced by your import, stock hitting zero) get caught.
- **Re-evaluate all now** — a button on the list screen to force a full sync on demand.

## Safe removal

The plugin tracks (per product) only the categories **it** assigned. If a product stops
matching a rule, it's removed **only** from plugin-managed categories — manual category
assignments are never touched.

## Import pipeline note

If your supplier import writes products via WooCommerce (firing `woocommerce_new_product` /
`_update_product`), rules apply automatically on each import. If it writes directly to the
DB, run **Re-evaluate all now** or rely on the hourly cron afterward.

## Notes / limits (v1)

- "Type" maps to the WooCommerce product type (simple/variable/…), not a Shopify-style
  merchandising type field. Use **Category** for merchandising groups.
- Real cron requires site traffic (WP-Cron) or a system cron hitting `wp-cron.php`.
- Deleting a smart collection removes the rule only; the category term is kept.
