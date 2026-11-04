# Maintenance (MNT-15)

## Release cadence

Public since 1.0.0 (2026-10-20); nine releases through 2.7.0 as of this
writing. Cadence in practice: a release per feature/fix round, not a fixed
calendar schedule — each one gated on its own independent security audit
(Vex) clearing, not on elapsed time. Review the version support matrix
(`docs/adr/0008-version-support-matrix.md`) periodically, and whenever a
WordPress/PHP/Nextcloud compatibility break surfaces.

## Upstream compatibility monitoring

`.github/workflows/ci.yml` runs a weekly scheduled job (`cron: '0 6 * * 1'`,
MNT-14) to catch upstream WordPress/PHP/Nextcloud breakage even when plugin
code hasn't changed. Connected and executing since 1.3.0 (confirmed via
`gh run list`) — no longer manual. The `multi-backend` profile (ownCloud,
OpenCloud) is not part of this automated matrix and still needs a manual
`run-e2e.sh`/`run-e2e-multi-backend.sh` pass when those backends' own
compatibility is in question.

## Dependency updates

Still none to monitor — the plugin ships zero Composer dependencies by
design (`docs/architecture.md` explains why). Revisit Dependabot/Renovate
only if that changes (MNT-13).

## Backup-format compatibility

`format_version` has bumped twice: 1 -> 2 (1.1.0, incremental file backups)
and is still 2 as of 2.7.0 — the 2.7.0 Migrate tab is a restore-time UI
addition over the existing migration mechanism, not a manifest schema
change. Any future schema change requires a `format_version` bump and an
entry here describing what changed and how older archives remain
readable, per MNT-04.

## Manifest migrations

None needed yet beyond the 1 -> 2 bump above — both versions are still
read by current restore code (`docs/backup-format.md`'s compatibility
policy).

## Restore regression gates

`tests/e2e/run-e2e.sh` (ordinary restore), `run-e2e-restore-migration.sh`
(cross-domain URL migration, including the symmetric idempotency-guard
coverage added in 2.7.0), and `tests/unit/run-all.php` (262 tests as of
2.7.0) are the regression gates: any change must pass a full backup ->
download -> restore -> content-assertion cycle, plus the full unit suite,
before merge, per MNT-16/MNT-19.

## Deprecation policy

Not written yet — nothing shipped under this plugin's current
(`wpthistle-backup-restore`) identity has been deprecated. The 2.0.0
rename itself is the one precedent: the old `WPGuard Nextcloud Backup`
plugin/slug/constants are kept working as a documented fallback rather
than deprecated outright — see `docs/releasing.md`'s cutover section.

## Credential rotation

Admin replaces the app password field (blank = unchanged, non-blank =
replace) without resetting destination, job history, or existing backups
— implemented in `class-settings.php`'s save validation. Formal rotation
test (save new password mid-job-history, confirm old backups still
listed/accessible) remains a backlog item (MNT-18), not yet automated.

## Emergency fixes

None issued out-of-band yet — every fix to date has gone through the
normal audit-and-release cycle documented in `STATUS.md`/`CHANGELOG.md`.
