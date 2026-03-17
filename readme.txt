===  Jezpress WooCommerce Delivery Dates ===
Contributors: jezpress
Tags: woocommerce, delivery, delivery dates, time slots, carriers, checkout
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.1.0
License: Proprietary

Customer delivery date and time slot selection at WooCommerce checkout, with carrier, zone, and schedule management.

== Description ==

Jezpress WooCommerce Delivery Dates lets your customers select a delivery date and time slot at checkout. Delivery schedules, carriers, and shipping zone mappings are managed in the WordPress admin under WooCommerce > Delivery Dates.

**Features:**
* Customer date and time slot selection on the classic WooCommerce checkout
* jQuery UI Datepicker for date selection — restricts selectable dates to configured delivery days
* Dynamic checkout — delivery dates refresh automatically when the shipping address or method changes
* Delivery fields hidden until a shipping address is entered
* Carrier management — add, edit, and map carriers to WooCommerce shipping zones
* Schedule Definitions — configure named weekly recurring patterns with per-day time slots; slots are created on-demand (no manual generation required)
* Max orders per slot with real-time booking count tracking
* Time slot labels displayed as "From HH:MM to HH:MM" format
* Delivery date shown in the admin order page, customer order detail page, and all WooCommerce emails
* Delivery Date column in the WooCommerce orders list
* Configurable settings: maximum future days, required/optional, checkout section label
* JezPress license and auto-update integration

**Note:** WooCommerce Blocks checkout is not supported. Requires the classic checkout shortcode.

== Installation ==

1. Upload the `jezpress-woo-delivery-dates` folder to `/wp-content/plugins/`.
2. Activate the plugin in *Plugins > Installed Plugins*.
3. Activate your license key under *WooCommerce > Delivery Dates > License*.
4. Go to *WooCommerce > Delivery Dates > Carriers* to add carriers and map them to shipping zones.
5. Go to *WooCommerce > Delivery Dates > Schedules* to create schedule definitions with days and time slots.
6. Configure general options under *WooCommerce > Delivery Dates > Settings*.

== Changelog ==

= 1.1.0 =
* Added Schedule Definitions — named weekly recurring patterns with per-day time slots and order cutoff times.
* Slots are now created on-demand from schedule definitions; no manual slot generation required.
* Available delivery dates are derived dynamically from active schedule definitions and the max_future_days setting.
* Added carrier-to-shipping-zone mapping — delivery dates filter automatically based on the customer's WooCommerce shipping zone.
* Delivery fields now refresh dynamically when the shipping address or method changes (WooCommerce updated_checkout integration).
* Delivery date and time slot fields are hidden until a valid shipping address is entered.
* Time slot labels now display as "From HH:MMam to HH:MMpm" format.
* Replaced General Settings tab label from "General Settings" to "Settings".
* Removed "Show Carrier Selection" and "Minimum Days in Advance" settings options.
* Checkout date picker now uses jQuery UI Datepicker with self-contained CSS (no admin theme dependency).
* Asset versions use filemtime() for automatic cache busting.

= 1.0.0 =
* Initial release.
