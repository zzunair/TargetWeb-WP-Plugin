# Lead Form module ("Request Information")

Part of the [TargetWeb](../../README.md) plugin. Owns the "Request
Information" modal + lead form on the homepage, product pages, or any
public page, and submits it to the **real TargetWeb API** described in
`WordPress-Integration-API-Reference.doc`. Replaces the theme's third-party
`targetWeb.js` / `displayQuotationForm()` flow.

## The TargetWeb API this plugin integrates with

Three fixed routes, called in this order, all under one per-environment
**Base URL**:

| Step | Route | Purpose |
|---|---|---|
| 1 | `POST {baseUrl}/api/Shopify/GetDmsSetupId` | Resolve this store to a `dmsSetupId` GUID, using the `store-url` header. |
| 2 | `POST {baseUrl}/api/Shopify/GetDmsSetupLocations` | Get that setup's pickup/service locations, using the `dmsSetup-id` header. |
| 3 | `POST {baseUrl}/api/CustomerQuotation/AddCustomerQuotation` | Submit the lead — `dmsSetupId` and `externalProductId` go in the body. |

Quirks from the reference doc that this plugin already accounts for:

- **Step 1's response is PascalCase** (`Entity`/`Status`/`Message`), not the
  camelCase envelope steps 2–3 use, and its `Content-Type` header may say
  `text/plain` even though the body is JSON. The plugin parses the body as
  JSON regardless and reads the capitalized keys only for this call.
- **Step 2's `status` field is always `0`** on a normal response — it does
  not signal success/failure there. The plugin relies on the HTTP status
  code instead. An empty `entityList` just means "no locations configured".
- **Missing/invalid headers throw a generic HTTP 500**, not a clean 400. The
  plugin never calls out with an empty `store-url` or `dmsSetup-id` — if the
  Base URL or store domain isn't configured, it fails gracefully in WordPress
  instead of hitting the API with bad headers.

Steps 1 and 2 are cached (12h / 6h, via WordPress transients) since they
rarely change, and re-fetched automatically once the cache expires or admin
settings are saved. Step 3 always calls out fresh.

## What this module does

- Binds to the existing `#tw-request-info-btn` button — **no theme changes
  required** to start using it.
- Renders its own modal + form (into `#tw-crm-popup` / `#leads-form-div`,
  reusing those elements if the theme already outputs them).
- Fields: **First Name*** , Last Name, **Email***, Phone (recommended, can be
  made required), Message, and a **Location** picker that only appears when
  a store has more than one configured location (per the API docs, a single
  location is auto-assigned server-side, so the picker is skipped then).
- Validates on the client and again on the server.
- If a WooCommerce product is present, resolves its ID server-side as
  `externalProductId` — never trusts the browser for it. On the homepage
  or any non-product page the field is omitted and the lead still submits.
- Resolves `dmsSetupId` server-side from the configured store domain —
  never accepts one from the browser.
- Fails gracefully (with a clear message, both admin-side and in the modal)
  if the Base URL / store domain aren't configured yet, or the store isn't
  registered in TargetWeb.
- Does **not** load `targetWeb.js` and does **not** call
  `displayQuotationForm()`.

## Folder structure

```
modules/lead-form/
  module.php                         Loaded automatically by ../../targetweb.php
  includes/
    class-tw-crm-settings.php        Options, environments, admin settings page
    class-tw-crm-api.php             GetDmsSetupId / GetDmsSetupLocations / AddCustomerQuotation
    class-tw-crm-frontend.php        Enqueue, shortcode, do_action button
    class-tw-crm-ajax.php            wp_ajax handlers (form data, submit, admin test)
    class-tw-crm-logger.php          Non-production-only debug logging
  assets/
    css/tw-crm-lead-form.css
    js/tw-crm-lead-form.js           Modal open/close + form + location picker + submit
```

## Setup steps

