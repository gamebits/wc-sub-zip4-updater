# AGENTS.md

## Cursor Cloud specific instructions

This repo is **not** a standalone app. It is a set of standalone PHP scripts that
are meant to be dropped into an existing **WordPress + WooCommerce (+ HPOS)** site
and executed with **WP-CLI** (`wp eval-file <script>.php`). There is no package
manifest, build step, or automated test suite. See `README.md` for the product
description and per-script usage.

A full WordPress + WooCommerce dev stack is pre-installed in the VM snapshot and
the seeded test data persists in it. Only the items below are non-obvious.

### Services / runtime (must be started each session — not auto-started)

- **MariaDB** (WordPress database): start with `sudo service mariadb start`.
- **WordPress dev site**: served by the PHP built-in server. Start with
  `php -S 0.0.0.0:8088 -t /var/www/wordpress` (admin: `admin` / `admin123` at
  `http://localhost:8088/wp-admin`). Only needed for browser/GUI work; the
  scripts themselves run via WP-CLI and do not need the web server.

### Key locations

- WordPress install: `/var/www/wordpress` (run all `wp` commands from there).
- Repo scripts stay in `/workspace`; invoke them with
  `wp eval-file /workspace/<script>.php` from `/var/www/wordpress`.

### Non-obvious gotchas

- **DB host must be `127.0.0.1`, not `localhost`.** In this VM `/var/run` is a real
  directory (not a symlink to `/run`), so the PHP mysqli default socket path
  (`/var/run/mysqld/mysqld.sock`) does not match MariaDB's actual socket
  (`/run/mysqld/mysqld.sock`). `wp-config.php` is configured to use TCP
  `127.0.0.1` to avoid this; keep it that way.
- **`shop_subscription` order type.** The scripts target WooCommerce
  *Subscriptions* (`type = 'shop_subscription'`), which is a paid extension that
  is **not** installed. A dev-only mu-plugin at
  `/var/www/wordpress/wp-content/mu-plugins/zip4-test-subscription-type.php`
  registers that order type so `wc_get_order()` can hydrate the seeded test
  subscriptions and the audit scripts list per-row output. This is test
  scaffolding for the environment only and is not part of the repo.
- **Seeded test data** lives directly in the HPOS tables `wp_wc_orders` /
  `wp_wc_order_addresses` (ids 1001–1005), covering 5-digit ZIPs, an existing
  ZIP+4, a billing-only fallback, a unit-designator-in-address_1 case, and a
  non-active/non-US row. Re-insert them if the DB is reset.

### What can / cannot be tested end-to-end here

- **Testable without external accounts (default modes):**
  `wp eval-file /workspace/wc-sub-zip4-updater.php` runs in **dry-run/audit** mode
  by default (no API calls, no DB writes), and
  `wp eval-file /workspace/secondary-address-scan.php` is pure SQL. Both run fully.
  `standardize-zip4.php` loads its WooCommerce hooks cleanly inside the live site.
- **Requires external/paid credentials (cannot run live here):** the **live**
  update path (`$is_dry_run = false` in `wc-sub-zip4-updater.php`) calls the USPS
  Address Validation API v3 (OAuth 2.0) at `https://apis.usps.com` and needs a
  USPS Developer Portal client id/secret. Real `shop_subscription` records also
  require the paid WooCommerce Subscriptions extension.

### Lint

- There is no configured linter. Use PHP's syntax checker: `php -l <file>.php`.
