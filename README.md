# Digital Workflow

Institute-wide workflow automation and e-Office platform: one platform, one
workflow engine, many configurable departmental workflows.

Laravel 13 · Inertia 3 / Vue 3 / TypeScript · Tailwind 4 · PostgreSQL 16 ·
Redis · OpenLDAP. Everything runs in Docker.

## Start

```
cp .env.example .env
make install     # image, dependencies, key, migrations + base seed, assets
make demo        # synthetic organisation + Computer Centre example workflows
```

Open http://localhost:8100 and sign in with a development directory account
(`rverma` = system administrator and Computer Centre operator, `pkumar` = HoD,
`asharma` = faculty, `s2024001` = student; password `Dev-Password-2026`).
Mail is caught at http://localhost:8028.

## Checks

```
make test        # PHPUnit (separate test database) + shared rule fixtures (Node)
make test-ldap   # integration tests against the dev OpenLDAP container
node tests/Browser/flow.mjs    # workflow scenario in a real browser (after make fresh demo)
node tests/Browser/efile.mjs   # e-file scenario
node tests/Browser/registrar.mjs   # leave, certificate verification, circulars
make lint audit
```

## Documents

* `docs/ARCHITECTURE.md` — modules, engine, data model
* `docs/IMPLEMENTATION_PLAN.md` — phases and what is verified
* `docs/REQUIREMENTS_TRACEABILITY.md` — every requirement → code → test
* `docs/INTEGRATIONS.md` — which external systems are connected, and how verified
* `docs/SECURITY.md` — trust boundaries, controls, residual risks
* `docs/ASSUMPTIONS_AND_DECISIONS.md` — decisions and settings the institute must confirm
* `docs/DEPLOYMENT.md` — processes, deployment steps, backups
