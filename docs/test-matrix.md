# Test matrix

See `docs/adr/0008-version-support-matrix.md` for the version table itself
(tested vs expected). This file tracks *what kind* of test covers what.

| Area | Test | Location | Executed this session? |
|---|---|---|---|
| TLS verification never disabled | grep-based static check | `tests/unit/NoInsecureTlsTest.php` | yes |
| Path-safety (zip-slip) | unit | `tests/unit/ArchivePathSafetyTest.php` | yes |
| Secret redaction in logs | unit | `tests/unit/LoggerRedactionTest.php` | yes |
| Manifest format-version rejection | unit | `tests/unit/ManifestFormatVersionTest.php` | yes |
| Settings validation (URL scheme, folder traversal) | unit | `tests/unit/SettingsValidationTest.php` | yes |
| Nextcloud auth/TLS/WebDAV round trip | integration | `tests/e2e/run-e2e.sh` (backup run itself exercises PUT/GET/PROPFIND) | yes, against local disposable Nextcloud 34 — exit 0 |
| Three successive backups | integration | `tests/e2e/run-e2e.sh` step 1 | yes — exit 0, three `verified` backups confirmed present on Nextcloud |
| Full backup -> download -> restore -> content assertions | E2E | `tests/e2e/run-e2e.sh` steps 2-4 | yes — exit 0, "E2E DRILL PASSED", run twice (once via bash directly, once via `docker/scripts/run-e2e.ps1`) |
| Credential not present in SQL/file export | integration | `tests/e2e/run-e2e.sh` step 5 + manual archive/wp-config.php grep | yes — confirmed absent from dump, archive, manifest, and archived wp-config.php |
| Non-admin cannot trigger backup / read settings | code review + nonce/capability check in `class-admin.php` | not independently driven by a second WP user account in this session | **not executed as a live test** — enforced by `current_user_can('manage_options')` + `wp_verify_nonce()` on every AJAX handler, same gate WordPress itself relies on; flagged for independent verification |
| Invalid app password / unreachable host / TLS error | failure injection | manual `Nextcloud_Client::test_connection()` calls with bad credential / bad host / no CA trust | yes — correctly classified `auth` (401) / `network` (DNS failure) / `network` (TLS verify failure), all fail closed |
| Interrupted run never reports success; previous good backups untouched | failure injection | manual: advanced a job 3 steps then stopped, confirmed status stayed `running` indefinitely and all 3 prior `verified` backups remained on Nextcloud | yes |
| Corrupted/missing remote archive part | failure injection | **not executed** — would require instrumenting a mid-upload byte flip; the read-back hash-verify code path that would catch it (`step_upload_parts`) is exercised and passing on the happy path, but a deliberately corrupted object was not tested this session | **not executed**, disclosed honestly |
| Packaged ZIP installs and activates independently of the dev bind-mount | packaging smoke test | `docker/scripts/package.ps1` then `wp plugin install <zip> --activate` on a separate instance | yes — `is_plugin_active()` confirmed true |
| Packaged ZIP extracts correctly via raw PHP `ZipArchive` (not just WP-CLI's install path) | packaging smoke test | `php -r '(new ZipArchive())->extractTo(...)'` against `dist/wpthistle-backup-restore-0.1.0.zip` inside a `wordpress:cli` container | yes — added 2026-10-19 after a real-host activation failure (see below); main plugin file confirmed present at the expected nested path post-fix |
| JOB-05 manual stuck-job cancel | E2E | `tests/e2e/run-e2e-cancel-job.sh` | yes — added 2026-10-19 after a real stuck job on a live site; create-but-never-advance a job, cancel it, confirm `failed` + `has_running_job()` false + idempotent re-cancel |
| Cancel respects the H-01 lock (doesn't race a live `advance()`) | E2E | `tests/e2e/run-e2e-cancel-lock-contention.sh` | yes — added 2026-10-19 (security-audit LOW-C1); genuine concurrent holder + canceller processes |
| Incompressible extensions (jpg/png/zip/pdf/etc.) stored not DEFLATEd, both stored and deflated entries extract byte-identical | unit | `tests/unit/ArchiveWriterCompressionTest.php` | yes — added 2026-10-20 with the second perf round |
| Multi-version WP/PHP/Nextcloud/MariaDB matrix | CI matrix | `.github/workflows/ci.yml` | yes — connected and executing on every push (confirmed via `gh run list` 2026-10-21); does not cover the `multi-backend` profile (ownCloud/OpenCloud), which stays manual |
| Upgrade-from-previous-version / schema migration | — | n/a yet | **not applicable** — 0.1.0 is the first version |

## E2E CI coverage, 2026-11-06

Audit requested after an external review (Nova, Meta Muse) flagged that STATUS.md's
2.7.1 entry noted `run-e2e-restore-migration-subdir.sh` as "not wired into
ci.yml" with no recorded reason — the table above predates most of these
scripts (still says "0.1.0 is the first version") and was never the live
record of what CI actually runs. This section is that record, as of
2.9.0/HEAD (`8788632`). "Last STATUS.md mention" is the nearest preceding
dated entry that script's name appears next to in `STATUS.md` — a proxy
for when it was last known to run, not a verified execution log.

| Script | In CI? | Why | Last STATUS.md mention |
|---|---|---|---|
| `run-e2e.sh` | **yes** | Main backup→restore drill; wired since 2026-10-19. | ongoing |
| `run-e2e-restore-migration-subdir.sh` | **yes, as of this audit** | Uses only the stack CI already provisions, no extra profile, no external creds. Wired this round; watch its first few real CI runs for the table-prefix-leak class of flake this suite has hit before (2026-11-02/03) — revert to manual with a dated reason here if it proves unstable. | 2026-11-04 |
| `run-e2e-restore.sh` | no — deliberately manual | Depends on the real-production-backup restore drill against `NC_TEST_*` credentials; that dedicated Nextcloud test account was revoked 2026-11-04 (`tsk-2026-10-20-001`). Fails loud by design when the creds are absent, rather than silently skipping. Needs a fresh test account from the owner before it can run anywhere, CI included. | 2026-10-20 (predates the 2026-11-04 revocation) |
| `run-e2e-update-checker.sh` | no — deliberately manual | Calls the real public GitHub API (`api.github.com`) to verify `Update_Checker` against live release data. Running this on every push risks GitHub API rate-limiting unrelated to any code change, and exercises an external service's availability, not just this plugin's logic. Stays a manual pre-release check. | 2026-10-20 |
| `run-e2e-multi-backend.sh` | no — deliberately manual | Needs the `multi-backend` Docker Compose profile (ownCloud/OpenCloud containers) the main CI job never brings up, and mutates `.env`'s table prefix (the exact mechanism behind the 2026-11-02/03 false-failure precedent). Wiring this needs its own CI job with the extra profile, not a line added to the existing one — scoped out of this pass. | 2026-11-03 |
| `run-e2e-notifications.sh` | no — deliberately manual | Delivers to real external channels (a real captured email, a real Discord HTTP POST per the 2.5.0 session) — CI's `.env` carries only throwaway generated secrets, never real webhook/API credentials, by design (see `docker/.env.example` and the CI job's own comment). Needs CI secrets provisioning to wire in, not attempted this round. | 2026-11-02 |
| `run-e2e-lock-race.sh` | no — deliberately manual | Drives genuine concurrent processes against a shared timing window; a loaded/variable-speed shared CI runner is a plausible source of flaky timing failures unrelated to any real regression. | 2026-10-19 |
| `run-e2e-cancel-lock-contention.sh` | no — deliberately manual | Same timing-sensitive-concurrency reasoning as `run-e2e-lock-race.sh`. | 2026-10-19 |
| `run-e2e-stale-lock-recovery.sh` | no — deliberately manual | Same timing-sensitive-concurrency reasoning as `run-e2e-lock-race.sh`. | 2026-10-20 |
| `run-e2e-cancel-job.sh` | no — not yet wired, candidate for a future pass | Uses only the already-provisioned default stack, no external creds, no concurrency. Not wired this round to keep this release scoped to docs/process fixes rather than a test-infra expansion; flagged for the owner as a reasonable next candidate. | 2026-10-22 |
| `run-e2e-chunked-upload.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-10-23 |
| `run-e2e-const-override.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-10-19 |
| `run-e2e-download-backup.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-10-21 |
| `run-e2e-forget-connection.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-11-01 |
| `run-e2e-incremental.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-10-20 |
| `run-e2e-restore-ajax-safety.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-10-20 |
| `run-e2e-restore-migration.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh` (the non-subdirectory migration drill). | 2026-10-21 |
| `run-e2e-retention.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-10-20 |
| `run-e2e-schedule-mode.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-11-01 |
| `run-e2e-scheduling.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-10-20 |
| `run-e2e-self-advance.sh` | no — not yet wired, candidate for a future pass | Same as `run-e2e-cancel-job.sh`. | 2026-11-01 |

**No silent gaps as of this audit**: every script above has either a CI step or a recorded, dated reason it stays manual. The nine "candidate for a future pass" scripts are genuinely wireable with no new CI infrastructure — deferred here only to keep this release scoped to docs/process fixes; worth a dedicated follow-up pass if the owner wants broader CI coverage.
