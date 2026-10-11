# Master Prompt for AI Coding Agents
## Institute-Wide Workflow Automation, e-Office and Digital File Management System Using Laravel

Copy the following prompt into your AI coding agent, such as OpenAI Codex, Claude Code, Cursor, or another autonomous software development agent. It is designed to guide the agent from repository analysis and architecture design through implementation, integration, testing, and deployment preparation.

---

# MASTER SOFTWARE DEVELOPMENT PROMPT

## 1. Your Role and Mission

You are an autonomous team of senior software engineering AI agents responsible for designing, developing, testing, documenting, and preparing a complete **Institute-Wide Workflow Automation, e-Office, and Digital File Management System** using Laravel.

You must work as a coordinated engineering team consisting of:

- Lead Software Architect / Orchestrator Agent
- Laravel Backend Development Agent
- Workflow Engine Agent
- Frontend UI/UX Agent
- Database Design Agent
- Authentication and Security Agent
- e-File and Digital Signature Agent
- Departmental Workflow Agent
- External Integration Agent
- Testing and Quality Assurance Agent
- DevOps and Documentation Agent

The Lead Architect is responsible for maintaining a consistent architecture, coordinating tasks, reviewing changes, resolving integration conflicts, and ensuring that all agents follow the same requirements.

**Your mission is to build working software, not merely produce an SRS, proposal, mockup, prototype, or collection of disconnected modules.**

You must create and modify the actual application code in the available project repository, implement database migrations, develop the frontend and backend, integrate the modules, execute tests, resolve defects, and prepare comprehensive technical documentation.

Do not stop after generating a project plan or application scaffold.

Continue through implementation and verification of the planned features, following the development phases defined below.

---

# 2. Project Background

The institution currently does not have a unified workflow automation layer.

Administrative files and requests are handled through a mixture of paper files, emails, manually maintained records, and department-specific applications.

The institution requires a centralized software platform that digitizes administrative processes and enables different departments to create, execute, monitor, and audit their workflows.

The solution must follow this fundamental architectural principle:

**ONE PLATFORM + ONE WORKFLOW ENGINE + MANY CONFIGURABLE DEPARTMENTAL WORKFLOWS.**

Do not develop separate applications or independent workflow engines for the Registrar Office, Finance Office, Purchase Office, Estate Office, Computer Centre, or other departments.

Instead, build a reusable workflow platform with shared authentication, authorization, configurable forms, approval chains, SLA management, notifications, document management, electronic files, audit trails, and integration services.

Individual departments should be able to introduce and modify routine workflows through the Administrator Panel without requiring application code changes.

New technical capabilities and external integrations may require development, but ordinary modifications to forms, approval sequences, deadlines, notification templates, and routing rules should be configurable.

---

# 3. Primary Business Objectives

The completed platform must:

1. Provide single sign-on using the institution's existing OpenLDAP infrastructure.
2. Support staff, faculty, students, and authorized external users.
3. Provide a generic, versioned, configurable workflow engine.
4. Provide a graphical workflow designer.
5. Provide a dynamic form-template builder.
6. Support configurable approval chains, conditional routing, parallel approvals, committee decisions, delegation, SLAs, reminders, and escalations.
7. Digitize administrative files, note sheets, correspondence, and file movement.
8. Support authorized electronic-signature services and digital document verification.
9. Automate notifications through email, SMS, WhatsApp Business, and in-application messaging.
10. Provide role-specific dashboards and consolidated institutional reporting.
11. Implement administrative, financial, procurement, infrastructure, and IT service workflows.
12. Support secure automation through integrations with LDAP, FreeRADIUS, the institutional email platform, virtualization platforms, and other approved systems.
13. Maintain a comprehensive, tamper-evident audit trail.
14. Enforce security, privacy, access control, separation of duties, and retention requirements.
15. Allow the institution to introduce additional workflows without redesigning the platform.

---

# 4. Mandatory Instructions for AI Coding Agents

Follow these instructions throughout development.

### 4.1 Inspect the Existing Repository First

Before modifying files:

- Inspect the repository structure.
- Determine whether an application already exists.
- Identify the Laravel and PHP versions.
- Read `composer.json`, frontend dependency manifests, route files, environment examples, and existing architecture documentation.
- Inspect database models, migrations, authentication, authorization, and logging.
- Identify existing modules and reusable components.
- Inspect available tests and development tooling.
- Identify any existing LDAP, SMTP, SMS, notification, storage, or API integrations.
- Identify application-specific conventions.

If the repository contains an existing application, extend it rather than replacing it.

Preserve working functionality unless a change is necessary and approved within the project scope.

If the repository is empty, create a new Laravel application using a currently supported release and compatible dependencies. Verify version compatibility before selecting packages.

### 4.2 Do Not Implement the Entire System as Hard-Coded Workflows

All departmental workflows must use the common workflow engine.

Do not hard-code approval chains directly into individual controllers.

Do not create separate approval engines for different departments.

Do not duplicate authentication, notification, audit, attachment, or approval functionality across modules.

### 4.3 Work in Small, Verifiable Engineering Steps

Before substantial implementation, create:

- `docs/ARCHITECTURE.md`
- `docs/IMPLEMENTATION_PLAN.md`
- `docs/REQUIREMENTS_TRACEABILITY.md`
- `docs/ASSUMPTIONS_AND_DECISIONS.md`
- `docs/SECURITY.md`

Maintain these documents throughout development.

Use the implementation plan to track requirements, dependencies, completed work, outstanding work, and test results.

### 4.4 Execute the Work, Do Not Merely Describe It

Do not respond with instructions telling the user how to develop the software.

Use the available coding tools to create the actual files, implement the functionality, run available checks, and fix the resulting defects.

Do not claim that a feature is implemented unless its code exists and the implementation has been verified to the extent possible in the available environment.

If an external service requires unavailable credentials, record that dependency and implement a genuine integration adapter with appropriate error handling. Do not falsely report the integration as operational.

### 4.5 Handle Missing Information Professionally

Do not invent:

- Institutional approval hierarchies.
- Government financial thresholds.
- Leave entitlements.
- Procurement rules.
- Legal obligations.
- External API capabilities.
- LDAP attribute mappings.
- E-signature provider credentials.
- Departmental policies.

Use configurable rules and documented assumptions.

Where institutional information is unavailable, implement the configuration mechanism and identify the settings that an authorized administrator must complete.

Do not block unrelated development because one external dependency has not yet been configured.

---

# 5. Technology Stack and Architecture

First inspect the existing environment and identify the most compatible architecture.

If the project is new, use the following preferred stack.

| Component | Preferred technology |
|---|---|
| Backend | Laravel, using a supported release |
| Language | PHP, compatible with the selected Laravel version |
| Frontend | Vue.js 3 with Inertia.js, or an equally well-justified architecture |
| Styling | Tailwind CSS and reusable UI components |
| Database | PostgreSQL preferred for a new deployment |
| Cache and queues | Redis where appropriate |
| Authentication | OpenLDAP integration |
| Authorization | Laravel Policies/Gates and a clearly defined permission model |
| Workflow execution | Custom versioned workflow engine built around transactional Laravel services |
| Background processing | Laravel Queue |
| Scheduled execution | Laravel Scheduler |
| File storage | Private filesystem or compatible object storage |
| Email | Institutional SMTP or approved mail provider |
| Notifications | Laravel Notifications with channel-specific adapters |
| Automated testing | PHPUnit or Pest, depending on project conventions |
| API documentation | OpenAPI where external or frontend APIs warrant it |
| Deployment | Ubuntu Server, Nginx or Apache, PHP-FPM, database, queue workers |

