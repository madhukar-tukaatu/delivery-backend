-- =====================================================================
-- RESTORE UNPAID DELIVERY CHARGES
-- Reverts delivery charge invoices that were falsely marked paid by the
-- 12 Nov reconciliation script without any recorded payment in merchant_invoice_payments.
-- =====================================================================

START TRANSACTION;

UPDATE `invoices`
SET `status` = 'unpaid',
    `paid_at` = NULL,
    `outstanding_amount` = `total_amount`,
    `updated_at` = NOW()
WHERE `type` = 'delivery_charges'
  AND `status` = 'paid'
  AND NOT EXISTS (
      SELECT 1 FROM `merchant_invoice_payments`
      WHERE `merchant_invoice_payments`.`invoice_id` = `invoices`.`id`
        AND `merchant_invoice_payments`.`status` = 'recorded'
  );

COMMIT;
