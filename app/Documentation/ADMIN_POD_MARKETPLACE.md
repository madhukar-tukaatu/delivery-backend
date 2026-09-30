# Admin: Multi-marketplace HamroPay + online POD

## Configure (order)

1. **Admin → Network → Marketplaces** (`/admin/marketplaces`)
   - Tukaatu: API `https://api.tukaatu.com`
   - FCA: API `https://api.fca.com.np`
   - Open each → save **HamroPay** (api_base_url, gateway_url, client_id, client_api_key, secret, HQ merchant_id).
   - Attach stores.
2. **Admin → Network → Merchants → {id}**
   - Set `marketplace_id`, `hamropay_merchant_id` and/or `external_store_id` (STORE-#####).
3. Optional fallback: **Admin → Finance → Payment Gateways** company HamroPay.
4. `.env` `HAMROPAY_*` is optional last resort only. Empty env does not block when marketplace (or company) admin credentials exist.

## POD resolve

shipment → merchant → marketplace → `payment_gateway_accounts` (owner_type=marketplace) → company → branch → env.

Store sub-merchant from merchant row only.

## Retest

1. Leave HAMROPAY_* empty in `.env`.
2. Save marketplace HamroPay in admin.
3. Staff: payment-session `{}` → QR; refresh until paid; delivered with online/qr + merchant_txn_id.
4. Webhook `delivery.delivered` includes paid / pod_collected_amount / payment_method / payment_reference.

## Tests

`php artisan test --filter=PodOnlinePaymentSessionTest` (6 passing).

## Key files

- migration `2026_09_30_150000_extend_marketplaces_for_integrations.php`
- `modules/Setting/Models/Marketplace.php`
- `modules/Setting/Http/Controllers/MarketplaceController.php`
- `modules/Billing/Services/PaymentGatewayAccountService.php` (marketplace owner)
- `modules/POD/Services/HamroPayPodPaymentService.php`
- `modules/Shipment/Jobs/SendShipmentCallback.php` (marketplace callback fallback)
- FE `/admin/marketplaces`, `/admin/marketplaces/[id]`, `/admin/merchants/[id]`
- Doc `app/Documentation/POD_ONLINE_PAYMENT.md`