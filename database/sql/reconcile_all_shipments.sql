-- =====================================================================
-- COMPREHENSIVE RECONCILIATION: ALL SHIPMENTS & INTER-BRANCH SETTLEMENTS
-- Applies universal rule: Origin branch is ALWAYS the collecting branch
-- that invoices/collects from the merchant and pays all destination,
-- transit, and HQ shares.
-- =====================================================================

START TRANSACTION;

-- 1. Standardize delivery charge payer to 'merchant' across all shipments
UPDATE `shipments`
SET `delivery_charge_paid_by` = 'merchant',
    `updated_at` = NOW()
WHERE `delivery_charge_paid_by` = 'customer' OR `delivery_charge_paid_by` IS NULL;

-- 2. Delivery charge invoices should reflect actual collection status:
-- (Do NOT force mark as paid; invoices remain unpaid until the branch collects payment)

-- 3. Universal fix for ALL shipment branch shares across the entire database:
-- Set collecting_branch_id to the shipment's origin branch.
-- Set collection_mode to 'merchant_invoice' (or 'company_invoice' if company payer).
UPDATE `shipment_branch_shares` s
JOIN `shipments` sh ON sh.id = s.shipment_id
SET s.`collecting_branch_id` = sh.`origin_branch_id`,
    s.`collection_mode` = CASE 
        WHEN sh.`delivery_free_by` = 'marketplace' THEN 'company_invoice'
        ELSE 'merchant_invoice'
    END,
    s.`updated_at` = NOW()
WHERE s.`collecting_branch_id` IS NULL 
   OR s.`collecting_branch_id` != sh.`origin_branch_id`
   OR s.`collection_mode` = 'customer';

-- 4. For every share where the branch is the origin collecting branch:
-- Origin branch keeps its own share -> status = 'settled', statement_id = NULL
UPDATE `shipment_branch_shares` s
JOIN `shipments` sh ON sh.id = s.shipment_id
SET s.`status` = 'settled',
    s.`statement_id` = NULL,
    s.`updated_at` = NOW()
WHERE s.`branch_id` = sh.`origin_branch_id`
  AND (s.`status` != 'settled' OR s.`statement_id` IS NOT NULL);

-- 5. For every share where the branch is a destination or transit branch:
-- If not yet on a statement, status should be 'pending' (ready for next statement)
UPDATE `shipment_branch_shares` s
JOIN `shipments` sh ON sh.id = s.shipment_id
SET s.`status` = 'pending',
    s.`updated_at` = NOW()
WHERE s.`branch_id` != sh.`origin_branch_id`
  AND s.`statement_id` IS NULL
  AND s.`status` = 'settled';

-- 6. Specific historical statements (1, 2, 3, 4, 5) reconciliation
UPDATE `inter_branch_statements` SET `from_branch_id` = 19, `to_branch_id` = 20, `total_amount` = 239.39, `line_count` = 1, `updated_at` = NOW() WHERE `id` = 1;
UPDATE `inter_branch_statements` SET `from_branch_id` = 19, `to_branch_id` = 21, `total_amount` = 63.40, `line_count` = 1, `updated_at` = NOW() WHERE `id` = 2;
UPDATE `inter_branch_statements` SET `from_branch_id` = 19, `to_branch_id` = 26, `total_amount` = 33.80, `line_count` = 1, `updated_at` = NOW() WHERE `id` = 3;
UPDATE `inter_branch_statements` SET `from_branch_id` = 24, `to_branch_id` = 19, `total_amount` = 134.34, `line_count` = 3, `updated_at` = NOW() WHERE `id` = 4;
UPDATE `inter_branch_statements` SET `from_branch_id` = 19, `to_branch_id` = 18, `total_amount` = 88.00, `line_count` = 1, `updated_at` = NOW() WHERE `id` = 5;

-- 7. Link historical destination shares to statements
UPDATE `shipment_branch_shares` SET `statement_id` = 1, `status` = 'on_statement' WHERE `id` = 103;
UPDATE `shipment_branch_shares` SET `statement_id` = 2, `status` = 'on_statement' WHERE `id` = 111;
UPDATE `shipment_branch_shares` SET `statement_id` = 3, `status` = 'on_statement' WHERE `id` = 110;
UPDATE `shipment_branch_shares` SET `statement_id` = 4, `status` = 'on_statement' WHERE `id` IN (115, 119, 123);
UPDATE `shipment_branch_shares` SET `statement_id` = 5, `status` = 'on_statement' WHERE `id` = 137;

-- 8. Reconstruct Statement Lines
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

COMMIT;
