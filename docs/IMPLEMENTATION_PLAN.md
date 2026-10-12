# Implementation plan

Status legend: ✅ done and tested · 🟡 partial (see notes) · ⬜ not started ·
⛔ blocked on an external dependency. Feature-level status:
`REQUIREMENTS_TRACEABILITY.md`.

## Phase 0 — Discovery and planning ✅

Repository was empty and the host had no PHP. Built `abviiitm/workflow-php:8.3`
(pdo_pgsql, ldap, redis, intl, gd, zip, bcmath) and a Compose stack (app,
queue, scheduler, nginx, PostgreSQL 16, Redis, Mailpit, dev OpenLDAP with its
own CA). Laravel 13.17, Inertia 3 / Vue 3 / TypeScript, Tailwind 4, Vue Flow
1.48 (MIT). Planning documents written.

## Phase 1 — Foundation ✅

Exit criterion met: users authenticate through the directory (verified
against the dev OpenLDAP over StartTLS, `LdapDirectoryTest` and a real browser
sign-in); permissions are enforced on the backend (`AdminAuthorizationTest`);
sensitive administrator actions are audited (every admin controller; tested).

Delivered: identity schema, LDAP login + provisioning + sync, RBAC, admin
panel (users, organisation, authorities, committees, roles, calendars,
notifications, audit), hash-chained audit log with DB triggers and
`audit:verify`, document store, notification framework, business calendar,
queues and scheduler.

## Phase 2 — Workflow engine ✅

Exit criterion met: `AdministratorBuildsWorkflowTest` — an administrator
builds and publishes a form and a four-step conditional workflow over HTTP,
and staff run it to completion, including a return for documents and an
approver-only field, with no code change.

Delivered: form builder (24 field types, conditional rules, calculated fields,
repeating tables, live option sources), graphical designer (Vue Flow) with
server-side validation, immutable versions, token-based engine (sequential,
conditional, parallel, committee rules, delegation, separation of duties,
return/resubmit, forward, reassign, hold/resume, cancel), automation actions,
timers, idempotent SLA reminders and escalations, inbox, requests, monitor,
dashboard, in-app notifications.

## Phase 3 — Computer Centre pilot 🟡

Delivered and tested: eight configured workflows (e-mail, Wi-Fi, VPN, VM,
equipment issue, software licence, deprovisioning, no-dues), account ledger,
IT asset register, licence register, automation actions, LDAP group
provisioning (verified against the dev directory), Proxmox adapter (tested
with faked API only), deprovisioning with legal hold and idempotency.

Open before the exit criterion can be signed off by the Computer Centre:
* ⛔ mail-platform adapter (needs the platform and API access);
* 🟡 Proxmox adapter to be run against a test pool;
* 🟡 FreeRADIUS to authorise on the `wifi-users`/`vpn-users` groups;
* the example routes, deadlines and VM quotas to be confirmed
  (`docs/ASSUMPTIONS_AND_DECISIONS.md`).

## Phase 4 — Electronic files ✅

Exit criterion met: `CorrespondenceAndHttpTest::test_file_lifecycle_over_http`
and `tests/Browser/efile.mjs` — a letter is diarised, a file is opened, the
letter filed, a note finalised, the file sent for approval, received, a
decision recorded, transferred onward, an outgoing reply dispatched, and the
whole history retrieved by authorised readers only.

Delivered: files with category numbering and classification; note sheet with
private drafts, numbered immutable notes (DB trigger), content hashes and
addenda; movement with acknowledgment and recall (one in transit, DB index);
diary/dispatch registers; versioned documents; links; read-access grants;
routing through configured workflows (`file_approval` category) with the
outcome written back; retention schedules, review command, legal hold,
recorded destruction and transfer; records register.

## Phase 5 — Administration and Registrar ✅

Exit criterion met: every Registrar workflow (leave, leave cancellation,
tour, TA/DA claim, LTC, LTC claim, medical claim, three NOCs, experience
certificate, circular approval, RTI application, RTI first appeal) has a
form, a configurable route published through the designer service,
server-side validation, notifications, audit history and acceptance tests
(`LeaveTest`, `TravelClaimsTest`, `CertificatesCircularsRtiTest`, 21 tests),
plus `tests/Browser/registrar.mjs` through the real UI.

Engine extensions (generic, configurable in the designer): submission
validators declared per workflow, live option sources that depend on the
person filling the form, step deadlines taken from a form date.

Bugs found and fixed in this phase: the engine picked the *oldest* history
entry where it meant the newest (hold/resume authority, final-notice
remarks, "returned by" banner, certificate signatory); dotted config keys
were read as nested paths; publication errors did not say what was wrong.

## Phase 6 — Finance, Purchase and vendor portal ✅

Exit criterion met: procurement progresses through its configured
lifecycle — indent, budget check and commitment, sourcing and comparative
statement, evaluation checked against the published rules, committee,
approval by value, purchase order, receipt and inspection, vendor bill with
three-way match, verification, sanction, payment and payment advice — with
permission checks, auditable transitions and financial rules that Finance
enters and publishes as versioned data. `ProcurementTest` (10) and
`FinanceHttpTest` (5) cover it, plus `tests/Browser/finance.mjs` through the
real UI (purchase officer, committee, Registrar, vendor, Finance).

