# Architecture

Institute-wide workflow automation, e-Office and digital file management platform.

**Principle: one platform, one workflow engine, many configurable departmental workflows.**
No department gets its own approval engine, its own login or its own audit log.
Departmental modules add *domain records* (an IT asset, a purchase order, a
leave balance) and *automation actions* (create a mailbox, debit a leave
balance); the route a request takes is always data in the workflow engine.

## 1. Runtime topology

```
             browser (Inertia + Vue 3 SPA)
                        |
                     nginx  ── serves /public only
                        |
                  PHP-FPM (app)  ──── PostgreSQL 16 (system of record)
                        |      \──── Redis (sessions, cache, queues, locks)
          queue workers (queue) ──── Mail (SMTP), SMS / WhatsApp adapters
          scheduler (scheduler) ──── OpenLDAP (auth, directory sync)
                                ──── provisioning targets (LDAP groups,
                                     FreeRADIUS, mail platform, Proxmox)
```

Everything runs in Docker (`compose.yaml`). The `ldap` and `mailpit`
containers are development stand-ins and are never deployed.

* **app** answers HTTP requests. Workflow decisions commit in a database
  transaction; side effects (notifications, integrations) are queued *after
  commit*, so a failing SMS never rolls back an approval.
* **queue** runs notification delivery, integration jobs and engine
  automation steps on named queues: `workflow`, `notifications`,
  `integrations`, `default`.
* **scheduler** runs SLA monitoring (reminders, escalations), directory
  sync, delegation expiry and audit chain verification.

## 2. Code layout (modular monolith)

```
app/
  Modules/
    Identity/         users, departments, designations, authorities,
                      committees, delegations, LDAP directory + sync
    Audit/            hash-chained audit log, AuditLogger, verification
    Workflow/         definitions, versions, engine, tasks, SLA, designer API
    Forms/            form templates/versions, schema validator
    Documents/        stored documents, versions, scanning, secure download
    Notifications/    templates, dispatcher, channel adapters, attempts
    Calendar/         holiday + working-time calendars, business-time maths
    Integrations/     integration jobs, idempotency, adapters, health
    EFile/            electronic files, note sheets, movements, correspondence   (Phase 4)
    ComputerCentre/   IT service requests, assets, licences, provisioning     (Phase 3)
    ...               Registrar, Finance, Estate (Phases 5-7)
  Http/               shared middleware, Inertia bootstrap
resources/js/
  Pages/<Module>/...  Inertia pages
  Components/         the shared design system (one button, one table ...)
  Workflow/           designer + dynamic form renderer
```

Each module owns its models, services, controllers, policies and routes
(`routes/modules/*.php`). Modules talk to each other through services
(`WorkflowEngine`, `AuditLogger`, `NotificationDispatcher`,
`DocumentStore`), never by writing to another module's tables.

## 3. Identity and authorization

* **Authentication** — `DirectoryService` interface. `LdapDirectoryService`
  (LdapRecord) binds with a read-only service account, searches the login
  attribute, then binds *as the user's DN* to verify the password over
  LDAPS/StartTLS with certificate validation. If the directory is
  unreachable, login fails closed; there is no fallback to a cached hash.
  External users (vendors, guests) use local accounts (`auth_source=local`).
* **Provisioning** — first successful login (or the scheduled
  `directory:sync`) creates/updates the local `users` row from a
  configurable attribute map (`config/directory.php`). New users receive
  **no roles** beyond the base role of their category; administrators grant
  everything else.
* **Authorization** — Spatie permissions (roles + permissions in the DB),
  checked in Policies and `$this->authorize()` on every controller action.
  Record-level rules (department scope, confidentiality, "is this my task")
  live in policies. The frontend only hides what the backend already forbids.
* **Organisation model** — `departments` (tree), `designations`,
  `authorities` (named offices such as HOD, DEAN_ACADEMIC, REGISTRAR) and
  time-bounded `authority_assignments` (user, authority, department scope,
  acting flag). Workflows reference *authorities*, never employee IDs.
  `committees` + `committee_members` (period, role, voting weight).
  `delegations` (from, to, period, workflow categories, allowed actions).

## 4. Workflow engine

### 4.1 Definitions and versions

`workflow_definitions` (stable key, department, category) own many
`workflow_versions`. A version holds the graph (`workflow_nodes`,
`workflow_transitions`), the form version it uses and its settings
(initiator rules, numbering prefix). States: `draft → published → retired`.
Published versions are immutable (enforced in the model and service).
Editing a published workflow clones it into a new draft. Each instance
records the version it started on and runs on it to completion.

### 4.2 Graph

