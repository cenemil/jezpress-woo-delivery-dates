===  Jezpress WooCommerce Delivery Dates ===
Contributors: jezpress
Tags: woocommerce, delivery, delivery dates, time slots, carriers, checkout
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.4.1
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
* Holidays — define named date ranges that block delivery availability, scoped to all carriers or specific carriers
* Calendar view — month, week, and day toggles; visualise delivery orders and holidays together in the admin
* Max orders per slot with real-time booking count tracking
* Time slot labels displayed as "From HH:MM to HH:MM" format
* Delivery date shown in the admin order page, customer order detail page, and all WooCommerce emails
* Delivery Date column in the WooCommerce orders list
* Configurable settings: maximum future days, required/optional, checkout section label, custom field labels, week start day, date display format
* Email tab — configure a shipping confirmation email (subject, sender name, content with variables) triggered manually from the admin order edit screen
* "Shipped to Carrier" checkbox on order edit screen — marks the order as shipped and sends the confirmation email once; persists as locked after first use
* Overdue notice icon on delivery date in orders list and order detail panel when delivery date has passed and order is not yet marked as shipped
* Shipped checkmark icon in orders list delivery date column with timestamp tooltip
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

= 1.4.1 =
* Checkout time slot loading is now inline (zero AJAX) when there are 20 or fewer available delivery dates — slot data for all dates is localised at page load, eliminating per-date AJAX round-trips. Falls back to AJAX automatically when dates exceed the threshold or when available dates change after an address update.

= 1.4.0 =
* Added Email tab in plugin settings — configure shipping confirmation email subject, sender name, and content using variables ({order_id}, {customer_name}, {delivery_date}, {site_title}). Uses WooCommerce email header/footer template.
* Added "Shipped to Carrier" checkbox on the admin order edit screen Delivery Details panel. Checking it on save sends the configured confirmation email to the customer once; the checkbox locks permanently after first use.
* Added overdue notice icon (red) on the delivery date in both the orders list column and the order detail panel when the delivery date has passed and the order has not been marked as shipped.
* Added shipped checkmark icon (green) in the orders list delivery date column with a tooltip showing the date and time the order was marked as shipped.
* Added Date Display Format setting — controls how the date appears in the checkout datepicker (Month Day Year, yy-mm-dd, mm/dd/yy, dd/mm/yy); does not affect the stored date format. Default is "March 8, 2026" style.
* Calendar cell overflow cap reduced from 5 to 3 items per type (holidays and orders tracked independently).
* Holiday For column in holidays list now truncates to first carrier name + "..." when multiple carriers are assigned; full list shown on hover via title attribute.
* Delivery details in the admin order panel reordered: Carrier → Date → Time Slot → Shipped to Carrier checkbox.

= 1.3.2 =
* Updated developer documentation (CLAUDE.md) to accurately reflect the raw JOIN query approach introduced in v1.3.1 for the calendar order query.

= 1.3.1 =
* Fix: calendar order query now uses a single lightweight JOIN query instead of loading full WC_Order objects, preventing PHP memory exhaustion on high-volume stores.

= 1.3.0 =
* Fixed calendar order query incompatibility with WooCommerce HPOS — replaced `meta_query` in `wc_get_orders()` with a direct meta-table query, resolving the `WC_Order_Data_Store_CPT::query` doing-it-wrong notice introduced in WooCommerce 9.2.0.
* Calendar: delivery order pills and holiday pills in month and week views are now capped at 5 per day. When a day has more than 5 items of either type, a "+ N more" toggle button is shown. Clicking it opens a dedicated modal listing all items for that day — separate modals for holidays and orders.

= 1.2.0 =
* Added Holidays management — create named date ranges that block delivery availability; scope to all carriers or specific carriers. Holidays are enforced at checkout and displayed in the new Calendar view.
* Added Calendar tab — full admin calendar with month, week, and day views. Delivery orders are shown as colour-coded pills per day. Holidays are overlaid with a distinct amber colour scheme.
* Available delivery dates now enforce per-schedule cutoff times for today — today is selectable if at least one matching schedule's cutoff has not yet passed.
* Checkout datepicker now respects the Week Starts On setting (Sunday or Monday).
* Checkout section heading is now optional — leave the Checkout Section Label blank to hide the heading entirely.
* Added Delivery Date Field Label and Time Slot Field Label settings for custom checkout copy.
* Time slot row is now hidden at checkout until a date is selected, and hidden again when no slots are available for the chosen date.
* Earliest selectable date at checkout is now today (when a valid cutoff window exists), previously always tomorrow.
* DB schema upgraded to version 6 (adds `{prefix}jwdd_holidays` table).

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
