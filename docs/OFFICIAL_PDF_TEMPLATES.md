# Official PDF forms

The institute's prescribed PDF forms are filled automatically from approved
requests and offered for download to the people entitled to them. The
uploaded file is the template: it is kept unchanged and every page of it is
the background of the generated document.

## 1. Template inventory

The institute's forms are kept in `resources/official-forms/` exactly as
published on iiitm.ac.in (byte-identical; tests check the hashes) and
registered by `InstituteFormsSeeder`. None of them has interactive fields;
all are mapped by position. Box positions were measured from each file
(text positions of the dotted / underlined blanks, ruled table lines, tick
boxes) and checked on rendered previews; the office should still confirm a
sample of each before relying on it (A-25).

### Mapped, published and issued by a workflow

| Form (file) | Issued by | When | Filled from the request | Left blank on purpose |
|---|---|---|---|---|
| Application for Casual Leave / RH / Station Leave / Special Casual Leave | `leave`, kinds CL, SL, DL | Sanction (step `sanction`) | Name, file no., designation, department, type, period, days, station leave (if leaving HQ), purpose, leave address, responsibilities, date, application no.; record of the sanction beside "Head of the Department / Reporting Officer", of the Registrar beside "Registrar/Director" | "Any other information"; signatures |
| Leave application for EL / HPL / EOL / Commuted / Vacation Leave (bilingual) | `leave`, kinds EL, HPL | Sanction | English lines: file no., name, designation, department, type, purpose, from, to, days, arrangement of classes, address, date, application no.; approval records | Hindi lines, "Leave applied for", person looking after responsibilities, mobile, "Sundays and holidays", Registrar-office leave balance |
| Request for Approval of Journey and T. A. Advance (Within India) | `tour` | HoD and Registrar sanction | Name, PF no., status tick (faculty/staff), designation, department, purpose, period, place, commencement, mode tick, advance requested and total, date, forwarded by HoD, Registrar's date, sanctioned amount in figures and words; approval records | Pay, joining/retirement dates, debit head, class, fare, DA, entitlement questions, recommendation ticks (not recorded by the workflow), Director block |
| Traveling Allowance Bill (TA Claim / Settlement) | `tada-claim` | Verification and sanction | Name, PF no., designation, department, purpose (the journey), total claim, advance drawn, net claim, date, "Pay Rupees" in words; sanction record | Journey legs and halts (the claim workflow records bills, not legs), items (1)–(4), page 2 family / other expenses |
| Medical Reimbursement Application Form (**confidential**) | `medical-claim` | Verification and sanction | P.F. no., name/designation/department, patient and relationship, total claimed, date; page 2 amount passed, net payable, "Please pay" in figures and words; sanction record | Pay scale, address, consultations, tests, enclosures, declaration choices, Certificate-A (medical adviser) |
| Statement of Accounts of Consumable / Contingency Advance (F-2) | `advance-settlement` | Verification and sanction | Department, name, employee no., designation, advance purpose / date / reference / amount (copied from the advance at submission), excess claimed, balance and refund reference, expense rows (no., date, particulars, amount; 11 rows) | Cash memo, supplier, budget head and stock register columns (not captured); signatures |
| Technical Verification / Installation Report | `goods-receipt` | Inspection | Inspecting officer, purchase order, received items with accepted quantities (9 lines); inspection record | Signatures |

### Registered, not yet mapped (no workflow for the process yet)

| Form | What is missing |
|---|---|
| Application for Long Leave (Annexure-I, 4 pages) | HoD recommendation sheet, thesis / project arrangements, clearances (DORC, F&A, House Allotment); a long-leave workflow |
| CPDA (Annexure-I conference, Annexure-II contingency purchase) | CPDA workflow and CPDA account |
| Application for conducting Workshop / Conference / FDP / Seminar / Event | Event approval workflow (HoD, DoRC, Director) |
| Undertaking for Abroad Visit (F-4) | Foreign-visit workflow. Two copies were supplied: the scan with the "Submitted for approval / Director" block is registered; the typed copy without it is kept in the folder |
| Requisition for Reimbursement (F-2) with GFR certificates | General reimbursement workflow |
| Local Conveyance Form | Local conveyance claim workflow |
| Claims Form for Remuneration / Honorarium / Sitting Charges | Honorarium workflow (often external persons) |
| Claim for Reimbursement of AMC of RO; of Newspaper Expenses | Declaration-based claim workflow. Page 1 of each file is the notification; the whole file is the template, so both pages are issued unless the office supplies the claim page alone |
| Indent (issue from stores) | Stores-issue workflow (this is not the purchase indent) |
| MDP Centre booking form; Requisition for Board Room | Estate bookings (Phase 7). The board-room page holds two copies of the form |

Forms in the institute's download folder that were not supplied with this
request (e.g. gate pass, no-dues, swimming pool, visitor hostel, academic
forms) are not registered.

## 2. How a form is filled

1. **Upload.** The file is stored as supplied (SHA-256 recorded) and never
   modified. `qpdf` writes a *working copy* (classic cross-reference table,
   no object streams, owner restrictions removed) because the free FPDI
   parser cannot read compressed cross-reference streams. Password-protected
   files are refused with a message.
2. **Inspect.** Page sizes are read, and interactive (AcroForm) fields are
   detected with their rectangles. In the mapper they appear as dashed amber
   boxes; one click maps a field to that rectangle.
3. **Map.** Each field is a box on a page (points, top-left origin) with a
   data source, type (one line, several lines, tick mark, table rows), font,
   size and smallest size, alignment, format and optional condition.