Do not introduce unnecessary frameworks, dependencies, or microservices.

The default architecture should be a **modular Laravel application**, with clear internal boundaries between identity, workflow execution, documents, notifications, audit logging, and departmental business modules.

Do not introduce a distributed microservice architecture unless there is a demonstrable technical requirement.

## 5.1 Application Modules

Organize the application into clear logical modules:

1. Identity and Organizational Management
2. Workflow Engine
3. Dynamic Form Builder
4. Electronic File Management
5. Note-Sheet Management
6. Document and Attachment Management
7. Electronic Signature Integration
8. Notification Services
9. Administration and Configuration
10. Leave and Employee Services
11. Finance and Procurement
12. Estate and Infrastructure
13. Computer Centre / IT Service Management
14. Vendor Portal
15. Reporting and Analytics
16. Audit and Records Management
17. External Integration Services

Use consistent naming, interfaces, service classes, validation, authorization, error handling, and testing patterns.

---

# 6. Phase Zero: System Discovery and Technical Planning

Before implementing application features, perform the following tasks.

### Task 0.1 — Repository Assessment

Document the existing application structure, dependencies, available infrastructure, and constraints.

### Task 0.2 — Requirements Decomposition

Convert the requirements in this prompt into a feature-level backlog with unique IDs.

Use identifiers such as:

- `AUTH-001`
- `IAM-001`
- `WF-001`
- `FORM-001`
- `FILE-001`
- `SIGN-001`
- `NOTIF-001`
- `LEAVE-001`
- `FIN-001`
- `PROC-001`
- `EST-001`
- `ITSM-001`
- `SEC-001`
- `NFR-001`

Record dependencies between features.

### Task 0.3 — Architecture Design

Document:

- Application architecture.
- Module boundaries.
- Database architecture.
- Authentication and authorization.
- Workflow execution model.
- Dynamic form storage and validation.
- File storage.
- Audit logging.
- Notification delivery.
- Integration mechanisms.
- Deployment architecture.
- Backup and recovery.

### Task 0.4 — Data Model Design

Create an initial entity-relationship model and identify the migrations necessary for the foundation.

### Task 0.5 — Implementation Plan

Divide implementation into the phases specified in Section 20.

**Once these planning artifacts are available, begin implementation. Do not stop at planning.**

---

# 7. Module A: Identity, OpenLDAP Authentication, and Authorization

Develop centralized identity and access management.

## 7.1 OpenLDAP Single Sign-On

Implement authentication against the institution's existing OpenLDAP directory.

Requirements:

- Connect to configured LDAP servers.
- Support encrypted LDAP connections using LDAPS or correctly configured StartTLS.
- Validate server certificates.
- Store connection details securely through environment configuration or an approved secret-management mechanism.
- Authenticate staff, faculty, and students using institutional credentials.
- Map LDAP identities to local application users.
- Retrieve configured attributes, including name, institutional email, department, designation, employee/student identifier, organizational unit, and account status.
- Handle missing attributes safely.
- Support user activation and deactivation.
- Support configurable attribute mappings.
- Handle LDAP timeouts, invalid credentials, unavailable servers, and synchronization failures.
- Prevent authentication bypass if LDAP is unavailable.
- Never log passwords or bind credentials.
- Never automatically grant administrative privileges to a newly synchronized LDAP user.

Use the appropriate Laravel authentication extension or a well-tested LDAP integration approach after verifying compatibility.

Keep LDAP-specific logic behind a dedicated service so that the application remains maintainable.

## 7.2 Local User Records

Maintain the application-specific information needed for:

- Department membership.
- Designation.
- Reporting hierarchy.
- Workflow permissions.
- Departmental responsibilities.
- Committee membership.
- Delegation.
- Role assignments.
- Approval authority.
- User category.

LDAP should remain authoritative for institutional credentials wherever policy requires.

## 7.3 Role-Based Access Control

Implement a permission system supporting:

- System Administrator.
- Department Administrator.
- Registrar Office staff.
- Finance staff.
- Purchase officers.
- HoDs.
- Deans.
- Director or other competent authorities.
- Faculty.
- Non-teaching staff.
- Students.
- Computer Centre administrators.
- Technicians.
- Committee members.
- Vendors.
- Other configurable user categories.

Support multiple roles per user.

Use permission checks through centralized Policies/Gates or a consistent authorization service.

Do not rely on frontend visibility or hidden buttons as the security boundary.

Every protected backend operation must enforce authorization.

## 7.4 Organizational Hierarchy

Support configurable:

- Departments.
- Offices.
- Organizational units.
- Designations.
- Reporting relationships.
- Approval authorities.
- Committees.
- Membership periods.
- Acting authorities.
- Delegation rules.

The workflow engine must resolve approvers from configured organizational data rather than embedding specific employee IDs in workflow logic.

---

# 8. Module B: Universal Workflow Engine

This is the central component of the entire application.

The development team must implement a functional workflow engine before building complex departmental processes.

## 8.1 Workflow Definition

Provide an Administrator Panel that allows authorized administrators to create workflows containing:

- Name.
- Unique workflow key.
- Description.
- Department.
- Category.
- Initiating user roles.
- Form template.
- Workflow graph.
- Approval stages.
- Transition rules.
- SLA configuration.
- Escalation configuration.
- Notification configuration.
- Required documents.
- Automatic actions.
- Publication status.
- Version information.

The workflow definition must be stored in the database.

Do not use PHP source-code edits as the normal method of changing an approval chain.

## 8.2 Graphical Workflow Designer

Create a web-based graphical designer that displays workflow nodes and connections.

Support nodes such as:

- Start.
- Form submission.
- Approval.
- Review.
- Conditional decision.
- Parallel approval.
- Committee decision.
- Clarification.
- Document verification.
- Digital signature.
- Automated integration action.
- Notification.
- Delay/SLA checkpoint where appropriate.
- Completion.
- Rejection.
- Cancellation.

Allow administrators to configure node properties and connect valid transitions.

If a visual workflow library is introduced, verify its current license, compatibility, accessibility, and maintenance status.

The final implementation must support saving, reloading, validating, testing, and publishing workflows.

Do not deliver a graphical mockup that cannot execute.

## 8.3 Workflow Versioning

Implement immutable published workflow versions.

When administrators modify a workflow:

- Create a new version.
- Preserve the previous version.
- Use the new version for new instances after publication.
- Preserve each existing instance's original version by default.
- Do not silently reinterpret existing approval tasks using new configuration.
- Require a controlled process if an existing workflow instance must be migrated.
- Record who created and published each version.

## 8.4 Workflow Execution

Implement a reusable workflow runtime capable of:

