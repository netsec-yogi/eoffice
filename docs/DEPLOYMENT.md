# Deployment and operations (draft)

Phase 9 completes this document (hardening review, load tests, restore
drill). What is already required:

## Processes

| Process | Command | Notes |
|---|---|---|
| Web | nginx → PHP-FPM (`app`) | nginx serves only `public/`; documents stream through the app |
| Queue workers | `php artisan queue:work redis --queue=workflow,notifications,integrations,default --tries=3` | Under a supervisor; at least two in production |
| Scheduler | `php artisan schedule:work` (or cron `* * * * * php artisan schedule:run`) | Runs `workflow:tick` (1 min), `workflow:sla-scan` (5 min), `directory:sync` (02:00), `audit:verify` (03:00) |

## Every deployment

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force`
3. `npm ci && npm run build`
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
5. **`php artisan queue:restart`** — workers keep the code and configuration
   they booted with. A worker started before a deployment will not know new
   automation actions (it reports them "not installed", which turns the step
   into a manual task) — observed in development, hence this step.

## Configuration that must be set (see ASSUMPTIONS_AND_DECISIONS.md)

`APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`,
`SESSION_SECURE_COOKIE=true`, database and Redis credentials, SMTP relay,
`LDAP_*` (hosts, CA bundle, bind account, attribute map), `SEED_ADMIN_USERNAME`,
integration drivers in `config/computer_centre.php`, `DOCUMENTS_SCANNER=clamav`.

## Official PDF forms

* The PHP image includes `qpdf` and `poppler-utils`; both are required (template
  upload, page images, generation checks). Rebuild the image after pulling.
* Generation runs on the `integrations` queue: restart workers after deploying
  PHP changes (`docker compose restart queue`).
* Set `INSTITUTE_NAME`, `INSTITUTE_SHORT_NAME`, `INSTITUTE_ADDRESS`,
  `INSTITUTE_CITY` if forms print them.
* Register the institute's forms once the workflows exist:
  `php artisan db:seed --class=InstituteFormsSeeder --force` (idempotent; it
  skips forms already registered, so later corrections are made as new
  versions in the panel, not by re-seeding).
* `storage/app/private/tmp/official` holds short-lived render files; it may be
  emptied at any time.

## Backups

* PostgreSQL: nightly `pg_dump -Fc` plus WAL archiving; keep the audit chain
  head (`select hash from audit_events order by id desc limit 1`) with each
  backup so a restored chain can be checked with `php artisan audit:verify`.
* `storage/app/private/documents`: back up with the database dump of the same
  moment (documents are referenced by database rows).
* Redis holds sessions, cache and queued jobs only; it can be rebuilt, but
  queued jobs in flight would be lost — drain the queue before maintenance.

## First start

```
cp .env.example .env   # then edit
make install           # image, composer, key, migrate + base seed, assets
```

Then sign in as `SEED_ADMIN_USERNAME` (directory credentials), create
departments, designations, authorities and holders, set the working calendar,
and publish workflows. `make demo` loads synthetic data for development only.
