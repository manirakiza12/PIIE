-- Run only through an approved read-only SQL session. No row reconciliation.
-- Results are institutional financial data: retain privately, never in CI logs.
START TRANSACTION READ ONLY;

SELECT school_id, staff_id, pay_period, COUNT(*) AS row_count,
       SUM(status = 'draft') AS draft_rows,
       SUM(status = 'approved') AS approved_rows,
       SUM(status = 'paid') AS paid_rows,
       SUM(net_pay) AS total_net_pay
FROM payroll
GROUP BY school_id, staff_id, pay_period
HAVING COUNT(*) > 1
ORDER BY school_id, staff_id, pay_period;

SELECT p.id, p.school_id, p.staff_id, p.pay_period, p.status,
       p.approved_by, p.net_pay
FROM payroll AS p
JOIN (
    SELECT school_id, staff_id, pay_period
    FROM payroll
    GROUP BY school_id, staff_id, pay_period
    HAVING COUNT(*) > 1
) AS duplicates
  ON p.school_id <=> duplicates.school_id
 AND p.staff_id <=> duplicates.staff_id
 AND p.pay_period <=> duplicates.pay_period
ORDER BY p.school_id, p.staff_id, p.pay_period, p.id;

SELECT COUNT(*) AS total_rows, SUM(net_pay) AS total_net_pay FROM payroll;
SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
COMMIT;
