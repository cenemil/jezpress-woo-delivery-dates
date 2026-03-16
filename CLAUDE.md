# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Identity
- **Slug:** `jezpress-woo-delivery-dates`
- **Text domain:** `jezpress-woo-delivery-dates`
- **Constant prefix:** `JWDD_`
- **Function prefix:** `jwdd_`
- **Class prefix:** `JWDD_`
- **DB option (settings):** `jwdd_settings`
- **DB tables:** `{prefix}jwdd_carriers`, `{prefix}jwdd_schedules`

## Requirements
- **WordPress:** 5.8+
- **PHP:** 7.4+
- **WooCommerce:** 6.0+
- **Checkout type:** Classic checkout only (WooCommerce Blocks not supported in v1.0)

## File Structure
```
jezpress-woo-delivery-dates/
├── jezpress-woo-delivery-dates.php   — Entry point, constants, hooks, boot
├── uninstall.php                      — Drops tables, deletes options (preserves license key)
├── readme.txt                         — Plugin readme
├── includes/
│   ├── class-jwdd-db.php             — DB schema: create_tables(), DB_VERSION, table name helpers
│   ├── class-jwdd-admin.php          — Singleton. Admin menu, 4 tabs, settings registration, script enqueue
│   ├── class-jwdd-carriers.php       — Carrier CRUD + AJAX handlers
│   ├── class-jwdd-schedules.php      — Schedule CRUD, recurring generation, checkout AJAX (nopriv)
│   ├── class-jwdd-checkout.php       — Checkout fields, validation, order meta save, booked_count
│   ├── class-jwdd-order.php          — Admin order display, email display, order list column
│   ├── class-jwdd-license.php        — Singleton. JezPress license (adapted from JWOR pattern)
│   └── class-jwdd-updater.php        — JezPress update server integration (adapted from JWOR pattern)
└── assets/
    ├── js/
    │   ├── jwdd-admin.js             — Carriers/Schedules CRUD + recurring generation (vanilla JS + fetch)
    │   └── jwdd-checkout.js          — Date change → AJAX time slot load (jQuery)
    └── css/
        ├── jwdd-admin.css
        └── jwdd-checkout.css
```

No Composer dependencies. No build step. Pure PHP + vanilla JS (admin) + jQuery (checkout).

## Boot Sequence

**Early load (before `plugins_loaded`):** `class-jwdd-updater.php` and `class-jwdd-license.php` are required directly in the entry point. `JWDD_Updater` and `JWDD_License` are instantiated here so WP cron auto-updates and license checks fire outside admin context.

**`jwdd_init()` fires on `plugins_loaded` (priority 20).** It:
1. Bails with an admin notice if WooCommerce is not active
2. Requires all remaining class files
3. Runs `JWDD_DB::create_tables()` if `jwdd_db_version` option is behind `JWDD_DB::DB_VERSION`
4. Calls `JWDD_License::get_instance()->init()`
5. Instantiates `JWDD_Admin::get_instance()`, `new JWDD_Carriers()`, `new JWDD_Schedules()`, `new JWDD_Checkout()`, `new JWDD_Order()`

## Database Schema

### `{prefix}jwdd_carriers`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint UNSIGNED | PK auto-increment |
| name | varchar(100) | Display name |
| code | varchar(50) | Slug/identifier |
| description | text | Optional |
| is_active | tinyint(1) | 1=active |
| sort_order | int | Display order |
| created_at / updated_at | datetime | |

### `{prefix}jwdd_schedules`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint UNSIGNED | PK auto-increment |
| carrier_id | bigint UNSIGNED | FK to jwdd_carriers (0 = no carrier) |
| schedule_date | date | The delivery date |
| start_time / end_time | time | Slot window |
| label | varchar(100) | Display label, e.g. "9:00am – 12:00pm" |
| max_orders | int | 0 = unlimited |
| booked_count | int | Incremented on order creation, decremented on cancellation/refund |
| is_active | tinyint(1) | 1=active |
| created_at | datetime | |

## Settings (`jwdd_settings` option)
```php
[
  'enabled'         => 1,           // Show fields at checkout
  'required'        => 1,           // Required to place order
  'cutoff_days'     => 1,           // Min days from today (0 = same-day allowed)
  'max_future_days' => 30,          // Latest selectable date offset
  'checkout_label'  => 'Select Delivery Date & Time',
  'show_carrier'    => 0,           // Show carrier selector at checkout
]
```