1. Starting an instance.
2. Resolving the relevant workflow version.
3. Validating submission data.
4. Creating the workflow instance.
5. Creating tasks for the appropriate users or authorities.
6. Evaluating transitions.
7. Processing approval and rejection decisions.
8. Handling conditional and parallel paths.
9. Pausing or resuming when required.
10. Triggering notifications.
11. Calculating deadlines.
12. Triggering reminders and escalations.
13. Invoking approved integration actions.
14. Completing or cancelling the instance.
15. Recording every state transition.

Use database transactions to ensure consistent workflow state.

Use concurrency controls so that an approval cannot be processed twice due to simultaneous requests.

## 8.5 Approval Assignment

Support assignment based on:

- Named user.
- User role.
- Designation.
- Department.
- Reporting hierarchy.
- Configured authority.
- Committee.
- Dynamically resolved approver.

Example:

`Initiator → HoD → Dean → Registrar → Director → Completed`

This must be an example only. All chains must be configurable.

## 8.6 Approval Actions

Implement:

- Approve.
- Reject.
- Return for clarification.
- Request additional documents.
- Forward where the workflow permits.
- Reassign where authorized.
- Put on hold.
- Resume.
- Cancel where permitted.
- Add remarks.
- Upload supporting documents.

Every decision must be checked against the instance's current state and the actor's actual authority.

Provide a decision history for each workflow instance.

## 8.7 Parallel and Committee Approvals

Support configurable parallel approvals.

Examples:

- Finance and technical evaluation occurring simultaneously.
- Multiple committee members submitting their decisions.
- Committee approval requiring a configured quorum.
- Unanimous approval.
- Majority approval.
- Weighted or rule-based decisions where institutional policy requires them.

Approval aggregation and quorum rules must be explicit configuration, not implicit assumptions.

## 8.8 Delegation

Implement time-bounded approval delegation.

Support:

- Delegating officer.
- Delegate.
- Validity period.
- Applicable workflow categories.
- Applicable departments.
- Allowed actions.
- Approval or authorization of delegation where necessary.
- Revocation.
- Audit history.

Ensure that delegation does not bypass authority restrictions or separation-of-duties rules.

## 8.9 SLA and Escalation

Implement:

- Configurable stage deadlines.
- Working-day calculations.
- Working-hour calendars.
- Institutional holiday calendars.
- Reminders.
- Escalation thresholds.
- Escalation recipients.
- Multiple escalation levels.
- Pause/resume behavior.
- Overdue dashboards.

Run deadline monitoring through scheduled/background processing.

Do not depend on the user keeping a browser window open.

SLA processing must be idempotent so that a scheduled job cannot create duplicate escalations.

## 8.10 Workflow States

Define and enforce valid states.

Examples:

- Draft.
- Submitted.
- Pending Approval.
- Under Review.
- Returned for Clarification.
- Resubmitted.
- Approved.
- Rejected.
- On Hold.
- Cancelled.
- Completed.
- Archived.

Prevent invalid transitions.

## 8.11 Workflow Dashboard

Provide:

- My Applications.
- My Pending Approvals.
- Applications Returned to Me.
- Overdue Tasks.
- Tasks Delegated to Me.
- Recently Completed Workflows.
- Department Pending Work.
- Escalated Tasks.
- Workflow History.
- Administrative Monitoring.

Show the current responsible authority and workflow progress where the user is authorized to see them.

---

# 9. Module C: Dynamic Form Builder

Develop a reusable, database-driven form builder.

## 9.1 Form Creation

Administrators must be able to create forms without programming.

Support these field types:

- Text.
- Text area.
- Number.
- Decimal.
- Date.
- Time.
- Date/time.
- Email.
- Telephone.
- Dropdown.
- Multi-select.
- Radio group.
- Checkbox.
- Repeating table.
- File upload.
- Multiple file upload.
- Employee selector.
- Student selector.
- Department selector.
- Vendor selector.
- Calculated value.
- Read-only field.
- Approval remark.
- Signature placeholder.

## 9.2 Field Configuration

For each field, support:

- Unique internal key.
- Display label.
- Help text.
- Placeholder.
- Required/optional.
- Default value.
- Minimum/maximum.
- Validation rules.
- Options.
- File restrictions.
- Visibility conditions.
- Editability conditions.
- Data source.
- Role-based visibility.
- Display order.
- Section/group.
- Read-only conditions.

## 9.3 Conditional Logic

Implement conditional field display and validation.

Example:

When the user selects "International Travel," display country, travel dates, foreign travel declaration, and additional supporting documents.

When the user selects "Earned Leave," apply the leave-specific balance and validation rules.

The frontend and backend must evaluate compatible rules.

Do not trust client-side validation alone.

## 9.4 Form Versioning and Submission Storage

Version form templates independently from their submissions.

Preserve the exact form version and relevant schema used for each submitted application.

Maintain:

- Submission data.
- Form version.
- Validation metadata where necessary.
- Supporting files.
- Submission timestamps.
- User reference.
- Workflow reference.

Use structured business tables for critical searchable information such as financial values and account references. Do not put the entire application database into one large JSON column merely because a form builder exists.

---

# 10. Module D: e-File, Digital Note Sheets, and Correspondence

Build an electronic file management system for institutional administrative processes.

## 10.1 Electronic File Creation

Support:

- File number.
- Subject.
- Department.
- File category.
- Initiating officer.
- Creation date.
- Confidentiality classification.
- Current custodian.
- Associated workflow.
- Supporting documents.
- Linked applications.
- Status.
- Retention category.

Generate file numbers using configurable institutional numbering rules.

## 10.2 Digital Note Sheets

Provide an interface inspired by traditional green note sheets.

Support:

- New note.
- Note sequence.
- Observation.
- Recommendation.
- Decision.
- Clarification.
- Document references.
- Attachments.
- Author.
- Designation.
- Timestamp.
- Signature where required.

Preserve previous notes.

Do not permit silent editing or deletion of finalized notes.

Where corrections are allowed, use an auditable correction or addendum mechanism.

Distinguish draft notes from finalized notes.

The green-note visual convention must not be the only way of distinguishing note types or statuses.

## 10.3 File Movement

Track file transfers between officers and departments.

Record:

- Sending officer.
- Receiving officer.
- Sending department.
- Receiving department.
- File identifier.
- Timestamp.
- Reason.
- Related note.
- Required action.
- Attachments.
- Acknowledgment.
- Result.

Maintain a complete file movement history.

## 10.4 Correspondence and Documents

Support incoming and outgoing correspondence, memoranda, scanned documents, orders, supporting files, and versioned attachments.

Enforce confidentiality, record-level authorization, retention, and document access policies.

Secure document access must be checked on every request.

A predictable document URL must never be sufficient to access a confidential file.

## 10.5 Audit Trail

Maintain an append-oriented audit history of file creation, note finalization, movement, access where required, approvals, document changes, and disposition.

Provide authorized audit access without granting ordinary administrators unrestricted modification rights over historical audit events.

---

# 11. Module E: Electronic Signatures and DigiLocker

Implement a provider-independent electronic-signature integration layer.

## 11.1 Aadhaar eSign

Support integration with an authorized Aadhaar eSign service provider.

Implement:

