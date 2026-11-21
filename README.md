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