Nodes have a `type` and a JSON `config`. Implemented types:

| type | behaviour |
|---|---|
| `start` | entry; the initiator's submission |
| `approval` / `review` / `verification` | human task(s) for resolved assignees; outcomes `approve`/`reject` (+ return, hold) |
| `committee` | one task per member; aggregation rule: `any`, `all`, `majority`, `quorum` (n), `weighted` (threshold) |
| `decision` | exclusive gateway; first outgoing transition whose condition matches |
| `parallel_split` / `parallel_join` | fork tokens / wait for all branches (or `any`) |
| `signature` | human task completed through a `SignatureProvider` |
| `automation` | runs a registered `AutomationAction` on the `integrations` queue |
| `notification` | sends a template, then continues |
| `timer` | waits for a business-time duration |
| `end` | terminal: `completed`, `rejected` or `cancelled` |

Transitions carry an `outcome` (approve, reject, default, success, failure,
timeout) and an optional **condition** written in the shared rule language
(§5.3).

### 4.3 Execution model

Token-based. `workflow_instances` holds status, form data and a
`lock_version`; `workflow_tokens` mark where execution currently is (several
tokens in parallel branches). Every command goes through
`WorkflowEngine`:

1. Open a transaction, `SELECT … FOR UPDATE` the instance row.
2. Check the instance state and the actor's authority over the task
   (assignee, valid delegate, or an administrator with the explicit
   permission).
3. Record the decision (`workflow_actions`, append-only), complete the task.
4. Advance tokens through automatic nodes until every token rests on a
   human/automation/timer node or an end.
5. Create tasks for newly reached human nodes (`AssigneeResolver`), compute
   due dates (`BusinessCalendar`).
6. Write the audit event; register after-commit callbacks for notifications
   and queued automation.

Two users acting on the same task at once: the second blocks on the row
lock, then finds the task no longer `pending` and receives a conflict error.
Unique keys on `(task_id)` in decisions back this up.

### 4.4 Assignment

`AssigneeResolver` turns a node's assignee spec into users:
`user`, `role`, `authority` (scope: initiator's department, a fixed
department, or a department taken from a form field), `reporting_manager`
(n levels up), `committee`, `form_field` (employee selector), `initiator`.
After resolution: active delegations are applied (the delegate gets the task,
`on_behalf_of` keeps the principal), and separation of duties removes the
initiator (unless the node explicitly allows self-approval). An empty result
is never silently skipped: the task goes to the definition's fallback
authority and an audit/alert is raised.

### 4.5 SLA

Node config: `sla: {duration, unit: hours|working_hours|working_days, reminders: [...], escalations: [{after, to: <assignee spec>, action: notify|reassign}]}`.
`BusinessCalendar` computes due dates against working days, working hours and
holiday calendars. The scheduled `workflow:sla-scan` command is idempotent:
each reminder and escalation level is a row with a unique key
`(task_id, kind, level)`, so re-running it never doubles an escalation.
On-hold instances pause their SLA clock (`paused_seconds`).

## 5. Dynamic forms

### 5.1 Storage

`form_templates` → `form_versions` (immutable once published, JSON schema of
sections and fields). A submission stores `form_version_id`, data and
revision number in `form_submissions`; the instance keeps the latest data.
Business-critical searchable values (amounts, dates, asset numbers) are
copied into typed columns of the domain tables by the module that owns them.

### 5.2 Validation

`FormSchemaValidator` builds Laravel validation rules from the schema,
evaluating visibility conditions on the server so hidden fields are
neither required nor accepted. The Vue renderer evaluates the same
conditions for display only.

### 5.3 Rule language (shared)

```json
{"all": [{"field": "travel_type", "op": "eq", "value": "international"},
         {"any": [{"field": "amount", "op": "gt", "value": 50000}]}]}
```

Operators: `eq neq gt gte lt lte in not_in contains empty not_empty`.
Implemented once in PHP (`RuleEvaluator`) and once in TypeScript
(`resources/js/Workflow/rules.ts`) with a shared fixture test file so the
two cannot drift.

## 6. Documents

All uploads enter through `DocumentStore`: server-side MIME sniffing against
an allow-list, size limit, randomised storage name on the private disk,
SHA-256 checksum, optional malware scan (`MalwareScanner`: ClamAV adapter or
`NullScanner`, which marks the document *unscanned* rather than clean).
Documents are served only by `DocumentController`, which authorises every
request through `DocumentPolicy`; URLs carry an ID, never a path, and knowing
the ID grants nothing.

## 7. Audit

