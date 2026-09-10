# Tukaatu Express — Store Integration Guide

This document is for **store / merchant partners** integrating with Tukaatu Express.
It covers:

1. Authentication
2. The API endpoints your store calls
3. The callbacks (webhooks) Tukaatu sends to your store
4. Callback signature verification
5. Status reference

> Base URL (production): `https://tukaatuexpress.com/api`
> All endpoints are prefixed with `/v1`.

---

## 1. Authentication

Your store authenticates every gateway API call with two headers (issued to you on approval):

| Header | Description |
| --- | --- |
| `X-Tukaatu-Key` | Your integration API key |
| `X-Tukaatu-Secret` | Your integration API secret |

Always send:

```
Accept: application/json
Content-Type: application/json
X-Tukaatu-Key: <your key>
X-Tukaatu-Secret: <your secret>
```

---

## 2. Store API endpoints

### 2.1 Create a shipment

`POST /v1/gateway/shipments`

Creating a shipment does **not** create a pickup by itself. If you already have an
open pickup for the same pickup location, the shipment is automatically added to it.

Request body:

```json
{
  "merchant_order_id": "ORD-10231",
  "pickup_location_id": 12,

  "receiver_name": "Sita Sharma",
  "receiver_phone": "9800000000",
  "receiver_email": "sita@example.com",
  "delivery_address": "Lakeside-6, Pokhara",
  "delivery_city": "Pokhara",
  "delivery_area": "Lakeside",
  "delivery_lat": 28.2096,
  "delivery_lng": 83.9856,

  "service_type": "standard",

  "packet": {
    "description": "1 x Cotton Kurta",
    "quantity": 1,
    "weight": 1.5,
    "declared_value": 2500,
    "parcel_type": "non_fragile",
    "fragile": false,
    "products": [
      {
        "product_id": "SKU-001",
        "name": "Cotton Kurta",
        "quantity": 1,
        "unit_price": 2500,
        "unit_weight": 1.5,
        "parcel_type": "non_fragile"
      }
    ]
  },

  "payment_type": "pod",
  "delivery_charge_paid_by": "merchant",

  "self_drop": false,
  "special_instructions": "Call before delivery",
  "remarks": "Handle with care"
}
```

Field rules:

| Field | Required | Notes |
| --- | --- | --- |
| `merchant_order_id` | yes | Your unique order id (string, max 100) |
| `pickup_location_id` | yes | Your registered pickup location id |
| `receiver_name` | yes | Receiver name |
| `receiver_phone` | yes | Receiver phone |
| `receiver_email` | no | Receiver email |
| `delivery_address` | yes | Receiver address |
| `delivery_city` / `delivery_area` | no | Helps branch routing |
| `delivery_lat` / `delivery_lng` | yes | Receiver coordinates |
| `service_type` | yes | `standard` \| `express` \| `same_day` |
| `packet` | yes | Parcel object (below) |
| `packet.quantity` | yes | integer >= 1 |
| `packet.weight` | yes | kg, > 0 |
| `packet.declared_value` | yes | >= 0. **Used as the POD amount for a POD order** if no explicit `pod_amount` is sent |
| `packet.parcel_type` | yes | e.g. `fragile` \| `non_fragile` |
| `packet.fragile` | yes | boolean |
| `packet.products[]` | no | Itemized products (name, quantity, unit_price, unit_weight) |
| `payment_type` | yes | `prepaid` \| `pod` |
| `delivery_charge_paid_by` | yes | `merchant` \| `customer` |
| `self_drop` | no | boolean; you will drop at branch yourself |
| `special_instructions` / `remarks` | no | Free text |

> **POD amount:** For a pay-on-delivery order (`payment_type: "pod"`), the amount the
> rider collects from the customer is taken from `packet.declared_value` (or the sum
> of product `unit_price × quantity`). You may also send an explicit top-level
> `pod_amount` to override it.
>
> **Delivery charge:** `delivery_charge_paid_by: "merchant"` means the Tukaatu
> delivery charge is billed to your store at settlement (not added to the customer
> collection). `"customer"` means the delivery charge is added to what the rider
> collects at the door.

Response `201`:

```json
{
  "success": true,
  "message": "Shipment created successfully.",
  "data": {
    "id": 104,
    "tracking_number": "TEX-20260910-445323",
    "status": "awaiting_pickup",
    "merchant_status": "pending",
    "payment_type": "pod",
    "pod_amount": 2500,
    "delivery_charge": 80,
    "total_collectable_amount": 2500
  }
}
```

### 2.2 Get a shipment

`GET /v1/gateway/shipments/{trackingNumber}`

Returns the current shipment record and status.

### 2.3 Cancel a shipment

`POST /v1/gateway/shipments/{trackingNumber}/cancel`

Cancels a shipment that has not yet progressed past an cancellable state.

### 2.4 Request a pickup (optional)

`POST /v1/gateway/pickups`

```json
{
  "pickup_location_id": 12,
  "store_reference": "PICKUP-88",
  "preferred_pickup_at": "2026-09-11T10:00:00+05:45",
  "remarks": "3 parcels ready"
}
```

`GET /v1/gateway/pickups/{requestNumber}` — retrieve a pickup by its request number.

---

## 3. Callbacks (webhooks)

Tukaatu POSTs lifecycle events to your configured **callback URL** as the shipment
moves through pickup, sorting, transfer and delivery. You receive one consistent,
signed request per event.

### 3.1 Envelope

Every callback body contains:

```json
{
  "application_number": "APP-000123",
  "merchant_reference": "STORE-CODE",
  "event": "delivery.delivered",
  "event_id": "evt_delivery_delivered_ab12...",
  "occurred_at": "2026-09-10T09:20:31+05:45",
  "merchant_id": 41,
  "shipment": {
    "id": 104,
    "tracking_number": "TEX-20260910-445323",
    "merchant_order_id": "ORD-10231",
    "status": "delivered",
    "merchant_status": "delivered",
    "origin_branch_id": 19,
    "destination_branch_id": 20,
    "current_branch_id": 20,
    "payment_type": "pod",
    "pod_amount": 2500,
    "delivery_charge": 80,
    "total_collectable_amount": 2500
  },
  "data": {
    "payment_method": "cash",
    "pod_collected_amount": 2500,
    "remarks": "Delivered to customer"
  }
}
```

> Pickup-phase events use a `pickup` object (with a `rider` block) instead of a
> `shipment` object, but share the same top-level fields (`event`, `event_id`,
> `occurred_at`, `merchant_id`).

### 3.2 Event list (in lifecycle order)

**Pickup phase**

| Event | Meaning |
| --- | --- |
| `pickup.rider_assigned` | A rider was assigned to collect your parcels |
| `pickup.rider_accepted` | Rider accepted the pickup |
| `pickup.rider_started` | Rider is on the way to your store |
| `pickup.rider_arrived` | Rider arrived at your store |
| `shipment.collected` | A specific parcel was collected (one per shipment) |
| `pickup.collected` | All parcels in the pickup were collected |
| `pickup.on_way_to_branch` | Rider left for the branch with your parcels |
| `shipment.received_at_origin` | A parcel was verified/received at the origin branch |
| `pickup.completed` | The pickup is complete |

**Sorting & transfer phase**

| Event | Meaning |
| --- | --- |
| `shipment.sorted_for_delivery` | Parcel sorted for same-branch last-mile delivery |
| `shipment.sorted_for_transfer` | Parcel sorted for branch-to-branch transfer |
| `shipment.in_transit` | Parcel dispatched to the destination branch |
| `shipment.received_at_destination` | Parcel arrived at the destination branch |

**Delivery phase**

| Event | Meaning | `data` fields |
| --- | --- | --- |
| `delivery.assigned` | Delivery rider assigned | `rider: {id,name,phone}` |
| `delivery.accepted` | Rider accepted the delivery | `rider: {id,name}` |
| `delivery.out_for_delivery` | Rider is out for delivery | — |
| `delivery.delivered` | Delivered (POD collected if applicable) | `payment_method`, `pod_collected_amount`, `remarks` |
| `delivery.failed` | Delivery attempt failed | `reason` |

### 3.3 Delivery expectations

- We POST JSON to your callback URL.
- Respond `2xx` to acknowledge. Any non-2xx (or timeout) is retried with backoff
  (up to 5 attempts).
- Callbacks may arrive out of order under retries — treat `event_id` as the unique
  key and use `occurred_at` to order events. Handle events idempotently.

---

## 4. Verifying the signature

Each callback includes these headers:

| Header | Description |
| --- | --- |
| `X-Tukaatu-Event-ID` | Unique event id (matches `event_id` in the body) |
| `X-Tukaatu-Timestamp` | Unix timestamp used in the signature |
| `X-Tukaatu-Signature` | `HMAC-SHA256(timestamp + "." + rawBody, your_callback_secret)` |

Verify before trusting a callback:

```php
$timestamp = $request->header('X-Tukaatu-Timestamp');
$raw       = $request->getContent(); // the exact raw body, unparsed
$expected  = hash_hmac('sha256', $timestamp . '.' . $raw, $YOUR_CALLBACK_SECRET);

if (! hash_equals($expected, $request->header('X-Tukaatu-Signature'))) {
    abort(401, 'Invalid signature');
}
```

```javascript
// Node.js (Express) — use the raw body, not the parsed JSON
const crypto = require("crypto");
const expected = crypto
  .createHmac("sha256", YOUR_CALLBACK_SECRET)
  .update(`${req.header("X-Tukaatu-Timestamp")}.${rawBody}`)
  .digest("hex");

if (expected !== req.header("X-Tukaatu-Signature")) {
  return res.status(401).send("Invalid signature");
}
```

> Sign against the **raw request body** exactly as received (do not re-serialize the
> parsed JSON — key order/whitespace would differ and break the signature).

---

## 5. Status reference

`merchant_status` is the simplified status meant for your store UI. `status` is the
detailed internal courier status.

| `merchant_status` | Covered detailed statuses |
| --- | --- |
| `pending` | `booked`, `awaiting_pickup`, `pickup_assigned` |
| `picked_up` | `picked_up`, `received_at_origin_branch` |
| `in_transit` | `sorted_for_delivery`, `sorted_for_transfer`, `in_transit`, `received_at_destination_branch` |
| `out_for_delivery` | `assigned_to_rider`, `out_for_delivery` |
| `delivered` | `delivered` |
| `failed` | `delivery_failed`, `pickup_failed` |
| `cancelled` | `cancelled` |

---

## 6. Typical end-to-end sequence

```
Create shipment (POST /v1/gateway/shipments)
  → pickup.rider_assigned → rider_accepted → rider_started → rider_arrived
  → shipment.collected → pickup.collected → pickup.on_way_to_branch
  → shipment.received_at_origin → pickup.completed
  → shipment.sorted_for_delivery            (same-branch)
       or shipment.sorted_for_transfer → shipment.in_transit
          → shipment.received_at_destination → shipment.sorted_for_delivery
  → delivery.assigned → delivery.accepted → delivery.out_for_delivery
  → delivery.delivered  (or delivery.failed)
```