1. **Install the plugin.** Copy/zip the top-level `targetweb` folder into
   `wp-content/plugins/` and activate **TargetWeb** in WP Admin → Plugins —
   this module loads automatically as part of it. WooCommerce is optional;
   the form works on the homepage without product pages.

2. **Open settings.** Go to **Products → TargetWeb CRM** in wp-admin
   (or **Settings → TargetWeb CRM** if WooCommerce is not installed).

3. **Enable the feature.** Check *Enable feature*. This is on by default.

4. **Pick the active environment.** Choose `dev`, `qa`, `staging`, or
   `production`. This single setting controls which Base URL both the
   frontend and the server use for all three API calls.

5. **Set the store identifier / shop domain.** This is sent as the
   `store-url` header for step 1 — it **must match exactly** what's stored
   in TargetWeb's setup record for this dealer, or the store won't resolve
   and the form will fail gracefully with a "not accepting submissions yet"
   message. Migrated automatically from the theme's `targetcrm_shop_domain`
   Customizer setting if left blank.

6. **Add the Base URL for each environment as they're provided.** Only a
   Base URL is needed per environment — the three routes above are fixed
   and baked into the plugin, nothing else to configure per route.

   Until real URLs are supplied, leave these blank — the plugin will show
   "Not configured" in admin and the modal will show a friendly message
   instead of silently failing or throwing a raw 500 from the API.

   Alternatively, a Base URL can be locked via a `wp-config.php` constant
   (this overrides the database value), e.g.:

   ```php
   define( 'TW_CRM_PRODUCTION_BASE_URL', 'https://api.example.com' );
   ```

   Naming pattern: `TW_CRM_{ENV}_BASE_URL` where `{ENV}` is `DEV`, `QA`,
   `STAGING`, or `PRODUCTION`.

   You can also intercept the resolved config via the
   `tw_crm_environment_config` filter, or the outgoing
   `AddCustomerQuotation` payload/request via `tw_crm_lead_payload` /
   `tw_crm_request_args`.

