<?php

use App\Modules\Calendar\Models\BusinessCalendar;
use App\Modules\ComputerCentre\Models\ItAccount;
use App\Modules\ComputerCentre\Models\ItAsset;
use App\Modules\ComputerCentre\Models\LicenceAllocation;
use App\Modules\ComputerCentre\Models\SoftwareTitle;
use App\Modules\Documents\Models\Document;
use App\Modules\EFile\Models\Correspondence;
use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Models\EFileMovement;
use App\Modules\EFile\Models\EFileNote;
use App\Modules\EFile\Models\FileCategory;
use App\Modules\EFile\Models\RetentionSchedule;
use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\BudgetHead;
use App\Modules\Finance\Models\GoodsReceipt;
use App\Modules\Finance\Models\ProcurementCase;
use App\Modules\Finance\Models\ProcurementRuleSet;
use App\Modules\Finance\Models\PurchaseOrder;
use App\Modules\Finance\Models\Vendor;
use App\Modules\Finance\Models\VendorInvoice;
use App\Modules\Identity\Models\Authority;
use App\Modules\Identity\Models\AuthorityAssignment;
use App\Modules\Identity\Models\Committee;
use App\Modules\Identity\Models\CommitteeMember;
use App\Modules\Identity\Models\Delegation;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\Designation;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Models\PdfTemplate;
use App\Modules\OfficialDocuments\Models\PdfTemplateVersion;
use App\Modules\OfficialDocuments\Models\WorkflowDocumentOutput;
use App\Modules\Registrar\Models\Certificate;
use App\Modules\Registrar\Models\CertificateTemplate;
use App\Modules\Registrar\Models\Circular;
use App\Modules\Registrar\Models\LeaveRecord;
use App\Modules\Registrar\Models\LeaveType;
use App\Modules\Registrar\Models\ReimbursementClaim;
use App\Modules\Registrar\Models\RtiRequest;
use App\Modules\Registrar\Models\TravelAuthorisation;
use App\Modules\Workflow\Models\FormTemplate;
use App\Modules\Workflow\Models\FormVersion;
use App\Modules\Workflow\Models\IntegrationJob;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Models\WorkflowVersion;
use Spatie\Permission\Models\Role;

/*
 | Morph map: alias => model. Enforced (AppServiceProvider), so a model that
 | is stored in a polymorphic column without an entry here fails loudly.
 */

return [
    'user' => User::class,
    'department' => Department::class,
    'designation' => Designation::class,
    'authority' => Authority::class,
    'authority_assignment' => AuthorityAssignment::class,
    'committee' => Committee::class,
    'committee_member' => CommitteeMember::class,
    'delegation' => Delegation::class,
    'document' => Document::class,
    'role' => Role::class,
    'business_calendar' => BusinessCalendar::class,
    'notification_template' => NotificationTemplate::class,
    'form_template' => FormTemplate::class,
    'form_version' => FormVersion::class,
    'workflow_definition' => WorkflowDefinition::class,
    'workflow_version' => WorkflowVersion::class,
    'workflow_instance' => WorkflowInstance::class,
    'workflow_task' => WorkflowTask::class,
    'integration_job' => IntegrationJob::class,
    'it_account' => ItAccount::class,
    'it_asset' => ItAsset::class,
    'software_title' => SoftwareTitle::class,
    'licence_allocation' => LicenceAllocation::class,
    'efile' => EFile::class,
    'efile_note' => EFileNote::class,
    'efile_movement' => EFileMovement::class,
    'correspondence' => Correspondence::class,
    'file_category' => FileCategory::class,
    'retention_schedule' => RetentionSchedule::class,
    'leave_type' => LeaveType::class,
    'leave_record' => LeaveRecord::class,
    'travel_authorisation' => TravelAuthorisation::class,
    'reimbursement_claim' => ReimbursementClaim::class,
    'certificate_template' => CertificateTemplate::class,
    'certificate' => Certificate::class,
    'circular' => Circular::class,
    'rti_request' => RtiRequest::class,
    'budget_head' => BudgetHead::class,
    'procurement_rule_set' => ProcurementRuleSet::class,
    'vendor' => Vendor::class,
    'procurement_case' => ProcurementCase::class,
    'purchase_order' => PurchaseOrder::class,
    'goods_receipt' => GoodsReceipt::class,
    'vendor_invoice' => VendorInvoice::class,
    'advance' => Advance::class,
    'pdf_template' => PdfTemplate::class,
    'pdf_template_version' => PdfTemplateVersion::class,
    'workflow_document_output' => WorkflowDocumentOutput::class,
    'official_document' => OfficialDocument::class,
];