- Signing request preparation.
- Document identification.
- User consent handoff.
- Request correlation.
- Provider response validation.
- Callback handling.
- Signature status verification.
- Signed-document storage.
- Error handling.
- Retry rules.
- Audit history.

Do not simulate Aadhaar eSign by generating an ordinary application OTP or placing an image of a signature on a document.

## 11.2 Digital Signature Certificates

Support DSC integration through a technically and legally appropriate signing mechanism.

Include certificate validation, expiry handling, signature verification, document integrity, timestamp validation where applicable, and failure handling.

The precise implementation must be selected after confirming the target users' signing environment and the chosen signing provider or middleware.

## 11.3 DigiLocker

Provide an integration adapter for authorized DigiLocker functionality.

Support applicable use cases such as:

- Obtaining authorized documents.
- Certificate verification.
- Verification-result storage.
- Document reference management.
- Association with applications.

Verify actual API availability, permissions, and technical requirements before claiming the integration is functional.

Do not describe an ordinary uploaded document as verified merely because it is present in the system.

## 11.4 Signature Architecture

Represent provider interactions through defined interfaces.

This allows service-provider changes without rewriting the workflow engine.

In development environments, use clearly identified mocks or test adapters. Never report a mock response as a real legal signature.

---

# 12. Module F: Notifications

Implement a centralized notification service.

Support:

- Email.
- SMS.
- WhatsApp Business through an approved provider.
- In-application notifications.

Use reusable adapters and configurable templates.

## 12.1 Notification Triggers

Handle:

- Application submission.
- New approval assignment.
- Reminder.
- SLA deadline approaching.
- SLA breach.
- Escalation.
- Rejection.
- Return for clarification.
- Resubmission.
- Final approval.
- File movement.
- Signature completion.
- Purchase order events.
- Invoice status changes.
- Maintenance ticket updates.
- IT account provisioning.
- Account deprovisioning.

## 12.2 Delivery Reliability

Use queues for asynchronous delivery.

Implement:

- Provider configuration.
- Retry policies.
- Failure recording.
- Delivery status where available.
- Duplicate prevention.
- Correlation identifiers.
- Administrative monitoring.

A notification failure must not automatically undo a workflow decision.

Never place credentials, passwords, session tokens, or unnecessary private data in notifications.

---

# 13. Module G: Administration and Configuration

Build a comprehensive Administrator Panel.

Authorized administrators must be able to manage:

### Identity and Organization
- Users.
- Departments.
- Offices.
- Designations.
- Reporting relationships.
- Roles.
- Permissions.
- Committees.
- Delegation.

### Workflow Configuration
- Workflow definitions.
- Workflow versions.
- Approval chains.
- Workflow nodes.
- Conditional transitions.
- SLAs.
- Escalation rules.
- Workflow publication.

### Forms
- Form templates.
- Form versions.
- Field definitions.
- Validation.
- Conditional logic.
- Supporting document requirements.

### System Configuration
- Holiday calendars.
- Working schedules.
- Notification providers.
- Notification templates.
- File numbering.
- Document categories.
- Retention policies.
- Certificate templates.
- Integration endpoints.
- Integration health.
- System settings.

### Audit and Monitoring
- Audit searches.
- Workflow health.
- Failed integrations.
- Failed queue jobs.
- Overdue workflows.
- Notification failures.
- Provisioning failures.

Sensitive configuration actions must be permission-controlled and audited.

Changes to published workflow definitions or financial decision rules must be versioned.

---

# 14. Module H: Departmental Workflows

Implement every departmental workflow using the universal workflow engine.

Each workflow must include:

1. Business purpose.
2. Eligible initiators.
3. Dynamic form.
4. Required supporting documents.
5. Validation rules.
6. Approval chain.
7. Conditional routing.
8. Possible decisions.
9. SLA and escalation.
10. Notifications.
11. Audit events.
12. Final output.
13. Reports.
14. Acceptance tests.

The following processes constitute the required scope.

## 14.1 Administration and Registrar

Implement:

**Leave Management**
- Casual Leave.
- Earned Leave.
- Duty Leave.
- Station Leave.
- Configurable leave categories.
- Eligibility.
- Balance tracking.
- Holiday calculations.
- Submission and approval.
- Modification.
- Cancellation.
- History.
- Leave balance adjustment.

**Tour and TA/DA**
- Tour approval.
- Travel authorization.
- Advance request.
- Tour completion.
- Expense claim.
- Supporting bills.
- TA/DA calculations.
- Verification.
- Sanction.
- Settlement.

**LTC**
- Eligibility.
- Application.
- Authorized dependent data.
- Advance.
- Supporting documents.
- Approval.
- Claim settlement.

**Medical Reimbursement**
- Medical claim.
- Bills and prescriptions.
- Verification.
- Query/clarification.
- Sanction.
- Payment status.

**NOCs and Certificates**
- Passport NOC.
- Higher studies NOC.
- Outside employment NOC.
- No-dues.
- Experience certificate.
- Configurable additional certificates.

Support certificate templates, unique certificate numbers, PDF generation, authorized signatures, and verification where appropriate.

**Circulars and Office Orders**
- Drafting.
- Review.
- Approval.
- Digital signing.
- Publication.
- Department-wise distribution.
- Read receipts.
- Explicit acknowledgments.
- Versioning.
- Archival.

**RTI Tracking**
- Registration.
- Assignment.
- Deadline tracking.
- Transfer where applicable.
- Response preparation.
- Dispatch.
- Appeal handling.
- Closure.
- Audit history.

Implement configurable statutory deadline rules and verify applicable timelines before production.

## 14.2 Finance and Purchase

Implement the end-to-end procurement workflow:

**Indent → Administrative Approval → Budget Check → Committee, Where Required → GeM/Tender Process → Evaluation → Approval → Purchase Order → Goods Receipt → Invoice Verification → Payment → Closure**

Support:

- Indents.
- Specifications.
- Estimated costs.
- Budget heads.
- Budget availability.
- Financial concurrence.
- Committee records.
- Procurement method.
- Tender tracking.
- GeM integration where authorized and technically available.
- Technical evaluation.
- Financial evaluation.
- Comparative statements.
- Purchase orders.
- Suppliers.
- Goods receipt.
- Inspection.
- Invoice submission.
- Bill verification.
- Payment authorization.
- Payment status.
- Closure.

Implement configurable regulatory rules.

Account for applicable GFR 2017 provisions and amendments, Department of Expenditure instructions, GeM requirements, institutional procurement policies, and delegation of financial powers.

Do not hard-code outdated procurement thresholds or invent procurement rules.

Record the rule version and relevant decision inputs used for procurement decisions.

Also implement:

- Advance requests.
- Expense statements.
- Advance settlement.
- Recovery of unspent balances.
- Outstanding advance tracking.

## 14.3 Vendor Portal

Provide authenticated vendor access.

Vendors should be able to:

- View their authorized records.
- Submit invoices.
- Upload required documents.
- Track invoice status.
- Respond to clarification.
- Upload corrected documents where permitted.
- View payment status.
- Download available payment advice.

Vendors must never access other vendors' records or confidential internal deliberations.

## 14.4 Estate and Infrastructure

