# Parts Portal

Creates and keeps in sync a **"Part Finder" Page** + **main-menu link** that
embeds the TargetWeb Parts Portal on WooCommerce sites — the WordPress
counterpart of the automation `PartsPortalService` already does for Shopify
via its Admin GraphQL API.

## How it works

Shopify dealers give TargetWeb an Admin **OAuth token**, so the backend can
create the Page/menu directly via Shopify's Admin GraphQL API.

WordPress dealers only ever give TargetWeb a **WooCommerce consumer
key/secret**, which only authorizes the `wc/v3` REST API (products/orders) —
it cannot create a Page or a nav-menu item.

So instead, TargetWeb calls **directly into this WordPress site** (by the
domain already on file for this dealer) whenever a dealer saves or enables
Parts Portal in the Digital Innovation admin, and this module does the
`wp_insert_post()`/nav-menu work locally, in that same request:

```
Dealer saves/enables Parts Portal in TargetWeb
                 |
                 v
POST {this site}/wp-json/targetweb/v1/parts-portal/sync
Header: Site-Url: https://this-site.com
Body:   { "enable": true, "iframeSrc": "https://.../EmbeddedParts?store=..." }
                 |
                 v
wp_insert_post()/wp_update_post() the "part-finder" Page ([tw_parts_portal] shortcode)
+ wp_update_nav_menu_item() on the site's nav menu
                 |
                 v
Response: { "pageId": 123, "pageUrl": "...", "menuItemId": 456 }
(read directly by TargetWeb - no separate "report back" call)
```

**Security model:** the same one already used by the TargetWeb Locations
plugin's `/locations` endpoint — no shared secret, no stored credential.
The request must present a `Site-Url` header equal to this site's own
`home_url()`. Since TargetWeb is calling this site directly (the domain
already on file for this dealer), that's enough to know the request is
meant for this site.

## What it does

- Exposes `POST /wp-json/targetweb/v1/parts-portal/sync` (see
  `includes/class-tw-parts-sync.php`), called on-demand only — no WP-Cron,
  no polling.
- Creates or updates a `part-finder` Page containing a `[tw_parts_portal]`
  shortcode. Rendered via a shortcode callback (not raw Page content) so the
  markup is never at the mercy of WordPress stripping `<iframe>`/`<script>`
  tags from a saved post.
- Adds a "Part Finder" link to the site's primary nav menu (or the first
  menu it can find, if no theme location is assigned yet), unless one
  already exists.
- If disabled, un-publishes the Page (draft, never deleted) and removes the
  menu link — both re-appear automatically the next time it's enabled.
- Enqueues `assets/js/tw-parts-portal.js` only on that page — the
  WooCommerce equivalent of the Shopify theme's old `targetWeb.js`:
  - iframe resize passthrough (unchanged/generic)
  - infinite-scroll "load more" passthrough (unchanged/generic)
  - add-to-cart via the **WooCommerce Store API**
    (`/wp-json/wc/store/v1/cart/add-item`) instead of Shopify's
    `/cart/add.js`, keyed by the WooCommerce product id the iframe sends
    (WooCommerce products sync as `"simple"`, so there's no separate variant
    id the way Shopify has one).

## Configuration

None. There is nothing to set up — **Products → TargetWeb Parts Portal** in
WP-Admin is a read-only status page showing the current Page/menu state,
purely for visibility.
