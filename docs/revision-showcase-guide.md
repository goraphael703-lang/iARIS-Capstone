# Showing the Panel's Revisions: Demo Script, Bug Log, and Objective Map

Three things for the defense: (1) a step-by-step script to show each revision live, (2) how
to pull up the actual bugs found and fixed this session as evidence, and (3) a table mapping
every revision item to its code, its test, and its measurable target.

Demo accounts (seeded by `php artisan db:seed --class=DemoSeeder`, password `password123`
for all): `admin@gmail.com` (Super Admin), `staff@dlsl.edu.ph` (IATO Staff),
`dean.cite@dlsl.edu.ph` (Dean, CITE), `lamp@dlsl.edu.ph` (LAMP Office),
`registrar@dlsl.edu.ph` (Registrar, College).

## Part A — Live demo script, one block per revision item

### #1 — Multi-level user access
1. Sign in as `admin@gmail.com` → **User Accounts**.
2. Open or create an Assistant Principal account. In the sub-level field, select **more than
   one** level (e.g. JHS and SHS) — this is the new multi-select; it used to allow only one.
3. Sign in as that Assistant Principal → **Applicants**. Point out the **level switcher** at
   the top of the page (only appears because they now have more than one assigned level) —
   switching it changes which sub-level's records are shown.

### #2 — Privacy (encryption + masking)
1. As `admin@gmail.com`, open any applicant record in **Applicants** — full name, email,
   contact number, address all show normally (admin is always an editor).
2. Sign in as `dean.cite@dlsl.edu.ph` → **Applicants** (their college-scoped page). Point out
   that email/contact/address are shown **masked** (`j***@g***.com`, `••• ••• 567`) — this is
   `ApplicantRecords::maybeMask()`, applied because a Dean is a viewer, not an editor.
3. To show the data is actually encrypted at rest, not just hidden by the UI:
   ```
   php artisan tinker
   >>> DB::table('applicants')->first()->first_name
   ```
   This prints ciphertext — the database itself never stores a readable name.
4. Open **Audit Logs** as admin and open any "Record Edited" entry — the diff shown resolves
   live from the encrypted column; it isn't stored as readable text in the log either.

### #3 — Encrypted name search
1. As `admin@gmail.com` or `staff@dlsl.edu.ph`, go to **Import Data** and import a file
   containing an applicant with the same name as an existing record.
2. Point out the duplicate is still caught and flagged — this works even though names are
   encrypted, because of the blind HMAC index (`name_search_hash`) added this session; without
   it, a duplicate check would have had to decrypt every single row to compare names.

### #4 — Import automation
1. As `admin@gmail.com`, open **Import Data** → **Import History**.
2. Point out each batch now shows its **source** ("Manual upload" vs. "Folder watch") and a
   **processing-time badge** — both new columns (`import_batches.source`, `processing_ms`).
3. To show the 4,000-record / 30-second target is actually tested, not just claimed, see
   Part B's test-suite step — `ImportAndIpaceTest` asserts `processing_ms < 30000` on a real
   4,000-row import.

### #5 — Approval rules
1. Sign in as `staff@dlsl.edu.ph` → edit an applicant's **contact number only** (a safe,
   auto-apply edit) → submit. Point out it applies immediately, no Review Queue entry.
2. Now edit the same applicant's **full name** (a larger change) → submit. Sign in as admin →
   **Review Queue** → the edit is waiting there, with **"Why this needs review"** showing the
   rule that routed it (`ApprovalPolicy::forApplicantAction()`), separate from any note the
   staff member typed.

### #6 — Archiving settings
1. As `admin@gmail.com`, open **Archiving** (sidebar → System → Data Management).
2. Show the **policy form** (separate retention years for enrolled vs. not-enrolled, plus the
   permanent-deletion window) — change a number, save, point out the Audit Logs entry
   ("Archive Policy Updated") it just created.
3. Show the **"Eligible for archiving" preview** table — explain this is a dry run; nothing
   is archived until "Archive eligible records now" is clicked.