Implement maintenance ticketing for:

- Electrical.
- Civil.
- Plumbing.
- Air conditioning.
- Carpentry.
- Other configurable categories.

Support assignment, reassignment, priority, attachments, SLA, escalation, technician updates, resolution, feedback, and reopening rules.

Also implement:

**Staff Quarters**
- Inventory.
- Categories.
- Eligibility.
- Application.
- Waiting lists.
- Priority rules.
- Committee recommendation.
- Allotment.
- Offer acceptance.
- Occupancy.
- Vacation.
- History.

**Guest House and Hall/Auditorium**
- Availability.
- Booking.
- Approval.
- Scheduling.
- Charges where applicable.
- Conflict prevention.
- Cancellation.
- Utilization reporting.

**Vehicle Requisition**
- Purpose.
- Destination.
- Schedule.
- Passenger count.
- Authorization.
- Vehicle and driver allocation.
- Journey details.
- Completion records.
- Utilization reports.

Prevent duplicate facility reservations using transaction-safe availability checks.

## 14.5 Computer Centre and IT Service Management

The Computer Centre must be the **first implementation pilot**.

Implement:

**Email Account Requests**
- Request.
- Identity validation.
- Approval.
- Account creation through an available integration.
- Confirmation.
- Audit history.

**Wi-Fi Access**
- Application.
- Approval.
- Eligibility.
- LDAP/FreeRADIUS integration where appropriate.
- Access profile.
- Expiration.
- Revocation.

**VPN**
- Justification.
- Resource scope.
- Duration.
- Approval.
- Provisioning.
- Renewal.
- Revocation.

**Server and Virtual Machine Requests**
- CPU.
- RAM.
- Storage.
- Operating system.
- Network.
- Application/project.
- Access requirements.
- Duration.
- Resource quota validation.
- Approval.
- Provisioning.
- Failure recovery.
- Decommissioning.

Where the institution uses Proxmox, implement integration through its supported API and permission model after verifying the target environment.

**IT Asset Register**
- Asset number.
- Category.
- Manufacturer.
- Model.
- Serial number.
- Purchase details.
- Warranty.
- Location.
- Custodian.
- Issue/return.
- Transfer.
- Repair.
- Disposal.

**Software License Management**
- Software catalog.
- License inventory.
- License allocation.
- Available capacity.
- Expiration.
- Renewal.
- Revocation.

## 14.6 Account Deprovisioning

Integrate IT account review/deprovisioning with the no-dues process.

Do not automatically delete or disable every account merely because a no-dues certificate has been approved.

Create a configurable, auditable process that verifies:

- User category.
- Employment/enrollment status.
- Required approvals.
- Account-retention policy.
- Mailbox retention.
- Data ownership and transfer.
- Research/project responsibilities.
- Legal holds.
- Approved exceptions.
- Reactivation requirements.

Where applicable, perform the following steps:

1. Receive an authorized deprovisioning request.
2. Validate the user's status.
3. Evaluate account-specific policy.
4. Verify all prerequisites.
5. Notify authorized stakeholders.
6. Apply approved access restrictions.
7. Preserve data according to policy.
8. Complete deprovisioning.
9. Record integration results.
10. Support controlled recovery after partial failures.

Provisioning and deprovisioning jobs must be idempotent.

A repeated job must not accidentally create duplicate accounts or execute an irreversible action multiple times.

---

# 15. Module I: Reporting and Dashboards

Build role-specific dashboards for employees, students, faculty, approvers, department administrators, and senior management.

## 15.1 User Dashboard

Display:

- My applications.
- Drafts.
- Pending approvals assigned to me.
- Returned requests.
- Completed requests.
- Upcoming deadlines.
- Notifications.
- My electronic files.
- My IT requests.
- Application history.

## 15.2 Approver Dashboard

Display:

- Pending approvals.
- Overdue items.
- SLA status.
- Delegated approvals.
- Clarification requests.
- Priority work.
- Historical decisions.

## 15.3 Department Dashboard

Display:

- Department-wise pending workflows.
- Overdue cases.
- Average processing time.
- SLA compliance.
- Workload by officer.
- Escalated cases.
- Completed cases.
- Rejection and return rates.

## 15.4 Executive Dashboard

Display authorized aggregate institutional information:

- Total active workflows.
- Pending approvals.
- Department-level backlogs.
- Processing-time trends.
- SLA compliance.
- Procurement status.
- Maintenance status.
- IT service requests.
- File movement.
- Historical trends.

Dashboards must enforce authorization and avoid exposing confidential records merely because a user can access an aggregate report.

---

# 16. Search and Document Management

Provide centralized, permission-aware search.

Support search across:

- Application number.
- File number.
- Subject.
- Department.
- Employee/student identifier, according to permissions.
- Purchase order.
- Vendor invoice.
- Certificate.
- Circular.
- Maintenance ticket.
- IT request.

Support filtering by date, department, workflow status, category, and other relevant attributes.

Implement:

- Pagination.
- Search indexing where appropriate.
- Secure document previews.
- Version history.
- Attachment downloads.
- Retention.
- Archival.
- Permission checks at retrieval time.

Never treat an unpredictable or predictable URL as a substitute for authorization.

---

# 17. Module J: Security, Privacy, and Audit

Security is a mandatory platform requirement, not an optional enhancement.

## 17.1 Authorization

Implement:

- Least privilege.
- Role-based access.
- Record-level authorization.
- Departmental scope.
- Workflow-stage authorization.
- Confidential document restrictions.
- Delegation boundaries.
- Separation of duties.
- Self-approval restrictions where required.
- Sensitive administrator permissions.

Every sensitive backend endpoint must enforce authorization independently of frontend behavior.

## 17.2 Audit Events

Record significant events, including:

- Authentication successes and failures.
- Role changes.
- Permission changes.
- Workflow creation and publication.
- Application submission.
- Approval and rejection.
- Delegation.
- File movement.
- Note-sheet finalization.
- Document changes.
- Signature operations.
- Procurement decisions.
- Financial workflow transitions.
- Account provisioning/deprovisioning.
- Configuration changes.

Audit entries should include appropriate actor, timestamp, affected record, action, outcome, and correlation information.

Where relevant, include previous/new values without storing secrets or unnecessary personal data.

Never log:

- Passwords.
- Password hashes.
- LDAP bind credentials.
- Session tokens.
- Authentication tokens.
- Private keys.
- OTPs.
- Other security secrets.

## 17.3 Tamper-Evident Audit Logging

Implement an auditable, append-oriented audit mechanism.

Prevent ordinary application users and administrators from modifying historical audit records through the normal application interface.

Where appropriate, use restricted database permissions, cryptographic integrity checks, or protected external audit storage.

Document the trust boundaries and residual risks. Do not describe an audit log as absolutely immutable if privileged database operators can still alter it.

## 17.4 Secure Document Handling

Implement:

- Server-side file type validation.
- File size limits.
- Safe filenames.
- Malware scanning integration where available.
- Private document storage.
- Secure download authorization.
- Protected temporary links where applicable.
- Document integrity checks where required.
- Retention and archival.
- Secure deletion according to policy.
- Backup protection.

## 17.5 Privacy and Compliance

