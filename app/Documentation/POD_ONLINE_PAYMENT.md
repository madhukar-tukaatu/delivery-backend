# POD Online Payment (Doorstep) — Ops & Dev Guide

## Root cause of production 422

```
POST /api/v1/staff/deliveries/{id}/payment-session → 422
"Store HamroPay sub-merchant is not registered. Complete HamroPay KYB first."
payload {}
```

**This was a code bug, not a genuine HamroPay KYB prerequisite for doorstep POD.**

Commit `48edc4e` ("pod with online payment") rewrote `StoreManagerPaymentService` to call HamroPay `createSession` and require `merchant.hamropay_merchant_id` / `hamropay_business_id`. That conflated two separate money tracks:

| Track | Provider | When |
|---|---|---|
| **Doorstep online POD** | **Store Manager** payment sessions | Rider at door, customer pays merchant QR |
| Cash POD | Local record only | Rider collects cash for later branch deposit |
| HQ settlements / branch commission | **HamroPay** (+ KYB for sub-merchants) | Settlements, not rider POD |

Empty request body `{}` is **valid**. Amount and method are not client-supplied on `payment-session`; Express derives amount from the shipment (`total_collectable_amount` / `pod_amount`).

## Fix applied (local, no git push)

- Restored `StoreManagerPaymentService` to call Store Manager HTTP APIs.
- Clearer validation when Store Manager is disabled / misconfigured / merchant missing `external_store_id`.
- Staff controller documents body shape; rejects accidental `amount` / `payment_method` on create.
- Rider UI labels and validation-error surfacing corrected (no more "HamroPay" for doorstep POD).
- Gateway `pod-qr` helpers kept, now backed by Store Manager sessions.

## Env keys (Express)

```env
# Required for doorstep online POD
STORE_MANAGER_PAYMENT_ENABLED=true
STORE_MANAGER_PAYMENT_BASE_URL=https://tukaatu.com
STORE_MANAGER_PAYMENT_INTEGRATION_ID=tukaatu-express
STORE_MANAGER_PAYMENT_INTEGRATION_SECRET=<shared secret with Store Manager>
STORE_MANAGER_PAYMENT_CREATE_PATH=/api/v1/integrations/tukaatu-express/pod-payment-sessions
STORE_MANAGER_PAYMENT_STATUS_PATH=/api/v1/integrations/tukaatu-express/pod-payment-sessions/{payment_session_id}
STORE_MANAGER_PAYMENT_TIMEOUT=20
STORE_MANAGER_PAYMENT_WEBHOOK_TOLERANCE=300

# APP_URL must be reachable by Store Manager for webhooks
APP_URL=https://api.tukaatuexpress.com
```

HamroPay env (`HAMROPAY_*`, `HAMROPAY_REGISTRATION_*`) remains for settlements/KYB — **not** required for doorstep POD QR.

## Merchant prerequisites (Store Manager path)

1. Express merchant linked to Store Manager: `external_store_id` (+ usually `external_platform`).
2. Optional per-merchant `integration_callback_secret`, else shared `STORE_MANAGER_PAYMENT_INTEGRATION_SECRET`.
3. Store Manager side: store exists, active, and has a payment account that can emit a dynamic QR for the order amount.
4. Shipment is POD/COD/To Pay with `total_collectable_amount` (or `pod_amount`) > 0.

**HamroPay KYB is NOT required for doorstep online POD.** If Store Manager itself uses HamroPay under the hood for the store's payment account, that is Store Manager's concern — Express must not gate on Express-side `hamropay_*` fields for this endpoint.

## Staff API contract

### Create session

`POST /api/v1/staff/deliveries/{delivery}/payment-session`

```json
{}
```

or

```json
{ "idempotency_key": "podpay_32_optional-retry-key" }
```

**Do not send** `amount`, `method`, or `payment_method` here (rejected as `prohibited`).

Preconditions: assigned rider; delivery `out_for_delivery`; `arrived_at` set.

### Poll status

`GET /api/v1/staff/deliveries/{delivery}/payment-session?refresh=1`

### Complete delivery (online)

`POST /api/v1/staff/deliveries/{delivery}/delivered`

```json
{
  "payment_method": "online",
  "payment_session_id": "ps_...",
  "customer_confirmed": true,
  "customer_name": "...",
  "customer_signature": "data:image/png;base64,...",
  "pod_collected_amount": 1100.00
}
```

Cash path unchanged: `"payment_method": "cash"` (no session id).

## Webhook complete flow

1. Rider arrives → chooses Online → Express `POST payment-session`.
2. Express signs request (HMAC SHA-256 of `timestamp + "." + body`) and calls Store Manager create path.
3. Store Manager returns `payment_session_id`, QR (`image_url` / `payload`), `settlement_destination: merchant`, amount, expiry.
4. Rider shows QR; customer pays merchant.
5. Store Manager POSTs event to Express:

   `POST {APP_URL}/api/v1/integrations/store-manager/payment-events`

   Headers: `X-Tukaatu-Timestamp`, `X-Tukaatu-Signature`, event id.
6. Express verifies signature, marks `pod_payment_sessions` paid, syncs local POD record as paid-direct-to-merchant.
7. Staff UI also polls `GET payment-session?refresh=1` as backup.
8. Rider completes delivery with `payment_method=online` + `payment_session_id` only after status is `paid`.

Webhook never auto-marks the shipment delivered — rider still confirms receipt/signature.

## How to test locally

1. Set Store Manager payment env keys (`ENABLED=true`, base URL, secret).
2. Ensure test merchant has `external_store_id`.
3. Create POD shipment with collectable amount > 0; assign rider; accept → out for delivery → arrive.
4. As rider: `POST .../payment-session` with `{}` — expect 200 + QR fields (or a clear Store Manager config/error message, never HamroPay KYB).
5. Simulate paid webhook (signed) or mock Store Manager status endpoint returning `paid`.
6. `POST .../delivered` with `payment_method=online` + session id + receipt fields.
7. Repeat with `payment_method=cash` to confirm cash path untouched.

### Mock tip

Point `STORE_MANAGER_PAYMENT_BASE_URL` at a local stub that returns a fixed pending session with QR payload, then a paid status on GET.

## Ops steps if something still fails after deploy

1. Confirm production is on this restored Store Manager code (not the HamroPay rewrite).
2. Set `STORE_MANAGER_PAYMENT_ENABLED=true` and secrets in production env; clear config cache.
3. Verify merchant `external_store_id` for the failing delivery's store.
4. Confirm Store Manager create endpoint is live and the store can issue payment QR.
5. Only if failure is on **settlements/HQ HamroPay** (not this staff payment-session endpoint) should ops chase HamroPay KYB / `hamropay_merchant_id`.
