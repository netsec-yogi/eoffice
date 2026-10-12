# Assumptions and decisions

Each entry records what was decided, why, and what an institutional owner
must confirm. Nothing here invents institutional policy: where a rule is
policy, the software provides a configuration point and the value is
listed under *Settings administrators must complete*.

## Decisions

| ID | Decision | Reason |
|---|---|---|
| D-01 | Laravel 13 / PHP 8.3, Inertia 3 + Vue 3 + TypeScript, Tailwind 4 | Current supported releases; same stack as the institute's other Laravel projects (alumni, admissions), so one team can maintain all of them. |
| D-02 | PostgreSQL 16 | Preferred by the brief; JSONB for form data, row locking, triggers for the append-only audit table. |
| D-03 | Modular monolith (`app/Modules/*`), no microservices | One team, one deployment; boundaries enforced by services, not the network. |
| D-04 | Custom token-based workflow engine | Needs institution-specific assignment (authorities, delegation, committees), business-time SLAs and immutable versions. No PHP BPMN engine fits without heavy adaptation. |
| D-05 | LDAP via `directorytree/ldaprecord` behind `DirectoryService` | Well-maintained, supports Laravel 13; the interface keeps LDAP specifics in one place and lets tests use an in-memory fake. |
| D-06 | Spatie `laravel-permission` | Mature RBAC with DB-stored roles/permissions; record-level rules live in policies. |
| D-07 | Workflow graph designer: Vue Flow (`@vue-flow/core`, MIT) | Actively maintained Vue 3 library; renders, edits, connects nodes. Graph is validated server-side before publish. |
| D-08 | Hash-chained audit log + PostgreSQL triggers | Tamper-evident within the database's trust boundary; documented as such (not "immutable"). |
| D-09 | Side effects after commit | Notification/integration failure must never undo a recorded decision. |
| D-10 | Development OpenLDAP container with self-signed CA | Exercises StartTLS *with* certificate validation in development. |
| D-11 | Shared rule language for forms and transitions, implemented in PHP and TS with common fixtures | Server is authoritative; client mirrors it only for display. |
| D-12 | Integrations that lack credentials end in `blocked/not_configured` and open a manual task | Never report an integration as done when it was not. |
| D-13 | Instance numbering via `sequences` table, row-locked per (prefix, year) | Gap-free, concurrency-safe numbers. |
| D-14 | A delegation never routes a request to its own initiator; the principal keeps it | Found by test: otherwise the step had nobody to go to. |
| D-15 | Automation that cannot run (not configured, not installed) becomes a manual task for `on_blocked`, else the fallback, else supervisors | Honest failure mode; work never stalls silently. |
| D-16 | Live option sources for select fields (`config/workflow.php` → `option_sources`) | Forms must offer only what a register contains (e.g. software titles); validated on the server. |
| D-17 | No-dues approval opens a deprovisioning *review*; it never closes access directly | Brief §14.6: closing accounts needs status, retention, data and legal-hold checks. |
| D-18 | Deprovisioning suspends mailboxes and stops VMs; it never deletes | Deletion is irreversible and policy-dependent; left as a deliberate manual act. |
| D-20 | File access is a single query scope (`FileAccess::scope`) used for both checks and lists | A list can never show a file the check would refuse. |
| D-21 | Records officers and administrators see file register metadata, not contents, unless they are participants or granted | Brief §10.4/§17.1: no blanket access to confidential records. |
| D-22 | Destruction erases notes and document blobs but keeps the register entry and history | Proof that and when a record was destroyed, under which schedule. |
| D-23 | Leave balance = sum of an append-only ledger; pending applications hold days | No stored balance can drift; every change is explained by an entry. |
| D-24 | Domain rules run as per-workflow submission validators, not in controllers | Rules stay with the module; routes stay configuration; the designer shows which checks apply. |
| D-25 | Public certificate verification shows only number, kind, holder, date, signatory and validity | Enough to authenticate a document without exposing its content. |
| D-26 | Procurement method comes from a published, versioned rule set (bands by value) entered by Finance; each case stores the rule version, band and value it was decided on | No GFR or DoE threshold is hard-coded; a rule change never alters a case already decided; the decision is explainable. |
| D-27 | Budget position = sum of an append-only ledger (allocation, re-appropriation, commitment, release, expenditure); funds are committed at the budget check and the commitment follows the order value | Same reasoning as leave (D-23); over-commitment is prevented under a row lock on the head. |
| D-28 | Vendors are confined to the portal by middleware (allow-list of routes) in addition to policies; they never see a workflow request page | Defence in depth: a new internal page cannot become visible to vendors by omission. |
| D-29 | On a returned request, external initiators receive only the fields they fill in; the return remark is the clarification they see | Officers' entries (deductions, internal notes) never reach the browser of an outside party. |
| D-30 | On the vendor-invoice route, only the verification step may return the bill; later steps approve or reject | A return always goes to the initiator; here that is the vendor. |
| D-31 | Official PDFs: mPDF + FPDI import the supplied pages; qpdf normalises uploads to a working copy | FPDI's free parser cannot read compressed cross-reference streams; the original stays untouched and hashed. mPDF shapes Devanagari through its HTML path. |
| D-32 | Interactive (AcroForm) fields are used as positions and the output is flattened | One rendering path for all forms: overflow checks, Devanagari, and a document nobody can edit after approval. |
| D-33 | A template version's file and mapping are one immutable unit; requests fix the version at submission | Historical documents stay reproducible; a revised form never changes what was issued or what a pending request will get. |
| D-34 | Official documents are served only by `OfficialDocumentController::download` (release + access + audit; 404 for every refusal); the generic document route refuses them | One audited door; no difference between "not yours" and "not there". |
| D-35 | Permissions that read other people's documents are excluded from the system administrator's `*` (`Permissions::adminExcluded`) | The brief forbids blanket administrator access to confidential documents. |
| D-36 | The engine dispatches `WorkflowSubmitted` after commit (alongside `WorkflowFinished`) | Modules can prepare at submission without touching the engine's transaction. |
| D-19 | Application timezone Asia/Kolkata | Office hours and deadlines are local; found when a 17:30 deadline displayed as 23:00. |

