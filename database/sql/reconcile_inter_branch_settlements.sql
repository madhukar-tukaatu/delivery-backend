-- =====================================================================
-- RECONCILIATION PATCH: INTER-BRANCH SETTLEMENTS & DELIVERY CHARGE BILLS
-- Fixes direction inversion where destination branch was recorded as payer
-- instead of the origin branch that collected the merchant delivery charge.
-- =====================================================================

START TRANSACTION;

-- 1. Delivery charge invoices should reflect actual collection status from merchants

-- 2. Fix collecting_branch_id and collection_mode on shipment_branch_shares
-- Origin branch is ALWAYS the collecting branch when merchant pays delivery charges.

-- Shipment 231 (origin 19 Kathmandu, dest 20 Pokhara)
UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 19, `collection_mode` = 'merchant_invoice', `statement_id` = NULL, `status` = 'settled', `updated_at` = NOW() 
WHERE `id` = 102; -- Kathmandu origin share kept by collector

UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 19, `collection_mode` = 'merchant_invoice', `statement_id` = 1, `status` = 'on_statement', `updated_at` = NOW() 
WHERE `id` = 103; -- Pokhara delivery share on Statement 1

-- Shipment 233 (origin 19 Kathmandu, dest 21 Butwal, transit 26 Damauli)
UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 19, `collection_mode` = 'merchant_invoice', `statement_id` = NULL, `status` = 'settled', `updated_at` = NOW() 
WHERE `id` = 109; -- Kathmandu origin share kept by collector

UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 19, `collection_mode` = 'merchant_invoice', `statement_id` = 3, `status` = 'on_statement', `updated_at` = NOW() 
WHERE `id` = 110; -- Damauli transit share on Statement 3

UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 19, `collection_mode` = 'merchant_invoice', `statement_id` = 2, `status` = 'on_statement', `updated_at` = NOW() 
WHERE `id` = 111; -- Butwal delivery share on Statement 2

-- Shipments 234, 235, 242 (origin 24 Hetauda, dest 19 Kathmandu)
UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 24, `collection_mode` = 'merchant_invoice', `statement_id` = NULL, `status` = 'settled', `updated_at` = NOW() 
WHERE `id` IN (114, 118, 122); -- Hetauda origin shares kept by collector

UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 24, `collection_mode` = 'merchant_invoice', `statement_id` = 4, `status` = 'on_statement', `updated_at` = NOW() 
WHERE `id` IN (115, 119, 123); -- Kathmandu delivery shares on Statement 4

-- Shipment 253 (origin 19 Kathmandu, dest 18 Itahari)
UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 19, `collection_mode` = 'merchant_invoice', `statement_id` = NULL, `status` = 'settled', `updated_at` = NOW() 
WHERE `id` = 136; -- Kathmandu origin share kept by collector

UPDATE `shipment_branch_shares` 
SET `collecting_branch_id` = 19, `collection_mode` = 'merchant_invoice', `statement_id` = 5, `status` = 'on_statement', `updated_at` = NOW() 
WHERE `id` = 137; -- Itahari delivery share on Statement 5

-- 3. Update Statements with correct Payer (from_branch_id) and Payee (to_branch_id) & Amounts
-- Statement 1: Kathmandu Franchise owes Pokhara Franchise delivery share Rs 239.39
UPDATE `inter_branch_statements`
SET `from_branch_id` = 19, `to_branch_id` = 20, `total_amount` = 239.39, `line_count` = 1, `updated_at` = NOW()
WHERE `id` = 1;

-- Statement 2: Kathmandu Franchise owes Butwal Franchise Branch delivery share Rs 63.40
UPDATE `inter_branch_statements`
SET `from_branch_id` = 19, `to_branch_id` = 21, `total_amount` = 63.40, `line_count` = 1, `updated_at` = NOW()
WHERE `id` = 2;

-- Statement 3: Kathmandu Franchise owes Damauli Franchise Branch transit share Rs 33.80
UPDATE `inter_branch_statements`
SET `from_branch_id` = 19, `to_branch_id` = 26, `total_amount` = 33.80, `line_count` = 1, `updated_at` = NOW()
WHERE `id` = 3;

-- Statement 4: Hetauda Franchise Branch owes Kathmandu Franchise delivery share Rs 134.34 (36.07 + 36.07 + 62.20)
UPDATE `inter_branch_statements`
SET `from_branch_id` = 24, `to_branch_id` = 19, `total_amount` = 134.34, `line_count` = 3, `updated_at` = NOW()
WHERE `id` = 4;

-- Statement 5: Kathmandu Franchise owes Itahari Tukaatu delivery share Rs 88.00
UPDATE `inter_branch_statements`
SET `from_branch_id` = 19, `to_branch_id` = 18, `total_amount` = 88.00, `line_count` = 1, `updated_at` = NOW()
WHERE `id` = 5;

-- 4. Reconstruct Statement Lines to reference beneficiary delivery/transit shares
DELETE FROM `inter_branch_statement_lines` WHERE `statement_id` IN (1, 2, 3, 4, 5);

INSERT INTO `inter_branch_statement_lines` 
(`id`, `statement_id`, `shipment_branch_share_id`, `shipment_id`, `role`, `share_amount`, `transport_amount`, `extra_distance_amount`, `amount`, `created_at`, `updated_at`) 
VALUES
(1, 1, 103, 231, 'delivery', 4.49, 0.00, 234.90, 239.39, '2026-10-18 06:23:01', NOW()),
(2, 2, 111, 233, 'delivery', 59.14, 0.00, 4.26, 63.40, '2026-10-18 06:23:01', NOW()),
(3, 3, 110, 233, 'transit', 33.80, 0.00, 0.00, 33.80, '2026-10-18 06:23:01', NOW()),
(4, 4, 115, 234, 'delivery', 28.15, 0.00, 7.92, 36.07, '2026-10-18 06:23:01', NOW()),
(5, 4, 119, 235, 'delivery', 28.15, 0.00, 7.92, 36.07, '2026-10-18 06:23:01', NOW()),
(6, 4, 123, 242, 'delivery', 42.40, 0.00, 19.80, 62.20, '2026-10-18 06:23:01', NOW()),
(7, 5, 137, 253, 'delivery', 88.00, 0.00, 0.00, 88.00, '2026-10-25 06:23:01', NOW());

-- 5. Fix any transfer shipments (including TEX-20261107-782642) where collecting_branch_id was inverted to destination
UPDATE `shipment_branch_shares` s
JOIN `shipments` sh ON sh.id = s.shipment_id
SET s.`collecting_branch_id` = sh.`origin_branch_id`,
    s.`collection_mode` = 'merchant_invoice',
    s.`status` = CASE WHEN s.`branch_id` = sh.`origin_branch_id` THEN 'settled' ELSE 'pending' END,
    s.`updated_at` = NOW()
WHERE s.`statement_id` IS NULL
  AND s.`collecting_branch_id` != sh.`origin_branch_id`;

COMMIT;
