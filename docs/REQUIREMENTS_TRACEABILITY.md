# Requirements traceability

Brief § = section of the master development prompt. Status: ✅ done and
tested · 🟡 partial (see note) · ⬜ not started · ⛔ blocked (external
dependency). "Tests" names the test class that proves the behaviour.
Paths are under `app/Modules/` unless stated.

Last updated: 2026-10-11 — 164 PHPUnit tests (+3 Proxmox), 39 shared
rule/formula fixtures in Node, browser scenario `tests/Browser/flow.mjs`.

## Identity and access (Brief §7)

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| AUTH-001 | LDAP bind authentication, LDAPS/StartTLS, certificate validation | ✅ | `Identity/Directory/LdapDirectoryService` | `Integration\LdapDirectoryTest` (real OpenLDAP), `AuthenticationTest` |
| AUTH-002 | Fail closed when the directory is unreachable | ✅ | `Identity/Services/LoginService` | `AuthenticationTest::test_sign_in_fails_closed…` |
| AUTH-003 | Configurable LDAP attribute map | ✅ | `config/directory.php`, `DirectoryUser::fromAttributes` | `DirectorySyncTest`, `LdapDirectoryTest` |
| AUTH-004 | Disabled / deactivated accounts cannot sign in | ✅ | `User::canSignIn`, `LoginService` | `AuthenticationTest` |
| AUTH-005 | Rate limiting; no credentials logged | ✅ | `AppServiceProvider` (`login` limiter), `AuditLogger::redact` | `AuthenticationTest`, `AuditChainTest` |
| AUTH-006 | Local accounts for vendors / external users | ✅ | `Admin/Http/UserController::storeLocal` | `AdminAuthorizationTest`, `AuthenticationTest` |
| AUTH-007 | Scheduled directory sync, safe against partial responses | ✅ | `Identity/Console/SyncDirectory` | `DirectorySyncTest` |
| IAM-001 | Departments (tree), designations | ✅ | `Admin/Http/OrganisationController` | `AdminAuthorizationTest` |
| IAM-002 | Reporting hierarchy (cycle-safe) | ✅ | `UserController::update` | `AdminAuthorizationTest::test_reporting_cycles…` |
| IAM-003 | Authorities with dated / acting holders | ✅ | `AuthorityController`, `AssigneeResolver` | `EngineTest` |
| IAM-004 | Committees: periods, roles, weights | ✅ | `CommitteeController` | `EngineTest`, `CommitteeRuleTest` |
| IAM-005 | Roles/permissions enforced on the backend | ✅ | `Identity/Permissions`, controllers `authorize()` | `AdminAuthorizationTest` |
| IAM-006 | New users never get admin rights | ✅ | `UserProvisioner` | `AuthenticationTest` |
| IAM-007 | Delegation: period, scope, actions, revocation, audit, SoD | ✅ | `Workflow/Engine/DelegationService` | `EngineTest` (delegation tests) |