Identify applicable requirements concerning:

- Government financial rules.
- RTI.
- Electronic signatures.
- Personal data protection.
- Institutional service rules.
- Records retention.
- Information security.
- Procurement.
- Institutional cybersecurity policy.

Do not assert that the application is legally compliant without validating the applicable requirements and the actual implementation.

---

# 18. Database and Data Integrity

Implement version-controlled Laravel database migrations.

Design a normalized data model covering the following logical entities.

### Identity
- Users.
- LDAP mappings.
- Departments.
- Designations.
- Reporting relationships.
- Roles.
- Permissions.
- Role assignments.
- Committees.
- Delegations.

### Workflow
- Workflow definitions.
- Workflow versions.
- Workflow nodes.
- Workflow transitions.
- Workflow instances.
- Workflow tasks.
- Approval decisions.
- SLA policies.
- Escalation rules.
- Form templates.
- Form versions.
- Form submissions.
- Workflow variables.
- Attachments.

### e-Office
- Electronic files.
- Notes.
- File movements.
- Correspondence.
- Document records.
- Document versions.
- Signature records.
- Retention rules.

### Administration
- System settings.
- Notification templates.
- Holiday calendars.
- Working-hour calendars.
- Audit events.
- Integration configurations.

### Departmental Domains
- Leave applications and balances.
- Tour requests.
- Reimbursement claims.
- Certificates.
- Procurement indents.
- Purchase orders.
- Goods receipts.
- Vendor invoices.
- Vendor records.
- Maintenance tickets.
- Quarter allotments.
- Facility bookings.
- Vehicle requests.
- IT service requests.
- IT assets.
- Software licenses.
- Provisioning/deprovisioning jobs.

### Integration and Operations
- Notification attempts.
- Integration jobs.
- External request correlation records.
- Delivery statuses.
- Job retry information.
- Failed integration records.

## Mandatory Database Rules

- Use foreign keys where appropriate.
- Apply unique constraints to important identifiers.
- Add indexes for common searches and joins.
- Use database transactions for critical state transitions.
- Prevent duplicate approval execution.
- Use optimistic or pessimistic concurrency controls where appropriate.
- Support safe database migrations.
- Avoid destructive schema changes without a documented migration strategy.
- Never use production data as test fixtures.
- Never place real credentials in seeders or repository files.
- Use synthetic data in automated tests.

Preserve historical records when workflows, approval chains, or forms are changed.

---

# 19. API and Integration Requirements

Create secure service interfaces for external systems.

Potential integrations include:

- OpenLDAP.
- FreeRADIUS.
- Institutional email platform.
- SMS provider.
- WhatsApp Business provider.
- Aadhaar eSign service.
- DSC signing solution.
- DigiLocker.
- GeM, where authorized and technically available.
- Proxmox or the existing virtualization platform.
- Existing HR/payroll systems.
- Existing finance or asset systems.

Each integration must have:

- A clear interface.
- Configuration validation.
- Authentication.
- Request/response validation.
- Timeout handling.
- Retry rules.
- Idempotency.
- Error logging.
- Audit correlation.
- Health monitoring.
- Administrative configuration.

Implement actual integrations where the environment and credentials permit.

Where external dependencies are unavailable, implement properly isolated adapters and clearly identified test doubles. Never hard-code fake production responses.

All credentials must be supplied through secure configuration or an approved secrets mechanism.

Do not commit API keys, passwords, private keys, or production tokens.

---

# 20. Mandatory Development Roadmap

Implement the platform in the following sequence.

Each phase must build on a working foundation and include its own tests.

## Phase 1 — Foundation

Implement:

- Laravel application foundation.
- Authentication architecture.
- OpenLDAP integration.
- User synchronization/mapping.
- Departments and organizational hierarchy.
- Role-based authorization.
- Administrator Panel.
- Audit logging.
- Notification framework.
- File storage infrastructure.
- Background queues and scheduled jobs.
- Automated test framework.

**Exit criterion:** Users can authenticate through the configured identity system, permissions are enforced on the backend, and sensitive administrator actions are audited.

## Phase 2 — Workflow Engine

Implement:

- Workflow definitions.
- Versioning.
- Graphical designer.
- Dynamic form builder.
- Workflow execution.
- Sequential approvals.
- Conditional branches.
- Parallel approvals.
- Committee approvals.
- Delegation.
- SLA monitoring.
- Reminders.
- Escalation.
- Workflow dashboard.
- Workflow history.

**Exit criterion:** An authorized administrator can create, publish, and execute a configurable multi-stage workflow without modifying application source code.

## Phase 3 — Computer Centre Pilot

Implement:

- Email account requests.
- Wi-Fi access requests.
- VPN requests.
- Server/VM requests.
- IT asset issue and return.
- Software license requests.
- Account provisioning integration.
- No-dues integration.
- Account-deprovisioning workflow.

**Exit criterion:** The Computer Centre can operate its principal workflows through the common engine, with documented integration dependencies and verified audit/security controls.

## Phase 4 — Electronic Files

Implement:

- Electronic file creation.
- Note sheets.
- File movement.
- Correspondence.
- Document versioning.
- File search.
- Retention configuration.
- File audit history.

**Exit criterion:** A file can be created, routed, annotated, approved, transferred, and retrieved with complete authorized historical tracking.

## Phase 5 — Administration and Registrar

Implement:

- Leave management.
- Tour and TA/DA.
- LTC.
- Medical reimbursement.
- NOCs.
- Certificates.
- Circulars.
- Office orders.
- RTI tracking.

**Exit criterion:** Each workflow has a working form, configurable approval route, validations, notifications, audit history, and acceptance tests.

## Phase 6 — Finance and Purchase

Implement:

- Indents.
- Budget checks.
- Purchase committee.
- Procurement tracking.
- GeM/tender integration where available.
- Purchase orders.
- Goods receipt.
- Invoice processing.
- Payment status.
- Advances and settlement.
- Vendor portal.

**Exit criterion:** Procurement can progress through its configured lifecycle with auditable transitions, permission checks, and configurable financial rules.

## Phase 7 — Estate and Infrastructure

Implement:

- Maintenance tickets.
- Technician assignments.
- SLA and escalation.
- Quarter allotment.
- Waiting lists.
- Guest house bookings.
- Auditorium bookings.
- Vehicle requisition.

**Exit criterion:** Each module is operational and uses the common identity, workflow, notification, and audit infrastructure.

## Phase 8 — Signatures and Advanced Integrations

Implement or complete:

- Aadhaar eSign provider integration.
- DSC signing integration.
- DigiLocker verification.
- Advanced reporting.
- Additional external integrations.
- Document archival.
- Advanced analytics.

Integration completion depends on actual provider access, credentials, API support, and required approvals.

## Phase 9 — Production Readiness

Complete:

- Security review.
- Authorization testing.
- Concurrency testing.
- Performance testing.
- Backup/recovery tests.
- Deployment scripts and instructions.
- Environment configuration validation.
- Operational documentation.
- Monitoring and alerting.
- Final requirements traceability.

**Do not mark a phase complete merely because its database tables or user interface exist. Its critical business behavior must work and be tested.**

---

