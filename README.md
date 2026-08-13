# Freya AppLovin Conversion API

Server-to-server (S2S) integration with the [AppLovin Axon Event API](https://b.applovin.com/v1/event) implementing the **restricted lead-generation flow**. Per AppLovin policy, only the supported fields are sent — no custom events, objects, email, or phone.

## What it sends

| Event | When | Data |
| --- | --- | --- |
| `page_view` | Front-end page navigation (GET, non-admin, non-404) | `data: null` |
| `generate_lead` | Successful purchase by a **new customer** (`processing` / `completed`) | `{ currency, value }` (order total) |

Both events are sent to `POST https://b.applovin.com/v1/event?pixel_id=<AXON_EVENT_KEY>` with the Conversion API key in the `Authorization` header.

- `page_view` is sent fire-and-forget (non-blocking) on `template_redirect`, since its volume is high.
- `generate_lead` is queued via **Action Scheduler** on `woocommerce_payment_complete` / order status `processing` / `completed`, so checkout is never blocked and failed sends are retried. Identifiers are snapshotted from the order (safe for async payment webhooks).
- Subscription renewals and returning customers are excluded (uses Freya Core `is_new_customer` when available).
- Gravity Forms submissions no longer fire `generate_lead`; click IDs are still persisted on GF entries for order attribution.

## Configuration

Set the credentials as `define()` constants near the top of `freya-applovin.php`:

```php
define( 'FREYA_APPLOVIN_API_KEY', '...' );   // Conversion API key (Authorization header)
define( 'FREYA_APPLOVIN_PIXEL_ID', '...' );  // Axon event key (pixel_id query parameter)
```

Optional constants (also in `freya-applovin.php`):

```php
define( 'FREYA_APPLOVIN_DEFAULT_CURRENCY', 'USD' );  // ISO 4217 fallback
define( 'FREYA_APPLOVIN_DEFAULT_LEAD_VALUE', 0 );    // fallback only; purchases use order total
```

An admin notice is shown until the API key and pixel ID are configured.

## Visitor identifiers

AppLovin requires at least one of `client_id` / `alart` / `user_id`, plus the always-required `client_ip_address`, `client_user_agent`, and `esi` (set to `web`). `aleid` is sent whenever available.

| Field | Source | Cookie |
| --- | --- | --- |
| `aleid` | `?aleid=` query param | `_axeid` (1 year) |
| `alart` | `?alart=` query param | `_axart` (1 year) |
| `client_id` | Generated UUID v4 (stable first-party) | `_axcid` (1 year) |
| `user_id` | Logged-in WordPress user ID (numeric) | — |
| `client_ip_address` | Proxy-aware (`CF-Connecting-IP` / `X-Forwarded-For` / `REMOTE_ADDR`) | — |
| `client_user_agent` | Request `User-Agent` | — |

`event_source_url` is truncated to the domain only (e.g. `https://freyameds.com/`), as required.

## Order attribution

At checkout the visitor's AppLovin identifiers are copied onto the WooCommerce order as meta, so purchases can be attributed to AppLovin reliably later — independent of the client IP, which is masked by iCloud Private Relay and CDN/carrier egress for most mobile traffic. Works with both classic and block (Store API) checkout and is HPOS-safe.

| Order meta key | Source cookie |
| --- | --- |
| `_freya_applovin_aleid` | `_axeid` |
| `_freya_applovin_alart` | `_axart` |
| `_freya_applovin_client_id` | `_axcid` |
| `_freya_applovin_lead_sent_at` | ISO timestamp (or `queued:…`) after generate_lead is queued/sent |

Click IDs are also mirrored into the WooCommerce session, logged-in user meta, Gravity Forms entry meta, and the checkout recovery payload, so they survive cookie loss (in-app browsers, cross-browser recovery links). An inline `wp_head` script sets `_axeid` / `_axart` client-side on every page load (including Breeze-cached HTML) and backs them up in `localStorage`. At checkout the resolver tries: cookie → session → user meta → recovery payload → GF entry (by billing email / `source_url`) → first-landing / attribution URLs on the order.

Only non-empty identifiers are stored, and existing values are never overwritten. Capture runs even when the Conversion API credentials are not configured.

## Requirements

- WooCommerce (for purchases + Action Scheduler)
- Freya Core recommended (for `is_new_customer` / renewal detection)
- Gravity Forms optional (click-ID persistence on quiz entries only)

## Filters

- `freya_applovin_track_page_view` (bool) — disable/enable page_view for the current request.
- `freya_applovin_track_purchase_lead` (bool, `WC_Order`) — final say on whether a purchase fires generate_lead.
- `freya_applovin_purchase_lead_statuses` (string[], `WC_Order`) — order statuses that count as successful (default `processing`, `completed`).
- `freya_applovin_purchase_lead_value` (float, `WC_Order`) — override the monetary value (default order total).
- `freya_applovin_purchase_lead_currency` (string, `WC_Order`) — override the ISO 4217 currency.