## Workflow engine (Brief §8)

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| WF-001 | Definitions in the database, admin-managed | ✅ | `Workflow/Graph/WorkflowDesigner` | `AdministratorBuildsWorkflowTest` |
| WF-002 | Immutable published versions; instances pinned | ✅ | `WorkflowVersion`, `WorkflowNode` guards | `EngineTest::test_running_requests_keep…`, `…immutable` |
| WF-003 | Graphical designer: save, reload, validate, publish | ✅ | `Pages/Admin/Workflows/Designer.vue` (Vue Flow) | `AdministratorBuildsWorkflowTest`, `flow.mjs` |
| WF-004 | Graph validation before publish | ✅ | `Workflow/Graph/GraphValidator` | `DesignAndFormsTest` |
| WF-005 | Start, validate, number, draft | ✅ | `WorkflowEngine::start/submit` | `EngineTest` |
| WF-006 | Sequential approvals | ✅ | `WorkflowEngine` | `EngineTest` |
| WF-007 | Conditional routing | ✅ | decision nodes + `RuleEvaluator` | `EngineTest`, `RuleFixturesTest` |
| WF-008 | Parallel split/join (all / any) | ✅ | `WorkflowEngine::join` | `EngineTest::test_parallel…` |
| WF-009 | Committee: any/all/majority/quorum/weighted | ✅ | `Engine/CommitteeRule` | `CommitteeRuleTest`, `EngineTest` |
| WF-010 | Approve, reject, return (clarification/documents), forward, reassign, hold, resume, cancel, remarks, step fields | ✅ | `WorkflowEngine` | `EngineTest`, `AdministratorBuildsWorkflowTest` |
| WF-011 | Assignment: user, role, authority, reporting chain, committee, form field | ✅ | `Engine/AssigneeResolver` | `EngineTest` |
| WF-012 | A task cannot be decided twice | ✅ | row lock + partial unique index | `EngineTest::test_a_task_cannot_be_decided_twice` |
| WF-013 | State machine | ✅ | `WorkflowInstance::TRANSITIONS` | `EngineTest::test_invalid_state_transitions…` |
| WF-014 | Deadlines on the business calendar | ✅ | `Calendar/Services/BusinessTime` | `BusinessTimeTest` |
| WF-015 | Reminders + multi-level escalation, idempotent | ✅ | `Engine/SlaMonitor` | `SlaAutomationTest` |
| WF-016 | Automation via registered actions; blocked → manual task | ✅ | `Automation/*`, `Jobs/RunAutomation` | `SlaAutomationTest`, `ComputerCentrePilotTest` |
| WF-017 | Dashboards: mine, pending, returned, overdue, delegated, department, monitor | ✅ | `TaskController`, `MonitorController`, `DashboardStats` | `AdministratorBuildsWorkflowTest` |
| WF-018 | Instance history / timeline | ✅ | `workflow_actions`, `InstancePresenter` | `AdministratorBuildsWorkflowTest` |
| WF-019 | Separation of duties | ✅ | `WorkflowEngine::authorizeTask/resolveAssignees` | `EngineTest` |

## Forms (Brief §9)

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| FORM-001 | Builder with all listed field types | ✅ | `Forms/FieldTypes`, `Pages/Admin/Forms/Builder.vue` | `DesignAndFormsTest` |
| FORM-002 | Field configuration incl. data source | ✅ | `FormSchemaValidator`, `OptionSources` | `DesignAndFormsTest`, `ComputerCentrePilotTest` (software) |
| FORM-003 | Conditional visibility/requirement on the server | ✅ | `FormDataValidator`, `rules.ts` | `DesignAndFormsTest`, shared fixtures |
| FORM-004 | Versioned templates; submissions keep their version | ✅ | `FormVersion`, `form_submissions` | `EngineTest::test_return…` |
| FORM-005 | Repeating tables, calculated fields | ✅ | `Rules/Formula`, `formula.ts` | `RuleFixturesTest`, `DesignAndFormsTest` |

## Platform services

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| SEC-001 | Hash-chained append-only audit + verification | ✅ (tamper-evident, see SECURITY R-1) | `Audit/AuditLogger`, triggers | `AuditChainTest` |
| SEC-002 | Secret redaction | ✅ | `config/audit.php` | `AuditChainTest` |
| SEC-003 | Security headers / nonce CSP | ✅ | `Http/Middleware/SecurityHeaders` | manual header check |
| SEC-004 | Private documents, content sniffing, per-request authorization | ✅ | `Documents/*` | `DocumentStoreTest` |
| NOTIF-001 | Template notifications: mail, in-app, SMS, WhatsApp | 🟡 mail + in-app ✅; SMS/WhatsApp log driver only ⛔ | `Notifications/*` | `NotificationDispatcherTest` |
| NOTIF-002 | Queued delivery, retries, attempts, idempotency | ✅ | `Jobs/DeliverNotification` | `NotificationDispatcherTest` |
| CAL-001 | Holiday + working-time calendars | ✅ | `Calendar/*` | `BusinessTimeTest` |
| ADMIN-001 | Admin panel for identity/organisation | ✅ | `Admin/Http/*` | `AdminAuthorizationTest` |
| ADMIN-002 | Audit search for auditors | ✅ | `AuditController` | `AdminAuthorizationTest` |
| ADMIN-003 | Monitoring: notifications, integrations, overdue | 🟡 notifications + overdue ✅; integration-job list not yet a page | `NotificationAdminController`, `MonitorController` | `AdministratorBuildsWorkflowTest` |

