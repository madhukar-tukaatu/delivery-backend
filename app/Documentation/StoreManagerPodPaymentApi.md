# Store Manager POD Payment API Guide

## Purpose

Tukaatu Express needs a server-to-server API from the Tukaatu Store Manager application (`tukaatu.com`) for pay-on-delivery (POD/COD) shipments.

At delivery time:

- **Prepaid shipment:** no payment API call is required. The rider confirms receipt with the customer and completes delivery.
- **POD shipment paid by cash:** no online payment API call is required. The customer pays the merchant directly in cash and the rider records `cash`.
- **POD shipment paid online:** Tukaatu Express calls the Store Manager API to create or retrieve a payment session for the exact shipment amount. Store Manager returns a shipment-specific QR code/payment session. The customer pays the merchant through that session. Delivery is completed only after Store Manager confirms a successful payment.

The payment must go to the merchant/store, not to Tukaatu Express. The Store Manager application owns the merchant payment-provider integration and must not return a QR code that routes funds to Tukaatu Express.

## Systems and terminology

| System | Responsibility |
|---|---|
| Tukaatu Express | Delivery assignment, rider workflow, shipment amount, delivery completion, and local POD/payment audit record |
| Tukaatu Store Manager (`tukaatu.com`) | Merchant/store identity, merchant payment-provider integration, dynamic QR/payment session, payment verification, and payment events |
| Merchant (for example `ABC Store`) | Receives the POD payment directly through its configured payment account |
| Customer | Pays the exact POD amount by cash or through the returned online QR/payment session |

The Express merchant record already contains the integration identity fields:

- `external_store_id`: Store Manager's immutable store identifier, for example `abcstore_123`.
- `external_platform`: source platform identifier, for example `tukaatu_store_manager`.
- `application_number` / merchant code: Tukaatu Express references for reconciliation.
- `merchant_order_id`: the merchant's order reference, when available.
- `tracking_number`: Tukaatu Express shipment identifier.

Do not identify a merchant by display name alone.

## Recommended base URL

The Express application should configure the Store Manager base URL per environment:

```text
STORE_MANAGER_PAYMENT_BASE_URL=https://tukaatu.com
```

The exact production and sandbox hostnames must be confirmed by the Store Manager team. Tukaatu Express must not hard-code a production URL in application code.

## Authentication and request signing

Use a dedicated integration credential for Tukaatu Express to Store Manager. Do not reuse a merchant's `X-Tukaatu-Key` / `X-Tukaatu-Secret` gateway credential for this reverse-direction call. Those headers authenticate Store Manager's calls into the Express gateway.

Recommended headers:

```http
Accept: application/json
Content-Type: application/json
X-Tukaatu-Integration-Id: <integration identifier>
X-Tukaatu-Timestamp: 1735689600
X-Tukaatu-Signature: <hex hmac sha256>
Idempotency-Key: podpay_<tracking number>
```

Signature calculation:

```text
signed_value = timestamp + "." + raw_request_body
signature = HMAC-SHA256(integration_secret, signed_value)
```

The signature must be calculated over the exact UTF-8 request bytes received by Store Manager. Store Manager should:

1. Reject missing or invalid signatures with `401`.
2. Reject timestamps outside a configured tolerance, recommended five minutes.
3. Prevent replay of a previously accepted timestamp/signature/request ID.
4. Compare the supplied `X-Tukaatu-Integration-Id` with the integration credential.
5. Never accept merchant identity, amount, or payment destination from an unauthenticated request.

The integration secret must be stored in environment/secret storage and never returned in an API response or logged.

## 1. Create or retrieve a POD payment session

### Endpoint

```http
POST {STORE_MANAGER_PAYMENT_BASE_URL}/api/v1/integrations/tukaatu-express/pod-payment-sessions
```

This endpoint must be idempotent. A retry using the same `Idempotency-Key` and the same shipment/order must return the existing open payment session instead of creating a second payment request.

### Preconditions

Store Manager should reject the request when:

- The merchant/store does not exist or is inactive.
- The store identifier is not owned by the authenticated integration.
- The shipment is not a POD/COD/To Pay order.
- The amount is zero, negative, malformed, or does not match the Store Manager order.
- The shipment/order is cancelled, already delivered, refunded, or otherwise terminal.
- The requested currency is unsupported.
- A successful payment already exists, unless the endpoint returns that successful session idempotently.

Tukaatu Express derives the amount from its persisted shipment data. The caller must not be able to change the amount by sending a second arbitrary amount for the same shipment.