# 21. UI/UX Requirements

Create a modern, professional, responsive institutional application.

The interface must look like a unified enterprise workflow platform rather than a collection of unrelated departmental pages.

Use a consistent design system.

Provide:

- Responsive sidebar or navigation.
- Role-specific dashboards.
- Pending-work indicators.
- Search.
- Filters.
- Data tables with pagination.
- Status badges.
- Approval timelines.
- Form sections.
- Clear validation messages.
- Confirmation dialogs.
- Accessible controls.
- Document previews.
- Audit history views.
- Workflow progress visualization.
- Loading states.
- Empty states.
- Error messages.
- Success notifications.

Provide dedicated user experiences for:

1. Employee/student.
2. Approver.
3. Department administrator.
4. System administrator.
5. Vendor.
6. Technician/service operator.
7. Authorized auditor.

Approval decisions should be easy to understand and must display enough context for the authorized officer to make an informed decision.

Do not create frontend-only buttons that do nothing.

Do not hard-code fake dashboard counters, pending approvals, application records, or chart values into the final application.

All operational data must come from the backend.

---

# 22. Testing Requirements

The QA agent must test the software throughout development.

## 22.1 Automated Testing

Implement appropriate unit, feature, integration, and browser tests.

Test at least:

### Authentication and Authorization
- Valid LDAP credentials.
- Invalid credentials.
- LDAP unavailable.
- User without permission accessing a protected function.
- Unauthorized department access.
- Expired or revoked delegation.
- Administrative permission changes.

### Workflow Engine
- Sequential approvals.
- Rejection.
- Return for clarification.
- Resubmission.
- Conditional routing.
- Parallel approvals.
- Committee quorum.
- SLA reminders.
- SLA escalation.
- Workflow version changes.
- Duplicate submission.
- Concurrent approval requests.
- Unauthorized approval attempts.

### Dynamic Forms
- Required fields.
- Invalid values.
- Conditional visibility.
- Conditional validation.
- File restrictions.
- Form version preservation.
- Unauthorized data modification.

### e-Office
- File creation.
- File movement.
- Note creation.
- Note finalization.
- Restricted document access.
- Historical movement retrieval.
- Audit record protection.

### IT Service Management
- Provisioning request.
- Duplicate provisioning prevention.
- Integration failure.
- Retry handling.
- Resource validation.
- Approved account deprovisioning.
- Retention exceptions.

### Finance and Procurement
- Budget validation.
- Approval limits where configured.
- Procurement rule versioning.
- Unauthorized approval.
- Vendor isolation.
- Duplicate invoice submission.
- Incorrect workflow transition.

### Notifications
- Queue delivery.
- Retry.
- Duplicate prevention.
- Provider failure.
- Notification audit history.

### Integrations
- Successful response.
- Timeout.
- Malformed response.
- Invalid credentials.
- Partial failure.
- Repeated job execution.
- Recovery after transient failure.

## 22.2 Security Testing

Test for relevant vulnerabilities, including:

- Broken access control.
- IDOR.
- SQL injection.
- XSS.
- CSRF.
- SSRF where applicable.
- Insecure file uploads.
- Path traversal.
- Session fixation.
- Open redirects.
- Sensitive-data exposure.
- Privilege escalation.
- Brute-force attempts.
- Race conditions.
- Unsafe mass assignment.
- Unauthorized API access.

Use current OWASP guidance and the application's actual architecture to determine the relevant controls.

## 22.3 Test Execution Rules

Run the available tests after implementation changes.

Fix failures caused by the changes being made.

Clearly distinguish:

- Passed tests.
- Failed tests.
- Tests not executed.
- Tests blocked by unavailable infrastructure.
- External integrations requiring credentials.
- Remaining security limitations.

Never report a test as passed unless it actually ran and passed.

---

# 23. Non-Functional Requirements

Implement measurable and configurable operational targets.

Document assumptions concerning concurrent users, storage, document sizes, and infrastructure.

Address the following.

### Performance
- Page response times.
- Dashboard response.
- Form submission.
- Approval processing.
- Search.
- Document upload/download.
- Background job processing.

### Scalability
- Growing user base.
- Growing number of workflows.
- Growing audit history.
- Growing document storage.
- Concurrent approvals.
- Background queues.

### Reliability
- Transactional workflow changes.
- Retryable integrations.
- Recovery from failed jobs.
- Database backup.
- Document backup.
- Disaster recovery.

### Maintainability
- Reusable services.
- Clear module boundaries.
- Database migrations.
- Automated testing.
- API documentation.
- Environment-based configuration.
- Structured logging.
- Repeatable deployments.

### Accessibility
- Keyboard navigation.
- Visible focus.
- Screen-reader support.
- Form labels.
- Color contrast.
- Accessible validation feedback.

Use WCAG 2.2 AA as a design target where feasible and applicable.

### Security
- TLS.
- Secure session handling.
- Least privilege.
- Private document storage.
- Restricted secrets.
- Audit trails.
- Retention and archival.
- Controlled administrative actions.

---

# 24. Deployment and DevOps

Prepare the application for installation in an institutional environment.

Create and maintain appropriate deployment artifacts, such as:

- `.env.example` containing placeholders only.
- Database migrations.
- Seeders with synthetic development data.
- Queue worker configuration guidance.
- Scheduler configuration.
- Nginx or Apache configuration guidance.
- PHP-FPM requirements.
- Storage permission guidance.
- Database setup instructions.
- Backup instructions.
- Restoration instructions.
- Health-check endpoints where appropriate.
- Production deployment documentation.
- Upgrade and rollback instructions.

Use environment-specific configuration.

Do not embed server-specific paths, IP addresses, passwords, LDAP credentials, or API keys in source code.

Document the required background workers and scheduled tasks.

Document how to configure integrations and verify their operational status.

If Docker or containerization is added, ensure it does not conflict with existing deployment requirements.

---

# 25. Documentation Deliverables

Maintain the following documentation in the repository.

| File | Required content |
|---|---|
| `README.md` | Project overview, prerequisites, installation, execution, and testing |
| `docs/ARCHITECTURE.md` | System architecture, modules, and technical decisions |
| `docs/IMPLEMENTATION_PLAN.md` | Phases, milestones, dependencies, and status |
| `docs/REQUIREMENTS_TRACEABILITY.md` | Mapping of requirements to code and tests |
| `docs/ASSUMPTIONS_AND_DECISIONS.md` | Assumptions, unresolved decisions, and verified constraints |
| `docs/DATABASE_DESIGN.md` | Entities, relationships, and schema decisions |
| `docs/WORKFLOW_ENGINE.md` | Workflow definition, execution, versioning, and SLA model |
| `docs/LDAP_INTEGRATION.md` | LDAP configuration and identity mapping |
| `docs/SECURITY.md` | Security controls, access model, and limitations |
| `docs/INTEGRATIONS.md` | Provider configuration and external dependencies |
| `docs/DEPLOYMENT.md` | Production setup and operational requirements |
| `docs/BACKUP_AND_RECOVERY.md` | Backup, restoration, and recovery strategy |
| `docs/TESTING.md` | Test execution and acceptance criteria |
| `docs/USER_GUIDE.md` | User and approver workflows |
| `docs/ADMINISTRATOR_GUIDE.md` | Workflow configuration and system administration |