## Computer Centre pilot (Phase 3)

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| ITSM-001 | E-mail account request + provisioning | 🟡 workflow ✅; mail platform adapter ⛔ (manual step) | `it-email`, `ProvisionEmail` | `ComputerCentrePilotTest` |
| ITSM-002 | Wi-Fi via LDAP/FreeRADIUS | ✅ (directory side) | `it-wifi`, `LdapGroupProvisioner` | `ComputerCentrePilotTest`, `LdapDirectoryTest` |
| ITSM-003 | VPN | ✅ (directory side) | `it-vpn` | `ComputerCentrePilotTest` (shared path) |
| ITSM-004 | Server/VM requests with quotas | 🟡 workflow + quotas ✅; Proxmox adapter unverified on a cluster | `it-vm`, `ProxmoxProvisioner` | `ComputerCentrePilotTest`, `ProxmoxProvisionerTest` |
| ITSM-005 | IT asset register, issue/return/transfer/repair/dispose | ✅ | `ComputerCentre/Services/AssetService` | `ComputerCentrePilotTest` |
| ITSM-006 | Software licences | ✅ | `LicenceService` | `ComputerCentrePilotTest` |
| ITSM-007 | No-dues → reviewed, idempotent deprovisioning; legal hold | ✅ | `no-dues`, `it-deprovision`, `Deprovision` | `ComputerCentrePilotTest` |

## e-Office (Phase 4, Brief §10)

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| FILE-001 | Electronic files: configurable numbering, classification, custodian, category, retention | ✅ | `EFile/Services/EFileService::open`, `FileCategory` | `EFileTest::test_files_are_numbered…` |
| FILE-002 | Note sheet: private drafts, numbered immutable finalised notes (model + DB trigger), content hash, addenda | ✅ | `EFileNote`, `efile_notes_final_guard` | `EFileTest` (notes, addenda) |
| FILE-003 | Movement: send with required action, in transit until acknowledged, recall, one movement at a time (DB index) | ✅ | `EFileService::send/receive/recall` | `EFileTest`, `CorrespondenceAndHttpTest` |
| FILE-004 | Correspondence: diary/dispatch registers, scans, filing; versioned documents | ✅ | `CorrespondenceService`, `DocumentStore` | `CorrespondenceAndHttpTest`, `EFileTest::test_documents…` |
| FILE-005 | Retention: schedules, review command, legal hold, recorded destruction / transfer | ✅ (schedules are placeholders, A-12) | `EFileService::dispose`, `efiles:retention-review` | `EFileTest::test_closing_sets_retention…` |
| FILE-006 | Record-level access by classification; audited viewing of confidential files; no blanket admin access | ✅ | `EFile/Services/FileAccess` | `EFileTest::test_classification…`, `…records_officers…` |
| FILE-007 | Approval: decision notes, or routing through a configured workflow with the outcome in file history | ✅ | `RecordWorkflowOutcome`, `RequestController` (efile) | `EFileTest::test_routing_a_file…` |
| FILE-008 | Permission-aware file search and register | ✅ | `EFileController::index` | `CorrespondenceAndHttpTest` |
| FILE-009 | Signature on notes | ⛔ Phase 8 (finalisation is authenticated, hashed and audited meanwhile) | — | — |