### Request example

```json
{
  "request_id": "req_01JQ7QK8TUKAATU01",
  "merchant": {
    "external_store_id": "abcstore_123",
    "external_platform": "tukaatu_store_manager",
    "merchant_reference": "MER-ABC123"
  },
  "shipment": {
    "tracking_number": "TKT-20260914-000123",
    "merchant_order_id": "ABC-ORDER-10045",
    "payment_type": "pod",
    "pod_amount": "1000.00",
    "delivery_charge": "100.00",
    "delivery_charge_paid_by": "customer",
    "total_collectable_amount": "1100.00",
    "currency": "NPR",
    "receiver": {
      "name": "Customer Name",
      "phone": "98XXXXXXXX",
      "city": "Kathmandu",
      "area": "Baneshwor"
    }
  },
  "payment": {
    "channel": "qr",
    "purpose": "pod",
    "requested_at": "2026-09-14T10:30:00Z"
  },
  "callback": {
    "url": "https://express.example.com/api/v1/integrations/store-manager/payment-events",
    "events": ["pod.payment.paid", "pod.payment.failed", "pod.payment.expired"]
  }
}
```

### Required request rules

- `shipment.total_collectable_amount` is the exact amount the customer must pay.
- Amounts must be decimal strings or fixed-precision numbers; do not use floating-point arithmetic for comparisons.
- Currency is currently `NPR` unless both teams agree to support another currency.
- `merchant.external_store_id`, `shipment.tracking_number`, and `shipment.merchant_order_id` must be retained for reconciliation.
- The Store Manager team must compare the amount with its own order record and return `409 AMOUNT_MISMATCH` when the values differ.
- `callback.url` must be allow-listed or registered for the merchant integration. Do not post payment events to an arbitrary URL supplied by an unauthenticated caller.
- The QR/payment session must be created for this shipment and amount, not a reusable generic merchant QR.

### Successful response

Return `201 Created` for a new payment session and `200 OK` when returning an existing idempotent session.

```json
{
  "success": true,
  "data": {
    "payment_session_id": "ps_01JQ7QK8TUKAATU99",
    "status": "pending",
    "merchant": {
      "external_store_id": "abcstore_123",
      "external_platform": "tukaatu_store_manager",
      "name": "ABC Store"
    },
    "shipment": {
      "tracking_number": "TKT-20260914-000123",
      "merchant_order_id": "ABC-ORDER-10045"
    },
    "amount": "1100.00",
    "currency": "NPR",
    "settlement_destination": "merchant",
    "payment": {
      "channel": "qr",
      "purpose": "pod",
      "qr": {
        "format": "image_url",
        "image_url": "https://tukaatu.com/payment-qr/ps_01JQ7Q8TUKAATU99.png",
        "payload": null
      },
      "checkout_url": null
    },
    "expires_at": "2026-09-14T10:45:00Z",
    "created_at": "2026-09-14T10:30:01Z"
  }
}
```

### QR response requirements

Return at least one of:

- `payment.qr.image_url`: HTTPS URL for a QR image that the rider UI can display.
- `payment.qr.payload`: payment URI/payload from which Tukaatu Express can render a QR code.
- `payment.checkout_url`: secure payment page/deep link, if the provider uses a checkout flow.

Prefer returning both `image_url` and `payload` when possible. The QR must encode the payment session and exact amount. It must not be a static generic QR that loses the shipment/payment reference.

`settlement_destination` must be exactly `merchant` for this POD flow. Tukaatu Express will not mark a payment as direct-to-merchant if this value is missing or has another value.

### Error response format

Use a stable error envelope:

```json
{
  "success": false,
  "error": {
    "code": "AMOUNT_MISMATCH",
    "message": "The requested amount does not match the store order.",
    "details": {}
  },
  "request_id": "req_01JQ7QK8TUKAATU01"
}
```

Recommended status/code combinations:

| HTTP | Code | Meaning |
|---:|---|---|
| 400 | `INVALID_REQUEST` | Missing or invalid field |
| 401 | `INVALID_SIGNATURE` | Authentication/signature failed |
| 403 | `STORE_NOT_ALLOWED` | Integration cannot access this store |
| 404 | `STORE_OR_ORDER_NOT_FOUND` | Store/order was not found |
| 409 | `AMOUNT_MISMATCH` | Store amount and Express amount differ |
| 409 | `IDEMPOTENCY_CONFLICT` | Same idempotency key used with different data |
| 409 | `PAYMENT_ALREADY_PAID` | A successful payment already exists |
| 422 | `NOT_POD_ORDER` | Order is not payable on delivery |
| 422 | `PAYMENT_NOT_AVAILABLE` | Store/provider cannot accept online payment |
| 410 | `PAYMENT_EXPIRED` | Existing session has expired |
| 429 | `RATE_LIMITED` | Retry after the server-provided delay |
| 500/503 | `PAYMENT_PROVIDER_ERROR` | Provider unavailable; no payment was confirmed |

## 2. Get payment-session status

### Endpoint

```http
GET {STORE_MANAGER_PAYMENT_BASE_URL}/api/v1/integrations/tukaatu-express/pod-payment-sessions/{payment_session_id}
```

The Express backend may call this endpoint after the customer scans the QR or when a webhook has not arrived. The rider frontend must not call Store Manager directly with provider credentials.

### Successful response

```json
{
  "success": true,
  "data": {
    "payment_session_id": "ps_01JQ7QK8TUKAATU99",
    "status": "paid",
    "merchant": {
      "external_store_id": "abcstore_123",
      "external_platform": "tukaatu_store_manager"
    },
    "shipment": {
      "tracking_number": "TKT-20260914-000123",
      "merchant_order_id": "ABC-ORDER-10045"
    },
    "amount": "1100.00",
    "currency": "NPR",
    "settlement_destination": "merchant",
    "provider_reference": "FONEPAY-987654321",
    "paid_at": "2026-09-14T10:34:10Z",
    "expires_at": "2026-09-14T10:45:00Z"
  }
}
```

Allowed statuses:

```text
pending
paid
failed
expired
cancelled
refunded
```

Only `paid` is a successful payment. A browser redirect, QR scan, client-supplied reference, or `pending` response must not be treated as payment confirmation.

## 3. Payment event webhook to Tukaatu Express

Status polling is a fallback. Store Manager should send a signed event to the callback URL supplied during session creation or registered for the integration.

### Endpoint owned by Tukaatu Express

```http
POST {TUKAATU_EXPRESS_BASE_URL}/api/v1/integrations/store-manager/payment-events
```

### Headers

```http
Content-Type: application/json
X-Tukaatu-Event-ID: evt_01JQ7QK8TUKAATU77
X-Tukaatu-Timestamp: 1735689850
X-Tukaatu-Signature: <hex hmac sha256>
```

Signature:

```text
signed_value = timestamp + "." + raw_request_body
signature = HMAC-SHA256(integration_secret, signed_value)
```

### `pod.payment.paid` event example

```json
{
  "event": "pod.payment.paid",
  "event_id": "evt_01JQ7QK8TUKAATU77",
  "occurred_at": "2026-09-14T10:34:10Z",
  "payment_session_id": "ps_01JQ7QK8TUKAATU99",
  "merchant": {
    "external_store_id": "abcstore_123",
    "external_platform": "tukaatu_store_manager"
  },
  "shipment": {
    "tracking_number": "TKT-20260914-000123",
    "merchant_order_id": "ABC-ORDER-10045"
  },
  "payment": {
    "status": "paid",
    "amount": "1100.00",
    "currency": "NPR",
    "settlement_destination": "merchant",
    "provider_reference": "FONEPAY-987654321",
    "paid_at": "2026-09-14T10:34:10Z"
  }
}
```

The Express webhook handler must:

1. Verify the signature and timestamp before parsing business data.
2. Deduplicate using `X-Tukaatu-Event-ID` and `payment_session_id`.
3. Find the shipment by authenticated merchant/integration identity and `tracking_number`.
4. Compare merchant, order, amount, currency, and settlement destination.
5. Accept only a provider-confirmed `paid` event.
6. Store the provider reference and paid time.
7. Mark the local POD payment as direct-to-merchant and allow delivery completion.
8. Return `200` or `204` after successful idempotent processing.
9. Return `401` for bad signatures, `409` for an irreconcilable duplicate/conflict, and `422` for a validly signed event that fails business reconciliation.

Store Manager should retry non-2xx responses with exponential backoff and stop retrying after receiving a successful response. It must not send a `paid` event until the payment provider has confirmed the payment.

## 4. Delivery workflow using this API

### Prepaid

```text
Shipment payment_type = prepaid
    → no Store Manager payment-session call
    → customer receives parcel
    → rider confirms customer receipt
    → rider completes delivery
    → Express marks shipment delivered
    → pod_status = not_required
    → settlement_status = not_required
```

