# POD Online Payment (Phase 6 — HamroPay, multi-marketplace)

Express supports **multiple marketplaces** (e.g. `https://api.tukaatu.com`, `https://api.fca.com.np`). Each marketplace has its own API/callback URL and HamroPay HQ credentials. Stores belong to a marketplace. POD resolves **marketplace + store from the shipment** (not a single `.env` URL).

## Admin screens

1. **Admin → Network → Marketplaces** (`/admin/marketplaces`)
   - Create/edit marketplace: `api_base_url`, `callback_url`, `callback_secret`, default flag.
   - On detail: **Save HamroPay** (API base, gateway URL, client id, api key, secret, HQ merchant id).
   - Attach stores to the marketplace.
2. **Admin → Network → Merchants** → open store (`/admin/merchants/{id}`)
   - Set `marketplace_id`, `external_store_id`, `hamropay_merchant_id` / `hamropay_business_id`.
3. **Admin → Finance → Payment Gateways** (`/admin/payment-gateways`)
   - Optional **company** HamroPay fallback (and branch accounts for settlements).
   - Marketplace credentials are preferred via Marketplaces screen (`owner_type=marketplace`).

## Credential resolve order (POD QR / staff payment-session / verify)

1. `payment_gateway_accounts` for the shipment's marketplace (`owner_type=marketplace`)
2. Company HamroPay account
3. Branch account (optional)
4. Optional last-resort: `HAMROPAY_*` env — **missing env alone must not block** when admin config exists

Store **sub-merchant** (never from `.env`):

1. `merchants.hamropay_merchant_id` / `hamropay_business_id`
2. else suffix of `external_store_id` (`STORE-00018` → `00018`)

## Flow

1. Store / marketplace: `POST /api/v1/gateway/payments/pod-qr` (merchant API key) with `merchant_order_id` / `external_order_id` / `amount`.
2. Express: resolve shipment → merchant → marketplace → HamroPay `createSession` (HQ merchant + store sub-merchant) → cache 30 min.
3. Customer pays in HamroPay app.
4. Verify: `POST /api/v1/gateway/payments/pod-qr/verify` `{ "merchant_txn_id" }` → `getTransaction`; `SUCCESS` → paid direct.
5. Staff: `POST /api/v1/staff/deliveries/{id}/payment-session` `{}` then `GET ...?refresh=1`.
6. Staff delivered: `payment_method` `qr`|`online`, `merchant_txn_id`, exact `pod_collected_amount`.
7. Webhook: `delivery.delivered` with `paid`, `pod_collected_amount`, `payment_method`, `payment_reference`. Callback URL = store `integration_callback_url` or marketplace `callback_url`.

## Retest

1. Admin → Marketplaces → open Tukaatu (and/or FCA) → save full HamroPay credentials (leave `.env` HAMROPAY_* empty).
2. Admin → Merchants → store → set marketplace + `hamropay_merchant_id` or `external_store_id`.
3. Shipment POD, out for delivery, rider arrived.
4. Staff Online/QR → `POST .../payment-session` `{}` → 200 with `qr_string` / `merchant_txn_id`.
5. After HamroPay SUCCESS, `GET .../payment-session?refresh=1` → `paid`.
6. `POST .../delivered` with `online`/`qr` + `merchant_txn_id` + amount.
7. Marketplace receives `delivery.delivered` webhook fields.

Gateway smoke:

```http
POST /api/v1/gateway/payments/pod-qr
X-Tukaatu-Key: ...
X-Tukaatu-Secret: ...

{ "merchant_order_id": "ORD-1", "external_order_id": "ORD-1", "amount": 1100 }
```