4. Scroll to **Archived records** → click **Restore** on one to show it comes back.
5. Point out a **Delete permanently** button is disabled (with a tooltip date) on a recently
   archived record, and only becomes clickable once that record has passed the retention
   window — click it on one that qualifies, type `DELETE` to confirm, and show the resulting
   "Applicant Permanently Deleted" entry in Audit Logs.

### #7 — Measurable targets and tests
This one is shown through the test suite and the instrument document, not the UI — see Part
B (running the suite) and `docs/usability-and-acceptance-testing.md` for the SUS/UAT
questionnaires you administer yourself. Part C below maps every target to its test by name.

## Part B — Viewing the bugs and errors found this session

Everything here is real, committed evidence — not a claim. Three ways to see it, in order of
how convincing each is to a panel:

**1. Run the test suite and watch it pass (the strongest evidence — it's live, right now):**
```
service mariadb start
php artisan migrate:fresh --seed --force
DB_CONNECTION=mysql DB_DATABASE=iaris php artisan test
```
Expect **148 passed**, with exactly **4 errors**, all in `PasswordResetMailTest` — that one
test is written to run under a different (sqlite) config and isn't a real failure (see its
file's own comment, or run `php artisan test tests/Feature/PasswordResetMailTest.php` alone,
with no `DB_CONNECTION` override, for 4/4 passing proof). Every other test — including the
ones written specifically to catch the bugs below — passes.

**2. Read the actual bug writeups in the git history:**
```
git log --oneline 4d98465^..971beb8
```
lists every commit from this session. The two commit messages worth opening in full, because
they document a real bug, how it was found, and the fix, in detail:
```
git show 45f5dad   # the dedup regression + the ImportBatch timestamp bug
git show c38c0ad   # the two masking gaps CrossLevelExposureTest found
```

**3. See the bugs' own tests, which exist specifically to prove each one is fixed and stays
fixed:**
- `tests/Feature/AdmissionsFolderImportTest.php` — the re-submitted-duplicate-file test.
- `tests/Feature/IncrementFiveQaTest.php` — the weekly-chart / `ImportBatch` timestamp test.
- `tests/Feature/CrossLevelExposureTest.php` — all 7 masking/isolation tests.
- `tests/Feature/ArchiveSettingsTest.php` — the payments foreign-key case
  (`test_permanent_deletion_requires_typing_delete_and_removes_the_record_and_its_payments`).

### Bugs found and fixed this session

