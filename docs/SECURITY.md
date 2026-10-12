# Security

## Trust boundaries

1. **Browser ↔ app** — everything the browser sends is untrusted. All
   authorization happens server-side in policies/gates; hidden buttons are
   cosmetics.
2. **App ↔ directory** — LDAPS or StartTLS with certificate validation
   against `LDAP_CA_CERT`. Read-only bind account for search; user password
   verified by binding as the user. Passwords are never stored or logged.
3. **App ↔ database** — the application role owns the schema. Audit rows
   are protected by triggers that reject UPDATE/DELETE; a DB superuser can
   still bypass them (residual risk R-1).
4. **App ↔ external providers** — credentials only via environment/secret
   store; adapters validate responses and time out.

## Controls

| Area | Control | Where |
|---|---|---|
| Authentication | LDAP bind, fail closed when unreachable, rate limiting per username+IP, session regeneration on login | `Modules/Identity` |
| Accounts | Disabled directory accounts and locally deactivated users cannot sign in; sync never grants admin roles | `DirectorySync` |
| Sessions | Encrypted Redis sessions, HttpOnly, SameSite=Lax, Secure in production, 60-min idle timeout | `.env`, `config/session.php` |
| Authorization | Spatie permissions + policies; every controller action authorizes; record scope by department/assignment/confidentiality | policies |
| Workflow | Decision only by current assignee or valid delegate; row lock + state check; self-approval blocked unless the node allows it | `WorkflowEngine`, `AssigneeResolver` |
| Delegation | Time-bounded, category-scoped, action-scoped; cannot delegate what you don't hold; delegate cannot approve own request | `DelegationService` |
| Documents | Private disk, allow-listed MIME by content, size cap, random names, checksum, malware scan hook, per-request authorization | `Modules/Documents` |
| Audit | Append-only hash chain, redaction of secret keys, `audit:verify` | `Modules/Audit` |
| Headers | CSP, X-Frame-Options DENY, nosniff, Referrer-Policy | middleware + nginx |
| CSRF | Laravel CSRF on all state-changing web routes | framework |
| External parties | Vendors confined to an allow-list of routes (`ConfineExternalUsers`); request pages denied by policy; returned forms stripped of officers' fields; portal data limited to the vendor's own records | `app/Http/Middleware`, `Finance/Http/VendorPortalController` |
| Vendor data | Bank account encrypted at rest (Laravel `encrypted` cast), never sent to the browser except masked, excluded from audit (field names only) | `Finance/Models/Vendor`, `VendorController` |
| Vendor sign-in | Local accounts issued by Purchase; random 16-character temporary password shown once to the officer, stored hashed, never logged | `VendorController::account` |
| Official documents | Released only after final approval, generation and checks; download route checks access per document, answers 404 to every refusal, audits downloads and refusals; generic document route refuses them; reading others' documents needs an explicit permission outside `*` | `Modules/OfficialDocuments` |
| PDF templates | Uploaded by template administrators only, MIME-sniffed, normalised by qpdf before parsing; values are HTML-escaped before mPDF renders them (no markup or remote fetches from form data) | `TemplateService`, `PdfRenderer` |
| Secrets | Never in repo; `.env` git-ignored; seeders use synthetic data only | — |

## Never logged

Passwords, password hashes, LDAP bind credentials, session IDs, tokens,
OTPs, private keys, eSign/Aadhaar identifiers beyond what audit requires.
Redaction keys: `config/audit.php` → `redact`.

## Residual risks

* **R-1** Privileged database operators can disable triggers and rewrite
  audit rows. The hash chain will reveal a rewrite only if the chain head is
  anchored outside the DB. Mitigation (production): periodic export of the
  chain head to external write-once storage, separate DB role for the app
  without `ALTER TABLE` on `audit_events`.
* **R-2** The `NullScanner` does not scan uploads; documents are marked
  `unscanned`. Enable ClamAV before production.
* **R-3** SMS/WhatsApp/eSign/DigiLocker are not connected until provider
  credentials and approvals exist; adapters fail visibly.
* **R-5** PDF parsing (qpdf, FPDI) runs on administrator-uploaded files in
  the PHP container. Keep the image patched; uploads are limited to holders
  of `official_templates.manage` and to the document size limit.
* **R-4** Vendor temporary passwords are handed over by the purchase officer
  out of band, and there is no forced change at first sign-in or self-service
  reset yet. Add both before inviting real vendors.

## Reporting

Security issues: report to the Computer Centre security contact (to be named).
