# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Identity
- **Slug:** `jezpress-woo-delivery-dates`
- **Text domain:** `jezpress-woo-delivery-dates`
- **Constant prefix:** `JWDD_`
- **Function prefix:** `jwdd_`
- **Class prefix:** `JWDD_`
- **DB option (settings):** `jwdd_settings`
- **DB tables:** `{prefix}jwdd_carriers`, `{prefix}jwdd_schedules`, `{prefix}jwdd_schedule_defs`
- **Current DB version:** `JWDD_DB::DB_VERSION = 4`

## Requirements
- **WordPress:** 5.8+
- **PHP:** 7.4+
- **WooCommerce:** 6.0+
- **Checkout type:** Classic checkout only (WooCommerce Blocks not supported)

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
│   ├── class-jwdd-schedule-defs.php  — Schedule Definition CRUD + AJAX handlers (since v1.1.0)
│   ├── class-jwdd-schedules.php      — Schedule slot on-demand creation, checkout AJAX (nopriv)
│   ├── class-jwdd-checkout.php       — Checkout fields, validation, order meta save, booked_count
│   ├── class-jwdd-order.php          — Admin order display, email display, order list column
│   ├── class-jwdd-license.php        — Singleton. JezPress license (adapted from JWOR pattern)
│   └── class-jwdd-updater.php        — JezPress update server integration (adapted from JWOR pattern)
└── assets/
    ├── js/
    │   ├── jwdd-admin.js             — Carriers/Schedules CRUD (vanilla JS + fetch)
    │   └── jwdd-checkout.js          — Datepicker + dynamic date refresh + AJAX time slot load (jQuery)
    └── css/
        ├── jwdd-admin.css
        └── jwdd-checkout.css