## Assumptions (to be confirmed)

| ID | Assumption | Owner |
|---|---|---|
| A-01 | The directory is OpenLDAP with `inetOrgPerson` entries; login attribute `uid`. Attribute map is configurable in `config/directory.php` / `.env`. | Computer Centre |
| A-02 | A read-only bind account will be provided for search/sync. | Computer Centre |
| A-03 | Students, faculty and staff all live in the same directory, distinguished by an attribute (dev: `employeeType`). | Computer Centre |
| A-04 | Disabled accounts are marked by an attribute/value (dev: `description=account-disabled`); configurable. | Computer Centre |
| A-05 | Vendors and external users are not in LDAP and use local accounts with strong passwords (and, later, MFA). | Registrar / Finance |
| A-06 | Timezone Asia/Kolkata; working week Mon–Fri, 09:00–17:30 as a *seed default only*; the real calendar is configured by admin. | Registrar |
| A-08 | Computer Centre routes (it-email … no-dues) are examples: recommendation by reporting officer (HoD as fallback), CC verification, Head CC for VPN / public IP / above-quota VMs. | Head, Computer Centre |
| A-09 | VM quotas per category in `config/computer_centre.php` are placeholders. | Head, Computer Centre |
| A-10 | Wi-Fi/VPN entitlement is LDAP group membership (`wifi-users`, `vpn-users`) evaluated by FreeRADIUS/VPN. | Computer Centre |
| A-11 | The no-dues clearances (Computer Centre, HoD, Accounts, then Registrar) are an example set; Library, Hostel etc. are added by configuration. | Registrar |
| A-12 | File categories, numbering patterns and retention schedules in `EFileDemoSeeder` are placeholders; the Registrar's records schedule must replace them. | Registrar |
| A-13 | Custody passes on acknowledgment, not on dispatch (traditional "file received" practice). | Registrar |
| A-14 | Leave types (CL, EL, HPL, DL, SL), their limits, notice and certificate thresholds, and the demo balances are placeholders; the leave rules applicable to the institute must be entered. | Registrar |
| A-15 | LTC blocks and journeys per block (`config/registrar.php`) are placeholders. | Registrar / Finance |
| A-16 | RTI limits in `config/registrar.php` follow RTI Act 2005 s.7(1), s.5(2), s.6(3), s.19 as commonly applied; verify before production. | RTI cell |
| A-17 | Certificates and circulars name the approving officer and carry a verification code; they are not digitally signed until Phase 8. | Registrar |
| A-18 | Finance workflow routes (`FinanceWorkflowSeeder`), including the example ₹5,00,000 limit for Director vs Registrar approval of purchases, are examples; the delegation of financial powers must be entered by Finance. | Finance / Registrar |
| A-19 | The seeded procurement rule set is demonstration data titled "DEMO — not institutional rules"; Finance must publish the rules in force (GFR 2017 and current DoE instructions, institute orders) before go-live. | Finance |
| A-20 | GeM is recorded manually (availability, reference); there is no GeM integration. | Purchase |
| A-21 | Payment is recorded (reference, date) after it is made through the institute's bank / PFMS process; the system does not initiate payments. | Finance |
| A-22 | Institute name, short name, address and city printed on forms come from `INSTITUTE_*` settings (`config/official_documents.php`); they are placeholders until set. | Registrar |
| A-23 | 20 institute forms were supplied (2026-10-12); 7 are mapped to existing workflows, 12 registered for mapping (docs/OFFICIAL_PDF_TEMPLATES.md). The synthetic form remains only as a test fixture. |
| A-24 | The wording printed for a recorded approval (`official_documents.approval_statement`) is a placeholder; the office should approve it. | Registrar |
| A-25 | Box positions on the institute forms were measured from the files and checked on previews, not confirmed by the office. Approval records are printed beside the signatory labels; which officer signs which block follows the example workflows (e.g. the leave "Sanctioning authority" is recorded against "Head of the Department / Reporting Officer"). | Registrar / Finance |
| A-07 | Sections 22.1 (remainder) onwards of the master prompt were truncated when supplied; the testing, documentation and acceptance requirements visible up to that point are applied, and the remainder is to be supplied. | Project sponsor |

## Settings administrators must complete before production

* LDAP hosts, CA bundle, bind DN/secret, people base DN, attribute map,
  category and disabled-account rules.
* Departments, designations, authorities and their current holders,
  committees and quorum rules.
* Holiday calendar(s) and working hours.
* Every departmental workflow route, SLA and escalation chain (seed
  workflows are *examples* and are marked as such).
* Leave entitlements, TA/DA rates, LTC rules, medical reimbursement rules.
* Procurement thresholds and delegation of financial powers (GFR 2017 and
  current DoE instructions must be verified by Finance before entry).
* RTI statutory timelines (verify against the RTI Act and current rules).
* Notification provider credentials (SMTP, SMS, WhatsApp Business).
* eSign ASP/ESP, DSC middleware, DigiLocker partner credentials.
* Provisioning targets (mail platform API, FreeRADIUS/LDAP groups, Proxmox).
* Document retention schedules.
