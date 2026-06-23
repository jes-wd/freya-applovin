# Freya AppLovin Conversion API

Server-to-server (S2S) integration with the [AppLovin Axon Event API](https://b.applovin.com/v1/event) implementing the **restricted lead-generation flow**. Per AppLovin policy, only the supported fields are sent — no custom events, objects, email, or phone.

## What it sends

| Event | When | Data |
| --- | --- | --- |
| `page_view` | Front-end page navigation (GET, non-admin, non-404) | `data: null` |
| `generate_lead` | A Gravity Forms submission | `{ currency, value }` |

Both events are sent to `POST https://b.applovin.com/v1/event?pixel_id=<AXON_EVENT_KEY>` with the Conversion API key in the `Authorization` header.

- `page_view` is sent fire-and-forget (non-blocking) on `template_redirect`, since its volume is high.
- `generate_lead` is queued via **Action Scheduler** so the submission is never blocked and failed sends are retried. The visitor identifiers are snapshotted at submission time and passed to the background job.

## Configuration

Set the credentials as `define()` constants near the top of `freya-applovin.php`:

```php
define( 'FREYA_APPLOVIN_API_KEY', '...' );   // Conversion API key (Authorization header)
define( 'FREYA_APPLOVIN_PIXEL_ID', '...' );  // Axon event key (pixel_id query parameter)
```

Optional constants (also in `freya-applovin.php`):

```php
define( 'FREYA_APPLOVIN_LEAD_FORM_IDS', array() );   // empty = all forms; or e.g. array( 5, 12 )
define( 'FREYA_APPLOVIN_DEFAULT_CURRENCY', 'USD' );  // ISO 4217
define( 'FREYA_APPLOVIN_DEFAULT_LEAD_VALUE', 0 );    // monetary value per lead
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

## Requirements

- Gravity Forms (for `generate_lead`)
- Action Scheduler (bundled with WooCommerce)

## Filters

- `freya_applovin_track_page_view` (bool) — disable/enable page_view for the current request.
- `freya_applovin_lead_form_ids` (array, $form_id) — form IDs that fire generate_lead (empty = all).
- `freya_applovin_track_lead` (bool, $form_id) — final say on whether a submission fires generate_lead.
- `freya_applovin_lead_value` (float, $entry_id, $form_id) — set `0` for duplicate / low-quality leads.
- `freya_applovin_lead_currency` (string, $entry_id, $form_id) — override the ISO 4217 currency.
