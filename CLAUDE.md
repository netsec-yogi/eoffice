# Digital Workflow: agent guide

Laravel 13 + Inertia 3 (Vue 3, TS) + Tailwind 4 + PostgreSQL 16 + Redis +
OpenLDAP, all in Docker. Requirements: the master brief; IDs in
`docs/REQUIREMENTS_TRACEABILITY.md` (AUTH-, WF-, FORM-, ITSM- …) are cited in
code comments. Keep the docs in `docs/` current when behaviour changes.

## Running things
- No host PHP. `docker compose exec -T app php artisan …` or the Makefile
  (`make test`, `make lint`, `make test-ldap`).
- Node runs in a throwaway container: `docker run --rm -u 1000:1000 -v "$PWD":/app -w /app node:22-alpine npm run build`.
- Tests use the `digital_workflow_testing` database (phpunit.xml). Never the dev DB.
- After changing PHP used by queued jobs: `docker compose restart queue` (workers keep boot-time code and config).

## Architecture rules
- One engine. Departmental modules add domain records and automation
  actions (`config/workflow.php`); routes, steps, deadlines and forms are
  configuration made with `WorkflowDesigner` / `FormService`, never code.
- Every workflow state change goes through `WorkflowEngine` (row lock,
  transaction, history, audit). Never update `workflow_*` tables directly.
- Published workflow and form versions are immutable (model guards).
- Approval authority comes from authorities, reporting lines and committees
  resolved by `AssigneeResolver` — never from roles or employee IDs in code.
- External systems sit behind interfaces with a `NotConfigured…` adapter; a
  missing integration becomes a manual task. Never fake success.
- Automation actions must be idempotent (`IntegrationJob.idempotency_key`).

## Conventions
- Models declare `#[Fillable]`; status/authority fields are set with
  `forceFill` in the owning service.
- Controllers stay thin and call `$this->authorize(<permission>)` or a policy
  on every action. Permissions live in `App\Modules\Identity\Permissions`.
- Security-relevant actions call `AuditLogger::record()`. Never log secrets.
- Files enter only through `DocumentStore` and leave only through
  `DocumentController` (owner implements `HasDocuments`). Exception: official
  PDFs leave only through `OfficialDocumentController::download` (release +
  per-document access + audit); see docs/OFFICIAL_PDF_TEMPLATES.md.
- Permissions that read other people's records go in
  `Permissions::adminExcluded()` so `system_admin`'s `*` never implies them.
- Every polymorphic model is listed in `config/morph.php`.
- Strict mode is on outside production: eager-load; never load a *partial*
  relation (`->load('x:id,code')`) onto the shared request user.
- Rule/formula changes: update PHP and TS together and add fixtures to
  `tests/fixtures/*.json`.
- UI: semantic colour tokens (`bg-surface`, `text-ink`, `text-muted`…),
  components from `resources/js/Components`, new pages in
  `resources/js/lib/navigation.ts`. Destructive actions confirm via `ask()`.
