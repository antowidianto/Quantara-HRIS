<?php

namespace Config\Routes;

use CodeIgniter\Router\RouteCollection;

final class PayrollRoutes
{
    public static function register(RouteCollection $routes): void
    {
        $routes->resource('payroll-components', ['controller' => 'Payroll\PayrollComponentController', 'filter' => 'permission:payroll.manage']);
        $routes->resource('benefit-policies', ['controller' => 'Payroll\BenefitPolicyController', 'filter' => 'permission:payroll.manage']);

        // Specific period actions must remain before the generic resource.
        $routes->post('payroll-periods/(:num)/calculate', 'Payroll\PayrollPeriodController::calculate/$1', ['filter' => ['permission:payroll.calculate', 'idempotency:required']]);
        $routes->post('payroll-periods/(:num)/review-variance', 'Payroll\PayrollPeriodController::reviewVariance/$1', ['filter' => ['permission:payroll.calculate', 'idempotency:required']]);
        $routes->post('payroll-periods/(:num)/submit', 'Payroll\PayrollPeriodController::submit/$1', ['filter' => ['permission:payroll.calculate', 'idempotency:required']]);
        $routes->post('payroll-periods/(:num)/approve', 'Payroll\PayrollPeriodController::approve/$1', ['filter' => ['permission:payroll.finalize', 'idempotency:required']]);
        $routes->post('payroll-periods/(:num)/break-glass-finalize', 'Payroll\PayrollPeriodController::breakGlassFinalize/$1', ['filter' => ['permission:payroll.break_glass', 'idempotency:required']]);
        $routes->post('payroll-periods/(:num)/finalize', 'Payroll\PayrollPeriodController::finalize/$1', ['filter' => ['permission:payroll.finalize', 'idempotency:required']]);
        $routes->post('payroll-periods/(:num)/disburse', 'Payroll\DisbursementController::disburse/$1', ['filter' => ['permission:payroll.finalize', 'idempotency:required']]);
        $routes->get('payroll-periods/(:num)/disburse-status', 'Payroll\DisbursementController::checkStatus/$1', ['filter' => 'permission:payroll.view']);
        $routes->get('payroll-periods/(:num)/pending-approvals', 'Payroll\PayrollPeriodController::pendingApprovals/$1', ['filter' => 'permission:payroll.view']);
        $routes->get('payroll-periods/(:num)/export-summary', 'Payroll\PayrollPeriodController::exportSummary/$1', ['filter' => 'permission:payroll.view']);
        $routes->get('payroll-periods/(:num)/bank-export', 'Payroll\PayrollPeriodController::bankExport/$1', ['filter' => ['permission:payroll.view', 'throttle:export']]);
        $routes->get('payroll-periods/(:num)/pph21-export', 'Payroll\PayrollPeriodController::pph21Export/$1', ['filter' => ['permission:payroll.view', 'throttle:export']]);
        $routes->get('payroll-periods/(:num)/bpjs-export', 'Payroll\PayrollPeriodController::bpjsExport/$1', ['filter' => ['permission:payroll.view', 'throttle:export']]);
        $routes->resource('payroll-periods', ['controller' => 'Payroll\PayrollPeriodController', 'only' => ['index', 'show'], 'filter' => 'permission:payroll.view']);
        $routes->resource('payroll-periods', ['controller' => 'Payroll\PayrollPeriodController', 'only' => ['new', 'create', 'edit', 'update', 'delete'], 'filter' => 'permission:payroll.manage']);

        $routes->get('payslips', 'Payroll\PayslipController::index', ['filter' => 'permission:payslip.view_all']);
        $routes->get('payslips/my', 'Payroll\PayslipController::my', ['filter' => 'permission:payslip.view_self']);
        $routes->get('payslips/(:num)', 'Payroll\PayslipController::show/$1', ['filter' => 'permission:payslip.view_self,payslip.view_all']);
        $routes->get('payslips/(:num)/pdf', 'Payroll\PayslipController::pdf/$1', ['filter' => 'permission:payslip.view_self,payslip.view_all']);
        $routes->get('payslips/(:num)/bukti-potong', 'Payroll\PayslipController::buktiPotong/$1', ['filter' => 'permission:payslip.view_self,payslip.view_all']);
        $routes->get('payroll-adjustments', 'Payroll\PayrollAdjustmentController::index', ['filter' => 'permission:payroll.adjustment.manage']);
        $routes->post('payroll-adjustments', 'Payroll\PayrollAdjustmentController::create', ['filter' => ['permission:payroll.adjustment.manage', 'idempotency:required']]);
        $routes->post('payroll-adjustments/(:num)/submit', 'Payroll\PayrollAdjustmentController::submit/$1', ['filter' => ['permission:payroll.adjustment.manage', 'idempotency:required']]);
        $routes->post('payroll-adjustments/(:num)/reverse', 'Payroll\PayrollAdjustmentController::reverse/$1', ['filter' => ['permission:payroll.adjustment.approve', 'idempotency:required']]);
        $routes->post('payroll-adjustments/(:num)/cancel', 'Payroll\PayrollAdjustmentController::cancel/$1', ['filter' => ['permission:payroll.adjustment.manage', 'idempotency:required']]);
        $routes->post('payroll/thr', 'Payroll\PayrollController::generateThr', ['filter' => ['permission:payroll.manage', 'idempotency:required']]);
        $routes->get('thr/projection', 'Payroll\PayrollController::thrProjection', ['filter' => 'permission:payroll.view,payroll.manage']);
        $routes->get('my-benefit-balances', 'Payroll\ReimbursementController::myBalances', ['filter' => 'permission:reimbursement.view']);
        $routes->post('reimbursements', 'Payroll\ReimbursementController::create', ['filter' => ['permission:reimbursement.create', 'idempotency:required']]);
        $routes->post('reimbursements/(:num)/approve', 'Payroll\ReimbursementController::approve/$1', ['filter' => ['permission:reimbursement.approve', 'idempotency:required']]);
        $routes->post('reimbursements/(:num)/reject', 'Payroll\ReimbursementController::reject/$1', ['filter' => ['permission:reimbursement.approve', 'idempotency:required']]);
        $routes->resource('reimbursements', ['controller' => 'Payroll\ReimbursementController', 'only' => ['index', 'show'], 'filter' => 'permission:reimbursement.view']);
        $routes->resource('reimbursements', ['controller' => 'Payroll\ReimbursementController', 'only' => ['update', 'delete'], 'filter' => 'permission:reimbursement.create']);
        $routes->post('loans', 'Payroll\LoanController::create', ['filter' => ['permission:loan.create', 'idempotency:required']]);
        $routes->resource('loans', ['controller' => 'Payroll\LoanController', 'only' => ['index', 'show'], 'filter' => 'permission:loan.view']);
        $routes->resource('loans', ['controller' => 'Payroll\LoanController', 'only' => ['update', 'delete'], 'filter' => 'permission:loan.create,loan.approve']);
    }
}
