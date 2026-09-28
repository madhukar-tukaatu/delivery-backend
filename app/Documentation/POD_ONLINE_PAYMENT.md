# POD Online Payment (Doorstep) — Ops & Dev Guide

## Intent

Doorstep POD online payment uses the **store HamroPay KYB sub-merchant** (`merchants.hamropay_merchant_id` / `hamropay_business_id`).

Express does **not** invent a QR and does **not** gate on `STORE_MANAGER_PAYMENT_ENABLED`. It calls HamroPay `createSession` with `subMerchantId` and returns `merchant_txn_id`, `amount`, `qr_string`, `payment_url`, `params` (plus `payment_session_id` for delivered).

| Track | Provider | When |
|---|---|---|
| **Doorstep online POD** | **HamroPay** createSession → store sub-merchant | Rider at door, customer pays merchant QR |
| Cash POD | Local record only | Rider collects cash for later branch deposit |
| HQ settlements / branch commission | HamroPay (+ KYB for sub-merchants) | Settlements |

Empty request body `{}` on staff `payment-session` is **valid**. Amount is derived from the shipment (`total_collectable_amount` / `pod_amount`).

Staff payment-session and gateway `pod-qr` share the same `StoreManagerPaymentService` HamroPay path. Webhook/callback signature auth reuses the existing Tukaatu integration HMAC (`X-Tukaatu-Timestamp` / `X-Tukaatu-Signature` with merchant `integration_callback_secret` or shared secret).

## Root cause of production 422

```
POST /api/v1/staff/deliveries/{id}/payment-session → 422
"Store Manager online payment is not enabled. Set STORE_MANAGER_PAYMENT_ENABLED=true (HamroPay KYB is not used for doorstep POD)."
payload {}
```

A later commit rewrote the HamroPay doorstep path back into an HTTP client that required `STORE_MANAGER_PAYMENT_ENABLED=true` and claimed KYB was unused. That blocked riders even when the store already had HamroPay KYB.

## Fix

- Restored `StoreManagerPaymentService` to call HamroPay `createSession` with store `hamropay_merchant_id` as `subMerchantId`.
- Removed the `STORE_MANAGER_PAYMENT_ENABLED` gate and the misleading "HamroPay KYB is not used for doorstep POD" message.
- Staff GET refresh / delivered verify poll HamroPay `getTransaction`.
- Gateway `pod-qr` uses the same service.

## Env keys still required (Express)

Platform HamroPay credentials (company/branch Payment Gateway account preferred; env fallback):

```env
HAMROPAY_API_BASE_URL=...
HAMROPAY_GATEWAY_URL=...
HAMROPAY_CLIENT_ID=...
HAMROPAY_CLIENT_API_KEY=...
HAMROPAY_SECRET=...
HAMROPAY_MERCHANT_ID=...   # platform merchant id (parent); store is subMerchantId

APP_URL=https://api.tukaatuexpress.com
```

Optional (webhook / callback HMAC if Store Manager or redirects hit Express payment-events):

```env
STORE_MANAGER_PAYMENT_INTEGRATION_SECRET=<shared secret>
# or per-merchant merchants.integration_callback_secret
STORE_MANAGER_PAYMENT_WEBHOOK_TOLERANCE=300
```

`STORE_MANAGER_PAYMENT_ENABLED` is **not** required for doorstep POD QR.

## Merchant prerequisites

1. Merchant has completed HamroPay KYB → `hamropay_merchant_id` (or `hamropay_business_id`) set on the Express merchant row.
2. Shipment is POD/COD/To Pay with `total_collectable_amount` (or `pod_amount`) > 0.
3. Platform HamroPay keys configured (env or Payment Gateway account).

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

**Do not send** `amount`, `method`, or `payment_method` (rejected as `prohibited`).

Preconditions: assigned rider; delivery `out_for_delivery`; `arrived_at` set.

Expected success payload (inside API `data`):

```json
{
  "payment_session_id": "POD34ABCDEF1234",
  "merchant_txn_id": "POD34ABCDEF1234",
  "status": "pending",
  "amount": "1100.00",
  "currency": "NPR",
  "settlement_destination": "merchant",
  "qr_string": "https://.../api/checkout?merchant_id=...&session_id=...",
  "payment_url": "https://.../api/checkout",
  "params": {
    "merchant_id": "...",
    "session_id": "...",
    "token": "...",
    "merchant_transaction_id": "POD34ABCDEF1234",
    "sub_merchant_id": "<store hamropay_merchant_id>",
    "success_url": "...",
    "failure_url": "..."
  },
  "payment": {
    "channel": "qr",
    "purpose": "pod",
    "qr": { "format": "payload", "image_url": null, "payload": "..." },
    "checkout_url": "https://.../api/checkout"
  },
  "expires_at": "..."
}
```

### Poll status

`GET /api/v1/staff/deliveries/{delivery}/payment-session?refresh=1`

Polls HamroPay `getTransaction` when status is still `pending`.

### Complete delivery (online)

`POST /api/v1/staff/deliveries/{delivery}/delivered`

```json
{
  "payment_method": "online",
  "payment_session_id": "POD34ABCDEF1234",
  "customer_confirmed": true,
  "customer_name": "...",
  "customer_signature": "data:image/png;base64,...",
  "pod_collected_amount": 1100.00
}
```

Cash path unchanged: `"payment_method": "cash"` (no session id).

## Flow

1. Rider arrives → chooses Online → Express `POST payment-session` with `{}`.
2. Express requires merchant `hamropay_merchant_id`, calls HamroPay `createSession` with platform `merchantId` + store `subMerchantId`.
3. Express returns `qr_string` / `payment_url` / `params` (never invents QR locally beyond composing HamroPay checkout params).
4. Rider shows QR; customer pays to the store sub-merchant.
5. Staff UI polls `GET payment-session?refresh=1`; Express calls HamroPay `getTransaction`.
6. Optional signed event to Express `POST /api/v1/integrations/store-manager/payment-events` (existing Tukaatu HMAC auth) also triggers a refresh.
7. Rider completes delivery with `payment_method=online` + `payment_session_id` after status is `paid`.

Webhook / redirect never auto-marks the shipment delivered — rider still confirms receipt/signature.

## Ops checklist

1. Confirm production has the HamroPay `StoreManagerPaymentService` (no `STORE_MANAGER_PAYMENT_ENABLED` gate for this endpoint).
2. Confirm platform `HAMROPAY_*` keys (or Payment Gateway account).
3. Confirm failing store has `hamropay_merchant_id` from KYB.
4. As rider: `POST .../payment-session` with `{}` → expect 200 + non-empty `qr_string` / `params`, never the old Store Manager enabled / "KYB is not used" message.