### POD with cash

```text
Shipment payment_type = pod/cod/to_pay
    → Express shows exact total_collectable_amount
    → customer pays merchant directly in cash
    → rider selects cash
    → Express records payment_destination = merchant
    → Express marks payment paid_direct
    → Express marks shipment delivered
    → no platform deposit or Tukaatu settlement
```

### POD with online payment

```text
Shipment payment_type = pod/cod/to_pay
    → Express backend validates out_for_delivery and positive amount
    → Express calls Store Manager POST payment-session endpoint
    → Store Manager validates ABC Store/order/amount
    → Store Manager creates provider session for the merchant
    → Store Manager returns shipment-specific QR/payment session
    → rider displays the returned QR
    → customer pays ABC Store
    → Store Manager verifies the provider result
    → Store Manager sends pod.payment.paid webhook, or Express polls status
    → Express verifies merchant/order/amount/currency/destination
    → Express records payment_method = online, payment_destination = merchant
    → Express marks payment paid_direct
    → Express permits delivery completion
```

If the session is pending, failed, expired, cancelled, or the payment cannot be reconciled, Express must not mark the POD payment as paid and must not complete the POD delivery.

## 5. Idempotency and reconciliation requirements

Store Manager must persist at least:

- `idempotency_key`
- `payment_session_id`
- `external_store_id`
- `tracking_number`
- `merchant_order_id`
- amount and currency
- provider reference
- payment status
- QR/session expiry
- created, paid, failed, and cancelled timestamps
- raw provider metadata protected from normal API output

The same request must not create two provider sessions. The same webhook must be safe to process repeatedly. A successful payment must be bound to one merchant, one shipment/order, one amount, and one currency.

## 6. What Store Manager must provide to Tukaatu Express

Before production integration, the Store Manager team must provide:

1. Sandbox and production base URLs.
2. Integration credential provisioning and rotation process.
3. The exact supported request/response schema, including whether QR is returned as an image URL, payload, checkout URL, or all three.
4. Provider status verification behavior and webhook retry policy.
5. Signature algorithm and timestamp/replay requirements.
6. Payment expiry, cancellation, refund, and duplicate-payment rules.
7. Confirmation that the payment settles directly to the merchant.
8. Test store/order identifiers and sample successful/failed/expired responses.
9. Rate limits and timeout expectations.
10. A support/reconciliation contact for mismatched payment events.

Until this contract is implemented and credentials are configured, Tukaatu Express must not guess an endpoint or mark a QR scan/reference as a verified online payment. The current static merchant QR path can remain a temporary fallback, but it is not a provider-verified payment flow.

## 7. Delivery callback fields

The delivery lifecycle callback channel also reports the rider milestones that surround payment. The active Express workflow emits these events after the local transaction commits:

```text
delivery.assigned
delivery.accepted
delivery.out_for_delivery
delivery.arrived
delivery.delivered
delivery.failed
```

Each event uses the normal callback envelope (`event`, `event_id`, `occurred_at`, `merchant_id`, `shipment`, and `data`). The `shipment` object contains the current Express shipment status and payment totals. Event-specific `data` includes:

| Event | Important `data` fields |
|---|---|
| `delivery.assigned` | `rider.id`, `rider.name`, `rider.phone` |
| `delivery.accepted` | `rider.id`, `rider.name` |
| `delivery.out_for_delivery` | `rider.id`, `rider.name`, `out_for_delivery_at` |
| `delivery.arrived` | `rider.id`, `rider.name`, `arrived_at` |
| `delivery.delivered` | `payment_method`, `payment_destination`, `payment_reference`, `payment_session_id`, `payment_status`, `pod_collected_amount`, `arrived_at`, `receipt_confirmation`, `remarks` |
| `delivery.failed` | `rider.id`, `rider.name`, `reason`, `failed_at` |

For prepaid deliveries, `delivery.delivered.data.receipt_confirmation` contains `confirmed`, `customer_name`, `confirmed_at`, `signature_present`, and a SHA-256 `signature_sha256` value. The raw signature image remains in Express private storage and is never sent in the merchant callback. For POD cash or verified online payment, `payment_destination` is `merchant`, `payment_status` is `paid_direct`, and the online flow includes the verified `payment_session_id` and provider reference. Store Manager should process callbacks idempotently using `event_id` and the shipment/order identity.