4. **Preview** with synthetic data (never a real person's request), then
   **publish**. Published versions, their files and their mappings are
   immutable; a revised form is a new version.
5. **Attach** to one or more workflows: required approvals, an optional
   condition, who may download, confidentiality, signature requirement,
   file name pattern, reissue policy. A workflow may issue several forms.

### Data sources

| Path | Meaning |
|---|---|
| `applicant.name/username/email/employee_id/category/department/department_code/designation` | The person who submitted |
| `form.<field>` | The value as stored (codes, ISO dates) |
| `form_label.<field>` | What the applicant saw (option labels, names) |
| `workflow.reference/title/name/submitted_at/completed_at/status` | The request |
| `approval.<step>.name/designation/department/date/remarks/decision/on_behalf_of/statement` | The latest approval at a step |
| `approvals` | All approvals, for a table: step, name, designation, department, date, remarks, statement |
| `institute.name/short_name/address/city` | `config/official_documents.php` (A-22) |
| `document.generated_at/version/template_version` | The document itself |
| `literal` | Fixed text; may contain `{{ path }}` placeholders for blanks that combine values ("Name, Designation, Department"). Empty values and the separators around them are dropped |

Formats: as stored, date, date and time (PHP pattern, default `d/m/Y`),
amount (Indian grouping, optional prefix such as `₹ `), amount in words
("One Lakh Twenty-Five Thousand … Rupees and Fifty Paise Only"), number, upper /
lower / title case. Empty values print nothing unless an "empty" text is
set; a **required** field that is empty stops generation. Table columns may
use `#` for the row number.

Values a form prints about *another* record (the advance being settled, the
journey being claimed) are copied onto the request when it is submitted
(`AdvanceSettlementValidator`, `ClaimValidator`), so the document shows them
even after that record has changed.

### Fitting text

A one-line value shrinks from its size to its smallest size; several-line
values wrap at word boundaries. If a value still does not fit, generation
**fails with the field named** — nothing is silently cut. Tables fail when
there are more rows than the form has room for.

### Scripts

Latin text uses DejaVu (₹ and ✓ included). Any value containing Devanagari
uses GNU FreeSerif, drawn through mPDF's HTML path, which applies OpenType
shaping (matras and conjuncts correct). The font is embedded as a subset.
Width checks measure unshaped text, which for Devanagari is slightly wider
than the result: fitting errs on the safe side.

## 3. When a document exists

| Event | Document |
|---|---|
| Request submitted | Row created per attached form, **template version fixed** (`waiting_approval`) |
| Returned, on hold, resubmitted | Still waiting; nothing downloadable |
| Final approval (`completed` / `approved`) | Condition checked, then queued |
| Generation | Required approvals re-checked on the instance itself → render → check (qpdf `--check`, page count and sizes equal the template) → stored privately with SHA-256 → `generated` (or `awaiting_signature`) |
| Rejected / cancelled | `not_generated` |
| Failure | `failed` with a code for the applicant ("Delayed: the office has been informed") and the technical detail for administrators, who are notified; the approval is untouched |

Generation runs on the `integrations` queue, three attempts with back-off.
Duplicate events find the existing row (a partial unique index allows one
current document per request and form); a job finding the document already
generating or generated does nothing.

## 4. Who may download

Default: only when the request is completed and approved, the document is
generated, and the person is in a group the attachment allows:

* applicant;
* officers who decided on the request;
* department staff holding `official_documents.department` (same department);
* record-keeping officers holding `official_documents.records`;
* administrators holding `official_documents.all`.

Confidential attachments narrow this to the applicant, the deciding officers
and holders of `official_documents.confidential`. None of these permissions
is part of the system administrator's `*`: reading other people's documents
is always an explicit grant. Superseded documents go to record keepers only.

Downloads go through `/official-documents/{uuid}/download` only; the generic
`/documents/{id}` route refuses official documents. Every download and every
refused attempt is audited. A refused request gets 404, whether the document
does not exist, is not ready, or belongs to someone else.

## 5. Signatures

A recorded approval is not a signature. The generator never draws signature
images and never makes approval look like a digital signature:

* forms may print the approving officer's name, designation and date, and
  `approval.<step>.statement`, which says in words that it is a record of
  approval, not a signature;
* signature boxes are left empty for a hand signature unless mapped;
* an attachment marked "release only after an authorised signature" holds
  the generated document as `awaiting_signature` (not downloadable) until
  the electronic-signature module (Phase 8, SIGN-001) signs it. Until a
  provider is configured, such documents stay held and the monitor says why.

## 6. Reissue and corrections

Administrators with `official_documents.manage` may **retry** a failed
generation and **reissue** an issued document with a recorded reason. The
earlier document is kept, marked superseded and linked from the new one; the
new one is generated from the version the request was submitted with unless
the officer explicitly chooses the current published version. File names of
reissues end in `_v2`, `_v3` …. A template change never alters an issued
document.

## 7. Technology and limits

* mPDF 8.3 with FPDI 2.6 (both MIT): imports existing pages as templates and
  writes Unicode text with OpenType shaping.
* qpdf (normalisation, `--check`, JSON inspection of fields) and poppler
  (`pdftoppm` page images for the mapper; `pdftotext`/`pdffonts` in tests),
  installed in the PHP image.
* Interactive fields are **flattened**: their rectangles are used as boxes
  and the output has no editable fields. Filling them in place would depend
  on the form's own fonts, which rarely include Devanagari, and could not
  check overflow. Choice and radio fields are mapped as text or tick marks.
* Rotated pages: field rectangles from interactive forms are not rotated;
  place boxes by hand on such pages.
* XFA-only forms (dynamic Adobe forms) have no static pages to import;
  supply a flattened PDF of the form instead.
