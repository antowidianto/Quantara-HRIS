<?php

namespace App\Libraries\Approval;

use App\Libraries\Approval\Adapters\AssetTransactionApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\AttendanceChangeApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\BonusApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\BusinessTripApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\CandidateOfferApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\CompensationReviewApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\EmployeeSensitiveChangeApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\GeneratedContractApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\HireConversionApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\IdpActivationApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\LeaveApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\LoanApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\MasterRateChangeApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\OvertimeApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\PayrollAdjustmentApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\PayrollPeriodApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\PerformanceGoalApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\PrivilegedActionApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\ReimbursementApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\TimesheetApprovalDomainAdapter;
use App\Libraries\Approval\Adapters\TrainingRegistrationApprovalDomainAdapter;

final class ApprovalDomainAdapterRegistry
{
    /** @var array<string,ApprovalDomainAdapterInterface> */
    private array $adapters = [];

    /** @param iterable<ApprovalDomainAdapterInterface>|null $adapters */
    public function __construct(?iterable $adapters = null)
    {
        $adapters ??= [
            new LeaveApprovalDomainAdapter(),
            new OvertimeApprovalDomainAdapter(),
            new AttendanceChangeApprovalDomainAdapter(),
            new ReimbursementApprovalDomainAdapter(),
            new LoanApprovalDomainAdapter(),
            new BusinessTripApprovalDomainAdapter(),
            new BonusApprovalDomainAdapter(),
            new PayrollPeriodApprovalDomainAdapter(),
            new PayrollAdjustmentApprovalDomainAdapter(),
            new CompensationReviewApprovalDomainAdapter(),
            new TrainingRegistrationApprovalDomainAdapter(),
            new TimesheetApprovalDomainAdapter(),
            new CandidateOfferApprovalDomainAdapter(),
            new GeneratedContractApprovalDomainAdapter(),
            new AssetTransactionApprovalDomainAdapter(),
            new HireConversionApprovalDomainAdapter(),
            new PerformanceGoalApprovalDomainAdapter(),
            new IdpActivationApprovalDomainAdapter(),
            new EmployeeSensitiveChangeApprovalDomainAdapter(),
            new MasterRateChangeApprovalDomainAdapter(),
            new PrivilegedActionApprovalDomainAdapter(),
        ];
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->module()] = $adapter;
        }
    }

    public function forModule(string $module): ApprovalDomainAdapterInterface
    {
        $module = strtoupper(trim($module));
        if (!isset($this->adapters[$module])) {
            throw new \RuntimeException(
                "Modul {$module} belum memiliki domain adapter; final approval diblokir"
            );
        }

        return $this->adapters[$module];
    }
}