| # | Bug | Found via | Fix |
|---|-----|-----------|-----|
| 1 | Encrypting `review_queue`/`audit_logs`/`generated_reports` broke on MariaDB: those columns were declared `json()`, which MariaDB enforces as `LONGTEXT` + `CHECK(json_valid(...))` — ciphertext isn't valid JSON, so saving failed outright. | Caught while building the encryption migrations, before anything shipped. | Converted each column to plain `LONGTEXT` in the same migration. |
| 2 | `GeneratedReport`'s `created_at` was silently dropped when a test set it explicitly — Eloquent's mass assignment only sets fillable fields, and it wasn't one. | Caught while writing `GeneratedReport`'s own tests. | Added `created_at` to `$fillable` alongside `public const UPDATED_AT = null;`. |
| 3 | **Self-introduced regression**: encrypting `review_queue.pending_action` broke `ImportAdmissionsFolder`'s duplicate-file check, which used a raw SQL JSON-path query against that now-encrypted column — the query could never match, so a re-submitted duplicate file got staged twice. | Full-suite regression run after the encryption work. | `ReviewQueueItem::hasPendingImportFor()` — decrypts candidates in PHP instead. Commit `45f5dad`. |
| 4 | **Pre-existing bug**: `ImportBatch` used `$timestamps = false` and the database's own clock for `created_at`, which doesn't respect `Carbon::setTestNow()` — broke the dashboard's "This Week" widget under time-travel tests. | Same full-suite regression run. | Switched to Eloquent-managed timestamps. Commit `45f5dad`. |
| 5 | **Pre-existing privacy gap**: `CollegeController::realApplicantsIn()` (Program Chair's applicants page) and `ScholarController`'s row builder (shared LAMP/IATO Staff scholars page) read contact fields straight off the model, bypassing masking entirely for viewer roles. | Writing `CrossLevelExposureTest` for item #7. | Applied `ApplicantRecords::shouldMask()`/`maskEmail()`/`maskContact()`/`maskAddress()` to both. Commit `c38c0ad`. |
| 6 | `payments.applicant_id` has no `nullOnDelete()` — permanently deleting an archived applicant with a payment record would have thrown a foreign-key violation. | Caught while building Archiving (item #6), before shipping. | Delete the applicant's payment rows first, in the same DB transaction. Commit `971beb8`. |
| 7 | **Unresolved, user-reported**: "imports as IATO staff don't work, says something about columns" — reproduced the same flow in this session's own environment with no error. Most likely cause: the reporting environment's database hasn't run this session's migrations yet. | User report during this session. | Gave remediation (`git pull`, `php artisan migrate`, `php artisan optimize:clear`) — **not confirmed fixed**; if it recurs, get the exact error text first. |

## Part C — Revision items mapped to code, tests, and targets

| # | Revision | Target / requirement | Key files | Proven by | Commit |
|---|----------|----------------------|-----------|-----------|--------|
| 1 | User access & level assignments | An Assistant Principal can hold more than one level | `User::assistantLevel()`, `LevelAssignments::rowsFor()`, `CollegeController::levelSwitchOptions()` | `ApSwitcherTest`, `AccountAssignmentsTest::test_assistant_principal_can_be_assigned_more_than_one_level` | `1b29662` |
| 2 | Privacy | Applicant PII encrypted at rest; masked for non-editors | `Applicant`, `ReviewQueueItem`, `AuditLog`, `GeneratedReport` casts; `ApplicantRecords::maybeMask()` | `CrossLevelExposureTest` (masking), existing encryption-at-rest via Eloquent casts | `4d98465`…`6be4cde`, `c38c0ad` |
| 3 | Encrypted name search | Duplicate/name search works without decrypting every row | `NameSearchIndex`, `Applicant::booted()`, `ApplicantEditFlow::ensureNotDuplicate()` | `LevelAssignmentsTest`/`DivisionApplicantEditTest` duplicate-check assertions | `95c96d8` |
| 4 | Import automation | Source and processing time tracked per batch | `ImportBatch` (`source`, `processing_ms`), `AdmissionsImportService::import()` | `ImportAndIpaceTest::test_four_thousand_xlsx_records_import_and_reimport_without_duplicates` (processing_ms assertion) | `71fb75f` |
| 5 | Approval rules | Safe staff edits auto-apply; larger ones go to Review Queue, with a visible rule | `ApprovalPolicy`, `ApplicantController::approvalDecision()` | `ApprovalPolicyTest` (4 cases) | `ba996bd` |
| 6 | Archiving settings | Configurable eligibility + retention, preview, restore, permanent deletion as its own gated action, full logging | `ArchiveSetting`, `ArchiveEligibility`, `ArchiveSettingsController` | `ArchiveSettingsTest` (6 cases) | `971beb8` |
| 7a | Import performance | 4,000 records in < 30s | `AdmissionsImportService` elapsed-time tracking | `ImportAndIpaceTest` (`assertLessThan(30000, ...)`) | `71fb75f` |
| 7b | Zero cross-level exposure | No unauthorized record or contact detail shown, on screens or exports | `ApplicantRecords`, `CollegeController`, `ScholarController` masking | `CrossLevelExposureTest` (7 cases) | `c38c0ad` |
| 7c | Validation coverage | All predefined import validation errors detected | `ApplicantsImport::model()` | `ImportAndIpaceTest::test_all_three_predefined_validation_errors_are_detected_and_reported` | `2efc9bc` |
| 7d | Usability / acceptance | SUS ≥ 85, UAT ≥ 4.21 | — (needs real respondents) | `docs/usability-and-acceptance-testing.md` (instrument, not yet administered) | `60596b7` |
| 7e | AI chart explanation accuracy | Figures/trends explained correctly | — | **Blocked**: the AI widget is out of scope this round | — |
