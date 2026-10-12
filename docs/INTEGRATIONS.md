# Integrations

Every external system sits behind an interface. When an adapter is missing
or not configured, the workflow step becomes a **manual task** for a named
person ("blocked: not configured"); nothing is ever reported as done that
was not done.

Status legend: ✅ working and verified here · 🟡 implemented, not verified
against the real system · ⛔ no adapter yet (manual task / log only).

| Integration | Interface | Adapter(s) | Status | Verified how |
|---|---|---|---|---|
| OpenLDAP sign-in | `Identity\Directory\DirectoryService` | `LdapDirectoryService` | ✅ | `tests/Integration/LdapDirectoryTest` against the dev OpenLDAP container over StartTLS with CA validation; wrong CA refused; injection and empty-password cases refused |
| Directory sync | same | same | ✅ (logic) | `DirectorySyncTest` with an in-memory directory; `directory:sync` run against the dev container |
| Wi-Fi / VPN entitlement | `ComputerCentre\Integrations\NetworkAccessProvisioner` | `LdapGroupProvisioner` (group membership read by FreeRADIUS / VPN) | ✅ dev directory · 🟡 FreeRADIUS side | Grant/revoke idempotency tested against the dev OpenLDAP. FreeRADIUS must be configured by the Computer Centre to authorise on `wifi-users` / `vpn-users` membership. |
| Mailbox create / suspend | `MailboxProvisioner` | `NotConfiguredMailbox` | ⛔ | Needs the institute's mail platform and its API (Google Workspace, Microsoft 365, Zimbra …). Until then the step is a manual task. |
| Virtual machines | `VirtualMachineProvisioner` | `ProxmoxProvisioner`, `NotConfiguredVm` | 🟡 | `ProxmoxProvisionerTest` with faked api2/json responses (clone, config, resize, idempotent reuse, refusals). **Not run against a real cluster.** Enable in a test pool first. Decommissioning stops the VM; deletion stays manual. |
| E-mail notifications | `Notifications\Channels\MailChannel` | Laravel mail (SMTP) | ✅ dev | Mailpit in development; production needs the institutional SMTP relay. |
| SMS | `PhoneChannel('sms')` | `log` driver only | ⛔ | Provider + DLT-registered templates required. Messages with another driver fail visibly in Admin → Notifications. |
| WhatsApp Business | `PhoneChannel('whatsapp')` | `log` driver only | ⛔ | Approved BSP and registered templates required. |
| Malware scanning | `Documents\Scanning\MalwareScanner` | `ClamAvScanner`, `NullScanner` | 🟡 | ClamAV INSTREAM adapter written; default is `NullScanner`, which marks files **unscanned** (shown in the UI). Run clamd and set `DOCUMENTS_SCANNER=clamav`. |
| Aadhaar eSign | (Phase 8) | — | ⛔ | Requires an empanelled ESP/ASP agreement. Signature steps cannot be published while no provider is configured (graph validator). |
| DSC signing | (Phase 8) | — | ⛔ | Requires choice of signing middleware for users' tokens. |
| DigiLocker | (Phase 8) | — | ⛔ | Requires partner/requester access. |
| GeM | — | — | ⛔ | Availability and GeM reference are recorded by the purchase officer (A-20). An integration needs authorised API access. |
| Bank / PFMS payment | — | — | ⛔ | Payments are made outside the system; the reference and date are recorded and a payment advice is generated (A-21). |

## Adding an adapter

1. Implement the interface (e.g. `MailboxProvisioner`) in the owning module's
   `Integrations/` folder. Return `ProvisionResult::ok/failed/notConfigured`;
   throw only for transient errors (the queue retries).
2. Make it idempotent: a retried job carries the same `IntegrationJob`
   (`idempotency_key`) and must not repeat an irreversible effect.
3. Bind it in `AppServiceProvider` behind a config driver; credentials only in
   `.env` / the secret store, never in the repository.
4. Test it with `Http::fake()` or a fake directory, and record here how it was
   verified against the real system.

## Automation actions available to workflow designers

`config/workflow.php` → `actions`:

| Key | What it does |
|---|---|
| `core.set_variables` | Writes computed values into the request's variables |
| `core.start_workflow` | Opens a request on another workflow (e.g. no-dues → deprovisioning review); decides nothing itself |
| `cc.provision_email` | Mailbox creation via `MailboxProvisioner` |
| `cc.grant_network_access` | Wi-Fi/VPN via `NetworkAccessProvisioner` |
| `cc.check_vm_quota` | Fails when a VM request exceeds the category quota (`config/computer_centre.php`) |
| `cc.provision_vm` | VM creation via `VirtualMachineProvisioner` |
| `cc.record_account` | Records access granted by hand in the ledger |
| `cc.issue_asset` | Issues the asset chosen at the store step |
| `cc.allocate_licence` | Allocates a software seat; fails when none are free |
| `cc.check_clearance` | No-dues: fails while IT assets are still issued |
| `cc.deprovision` | Applies an approved deprovisioning to the account ledger; stops on a legal hold |
| `leave.reserve` / `leave.approve` / `leave.cancel` | Hold, debit and credit back leave in the ledger |
| `registrar.travel` | Journeys and claims register (record, approve, record claim, sanction, mark paid) |
| `registrar.issue_certificate` | Numbered PDF certificate with verification code |
| `registrar.publish_circular` | Number, PDF, receipts, notifications |
| `registrar.rti` | RTI register (register, reply, appeal filed, appeal decided) |
| `finance.register` | Purchase and advance register (`step`: record_indent, commit_budget, check_evaluation, issue_order, record_receipt, accept_receipt, record_invoice, verify_invoice, sanction_invoice, pay_invoice, record_advance, sanction_advance, disburse_advance, settle_advance). `check_evaluation` fails with the gaps against the rules; `commit_budget` fails when funds are short. |
