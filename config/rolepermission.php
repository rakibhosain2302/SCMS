<?php

/*
 * The staff roles of a samiti (nit-master). For a role listed here this file is the truth: the
 * first page opened after a change applies it (php artisan nit:roles does it at once, --dry-run
 * shows the changes). The super admin role and roles not listed are never touched.
 * Format: 'Group::ability' or 'Group::*' for a whole group, as on Authorization › Role.
 */

$view = ['Profile::*', 'Dashboard::dashboard_view', 'Member::member_view'];

return [
    'Field Officer' => [
        ...$view,
        'Member::member_create',
        'Finance: Savings::finance_savings_view', 'Finance: Savings::finance_savings_deposit',
        'Finance: Loans::finance_loan_view', 'Finance: Loans::finance_loan_create', 'Finance: Loans::finance_loan_repay',
    ],

    'Cashier' => [
        ...$view,
        'Member::member_create', 'Member fees::member_fee_collect',
        'Finance: Accounting::finance_dashboard_view',
        'Finance: Savings::finance_savings_view', 'Finance: Savings::finance_savings_deposit', 'Finance: Savings::finance_savings_withdraw',
        'Finance: Shares::finance_share_view', 'Finance: Shares::finance_share_manage',
        'Finance: Loans::finance_loan_view', 'Finance: Loans::finance_loan_repay',
        'Finance: Cash & Bank::finance_cashbank_view', 'Finance: Cash & Bank::finance_transfer_view', 'Finance: Cash & Bank::finance_transfer_create',
        'Finance: Income & Expense::finance_income_view', 'Finance: Income & Expense::finance_income_create',
        'Finance: Income & Expense::finance_expense_view', 'Finance: Income & Expense::finance_expense_create',
        'Finance: Reports::finance_report_view',
    ],

    // Approves what cashiers and officers write (maker-checker) and runs the branch.
    'Branch Manager' => [
        ...$view,
        'Member::*', 'Member fees::*',
        'Finance: Accounting::finance_dashboard_view', 'Finance: Accounting::finance_journal_view', 'Finance: Accounting::finance_transaction_reverse',
        'Finance: Savings::*', 'Finance: Shares::finance_share_view', 'Finance: Shares::finance_share_manage',
        'Finance: Loans::*',
        'Finance: Cash & Bank::*',
        'Finance: Income & Expense::*',
        'Finance: Reports::finance_report_view',
    ],

    'Accountant' => [
        ...$view,
        'Finance: Accounting::*',
        'Finance: Cash & Bank::*',
        'Finance: Income & Expense::*',
        'Finance: Reports::*',
        'Finance: Savings::finance_savings_view', 'Finance: Savings::finance_savings_interest',
        'Finance: Shares::*',
        'Finance: Loans::finance_loan_view',
    ],

    // Sees everything of every branch, changes nothing.
    'Auditor' => [
        ...$view,
        'Finance: Accounting::finance_dashboard_view', 'Finance: Accounting::finance_account_view', 'Finance: Accounting::finance_journal_view',
        'Finance: Cash & Bank::finance_cashbank_view', 'Finance: Cash & Bank::finance_transfer_view',
        'Finance: Savings::finance_savings_view', 'Finance: Shares::finance_share_view', 'Finance: Loans::finance_loan_view',
        'Finance: Income & Expense::finance_income_view', 'Finance: Income & Expense::finance_expense_view',
        'Finance: Reports::*',
    ],
];