```

No Composer dependencies. No build step. Pure PHP + vanilla JS (admin) + jQuery (checkout).

## DB Schema Notes
- Tables use `dbDelta()` so `create_tables()` is safe to call repeatedly for upgrades.
- To upgrade the schema, increment `JWDD_DB::DB_VERSION` in `class-jwdd-db.php`. The boot sequence in `jwdd_init()` calls `create_tables()` whenever the stored `jwdd_db_version` option is behind this constant.
- Indices on `{prefix}jwdd_schedules`: `carrier_id`, `schedule_def_id`, `schedule_date`, `is_active`. Indices on `{prefix}jwdd_carriers`: `code`, `is_active`.

## Schedule Label Format
Slot labels are always auto-generated as `From g:ia to g:ia` from `start_time`/`end_time` (e.g. `From 9:00am to 12:00pm`). The `label` field on `{prefix}jwdd_schedule_defs` slots is ignored for display — the time-based format always wins. The cached `_jwdd_time_slot_label` on order meta uses this value. Existing DB rows are updated to the new format on next access in `get_slots_for_date()`.

## Schedule Definition → Slot Relationship (since v1.1.0)
`JWDD_Schedule_Defs` stores named recurring patterns. Each definition's `days_of_week` is a JSON array:
```json
[
  {
    "day": 1,
    "cutoff": "10:00",
    "slots": [
      {"start": "09:00", "end": "12:00", "label": ""},
      {"start": "13:00", "end": "17:00", "label": ""}
    ]
  }
]
```
`day` is 0 (Sun)–6 (Sat). `cutoff` is an `HH:MM` order cutoff time or empty.

**No manual slot generation is required.** `JWDD_Schedules::get_slots_for_date()` reads active schedule definitions for the date's day-of-week and creates `{prefix}jwdd_schedules` rows on-demand (keyed by `schedule_def_id + schedule_date + start_time`). This gives each slot a real DB row ID for order meta and capacity tracking, while keeping slot data always in sync with the definition config.

## Available Dates Derivation
`JWDD_Schedules::get_available_dates()` does **not** query pre-generated slot rows. It reads active schedule definitions, collects which days-of-week have at least one slot configured, then walks the date window (`+1 day` to `+max_future_days`) and returns every matching date. This means dates are always live — changing a schedule definition takes effect immediately.

## Boot Sequence

**Early load (before `plugins_loaded`):** `class-jwdd-updater.php` and `class-jwdd-license.php` are required directly in the entry point. `JWDD_Updater` and `JWDD_License` are instantiated here so WP cron auto-updates and license checks fire outside admin context.

**`jwdd_init()` fires on `plugins_loaded` (priority 20).** It:
1. Bails with an admin notice if WooCommerce is not active
2. Requires all remaining class files
3. Runs `JWDD_DB::create_tables()` if `jwdd_db_version` option is behind `JWDD_DB::DB_VERSION`
4. Calls `JWDD_License::get_instance()->init()`
5. Instantiates `JWDD_Admin::get_instance()`, `new JWDD_Carriers()`, `new JWDD_Schedule_Defs()`, `new JWDD_Schedules()`, `new JWDD_Checkout()`, `new JWDD_Order()`

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
| shipping_zones | text | JSON array of `{zone_id, est_days}` objects |
| created_at / updated_at | datetime | |

### `{prefix}jwdd_schedules`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint UNSIGNED | PK auto-increment |
| carrier_id | bigint UNSIGNED | FK to jwdd_carriers (0 = no carrier) |
| schedule_def_id | bigint UNSIGNED | FK to jwdd_schedule_defs (0 = manually created) |
| schedule_date | date | The delivery date |
| start_time / end_time | time | Slot window |
| label | varchar(100) | Display label, e.g. "From 9:00am to 12:00pm" |
| max_orders | int | 0 = unlimited |
| booked_count | int | Incremented on order creation, decremented on cancellation/refund |
| is_active | tinyint(1) | 1=active |
| created_at | datetime | |

### `{prefix}jwdd_schedule_defs`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint UNSIGNED | PK auto-increment |
| name | varchar(100) | Display name for the definition |
| carrier_id | bigint UNSIGNED | FK to jwdd_carriers (0 = no carrier) |
| days_of_week | text | JSON array of `{day, cutoff, slots: [{start, end, label}]}` — day is 0 (Sun)–6 (Sat), cutoff is `HH:MM` or empty |
| is_active | tinyint(1) | 1=active |
| created_at / updated_at | datetime | |

## Settings (`jwdd_settings` option)
```php
[
  'enabled'         => 1,           // Show fields at checkout
  'required'        => 1,           // Required to place order
  'max_future_days' => 30,          // Latest selectable date offset (tomorrow + N days)
  'checkout_label'  => 'Select Delivery Date & Time',
]
```
Earliest selectable date is always tomorrow (hardcoded in `get_available_dates()`). Carrier selection at checkout is not shown; carrier is derived from the selected slot's `carrier_id`.

## Admin Page

Located under **WooCommerce > Delivery Dates** (page slug: `jwdd-delivery-dates`, capability: `manage_woocommerce`).

| Tab | URL param | Purpose |
|-----|-----------|---------|
| Settings | `?tab=settings` (default) | Plugin enable/disable, checkout options |
| Carriers | `?tab=carriers` | Carrier list or add/edit form (`action=add|edit&carrier_id=N`) |
| Schedules | `?tab=schedules` | Schedule definition list or add/edit def (`action=add|edit&def_id=N`) |
| License | `?tab=license` | License activate/deactivate form |

The Schedules tab manages **schedule definitions** (named recurring patterns). The carrier form includes a "Shipping Zones & Estimated Delivery" section to map WC shipping zones to estimated delivery days.

Non-license tabs are gated: if `JWDD_License::is_valid()` returns false, only the License tab renders.

## AJAX Actions

### Admin (require nonce `jwdd_admin_nonce` + `manage_woocommerce`)
| Action | Handler | Description |
|--------|---------|-------------|
| `jwdd_save_carrier` | `JWDD_Carriers::ajax_save_carrier()` | Insert or update carrier (includes `shipping_zones_json`) |
| `jwdd_delete_carrier` | `JWDD_Carriers::ajax_delete_carrier()` | Delete carrier + its schedules |
| `jwdd_get_carriers` | `JWDD_Carriers::ajax_get_carriers()` | Return all carriers as JSON |
| `jwdd_save_schedule_def` | `JWDD_Schedule_Defs::ajax_save()` | Insert or update a schedule definition |
| `jwdd_delete_schedule_def` | `JWDD_Schedule_Defs::ajax_delete()` | Delete a schedule definition + its slots |
| `jwdd_save_schedule` | `JWDD_Schedules::ajax_save_schedule()` | Insert or update a single schedule slot |
| `jwdd_delete_schedule` | `JWDD_Schedules::ajax_delete_schedule()` | Delete a schedule slot |
| `jwdd_get_schedules` | `JWDD_Schedules::ajax_get_schedules()` | Return filtered schedules as JSON (filter by `carrier_id`, `date_from`, `date_to`, `def_id`) |

### Public (nonce `jwdd_checkout_nonce`, nopriv)
| Action | Handler | Description |
|--------|---------|-------------|
| `jwdd_get_available_dates` | `JWDD_Checkout::ajax_get_available_dates()` | Return available dates + `has_address` flag for current session; called on `updated_checkout` |
| `jwdd_get_time_slots` | `JWDD_Schedules::ajax_get_time_slots()` | Return available slots for a given date (creates slot rows on-demand) |

## Checkout Integration

- **Hook:** `woocommerce_before_order_notes` renders the delivery section
- **Fields:** `jwdd_delivery_date` (hidden input, `Y-m-d`), `jwdd_delivery_date_picker` (visible jQuery UI Datepicker, readonly, not submitted), `jwdd_time_slot_id` (select, AJAX-populated), `#jwdd-date-status` (status message `<p>`, JS-controlled)
- **Datepicker:** jQuery UI Datepicker (`jquery-ui-datepicker`). Restricted to `jwdd_checkout.available_dates` via `beforeShowDay`. Display format `D, d M yy`; alt format `yy-mm-dd` written to the hidden field. Styles are self-contained in `jwdd-checkout.css`.
- **Initial render:** date/slot rows are hidden (`display:none`). JS shows them once address is confirmed and dates are available.
- **Address detection:** `JWDD_Checkout::get_applicable_carrier_ids()` returns `null` (no filter) if no address/no carriers, `[]` if address present but no matching carrier, or `[id, ...]` for matched carriers. `has_address` flag is `true` if shipping or billing country is set.
- **Dynamic refresh:** On WooCommerce `update_checkout` event → JS shows loading message and clears selection. On `updated_checkout` → JS calls `jwdd_get_available_dates` AJAX and re-renders datepicker with fresh dates.
- **States shown in `#jwdd-date-status`:** address required / loading / no dates available / error.
- **Available dates** are computed from active schedule definitions on both initial page load (`wp_localize_script`) and each `updated_checkout` AJAX refresh.
- **Validation:** `woocommerce_checkout_process` — validates date and slot if `required = 1`; re-checks slot availability (race condition protection).
- **Save:** `woocommerce_checkout_create_order` — saves `_jwdd_delivery_date`, `_jwdd_time_slot_id`, `_jwdd_time_slot_label`, `_jwdd_carrier_id` to order meta.
- **Booking count:** incremented on `woocommerce_checkout_order_created`; decremented on cancelled/refunded.

## Order Meta Keys
| Key | Value |
|-----|-------|
| `_jwdd_delivery_date` | Date string `Y-m-d` |
| `_jwdd_time_slot_id` | Schedule row ID |
| `_jwdd_time_slot_label` | Cached slot label, e.g. "From 9:00am to 12:00pm" |
| `_jwdd_carrier_id` | Carrier row ID |

## Order Display
`JWDD_Order` displays delivery details in three places: admin order detail page (`woocommerce_admin_order_data_after_billing_address`), customer My Account order detail (`woocommerce_order_details_after_order_table`), and order emails (`woocommerce_email_after_order_table`). It also adds a **Delivery Date** column to the orders list table, inserted after `order_status`.

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
