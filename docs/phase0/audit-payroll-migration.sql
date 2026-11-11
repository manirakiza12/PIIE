-- Read-only pre-deployment SQL for the payroll migration.
-- Compatible with the EXISTING production schema: does not reference any
-- column or table introduced by 2026_11_05_000001_enhance_payroll_module_tables.
-- Run through an approved read-only SQL session only. No row reconciliation.
START TRANSACTION READ ONLY;

-- Duplicate payroll periods (blocks the unique index).
SELECT school_id, staff_id, pay_period, COUNT(*) AS row_count
FROM payroll
GROUP BY school_id, staff_id, pay_period
HAVING COUNT(*) > 1
ORDER BY school_id, staff_id, pay_period;

-- Historical paid payroll records (count and total net pay). These are the
-- rows whose paid_amount will be reconciled by the migration. Computed
-- without referencing paid_amount, which does not exist pre-migration.
SELECT COUNT(*) AS historical_paid_record_count,
       COALESCE(SUM(net_pay), 0) AS historical_paid_net_pay_total
FROM payroll
WHERE status = 'paid';

-- Whether payroll_payments exists pre-migration (0 = not yet created).
SELECT COUNT(*) AS payroll_payments_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_payments';

-- Existing salary_structures columns (to confirm pre-migration baseline).
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salary_structures'
ORDER BY ORDINAL_POSITION;

-- Existing payroll table columns (to confirm pre-migration baseline).
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'
ORDER BY ORDINAL_POSITION;

-- Existing indexes on payroll.
SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

-- Table size for schema-lock risk assessment.
SELECT table_name, table_rows, data_length + index_length AS bytes
FROM information_schema.tables
WHERE TABLE_SCHEMA = DATABASE() AND table_name IN ('payroll', 'salary_structures');

COMMIT;