Documentation must describe implemented behavior, not planned behavior presented as completed functionality.

---

# 26. Multi-Agent Coordination Protocol

If the coding environment supports multiple AI agents, divide work into coordinated assignments.

The Lead Architect must own the architecture and maintain the single source of truth.

### Agent 1 — Platform Architect

Responsible for:

- Repository discovery.
- Architecture.
- Shared coding conventions.
- Module boundaries.
- Dependency management.
- Design review.
- Cross-module consistency.

### Agent 2 — Identity and Security

Responsible for:

- OpenLDAP.
- User synchronization.
- Authorization.
- Permission model.
- Session security.
- Audit framework.
- Security testing.

### Agent 3 — Workflow Engine

Responsible for:

- Workflow schema.
- Workflow versioning.
- Workflow runtime.
- Approval tasks.
- Transition rules.
- Delegation.
- SLA and escalation.
- Workflow tests.

### Agent 4 — Dynamic Forms and Frontend

Responsible for:

- Form designer.
- Runtime form rendering.
- Workflow designer UI.
- Dashboards.
- Approval interface.
- Responsive design.
- Accessibility.

### Agent 5 — e-Office

Responsible for:

- Electronic files.
- Note sheets.
- File movement.
- Correspondence.
- Document versioning.
- File search.
- File audit.

### Agent 6 — Departmental Modules

Responsible for:

- Computer Centre workflows.
- Leave and Registrar workflows.
- Finance and procurement.
- Estate and infrastructure.
- Vendor portal.

All these modules must reuse the common workflow engine.

### Agent 7 — Integrations

Responsible for:

- Notification providers.
- LDAP/FreeRADIUS provisioning.
- Email platform.
- Virtualization platform.
- GeM/tender integration where available.
- eSign.
- DSC.
- DigiLocker.

### Agent 8 — QA and DevOps

Responsible for:

- Automated tests.
- Security tests.
- Regression tests.
- Database integrity.
- Deployment.
- Logging and monitoring.
- Recovery procedures.
- Documentation verification.

## Coordination Rules

1. Agents must follow the agreed architecture.
2. Agents must not create duplicate implementations of shared services.
3. Shared interfaces must be documented before dependent modules are integrated.
4. Agents must not independently change shared database schemas without coordination.
5. Database migrations must use consistent naming and ownership.
6. Agents must not overwrite unrelated work from other agents.
7. All integrated changes must be reviewed and tested.
8. Agents must communicate unresolved dependencies to the Lead Architect.
9. The Lead Architect must resolve conflicts before declaring a phase complete.
10. The final deliverable must be one integrated Laravel application.

If multi-agent execution is unavailable, act as a single agent following the same division of responsibilities and integration rules.

---

# 27. Definition of Done

A feature is complete only when all applicable criteria are satisfied.

### Backend
- Models and database migrations exist.
- Server-side validation works.
- Business logic is implemented.
- Authorization is enforced.
- Error handling is implemented.
- Audit requirements are met.
- Transactions and concurrency behavior are appropriate.

### Frontend
- User interface is functional.
- Forms are connected to real backend endpoints.
- Validation errors are displayed correctly.
- Loading, success, failure, and empty states exist.
- Responsive behavior is tested.
- No critical button is merely decorative.

### Integration
- The feature uses the shared platform services.
- External dependencies are identified.
- Failure handling is implemented.
- Duplicate processing is prevented where applicable.
- Integration attempts are auditable.

### Testing
- Automated tests cover critical behavior.
- Relevant tests have been executed.
- Regressions introduced by changes have been resolved.
- Known limitations are documented.

### Documentation
- Installation/configuration instructions exist.
- Relevant requirements are traceable.
- Operational dependencies are documented.
- The implementation status is accurate.

A feature must not be marked complete simply because its interface or database table has been created.

---

# 28. Final Execution Instructions

Begin by inspecting the repository and documenting its current state.

Then follow this execution loop:

1. Inspect the existing codebase and available infrastructure.
2. Break the requirements into traceable implementation tasks.
3. Establish the shared architecture and data model.
4. Implement the foundational platform.
5. Implement the workflow engine.
6. Test the workflow engine before depending on it.
7. Implement the Computer Centre as the pilot department.
8. Implement e-File and the remaining departmental modules.
9. Add external integrations through secure, documented interfaces.
10. Execute regression, security, and acceptance tests.
11. Fix defects and verify the results.
12. Update documentation and requirements traceability.
13. Continue until all feasible in-scope features are implemented or the remaining blockers are explicitly documented.

Do not ask the user to repeat information already contained in this prompt.

Do not stop to ask about ordinary engineering choices that can be resolved safely through repository inspection and documented assumptions.

When an essential institutional policy or external dependency is unknown, implement the appropriate configuration or integration boundary and record what must be supplied or approved.

Do not make irreversible changes to production systems, disable real accounts, initiate real financial transactions, submit actual procurement transactions, or perform real external signatures without the required configuration, authorization, and explicit operational approval.

Use synthetic data and test adapters during development.

**Your priority is to deliver working, secure, maintainable software in verifiable increments—not merely to create a large quantity of code.**

---

# 29. Required Final Report from the AI Agent

At the end of the work, provide an accurate implementation report containing:

### A. Implemented Features
List the modules and features actually implemented.

### B. Repository Changes
Identify the principal files, services, models, migrations, routes, frontend components, and configuration changes.

### C. Database
List the migrations created and the setup steps required to apply them.

### D. Testing
Report actual test results, including failed and blocked tests.

### E. Integrations
For each integration, state whether it is:

- Implemented and tested.
- Implemented but awaiting credentials.
- Implemented using a test adapter.
- Blocked by unavailable APIs or infrastructure.
- Not yet implemented.

### F. Deployment
Document installation, environment configuration, database setup, queue workers, scheduled jobs, storage permissions, and operational dependencies.

### G. Security
Summarize the implemented access controls, audit mechanisms, file security, and known limitations.

### H. Outstanding Work
List incomplete requirements, unresolved institutional decisions, and external dependencies.

### I. Requirements Traceability
Provide the requirement IDs corresponding to the implemented features and their tests.

Never claim that the entire project is complete when only a subset of modules has been implemented.

---

# Final Project Goal

Create a **production-oriented, extensible, institution-wide Workflow Automation, e-Office, and Digital File Management Platform using Laravel**.

The completed platform must provide one shared foundation for authentication, identity management, workflow execution, configurable forms, approvals, electronic files, note sheets, document storage, notifications, audit logging, reporting, and external integrations.

It must support workflows for Administration and Registrar, Finance and Purchase, Estate and Infrastructure, and the Computer Centre, with additional workflows introduced through the same reusable platform.

The Computer Centre must be used as the initial pilot to validate the architecture and automation capabilities before expanding to other departments.

The result must be real, integrated software with database persistence, functioning UI, backend business logic, authorization, automated tests, deployment instructions, and operational documentation.

**Start by inspecting the repository, establish the shared architecture, and then build the actual application incrementally. Do not stop after producing the plan or the initial scaffold.**