## Administration and Registrar (Phase 5, Brief §14.1)

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| LEAVE-001 | Configurable leave types; eligibility; notice; max per application; working vs calendar days with holidays; half days; overlap; balance held while pending; ledger debit on sanction; cancellation / curtailment credit back; admin credits, adjustments, lapses | ✅ (types and limits are placeholders, A-14) | `Registrar/Services/LeaveService`, `Validators/LeaveApplication`, `Actions/LeaveActions` | `LeaveTest` |
| REG-001 | Tour / travel authorisation with advance; TA/DA claim against the approved tour; LTC with block entitlement and dependants; medical reimbursement; verification, sanction, payment; advance netted; recovery shown | ✅ (LTC blocks placeholder, A-15) | `TravelAuthorisationValidator`, `ClaimValidator`, `TravelActions` | `TravelClaimsTest` |
| REG-002 | NOCs (passport, higher studies, outside employment), experience certificate; templates; unique numbers; PDF; named approving signatory; public verification; revocation | ✅ (no digital signature until Phase 8) | `CertificateService`, `IssueCertificate`, `/verify/{code}` | `CertificatesCircularsRtiTest` |
| REG-003 | Circulars / office orders / notices: draft, approval through a workflow, publication with number and PDF, department/category distribution, read receipts, acknowledgments, versions that supersede, withdrawal, archival | ✅ | `CircularService`, `PublishCircular` | `CertificatesCircularsRtiTest` |
| REG-004 | RTI: registration, PIO assignment, statutory reply date (30 days / 48 hours / +5 via APIO) as the PIO's deadline, transfer, reply and dispatch, first appeal with decision date, register with clocks | ✅ (limits configurable, verify, A-16) | `RtiRegistration`, `RtiActions`, `RtiController` | `CertificatesCircularsRtiTest` |
| WF-020 | Engine: per-workflow submission validators, user-scoped option sources, deadlines from a form date | ✅ | `SubmissionValidator`, `OptionSources`, `WorkflowEngine::dueAt` | Phase 5 tests |

## Finance, Purchase and vendors (Phase 6, Brief §14.2)

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| PROC-001 | Indent → budget check → sourcing (GeM availability, tender reference) → quotations and comparative statement (L1 ranking, justification when not L1) → committee → approval by value → purchase order (numbered PDF) → goods receipt and inspection → invoice → payment; every step audited | ✅ (routes are examples, A-18) | `Finance/Services/ProcurementService`, `Actions/ProcurementActions`, `FinanceWorkflowSeeder` | `ProcurementTest::test_full_lifecycle…`, `FinanceHttpTest::test_purchase_officer…` |
| PROC-002 | Procurement method by value from versioned rules entered by Finance with the authority cited; published rules immutable; each case records the rule version and inputs used; no threshold in code | ✅ (demo rules only, A-19) | `ProcurementRules`, `ProcurementRuleSet`, `IndentValidator` | `ProcurementTest::test_rule_sets…`, `FinanceHttpTest::test_budget_and_rules…` |
| FIN-001 | Advances: request, recommendation, sanction (amount editable), disbursement, settlement against an expense statement with refund / payable balance; outstanding and overdue register | ✅ | `ProcurementActions` (advance steps), `AdvanceSettlementValidator`, `AdvanceController` | `ProcurementTest::test_advance…` |
| FIN-002 | Budget heads per financial year and department; allocation and re-appropriation; funds checked at indent and committed at the budget check; commitment adjusted to the order value; expenditure on payment; release on rejection, cancellation or closure; append-only ledger | ✅ | `BudgetService`, `BudgetEntry`, `ReleaseOnFinish` | `ProcurementTest::test_indent…`, `…rejected_indent…`, `…budget_ledger…` |
| FIN-003 | Bill processing: three-way match (order, accepted goods, amount not yet billed), duplicate invoice numbers refused, deductions at verification, sanction, payment reference, payment advice PDF | ✅ | `InvoiceValidator`, `ProcurementService::pay` | `ProcurementTest::test_full_lifecycle…`, `…part_bills…` |
| VEND-001 | Vendor register: GSTIN / PAN / IFSC validation, bank account encrypted at rest and shown masked, MSME flag, activation; portal sign-in issued by Purchase with a one-time password | ✅ | `VendorController` | `FinanceHttpTest::test_vendor_register…` |
| VEND-002 | Vendor portal: own POs (PDF), bill submission, status, clarification requests and responses, payments and advices; never another vendor's records, internal notes, approvers or estimates | ✅ | `VendorPortalController`, `ConfineExternalUsers`, `RequestController::initiatorView` | `FinanceHttpTest::test_vendors_are_confined…`, `…clarification…` |