* Five example workflows (`FinanceWorkflowSeeder`): purchase indent, goods
  receipt, vendor invoice, advance, advance settlement. The routes and the
  value limit for Director vs Registrar approval are examples (A-18).
* The demo rule set (`FinanceDemoSeeder`) is titled "DEMO — not
  institutional rules" and every screen showing it says so.
* Budget heads with an append-only ledger; commitments follow the order
  value; payment moves the amount from committed to spent; cancelled or
  rejected indents release their commitment.
* Vendor portal: own orders, bills, clarifications and payment advices only.
  Vendors are confined to the portal by middleware as well as by policies.

Defects found by verification and fixed in this phase: a vendor answering a
returned bill would have seen officers' internal fields (now removed on the
server); a returned bill's order dropped out of the vendor's own order list,
so the bill could not be resubmitted; the Finance Officer's "return" would
have sent internal remarks to the vendor (the sanction step now offers
approve / reject only); PO items exposed the indent's internal price
estimates to the vendor; Inertia's page-existence check pointed at
`js/pages` (lower case), so component assertions were never possible;
"-0.00" shown for empty budget columns.

## Official PDF forms ✅ (requested before Phase 7)

The approved-request → official institute PDF flow works end to end:
template upload and versioning, visual field mapping (flat and fillable
forms), previews with synthetic data, workflow outputs, generation after
final approval on the queue, status for users and administrators, audited
downloads with per-document access, reissue, and the monitor. 18 tests
(`OfficialDocumentTest`, `TemplateAdministrationTest`) cover the 13
acceptance tests of the brief, plus `tests/Browser/officialdocs.mjs`.

**Institute forms (2026-10-12):** 20 supplied forms imported unchanged; 7
mapped, published and attached (casual leave, earned leave, T.A. advance,
TA bill, medical reimbursement — confidential, advance settlement F-2,
technical verification); 12 registered for mapping once their workflows
exist. Added for them: `{{ path }}` placeholders in fixed text, amounts in
words, row numbers in tables, and submission-time snapshots of the advance
and journey a form refers to. `InstituteFormsTest` renders all seven and
checks that nothing outside their boxes changes on any page. Signing before
release waits for the signature module (Phase 8).

Defects found by verification and fixed: a non-UUID document link returned
500 instead of 404; FPDI rejected PDFs with compressed cross-reference
streams (normalised now); Devanagari drawn with `Cell()` was unshaped (now
through mPDF's HTML path); the system Noto Devanagari font crashed mPDF's
font parser (GNU FreeSerif used instead).

## Phases 7–9 ⬜

* Phase 7 (Estate): bookings need transaction-safe availability (exclusion
  constraints in PostgreSQL).
* Phase 8: signature steps are already blocked from publishing until a
  `SignatureProvider` exists; certificates and circulars state they are not
  digitally signed.
* Phase 9: load tests, restore drill, deployment scripts.

## Verification log

| Date | Check | Result |
|---|---|---|
| 2026-10-11 | LDAP StartTLS search with dev CA; with wrong CA | pass; refused (expected) |
| 2026-10-11 | Real sign-in over HTTP as seeded admin | 302 → dashboard; audit row written |
| 2026-10-11 | `php artisan test` | 164 passed |
| 2026-10-11 | `npm run test:rules` (shared fixtures, TS mirror) | 39 passed |
| 2026-10-11 | `tests/Browser/flow.mjs` (submit → recommend → verify → manual mailbox → completed; admin screens) | passed, no console errors |
| 2026-10-11 | `composer audit`, `npm audit --omit=dev` | no advisories / 0 vulnerabilities |
| 2026-10-11 | Phase 4: `php artisan test` | 180 passed |
| 2026-10-11 | `tests/Browser/efile.mjs` | passed |
| 2026-10-11 | Phase 5: `php artisan test` | 201 passed |
| 2026-10-11 | `tests/Browser/registrar.mjs` (leave sanction and balance, NOC issued and publicly verified, circular approved and acknowledged) | passed |
| 2026-10-11 | Phase 6: `php artisan test` | 216 passed |
| 2026-10-11 | `tests/Browser/finance.mjs` (indent → commitment → 3 quotations, L1 selected → committee → Registrar → PO in vendor portal → receipt and inspection → vendor bill → verification with deductions → sanction → payment advice in portal; vendor refused internal pages) | passed; flow, efile and registrar scenarios re-run and passed |
| 2026-10-12 | Official PDF forms: `php artisan test` | 234 passed |
| 2026-10-12 | `tests/Browser/officialdocs.mjs` (mapper and sample preview; leave waiting → sanctioned → applicant downloads PDF; officer outside the groups refused; monitor) | passed |
| 2026-10-12 | Institute forms: 7 mapped forms render, pass checks, pixel-identical outside boxes; CL/EL leave issue their own forms | passed |
| 2026-10-11 | Defects found by verification and fixed | partial relation on shared user (500 on pages), delegation to the initiator, approver-only fields accepted at submission, manual-task double assignment, designer watcher loop, UTC office hours, stale queue worker (documented in DEPLOYMENT.md) |
