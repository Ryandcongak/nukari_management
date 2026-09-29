-- Reset data operasional untuk pengujian pengguna.
-- Data yang dipertahankan: users, employees, payroll_settings, transaction_categories.
-- Pengaman wajib: script hanya dapat dijalankan pada database berakhiran _test
-- atau _testing. Gunakan database terpisah, jangan database operasional.
DELIMITER //
DROP PROCEDURE IF EXISTS nukari_assert_test_database//
CREATE PROCEDURE nukari_assert_test_database()
BEGIN
    IF DATABASE() NOT REGEXP '(_test|_testing)$' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Reset dibatalkan: gunakan database berakhiran _test atau _testing';
    END IF;
END//
CALL nukari_assert_test_database()//
DROP PROCEDURE nukari_assert_test_database//
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE activity_logs;
TRUNCATE TABLE asset_audits;
TRUNCATE TABLE asset_borrowings;
TRUNCATE TABLE asset_documents;
TRUNCATE TABLE asset_events;
TRUNCATE TABLE asset_maintenance;
TRUNCATE TABLE asset_maintenance_plans;
TRUNCATE TABLE asset_movements;
TRUNCATE TABLE asset_reminders;
TRUNCATE TABLE asset_rental_payments;
TRUNCATE TABLE asset_rentals;
TRUNCATE TABLE asset_sales;
TRUNCATE TABLE assets;
TRUNCATE TABLE attendances;
TRUNCATE TABLE clients;
TRUNCATE TABLE debt_payments;
TRUNCATE TABLE debts;
TRUNCATE TABLE employee_cash_advances;
TRUNCATE TABLE employee_leaves;
TRUNCATE TABLE employee_loan_payments;
TRUNCATE TABLE employee_loans;
TRUNCATE TABLE operational_fund_movements;
TRUNCATE TABLE operational_funds;
TRUNCATE TABLE payroll_additions;
TRUNCATE TABLE payroll_components;
TRUNCATE TABLE payroll_project_allocations;
TRUNCATE TABLE payrolls;
TRUNCATE TABLE product_cashier_sale_items;
TRUNCATE TABLE product_cashier_sales;
TRUNCATE TABLE product_custom_orders;
TRUNCATE TABLE product_hpp_calculations;
TRUNCATE TABLE product_production_materials;
TRUNCATE TABLE product_productions;
TRUNCATE TABLE product_sales;
TRUNCATE TABLE products;
TRUNCATE TABLE project_asset_photos;
TRUNCATE TABLE project_fund_receipts;
TRUNCATE TABLE project_funds;
TRUNCATE TABLE project_galleries;
TRUNCATE TABLE project_task_logs;
TRUNCATE TABLE project_task_members;
TRUNCATE TABLE project_tasks;
TRUNCATE TABLE project_milestones;
TRUNCATE TABLE project_transaction_receipts;
TRUNCATE TABLE project_transactions;
TRUNCATE TABLE project_workers;
TRUNCATE TABLE project_payment_terms;
TRUNCATE TABLE projects;
TRUNCATE TABLE raw_material_movements;
TRUNCATE TABLE raw_materials;
TRUNCATE TABLE reimbursement_payments;
TRUNCATE TABLE reimbursements;
TRUNCATE TABLE suppliers;
TRUNCATE TABLE system_notifications;
TRUNCATE TABLE transactions;
TRUNCATE TABLE finance_accounts;

SET FOREIGN_KEY_CHECKS = 1;
