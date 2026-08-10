# WordPress Plugins

This repository is a source monorepo containing **independent WordPress
plugins**. Each plugin is self-contained and installed separately — this
repo is not itself a single installable plugin.

## Plugins

| Plugin | Location | What it does | Docs |
|---|---|---|---|
| **TW Smart Collections** | [`tw-smart-collections/`](tw-smart-collections/tw-smart-collections.php) | Shopify-style smart collections for WooCommerce — define condition rules (price, stock, tag, brand, etc.) and matching products are auto-assigned to a real product category. | [docs/tw-smart-collections.md](docs/tw-smart-collections.md) |
| **TargetWeb** | [`targetweb/`](targetweb/README.md) | Modular plugin for TargetWeb's WooCommerce integrations. Currently ships a "Request Information" lead-form module; built to grow via additional self-contained modules. | [targetweb/README.md](targetweb/README.md) |

Both plugins are self-contained folders — each one is a complete, installable
WordPress plugin with its own bootstrap file, and each has its own copy of
the update checker library (see [Automatic updates](#automatic-updates-via-github)
below).

## Installing a plugin from this repo

Each plugin has its own install steps in its docs (linked above), but in general:

1. **TW Smart Collections** — copy/zip the entire `tw-smart-collections/`
   folder into `wp-content/plugins/` and activate it.
2. **TargetWeb** — copy/zip the entire `targetweb/` folder into
   `wp-content/plugins/` and activate it; its modules (e.g. Lead Form) load
   automatically.

## Automatic updates via GitHub

Both plugins self-update straight from this repo's GitHub Releases, using the
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
library — no WordPress.org listing needed. Once installed, each plugin shows
the normal WP-Admin "update available" notice and one-click update on the
**Plugins** page, just like a WordPress.org plugin.

### How it works

- This is a monorepo with **two plugins**, so a single tag/release covers
  both. Each plugin vendors its own copy of `plugin-update-checker` at
  `<plugin>/vendor/plugin-update-checker/` and only watches for its **own**
  release asset (`tw-smart-collections.zip` / `targetweb.zip`), so the two
  plugins update independently even though they share one release.
- `.github/workflows/release.yml` builds both ZIPs and attaches them to a
  GitHub Release automatically whenever a `v*` tag is pushed.

### Releasing a new version

1. Bump the `Version` header in the plugin file(s) you changed (also bump
   `TW_VERSION` in `targetweb/targetweb.php` if that's the one you touched).
2. Commit to `main`.
3. Tag and push:
   ```bash
   git tag v1.2.0
   git push origin v1.2.0
   ```
4. GitHub Actions builds `tw-smart-collections.zip` and `targetweb.zip` and
   publishes them on a new GitHub Release for that tag — nothing else to do.
5. Sites running these plugins pick up the update within ~12 hours
   automatically, or immediately if you force a check (see each plugin's docs
   for testing steps).

Since one tag covers both plugins, keep it simple: bump **both** plugins'
version numbers together (even the one that didn't change) so their local
headers never fall behind the shared tag — otherwise WordPress can keep
re-offering the "same" update forever.

## Adding a new plugin to this repo

Give it its own top-level folder (mirroring `targetweb/`) with its own
`README.md`, then add a row to the table above so it's easy to find. Plugins
in this repo don't share code with each other — if functionality needs to be
shared, prefer building it as a module inside `targetweb/` instead of a new
top-level plugin (see [targetweb/README.md](targetweb/README.md) for the
module conventions). If it should also self-update from GitHub, vendor
`plugin-update-checker` into it the same way and add it to
`.github/workflows/release.yml`.
