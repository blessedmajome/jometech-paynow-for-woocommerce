# Paynow Zimbabwe Payment Gateway for WooCommerce (JomeTech Africa build)

A build of the Paynow payment gateway for WooCommerce that keeps working when Paynow's payment notification does not reach your website.

Maintained by [JomeTech Africa](https://jometechafrica.com). Based on the Paynow Zimbabwe plugin, version 1.3.5. This is not an official Paynow release and JomeTech Africa is not affiliated with Paynow.

## The problem it solves

The original plugin marks an order as paid only when Paynow's server calls the shop's result URL. If that call fails (for example a TLS or firewall problem between Paynow and the host), the customer pays, the money arrives, and the order stays on "Pending payment".

This build asks Paynow for the payment status itself:

- when the customer returns to the order received page;
- 2 minutes and 10 minutes after checkout;
- every hour, for unpaid Paynow orders of the last 5 days, including orders WooCommerce cancelled for being unpaid;
- when staff choose **Order actions > Check payment with Paynow** on an order.

The order is only ever changed from Paynow's own signed reply to the poll URL, never from data sent to the result URL.

## Other changes from 1.3.5

- No fatal error when Paynow cannot be reached; the check is retried later.
- The paid amount is compared with the order total. An underpaid order is put on hold for a person to check.
- The status endpoint (`wp-json/paynow/v1/order/ID`) requires the order key.
- Debug details go to WooCommerce > Status > Logs (source `paynow`), not to a web-readable file.
- Classic checkout no longer raises PHP warnings when a transaction starts.

See `changelog.txt` for the full list.

## Requirements

- WordPress with WooCommerce
- A Paynow merchant account with an integration ID and key (USD and/or ZiG)
- Permalinks set to anything other than "Plain"
- WordPress cron running (it runs on site visits by default)

## Install

1. If another Paynow plugin for WooCommerce is installed, deactivate it first. Your Paynow settings are kept: this build reads the same settings.
2. Download this repository as a zip, then in WordPress go to Plugins > Add New > Upload Plugin and upload it.
3. Activate the plugin.
4. Check WooCommerce > Settings > Payments > Paynow and place one small test order.

Do not run this build and another Paynow plugin for WooCommerce at the same time.

## Recovering orders that were paid but stayed unpaid

Open the order, choose **Order actions > Check payment with Paynow** and press Update. If Paynow confirms the payment, the order moves to Processing and a note is added.

## How it was tested

Version 1.3.5.2 was run on a WordPress 6.5.5 and WooCommerce 9.4.2 test shop (PHP 8.3) with a stand-in for Paynow that signs replies the way Paynow does. Every check passed with block and classic checkout, on both WooCommerce order storage modes. The scenarios covered: notification never arrives, notification arrives, forged notification, cancelled, failed and underpaid payments, Paynow unreachable or replying badly, the mobile money waiting page, the hourly re-check, the admin order action, ZiG orders, and installing over the original plugin.

It was then confirmed on a live shop (WordPress 7.1.5, WooCommerce 11.2.2, PHP 8.1) with a real Paynow payment: the order was marked paid with its Paynow reference, and the manual check returned Paynow's live status for older orders. InnBucks, WooCommerce Subscriptions and multisite were not covered, so place one small real order after installing.

## Licence

GPL-2.0-or-later, the same licence as the plugin it is based on. See `LICENSE`.

## Support

JomeTech Africa, Harare, Zimbabwe: https://jometechafrica.com