## Official PDF forms (brief: "Institute Official PDF Forms")

| ID | Requirement | Status | Code | Tests |
|---|---|---|---|---|
| DOC-001 | Template management: upload, keep the original unchanged, preview, edit details, versions (replace = new version), history, deactivate, which workflows use it; audited | ✅ (20 institute forms imported; 7 mapped — inventory in docs/OFFICIAL_PDF_TEMPLATES.md) | `OfficialDocuments/Services/TemplateService`, `Http/TemplateController`, `Pdf/TemplateInspector` | `TemplateAdministrationTest`, `InstituteFormsTest` |
| DOC-002 | Field mapping for fillable and flat / scanned PDFs: detected AcroForm fields, visual placement per page, data sources (applicant, form, labels, workflow, approvals, institute), formats, conditions, tables, no silent overflow, Devanagari | ✅ (interactive fields are flattened, D-32) | `MappingValidator`, `DocumentData`, `PdfRenderer`, `ValueFormatter`, `Admin/PdfTemplates/Mapper.vue` | `TemplateAdministrationTest`, `OfficialDocumentTest::test_final_approval…` |
| DOC-003 | Workflow outputs: several forms per workflow, required approvals, condition, download groups, confidentiality, signature before release, file name, reissue policy | ✅ | `WorkflowDocumentOutput`, `TemplateService::saveOutput` | `OfficialDocumentTest::test_a_condition…` |
| DOC-004 | Generation only after final approval, re-checked on the instance; queued, idempotent, retryable; status for users and administrators; dashboard and request page; download only through an authorised, audited route | ✅ | `OfficialDocumentService`, `GenerateOfficialDocument`, `OfficialDocumentAccess`, `OfficialDocumentController` | `OfficialDocumentTest` tests 1–6, 10–12; `tests/Browser/officialdocs.mjs` |
| DOC-005 | Original layout preserved: same pages and sizes, nothing changed outside the filled boxes; versions pinned at submission; issued documents never change | ✅ | `PdfRenderer`, `PdfChecker` | `OfficialDocumentTest::test_the_original_layout…` (pixel comparison), `…new_template_version…` |
| DOC-006 | Approval is never presented as a signature; signature-required documents held until an authorised signature (SIGN-001) | ✅ hold / ⛔ signing (Phase 8) | `OfficialDocumentService::produce`, `approval.<step>.statement` | `OfficialDocumentTest::test_a_signature_requirement…` |
| DOC-007 | Authorised reissue with reason; earlier document kept as superseded | ✅ | `OfficialDocumentService::regenerate` | `OfficialDocumentTest::test_authorised_reissue…` |

## Later phases

| ID | Requirement | Phase | Status |
|---|---|---|---|
| EST-001..004 | Maintenance, quarters, bookings, vehicles | 7 | ⬜ |
| SIGN-001 | Provider-independent signature interface | 8 | ⬜ (signature steps are blocked from publishing until a provider exists) |
| SIGN-002..004 | Aadhaar eSign, DSC, DigiLocker | 8 | ⛔ provider access |
| RPT-001 | Executive dashboards / analytics | 8 | 🟡 monitor statistics only |
| NFR-001 | Backup/restore drill, load and concurrency tests, deployment scripts | 9 | 🟡 docs/DEPLOYMENT.md draft |
