# WooCommerce Subscriptions ZIP+4 Updater

This WordPress script identifies any USA-based [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/), then queries the [USPS Address Validation API](https://developers.usps.com/addressesv3) to update the subscription's mailing address to use the [ZIP+4](https://tools.usps.com/zip-code-lookup.htm?byaddress) format. It updates the shipping address only, unless there isn't one, in which case it updates the billing address.

## But why?

Standard five-digit ZIP codes are efficient, but the ZIP+4 system (introduced by the United States Postal Service in [1983](https://facts.usps.com/decoding-the-zip-code/)) acts as a high-definition GPS for mail sorting. While mail will usually arrive with just five digits, the extra four digits significantly optimize the "last mile" of delivery.

When the four optional digits are are appended to the ZIP code, USPS machines can automatically sort your mail into the exact order of the mail carrier's route (known as "[walk sequence](https://pe.usps.com/archive/html/dmmarchive20030810/M050.htm)"). Without it, a piece of mail may require manual sorting at the local post office, which adds time and increases the margin for human error.

Plugins exist to validate addresses when orders are submitted, whereas this script retroactively refines addresses for existing subscriptions.

## Requirements

* WordPress 6.0+ (May 2022 or later)
* WooCommerce 8.0+ (August 2023 or later)
* WooCommerce Subscriptions 5.0+ (March 2023 or later)
* High-Performance Order Storage ([HPOS](https://woocommerce.com/document/high-performance-order-storage/)) enabled (w/ or w/o [compatibility mode](https://woocommerce.com/document/high-performance-order-storage/#synchronization))
* PHP 7.4+ (November 2019 or later)
* MySQL 5.7+ (October 2015 or later) or MariaDB 10.3+ (April 2017 or later)
* USPS API v3 / OAuth 2.0 (January 2026 or later)
* SSH access

## Configuration

Before running `wc-sub-zip4-updater.php`, configure it to your specifications:

### USPS API

You'll need a USPS API client key and secret, both of which are available from the [USPS Developer Portal](https://developers.usps.com/user/apps). If you are already using the [USPS Shipping Method](https://woocommerce.com/products/usps-shipping-method/) extension for WooCommerce, then you likely already have these credentials at `/wp-admin/admin.php?page=wc-settings&tab=shipping&section=usps`.

Provide these credentials in lines 16–17 of the script.

### API & server limits

As of January 2026, USPS API calls are rate-limited to [60 per hour](https://www.smarty.com/blog/usps-api-rate-limit). To ensure this limit is not exceeded (which would cause the API key to be blocked), the script pauses for 62 seconds between each query. If you are one of the elite few who has a higher threshold on your developer account, this rate can be adjusted on line 82.

This means even a small database of a few hundred subscribers will take hours to process, which hits another limit: the hosting server's `max_execution_time`. In my tests, I was able to update 447 addresses in eight hours before the script prematurely stopped.

The script estimates its total run time prior to beginning:

> `### LIVE MODE [Target: ACTIVE ONLY] ###`  
> `Batch Size: 500 | Estimated Completion: 8h 37m`

Fortunately, the script can be run in batches, picking up where it left off.

### Subscription targeting

WooCommerce stores subscriptions in one of [six states](https://woocommerce.com/document/subscriptions/statuses/):

* Active
* Pending
* On Hold
* Pending Cancellation
* Cancelled
* Expired

Depending on which subscriptions you want the script to target, update line 11 of the script with one of the following numerical values (1–3):

1. Active subscriptions only
2. Pending, On Hold, Pending Cancellation, Cancelled & Expired subscriptions only
3. All subscriptions

The script defaults to active only.

### Dry run

The script defaults to performing a dry run that outputs a list of all subscriptions and addresses that need their ZIP codes updated to ZIP+4. This mode, which makes no USPS API queries or database updates, culminates in a summary such as the following:

> Found 123 subscriptions to be updated and skipped 45 subscriptions that already have ZIP+4.

To perform a live run that updates the addresses, change line 14 from `true` to `false`.

## Usage

Take a backup of your database, then upload the script via SFTP or nano to your shell environment and execute it with this WP-CLI command:

`wp eval-file wc-sub-zip4-updater.php`

A log of changes will be displayed in the terminal window and, if possible, saved in `/tmp/usps_update_log.txt`.

## Secondary addresses

If an address has a secondary field, such as an apartment number, that data should be stored in the "Address 2" field. If the customer instead appended it to the "Address 1" field, this script will not return a valid ZIP+4 code.

The `secondary-address-scan.php` script can identify many active subscriptions that have information included in the "Address 1" field that should be manually moved to "Address 2". No configuration or API credentials are necessary — just upload it to your WordPress directory and run it with `wp eval-file`.

## Ongoing fixes

This script is best run once to standardize all existing addresses in your database. To automatically update all new orders as they are made, add `standardize-zip4.php` to a plugin such as [Code Snippets](https://wordpress.org/plugins/code-snippets/). USA-based orders will have their ZIP codes standardized using the ZIP+4 format, and a private note will be added to eligible customers' orders, indicating that this change was made. (This process slows down the checkout experience by about one second.)

Be careful when using this code on high-traffic sites, as this script can also max out your USPS API limits if you receive more than 60 orders an hour.

## Credit

These scripts were entirely vibecoded with [Google Gemini](https://gemini.google.com/) by Ken Gagne and is available under a GNU General Public License (GPL) v2.0.