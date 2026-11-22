# MTAL Persian Calendar

Independent continuation of the MTAL Persian Calendar WordPress plugin.

## Version 0.5.0

This repository starts from the current 0.4.0 plugin source and adds server-side stay-price calculation.

### Server-side price calculation

The public AJAX action `mtal_calculate_suite_price` accepts:

- `post_id`: published page/post containing the suite pricing metadata
- `check_in`, `check_out`: Jalali dates in `YYYY/MM/DD` format (Persian and Arabic digits are normalized)
- `guests`: guest count
- `max_months`: calendar horizon, limited to 1–3
- `nonce`: WordPress nonce created for `mtal_calendar_rules_nonce`

The server validates the dates, date horizon, blocked nights, and guest count, then calculates each night using the existing priority: manual date price, weekend price (Jalali weekday indexes 4 and 5), then regular price. Checkout is exclusive; the checkout date is not charged or treated as an occupied night. Extra-guest charges are applied per night above the saved base capacity.

The calendar requests this endpoint after a complete date selection. The total-price target field is cleared while the server calculation is pending and receives the server response only after success. Errors are exposed through the `mtal:serverPriceError` event; success is exposed through `mtal:serverPriceCalculated`.

### Important integration note

This plugin does not currently contain a booking/order/payment processor integration. The AJAX result is authoritative for the calendar's displayed/submitted total, but a separate checkout/payment integration must call the same server-side calculation and validate the amount immediately before creating an order or charging a customer. Do not trust a browser-submitted total as a payment amount.

## Installation

Upload this repository as a WordPress plugin directory and activate `mtal-persian-calendar.php`. Elementor is required for the widgets.


## WooCommerce booking approval workflow (0.6.0)

When WooCommerce is active, the plugin can turn submissions from the existing Elementor Pro form into WooCommerce booking orders. It does not create a replacement form and it never trusts the submitted total-price field.

### Existing Elementor form fields

- Keep the existing date-range field connected to the calendar widget's date target selector.
- Keep a hidden Elementor field with **Field ID** `mtal_qty`; the calendar fills it with the selected guest count.
- If the form is embedded directly on the suite page, the suite post ID is taken from Elementor's submitted page ID. If the form is in a template/listing or another page, add a hidden field with **Field ID** `mtal_post_id`; the calendar fills it automatically from the calendar widget.
- For customer SMS/payment details, use recognizable field IDs such as `phone` or `mobile`. Email and name fields are detected from IDs containing `email`, `name`, or `fullname`.

### Workflow

1. On valid form submission, the plugin recalculates the stay price on the server and creates a WooCommerce order with status **در انتظار تأیید مدیر**.
2. Every occupied night (check-in inclusive, check-out exclusive) is held for 30 minutes. The calendar and server-side price validation both treat active holds as unavailable.
3. The user sees a 30-minute countdown. The server expiry timestamp is authoritative; the browser timer is only a display.
4. The manager approves or rejects the request from the WooCommerce order screen. Rejection releases the dates immediately. Approval changes the order to WooCommerce's standard **pending payment** status; the existing WooCommerce payment URL is then available.
5. Successful payment makes the hold permanent. If the manager has not approved or the customer has not paid by the original 30-minute deadline, the order expires and the dates become available again.

The database hold table is created/upgraded automatically. Expired holds are ignored by availability checks even if WP-Cron has not yet run; WP-Cron updates the corresponding order status and removes expired rows.

### FarazSMS notifications

The booking workflow uses WooCommerce order-status changes, not SMS commands. In FarazSMS → WooCommerce order SMS settings:

- Enable the **admin** notification for **در انتظار تأیید مدیر** to notify the manager when a request is submitted.
- Enable the **customer** notification for **در انتظار پرداخت** to notify the customer after manager approval.
- Optionally enable the **customer** notification for **لغو شده** so a rejected or expired request also generates an SMS.
- Include `%payment_url%` in the customer message for the WooCommerce payment link. FarazSMS 3.22.320 supports this order variable.

No FarazSMS API credentials are copied into this plugin. SMS delivery remains controlled by the existing FarazSMS configuration.

### Important setup and test notes

- The Elementor Pro form must contain the `mtal_qty` field. If the form is not on the suite page, it must also contain `mtal_post_id`.
- Make sure the form's phone field ID contains `phone`, `mobile`, or `tel` so WooCommerce can populate the billing phone used by FarazSMS.
- Configure WooCommerce's store currency/price unit consistently with the calendar's configured prices (the calendar price fields are documented in toman).
- The admin decision controls are available on the WooCommerce order detail screen. The workflow supports the classic order editor and the HPOS order details screen.
- Test the complete process on a staging site with the actual Elementor form, WooCommerce payment gateway, and FarazSMS settings before using it for live reservations.


### Booking management dashboard

- Users with the WooCommerce `manage_woocommerce` capability can open **تقویم فارسی MTAL → مدیریت رزروها** in the WordPress dashboard.
- The page lists MTAL booking orders and supports filtering by order status, pagination, and links to the full WooCommerce order details.
- Pending requests can be approved or rejected directly from the list. These actions use the same nonce-protected WooCommerce booking decision handler as the order details screen; approval enables the normal payment URL and rejection releases the held dates.
- An admin-only shortcut to this page is also available in the Elementor settings panels for both MTAL calendar widgets. It is an editor/admin shortcut, not a public-facing frontend control.