## Admin Page

Located under **WooCommerce > Delivery Dates** (page slug: `jwdd-delivery-dates`, capability: `manage_woocommerce`).

| Tab | URL param | Purpose |
|-----|-----------|---------|
| General Settings | `?tab=settings` (default) | Plugin enable/disable, checkout options |
| Carriers | `?tab=carriers` | Carrier list + add/edit form (AJAX) |
| Schedules | `?tab=schedules` | Schedule list + filter + add form + recurring generator (AJAX) |
| License | `?tab=license` | License activate/deactivate form |

Non-license tabs are gated: if `JWDD_License::is_valid()` returns false, only the License tab renders.

Admin JS (`jwdd-admin.js`) is enqueued on all tabs of the plugin page.

## AJAX Actions

### Admin (require nonce `jwdd_admin_nonce` + `manage_woocommerce`)
| Action | Handler | Description |
|--------|---------|-------------|
| `jwdd_save_carrier` | `JWDD_Carriers::ajax_save_carrier()` | Insert or update carrier |
| `jwdd_delete_carrier` | `JWDD_Carriers::ajax_delete_carrier()` | Delete carrier + its schedules |
| `jwdd_get_carriers` | `JWDD_Carriers::ajax_get_carriers()` | Return all carriers as JSON |
| `jwdd_save_schedule` | `JWDD_Schedules::ajax_save_schedule()` | Insert or update schedule slot |
| `jwdd_delete_schedule` | `JWDD_Schedules::ajax_delete_schedule()` | Delete a schedule slot |
| `jwdd_get_schedules` | `JWDD_Schedules::ajax_get_schedules()` | Return filtered schedules as JSON |
| `jwdd_generate_recurring` | `JWDD_Schedules::ajax_generate_recurring()` | Bulk-create slots by day-of-week/date-range |

### Public (nonce `jwdd_checkout_nonce`, nopriv)
| Action | Handler | Description |
|--------|---------|-------------|
| `jwdd_get_time_slots` | `JWDD_Schedules::ajax_get_time_slots()` | Return available slots for a given date |

## Checkout Integration

- **Hook:** `woocommerce_before_order_notes` renders the date/carrier/time-slot fields
- **Fields:** `jwdd_delivery_date` (select), `jwdd_time_slot_id` (select, AJAX-populated), `jwdd_carrier_id` (select, optional)
- **Validation:** `woocommerce_checkout_process` — validates date and slot are set if `required = 1`; also re-checks slot availability against DB (race condition protection)
- **Save:** `woocommerce_checkout_create_order` — saves `_jwdd_delivery_date`, `_jwdd_time_slot_id`, `_jwdd_time_slot_label`, `_jwdd_carrier_id` to order meta via `$order->update_meta_data()`
- **Booking count:** incremented on `woocommerce_checkout_order_created`; decremented on `woocommerce_order_status_cancelled` and `woocommerce_order_status_refunded`

## Order Meta Keys
| Key | Value |
|-----|-------|
| `_jwdd_delivery_date` | Date string `Y-m-d` |
| `_jwdd_time_slot_id` | Schedule row ID |
| `_jwdd_time_slot_label` | Cached slot label string |
| `_jwdd_carrier_id` | Carrier row ID |

## JezPress Platform

### Updater (`includes/class-jwdd-updater.php`)
- Class: `JWDD_Updater` — identical pattern to `JWOR_Updater`
- Cache key prefix: `jwdd_update_`
- Manual check URL param: `jwdd_check`

### License (`includes/class-jwdd-license.php`)
- Class: `JWDD_License` — identical pattern to `JWOR_License`
- Cron hook: `jwdd_license_check` (daily)
- Option name: `jzwb_lic_` + first 8 chars of `md5('jezpress-woo-delivery-dates')`
- Redirect URLs use page slug `jwdd-delivery-dates`

## Releasing a New Version
1. Update `JWDD_VERSION` constant in `jezpress-woo-delivery-dates.php`
2. Update the `Version:` plugin header in the same file
3. Update `Stable tag:` in `readme.txt` and add a changelog entry

Then upload via JezPress CLI:
```bash
jezpress plugins preflight jezpress-woo-delivery-dates ./jezpress-woo-delivery-dates.zip
jezpress plugins upload jezpress-woo-delivery-dates ./jezpress-woo-delivery-dates.zip
```
