<?php

use App\Modules\ComputerCentre\Actions\AllocateLicence;
use App\Modules\ComputerCentre\Actions\CheckClearance;
use App\Modules\ComputerCentre\Actions\CheckVmQuota;
use App\Modules\ComputerCentre\Actions\Deprovision;
use App\Modules\ComputerCentre\Actions\GrantNetworkAccess;
use App\Modules\ComputerCentre\Actions\IssueAsset;
use App\Modules\ComputerCentre\Actions\ProvisionEmail;
use App\Modules\ComputerCentre\Actions\ProvisionVm;
use App\Modules\ComputerCentre\Actions\RecordAccount;
use App\Modules\ComputerCentre\Services\SoftwareOptions;
use App\Modules\Finance\Actions\ProcurementActions;
use App\Modules\Finance\Options\BudgetHeadsOptions;
use App\Modules\Finance\Options\MyBillableOrdersOptions;
use App\Modules\Finance\Options\MyOpenAdvancesOptions;
use App\Modules\Finance\Options\ReceivableOrdersOptions;
use App\Modules\Finance\Validators\AdvanceSettlementValidator;
use App\Modules\Finance\Validators\GoodsReceiptValidator;
use App\Modules\Finance\Validators\IndentValidator;
use App\Modules\Finance\Validators\InvoiceValidator;
use App\Modules\Registrar\Actions\IssueCertificate;
use App\Modules\Registrar\Actions\LeaveApprove;
use App\Modules\Registrar\Actions\LeaveCancel;
use App\Modules\Registrar\Actions\LeaveReserve;
use App\Modules\Registrar\Actions\PublishCircular;
use App\Modules\Registrar\Actions\RtiActions;
use App\Modules\Registrar\Actions\TravelActions;
use App\Modules\Registrar\Options\LeaveTypesOptions;
use App\Modules\Registrar\Options\MyLeavesOptions;
use App\Modules\Registrar\Options\MyLtcOptions;
use App\Modules\Registrar\Options\MyToursOptions;
use App\Modules\Registrar\Options\RtiRequestsOptions;
use App\Modules\Registrar\Validators\ClaimValidator;
use App\Modules\Registrar\Validators\LeaveApplication;
use App\Modules\Registrar\Validators\LeaveCancellation;
use App\Modules\Registrar\Validators\LtcAuthorisationValidator;
use App\Modules\Registrar\Validators\LtcClaimValidator;
use App\Modules\Registrar\Validators\MedicalClaimValidator;
use App\Modules\Registrar\Validators\RtiAppeal;
use App\Modules\Registrar\Validators\RtiRegistration;
use App\Modules\Registrar\Validators\TravelAuthorisationValidator;
use App\Modules\Workflow\Automation\SetVariablesAction;
use App\Modules\Workflow\Automation\StartWorkflowAction;

return [
    /*
     | Automation actions available to workflow designers: key => class
     | implementing App\Modules\Workflow\Automation\AutomationAction.
     | Departmental modules add theirs here (Computer Centre provisioning ...).
     */
    'actions' => [
        'core.set_variables' => SetVariablesAction::class,
        'core.start_workflow' => StartWorkflowAction::class,

        // Computer Centre (Phase 3)
        'cc.provision_email' => ProvisionEmail::class,
        'cc.grant_network_access' => GrantNetworkAccess::class,
        'cc.check_vm_quota' => CheckVmQuota::class,
        'cc.provision_vm' => ProvisionVm::class,
        'cc.record_account' => RecordAccount::class,
        'cc.issue_asset' => IssueAsset::class,
        'cc.allocate_licence' => AllocateLicence::class,
        'cc.check_clearance' => CheckClearance::class,
        'cc.deprovision' => Deprovision::class,

        // Administration and Registrar (Phase 5)
        'leave.reserve' => LeaveReserve::class,
        'leave.approve' => LeaveApprove::class,
        'leave.cancel' => LeaveCancel::class,
        'registrar.travel' => TravelActions::class,
        'registrar.issue_certificate' => IssueCertificate::class,
        'registrar.publish_circular' => PublishCircular::class,
        'registrar.rti' => RtiActions::class,

        // Finance and Purchase (Phase 6)
        'finance.register' => ProcurementActions::class,
    ],

    // Live option lists for select fields: source key => invokable class.
    'option_sources' => [
        'software_titles' => SoftwareOptions::class,
        'leave_types' => LeaveTypesOptions::class,
        'my_leaves' => MyLeavesOptions::class,
        'my_tours' => MyToursOptions::class,
        'my_ltc' => MyLtcOptions::class,
        'rti_requests' => RtiRequestsOptions::class,
        'budget_heads' => BudgetHeadsOptions::class,
        'receivable_orders' => ReceivableOrdersOptions::class,
        'my_billable_orders' => MyBillableOrdersOptions::class,
        'my_open_advances' => MyOpenAdvancesOptions::class,
    ],

    // Business validation per workflow (settings.validator): key => class
    // implementing App\Modules\Workflow\Forms\SubmissionValidator.
    'validators' => [
        'leave.application' => LeaveApplication::class,
        'leave.cancellation' => LeaveCancellation::class,
        'travel.tour' => TravelAuthorisationValidator::class,
        'travel.ltc' => LtcAuthorisationValidator::class,
        'claim.tour' => ClaimValidator::class,
        'claim.ltc' => LtcClaimValidator::class,
        'claim.medical' => MedicalClaimValidator::class,
        'rti.request' => RtiRegistration::class,
        'rti.appeal' => RtiAppeal::class,
        'purchase.indent' => IndentValidator::class,
        'purchase.receipt' => GoodsReceiptValidator::class,
        'purchase.invoice' => InvoiceValidator::class,
        'advance.settlement' => AdvanceSettlementValidator::class,
    ],

    // When true, delegations created by the delegator need approval by a
    // holder of delegations.manage before they take effect.
    'delegation_requires_approval' => (bool) env('WORKFLOW_DELEGATION_REQUIRES_APPROVAL', false),

    // Workflow categories offered in the designer (free text is also allowed).
    'categories' => ['general', 'it_services', 'leave', 'travel', 'reimbursement', 'certificates', 'procurement', 'finance', 'estate', 'circulars', 'rti'],

    // Default SLA reminder lead (fraction of the step's duration elapsed).
    'reminder_at' => 0.75,
];
