# POD ensureConfigured removal (Express)

## Problem
`StoreManagerPaymentService::ensureConfigured()` blocked rider Online QR when
`STORE_MANAGER_PAYMENT_ENABLED` was false (Express-side gate). That flag is
Store Manager / ops concern, not Express.

## Change
- Removed `ensureConfigured()` entirely (enabled + preflight secret/integration checks).
- `createForShipment` still validates shipment eligibility via `assertEligibleShipment`
  (merchant, external_store_id, POD/COD amount, status).
- Then always POSTs shipment/receiver payload to Store Manager.
- Only clear config failure before call: empty `STORE_MANAGER_PAYMENT_BASE_URL`.
- Secret/auth failures surface as SM HTTP errors (401/403 messaging already present).
- `refreshSession` no longer gated; soft-returns if base_url empty.
- StaffDeliveryController docblock updated accordingly.
- FE unchanged: staff/deliveries Online QR still `staffCreatePaymentSession(id)` -> POST `{}`.

## Files
- modules/POD/Services/StoreManagerPaymentService.php
- modules/Delivery/Http/Controllers/StaffDeliveryController.php (docblock only)

Do NOT commit/push from this task.