7. **Use "Test connection".** On the settings page, pick an environment and
   click **Test connection** — it calls `GetDmsSetupId` (bypassing the
   cache) and, if that resolves, `GetDmsSetupLocations`, showing you the
   resolved `dmsSetupId` and location list right in wp-admin. Use this to
   verify a Base URL + store domain combination before testing the frontend.

   On failure, it also shows an **expandable "error details" panel** with
   the exact URL that was called, the HTTP status code, the raw response
   body, and — if the request never reached the server at all — the
   underlying transport/cURL error (e.g. DNS failure, TLS/certificate
   problem, timeout, connection refused). This is what to check first when
   a given environment (e.g. staging or production) fails while another
   (e.g. QA) works: it usually points at one of:
   - **No/blank Base URL** for that specific environment (shown right in
     the result — double check you saved the URL for *that* environment,
     not just the active one).
   - **A different host/IP being blocked** by a firewall or WAF on the API
     side — look for a transport error rather than an HTTP status.
   - **TLS/SSL certificate issues** on that environment's host — shows up
     as a `cURL error 60` transport message.
   - **A non-JSON or unexpected response body** (e.g. an HTML error page
     from a load balancer/WAF instead of the API's JSON) — visible in the
     raw response body panel.

8. **(Optional) CTA text, phone requirement, debug logging.** Adjust as
   needed. Debug logging only ever writes to the PHP error log outside of
   the `production` environment, even if left on.

9. **Verify.** The settings page header shows **Active environment**,
   **Active Base URL**, and **Store URL header** so you can confirm what
   will be used before testing the frontend.

## Using the button

### Theme modal (popup)

Put this id on the button that opens the form:

```html
<button type="button" id="tw-request-info-btn" data-product-id="123">Request Information</button>
```

And this popup shell in the theme (TargetWeb-WP-Theme uses a designed version of this):

```html
<div id="tw-crm-popup">
  <div id="leads-form-div"></div>
</div>
```

`data-tw-crm-open` also works if the button id is already used elsewhere.

### Simple in-page form (no popup)

Homepage / lead-gen themes (e.g. Lead-Gen-1) and product themes that keep
the form on the page (e.g. Virtual-showroom) should omit the button and
mark the mount as inline:

```html
<div id="tw-crm-popup" data-tw-crm-inline>
  <div id="leads-form-div"></div>
</div>
```

Or output it with `[tw_crm_lead_form]` / `do_action( 'tw_crm_render_form' )`.
If there is no `#tw-request-info-btn`, the plugin auto-detects the simple form.

- **Plugin modal** (default): the plugin builds `#tw-crm-app-popup` and
  never reuses theme markup.
- **Theme**: pick this on **Products → TargetWeb CRM**. Works for both a
  theme popup and a simple in-page form.
- **If you want the plugin to render the button itself**, use either:
  - Shortcode: `[tw_crm_request_info_button]`
  - Template code: `do_action( 'tw_crm_render_button' );`

  Both output markup compatible with the theme's existing CSS
  (`id="tw-request-info-btn"`, `class="tw-sp-cta"`,
  `data-product`/`data-product-id`).

## Migrating off the theme's duplicate code

Once this plugin is active and verified, the theme can drop its duplicates:

1. Remove the `<script src="https://digitalinnovationweb.z19.web.core.windows.net/Extention/targetWeb.js">` enqueue.
2. Remove the `displayQuotationForm()` call and any code that populates
   `#leads-form-div` from that third-party script.
3. Remove the theme's own open/close JS for `#tw-crm-popup` (the plugin's JS
   now owns this — it reuses the existing `#tw-crm-popup` markup if present,
   or builds its own if the theme's `#tw-crm-popup` div is also removed).
4. Optionally remove the theme's own `#tw-crm-popup` markup entirely — the
   plugin builds an equivalent shell automatically if it's missing.
5. Optionally stop rendering the theme's own CTA button and switch to
   `do_action( 'tw_crm_render_button' )` (or the shortcode) so CTA text/
   visibility is controlled from **Products → TargetWeb CRM** instead of the
   Customizer.
6. The `targetcrm_shop_domain` Customizer setting can be removed once the
   *Store identifier* field on the plugin's settings page has a value saved
   (it was only used as a one-time migration fallback).
7. `targetcrm_client_id` (used by the separate Constellation `widget.js`
   chat widget) is **out of scope** — leave it as-is, this plugin does not
   touch it.

## Payload sent to `AddCustomerQuotation`

`dmsSetupId` and `externalProductId` are resolved **server-side** — never
trusted from the browser:

```json
{
  "firstName": "Jane",
  "lastName": "Doe",
  "email": "jane@example.com",
  "phone": "5551234567",
  "message": "Interested in this unit, is it still available?",
  "dmsSetupId": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "externalProductId": "123",
  "dmsLocationId": "b1e1a2c3-...-..."
}
```

`dmsLocationId` is only included when the resolved setup has more than one
location and the customer picked one from the dropdown; it's omitted when
there's exactly one (TargetWeb auto-assigns it) or zero.

## Safety notes

- Nothing initializes/calls out if the active environment has no Base URL
  or store domain configured — the modal shows a friendly message and
  disables the submit button instead of triggering the API's generic 500
  for missing headers.
- All JS is defensive: missing DOM nodes (e.g. no `#tw-crm-popup`, no
  `#tw-request-info-btn` on a given page) never throw.
- The plugin loads its assets on public frontend pages (homepage included)
  whenever the feature is enabled. Missing DOM nodes never throw.
- All output is escaped; all input is sanitized/validated server-side
  regardless of client-side checks.
- `dmsSetupId` and the location list are cached via transients and never
  accepted from the client on submit — always re-derived server-side from
  the configured store domain.
