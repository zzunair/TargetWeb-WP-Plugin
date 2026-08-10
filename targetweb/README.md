# TargetWeb

WordPress/WooCommerce plugin for TargetWeb's store integrations. Built as a
small **module framework**: the plugin itself only discovers and loads
modules, and every feature lives in its own self-contained folder under
`modules/`. New functionality is added as a new module, without touching
the other modules or the plugin bootstrap.

- **Plugin Name:** TargetWeb
- **Author:** TargetWeb
- **Requires:** PHP 7.4+

## Folder structure

```
targetweb/
  targetweb.php              Plugin bootstrap — discovers & loads every module
  README.md                  This file
  vendor/
    plugin-update-checker/     Vendored library that powers self-updates from GitHub
  modules/
    lead-form/                "Request Information" lead capture modal (WooCommerce product pages)
      module.php               Module bootstrap
      includes/                Module-specific classes
      assets/                  Module-specific CSS/JS
      README.md                Module-specific documentation
    <next-module>/             Future features go here, one folder each
      module.php
      ...
```

## How the module system works

`targetweb.php` does nothing feature-specific. On load, it:

1. Defines shared constants: `TW_VERSION`, `TW_FILE`, `TW_DIR`, `TW_URL`,
   `TW_MODULES_DIR`.
2. Globs `modules/*/module.php` and `require_once`s every match it finds.

That's the entire contract — a module is just a folder containing a
`module.php`. Nothing else is required to be registered anywhere else, so
adding a feature later never means editing `targetweb.php`.

### Adding a new module

1. Create `modules/<your-module>/module.php`.
2. Inside it, define your own constants prefixed uniquely to that module
   (e.g. `TW_CRM_*` for `lead-form`) so multiple modules never collide.
3. Hook your setup on `plugins_loaded` (or `init`, `admin_menu`, etc. as
   appropriate) — don't run side effects at the top level of the file,
   since all modules load during the same request.
4. If you need an activation/deactivation hook, register it against the
   **main plugin file**, not your module file, since WordPress only fires
   those hooks for the plugin's actual entry file:
   ```php
   register_activation_hook( TW_FILE, 'your_module_activate' );
   ```
5. Keep module-owned assets/includes inside your own module folder
   (`plugin_dir_path( __FILE__ )` / `plugin_dir_url( __FILE__ )` inside your
   `module.php` correctly resolve to your module's own folder, even nested).
6. Add a `README.md` inside your module folder documenting it, and link it
   from the table below.

### Naming convention

Every module should use a short, unique prefix for its PHP constants,
option names, class names, and AJAX/nonce actions, to avoid collisions
between modules and with WordPress core/other plugins (e.g. `TW_CRM_` for
the lead-form module).

## Modules

| Module | Folder | What it does |
|---|---|---|
| Lead Form | [`modules/lead-form/`](modules/lead-form/README.md) | "Request Information" modal + form on WooCommerce single product pages, submitting to the TargetWeb CRM API (`GetDmsSetupId` / `GetDmsSetupLocations` / `AddCustomerQuotation`). Replaces the theme's old third-party `targetWeb.js` / `displayQuotationForm()` flow. |

More modules will be added here as they're built — see each module's own
README for its setup steps and configuration.

## Installation

1. Copy/zip this entire `targetweb` folder into `wp-content/plugins/`.
2. Activate **TargetWeb** in WP Admin → Plugins.
3. Configure each module from its own admin page — see the module table
   above for links to their docs.

## Updates

This plugin self-updates from this repo's GitHub Releases (see the root
[README.md](../README.md#automatic-updates-via-github) for how releases are
published, and how to bump the version and cut a new one). Once installed
from a release ZIP, WP-Admin → **Plugins** will show a normal "update
available" notice whenever a new tag is released.
