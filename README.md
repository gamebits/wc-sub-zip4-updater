# WooCommerce ZIP+4

This WordPress plugin standardizes USA mailing addresses on [WooCommerce](https://woocommerce.com/) orders and [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) to the [USPS ZIP+4](https://tools.usps.com/zip-code-lookup.htm?byaddress) format using the [USPS Address Validation API](https://developers.usps.com/addressesv3).

## But why?

Standard five-digit ZIP codes are efficient, but the ZIP+4 system (introduced by the United States Postal Service in [1983](https://facts.usps.com/decoding-the-zip-code/)) acts as a high-definition GPS for mail sorting. While mail will usually arrive with just five digits, the extra four digits significantly optimize the "last mile" of delivery.

When the four optional digits are appended to the ZIP code, USPS machines can automatically sort mail into the exact order of the mail carrier's route (known as "[walk sequence](https://pe.usps.com/archive/html/dmmarchive20030810/M050.htm)"). Without it, a piece of mail may require manual sorting at the local post office, which adds time and increases the margin for human error.

## Requirements

* WordPress 6.0+
* WooCommerce 8.0+
* WooCommerce Subscriptions 5.0+ (optional; required for the Subscriptions tab)
* High-Performance Order Storage ([HPOS](https://woocommerce.com/document/high-performance-order-storage/)) enabled
* PHP 7.4+
* USPS API v3 / OAuth 2.0 credentials

## Installation

1. Copy the `woocommerce-zip4` folder into `wp-content/plugins/`.
2. Activate **WooCommerce ZIP+4** from the WordPress Plugins screen.
3. Open **WooCommerce → ZIP+4** to configure the plugin.

## USPS credentials

The plugin reads credentials automatically:

1. **USPS Shipping Method extension (recommended)** — if installed, active, and configured with REST API Key and Secret at **WooCommerce → Settings → Shipping → USPS**
2. **`wp-config.php` constants** — if the extension is unavailable:

```php
define( 'USPS_CLIENT_ID', 'your-client-id' );
define( 'USPS_CLIENT_SECRET', 'your-client-secret' );
```

If neither source is configured, a warning appears at the top of the plugin settings screen.

Obtain credentials from the [USPS Developer Portal](https://developers.usps.com/user/apps).

## Settings tab

Choose which actions should silently standardize USA ZIP codes:

* New order placed
* Address updated by customer
* Subscription updated by customer
* Address updated by administrator
* Subscription updated by administrator

When a match is found, the plugin updates the address and adds an internal order or subscription note indicating the ZIP+4 change.

## Subscriptions tab

Available when WooCommerce Subscriptions is active. This screen performs a one-time batch update of existing subscriptions whose USA shipping (or billing fallback) addresses still use five-digit ZIP codes.

Select the subscription statuses to include, then click **Process Subscriptions**. The plugin reports real-time progress (for example, `3/457`) while respecting the USPS rate limit of approximately 60 requests per hour.

## Secondary addresses

If an apartment or unit number is stored in Address 1 instead of Address 2, the USPS API may not return a valid ZIP+4. The standalone `secondary-address-scan.php` utility can identify active subscriptions that need manual cleanup. Run it with:

`wp eval-file secondary-address-scan.php`

## API rate limits

USPS API calls are rate-limited to roughly [60 per hour](https://www.smarty.com/blog/usps-api-rate-limit). The subscription batch processor waits 62 seconds between requests. On high-traffic stores, enabling all automatic checkout hooks can also approach this limit.

## Credit

These scripts were originally vibecoded with [Google Gemini](https://gemini.google.com/) by Ken Gagne and are available under a GNU General Public License (GPL) v2.0.