`audit_events` is append-only and hash-chained: each row stores
`hash = SHA-256(prev_hash || canonical_json(row))`. In PostgreSQL, triggers
reject `UPDATE`/`DELETE` on the table. `php artisan audit:verify` walks the
chain and reports the first broken link. This is **tamper-evident, not
tamper-proof**: a database superuser can drop the trigger and rewrite the
chain. Production should additionally ship events to external append-only
storage (see `docs/SECURITY.md`). Secrets are redacted by key before
writing (`config/audit.php`).

## 8. Notifications

`NotificationDispatcher::send(templateKey, recipients, context)` renders the
admin-editable template per channel, writes one `notification_messages` row
per (recipient, channel) with a deterministic idempotency key, and queues
delivery. Channel adapters: `mail`, `database` (in-app), `sms`, `whatsapp`.
SMS/WhatsApp ship with a `log` driver; real providers are adapters selected by
config. Every attempt is recorded; failures are visible in the admin monitor.

## 9. Integrations

Long-running or external actions are `integration_jobs` with an
idempotency key (unique), status, attempts and a correlation ID. The job
calls an adapter behind an interface (e.g. `MailboxProvisioner`,
`NetworkAccessProvisioner`, `VirtualMachineProvisioner`). If an adapter is
not configured the job ends `blocked: not_configured` and a human task is
created for manual completion — it never reports success it did not achieve.

## 10. Data model (core)

See `database/migrations`. Main tables:

* Identity: `users`, `departments`, `designations`, `authorities`,
  `authority_assignments`, `committees`, `committee_members`, `delegations`,
  `roles`, `permissions` (+ pivots).
* Workflow: `workflow_definitions`, `workflow_versions`, `workflow_nodes`,
  `workflow_transitions`, `workflow_instances`, `workflow_tokens`,
  `workflow_tasks`, `workflow_actions`, `workflow_task_events` (SLA),
  `form_templates`, `form_versions`, `form_submissions`.
* Platform: `audit_events`, `documents`, `notification_templates`,
  `notification_messages`, `holiday_calendars`, `holidays`,
  `working_calendars`, `integration_jobs`, `system_settings`, `sequences`.

## 11. e-Office (Phase 4)

`EFile` (custodian, classification, retention) with `efile_notes`,
`efile_movements`, `correspondence`, `efile_access_grants`, `efile_links` and
the append-only `efile_events`. `EFileService` owns every change (row lock +
history event + audit). `FileAccess` is the single definition of who may
read a file, used as a check and as a query scope. A file routed through a
workflow is the request's `subject`; `WorkflowFinished` (dispatched after
commit by the engine) lets `RecordWorkflowOutcome` write the result into the
file history. Database triggers keep finalised notes and file history
immutable; only a file destroyed under its schedule may lose its notes.

## 12. Finance and Purchase (Phase 6)

`Finance` owns budget heads and their append-only `budget_entries`,
versioned `procurement_rule_sets`, `vendors`, `procurement_cases` (one per
indent request), `quotations`, `purchase_orders`, `goods_receipts`,
`vendor_invoices` and `advances`. Approvals are ordinary workflow steps;
the `finance.register` automation keeps the register in step and is
idempotent. `IndentValidator` prices the indent, checks the head and asks
`ProcurementRules` for the method; the decision is stored with the rule
version. `ReleaseOnFinish` (on `WorkflowFinished`) releases commitments of
rejected or cancelled indents. Vendors are users of category `vendor` with
a local password; `ConfineExternalUsers` limits them to the portal routes,
and `RequestController` shows them only their own fields of a returned bill.

## 13. Official PDF forms

`OfficialDocuments` keeps `pdf_templates` → `pdf_template_versions` (original
file through `DocumentStore`, qpdf working copy, pages, detected fields and
the field mapping, immutable once published), `workflow_document_outputs`
(workflow → form, with release and access terms) and `official_documents`
(one current row per request and form, pinned template version, status,
stored file and hash, supersession chain). `WorkflowSubmitted` creates rows;
`WorkflowFinished` queues `GenerateOfficialDocument`, which re-checks the
instance, renders with `PdfRenderer` (mPDF + FPDI), validates with
`PdfChecker` and stores the result. See docs/OFFICIAL_PDF_TEMPLATES.md.

## 14. Deployment

Ubuntu server, Docker Compose (or the same processes under systemd):
nginx → PHP-FPM, `queue:work` workers under a supervisor, `schedule:work`
(or cron `schedule:run`), PostgreSQL with WAL archiving and nightly
`pg_dump`, Redis with AOF. Private document storage is backed up with the
database; restore is tested (Phase 9). See `docs/DEPLOYMENT.md` (Phase 9).
