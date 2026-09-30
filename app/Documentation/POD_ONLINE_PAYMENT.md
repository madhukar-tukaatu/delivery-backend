# POD Online Payment (Doorstep) — Ops & Dev Guide

## Root cause of production 422

```
POST /api/v1/staff/deliveries/{id}/payment-session → 422
"Store HamroPay sub-merchant is not registered. Complete HamroPay KYB first."
payload {}
```

**This was a code bug, not a genuine HamroPay KYB prerequisite for doorstep POD.**

Commit `48edc4e` / `52e32bc` rewrote `StoreManagerPaymentService` to call HamroPay
`createSession` and require store sub-merchant KYB. That conflated two money tracks:

| Track | Provider | When |
|---|---|---|
| **Doorstep online POD** | **Store Manager** payment sessions | Rider at door, customer pays merchant QR |
| Cash POD | Local record only | Rider collects cash for later branch deposit |
| HQ settlements / branch commission | **HamroPay** (+ KYB for sub-merchants) | Settlements, not rider POD |

Empty request body `{}` is **valid**. Amount and method are not client-supplied on
`payment-session`; Express derives amount from the shipment
(`total_collectable_amount` / `pod_amount`).

## Fix applied

- Restored `StoreManagerPaymentService` to call Store Manager HTTP APIs
  (`STORE_MANAGER_PAYMENT_BASE_URL` + signed integration headers).
- Removed Express-side `STORE_MANAGER_PAYMENT_ENABLED` gate for create/refresh
  (only empty `BASE_URL` blocks the call with a clear config error).
- Staff controller accepts `{}`; rejects accidental `amount` / `payment_method` on create.
- `delivered` accepts `payment_method` `cash` | `online` | `qr` with
  `payment_session_id` / `merchant_txn_id`.
- Gateway `pod-qr` helpers stay, backed by the same Store Manager sessions.
- HamroPay KYB is **not** required for staff doorstep payment-session.

## Env keys (Express)

```env
# Required for doorstep online POD (Store Manager)
STORE_MANAGER_PAYMENT_BASE_URL=https://tukaatu.com
STORE_MANAGER_PAYMENT_INTEGRATION_ID=tukaatu-express
STORE_MANAGER_PAYMENT_INTEGRATION_SECRET=<shared secret with Store Manager>
STORE_MANAGER_PAYMENT_CREATE_PATH=/api/v1/integrations/tukaatu-express/pod-payment-sessions
STORE_MANAGER_PAYMENT_STATUS_PATH=/api/v1/integrations/tukaatu-express/pod-payment-sessions/{payment_session_id}
STORE_MANAGER_PAYMENT_TIMEOUT=20
STORE_MANAGER_PAYMENT_WEBHOOK_TOLERANCE=300
# Optional legacy flag (Express no longer gates create on this):
STORE_MANAGER_PAYMENT_ENABLED=true
```

HamroPay env (`HAMROPAY_*`) remains for **HQ settlements / KYB**, not doorstep POD.

## Staff rider endpoints

```http
POST /api/v1/staff/deliveries/{id}/payment-session
Content-Type: application/json

{}
```

```http
GET /api/v1/staff/deliveries/{id}/payment-session?refresh=1
```

```http
POST /api/v1/staff/deliveries/{id}/delivered
{
  "payment_method": "online",
  "payment_session_id": "<session id from create>",
  "merchant_txn_id": "<same id ok>",
  "pod_collected_amount": 1100.00,
  "customer_confirmed": true,
  "customer_name": "...",
  "customer_signature": "data:image/png;base64,..."
}
```

## Retest (delivery id 34)

1. Redeploy Express backend with this fix (required — prod still had HamroPay path).
2. Confirm Express env has `STORE_MANAGER_PAYMENT_BASE_URL` + integration secret.
3. Rider: out_for_delivery → arrived → Online/QR → `POST .../payment-session` with `{}`.
4. Expect 200 + session/QR (not 422 HamroPay KYB).
5. Customer pays; poll `GET .../payment-session?refresh=1` until `paid`.
6. Complete delivered with `payment_method=online` + session id.

## Related docs

- `app/Documentation/StoreManagerPodPaymentApi.md` — Store Manager contract
