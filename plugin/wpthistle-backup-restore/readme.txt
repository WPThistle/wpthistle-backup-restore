=== WPThistle Backup and Restore ===
Contributors: itsmnlop
Donate link: https://buymeacoffee.com/itsmnlop
Tags: backup, restore, nextcloud, webdav, disaster-recovery
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backs up your WordPress files and database to a Nextcloud, ownCloud, or OpenCloud server you control over authenticated HTTPS WebDAV -- or straight to your browser, zero cloud dependency -- with read-back checksum verification before anything is called a success, and one-click restore.

== Description ==

Phase 1 MVP, security-audited and verified against real production data: manual, on-demand backups only. No scheduling, no retention/pruning, no one-click restore yet — see docs/restore-manual.md in the source repository for the manual disaster-recovery procedure, which is the only restore path in this release.

A backup is never reported successful until every uploaded component has been read back from Nextcloud and its SHA-256 checksum verified, and a final completion marker has itself been uploaded and verified.

== Requirements ==

* A Nextcloud server reachable over HTTPS with a valid certificate (or a private CA you supply the path to).
* A dedicated, non-admin Nextcloud account and an app password generated under Personal Settings > Security. Do not use your main Nextcloud account password.

== Installation ==

1. Upload and activate the plugin.
2. Go to WPThistle > Backup and Restore.
3. Enter your Nextcloud URL, the dedicated account's username, its app password, and a destination folder.
4. Click Test Connection.
5. Click Back Up Now.

**Reinstalling or manually updating via Upload Plugin:** if the plugin is already installed and you're uploading a new copy by hand (rather than via Plugins > Updates), WordPress will offer a "Replace current with uploaded" option — use it. If you instead delete and re-upload without that option, or upload more than once without removing the previous attempt first, WordPress creates a second folder (e.g. `wpthistle-backup-restore-1`) instead of overwriting, which can leave a broken or duplicate install. If you ever see two WPThistle Backup and Restore entries in your Plugins list, delete the extra folder via FTP or your host's file manager before activating either one.

== Frequently Asked Questions ==

= What's the actual difference between "Scheduled" and "Tiered"? =

Scheduled only ever attempts a backup on the day(s) you pick -- on every
other day, nothing runs at all. Tiered has no day picker because it
attempts a backup every single day, with no exceptions; the daily/
weekly/monthly keep-counts only control how many of those get kept
afterward, not whether that day's backup happens in the first place.

This matters most if you set Tiered's "keep daily" count to 0: backups
still run every day (full bandwidth/server load/Nextcloud API cost each
time), and pruning simply deletes most of them again right away, keeping
only whichever one represents the current week/month. If you only want
a backup on specific day(s), and never want the server to do backup work
on the others, use Scheduled instead -- Tiered will always cost more
than that, even configured down to the same number of backups kept.

= I set all three Tiered counts (daily/weekly/monthly) to 0 -- does that delete everything? =

No. Same "0 = keep every backup forever" rule the simple Scheduled
keep-last-N count has always had -- with every Tiered count at 0,
pruning does nothing at all rather than being read as "keep none."

== Changelog ==

= 2.2.2 =
Docs only: added an FAQ section explaining the real difference between Scheduled and Tiered (Tiered backs up every day regardless of its keep-counts, including a "keep daily" of 0 -- Scheduled only ever runs on the day(s) picked). Lives on the plugin's "View details" page, not the Settings screen, so it's there for whoever wants it without adding more text to the settings UI itself.

= 2.2.1 =
UI fix: the Backup Plan and Cadence dropdowns' option text was long enough to get clipped in the dropdown box itself. Options are now short ("Scheduled"/"Tiered", "Daily"/"Monthly"); the fuller explanation moved to description text below the dropdown that switches to match whichever option is currently selected.

= 2.2.0 =
Backup plan is now one dropdown instead of two settings that could silently contradict each other (picking specific days of the week while tiered retention was also on, which made the "daily" tier not actually daily). Choose **Scheduled** -- pick a cadence (daily day-of-week, or now also monthly day-of-month, including a "Last Day of the Month" option for shorter months) plus a simple keep-last-N count -- or **Tiered** -- backs up every day, no day picker, GFS-style daily/weekly/monthly keep-counts. Existing installs carry their current setup over automatically (day-of-week + keep-last-N users land on Scheduled/Daily with their days intact; anyone who had the old standalone tiered-retention checkbox on lands on Tiered) -- nothing changes behavior on upgrade without being resaved.

= 2.1.0 =
Scheduling and retention overhaul. Replaces the old exact-time (HH:MM) single-event schedule with a day-based trigger: the first site visit (of any kind) on a selected day starts that day's backup, whichever day it is, no missed-instant window to depend on. Fixes the real cause of a backup silently stalling overnight with no one around to finish it: a job now advances itself step-by-step via a self-triggered background request (the same mechanism WordPress core uses to wake up its own cron) instead of waiting on a visitor's browser to keep polling it forward. Adds a plain-English backup status readout right on the settings page (succeeded / running / stalled / failed) — no CLI or database access ever required to check on a backup. Adds optional tiered (daily/weekly/monthly) retention using the same bucket-and-prune approach as restic/Borg, off by default. Security audit of the new self-advance endpoint in progress; see STATUS.md once complete.

= 2.0.0 =
Renamed from "WPGuard Nextcloud Backup" to "WPThistle Backup and Restore" — the plugin outgrew its old name once it covered ownCloud, OpenCloud, and zero-cloud local downloads, and "WPGuard" turned out to already be in active use elsewhere in the WordPress security space. This is a breaking change from WordPress's point of view: a new plugin slug, so it shows up as a separate install. Your settings, backup history, and site identity carry over automatically the first time this version activates — deactivate the old plugin first (do not delete it yet), activate this one, confirm everything looks right, then delete the old plugin's files. See docs/releasing.md and STATUS.md for the full migration detail and what was checked before shipping this.

Also fixes a credential-handling gap found while rehearsing this migration, present since one-click restore shipped in 1.1.0 (not present in 1.0.0, which had no restore feature yet): a one-click restore's own job record legitimately holds your Nextcloud/ownCloud/OpenCloud app password/app token temporarily, and backups taken afterward did not exclude that record from the exported database — only the separate settings row was ever excluded. This does not apply if you used the wp-config.php constant-override credential storage with no password ever saved to the database. It also means any backup you downloaded to your own computer during that window, not just ones left on your Nextcloud/ownCloud/OpenCloud server, may contain it. This release excludes it going forward and clears it from any job record already in your database. If you have ever used one-click restore on this site between 1.1.0 and 1.4.0, revoke and replace that app password/token as a precaution — the WordPress admin password and your database credentials are not affected.

Note for anyone still running 1.4.0 or earlier: this version cannot offer itself as an automatic update to those installs, since the plugin slug and update-feed repository both changed. Install this version manually (deactivate the old plugin, activate this one, confirm, then delete the old plugin's files) rather than waiting for an update notice that will not appear.

= 1.4.0 =
Phase 2 release (final item): resumable WebDAV chunking v2 for backup parts bigger than one chunk, on servers that support it (Nextcloud, ownCloud — confirmed live; OpenCloud keeps the existing single-PUT transport, confirmed live it doesn't support this dialect). If a connection drops mid-upload, the next attempt resumes from whatever the server already has instead of re-sending the whole part, with no change to how backups are configured or run. Security audit: one MEDIUM, two LOW, all fixed and re-verified before release — see this project's STATUS.md and docs/threat-model.md (T24) for the full history.

= 1.3.0 =
Phase 3 release: a shared top-level WPThistle admin menu (future WPThistle-family plugins land as submenus); "Download Now" — a second, independent backup destination that packages a backup into a flat zip and serves it to your browser, with zero Nextcloud dependency; multi-backend support (ownCloud, OpenCloud, anything speaking the same WebDAV dialect, alongside Nextcloud); and WP-CLI parity for both of the above (`run --destination=download`, `restore_local`). Every feature went through an independent security audit, fixes applied, and re-verification before shipping — see this project's STATUS.md and docs/threat-model.md (T21–T23) for the full history.

= 1.2.0 =
Phase 2.5 release: checking "this backup is from a different site" on restore now means migration, not just "proceed anyway" — every URL in the backup (including inside PHP-serialized data) is rewritten to match this site's own address before anything is restored live, so logging into the restored site actually works. Four rounds of independent security audit findings, all fixed and re-verified, including two distinct PHP serialization-amplification attack shapes found via live adversarial testing against real production backup data. See this project's STATUS.md and docs/threat-model.md for the full history.

= 1.1.0 =
Phase 2 release: automatic stale-lock recovery, scheduled (daily/weekly) backups, retention pruning, incremental file backups, a self-hosted update feed via GitHub Releases, and one-click in-place restore (from either the configured Nextcloud destination or an uploaded backup bundle). Every feature went through an independent security audit, fixes applied, and re-verification before shipping — see this project's STATUS.md and docs/threat-model.md for the full history. Verified against real production WordPress/Nextcloud data throughout, including full real restores with 2FA state intact.

= 1.0.0 =
Phase 1 MVP release. Two full independent security audits, both CLEAR TO SHIP. Verified against real production WordPress/Nextcloud data, including a full real restore with 2FA state intact. Fixes and additions since the initial 0.1.0 build: concurrent job-advance mutual exclusion; wp-config.php credential redaction before archiving; WebDAV redirect/streaming/CA-bundle hardening; ZIP packaging fixed to use spec-compliant path separators; batched file/table export to cut AJAX round trips; a manual Cancel/Reset action for a stuck job; a diagnostic per-backup job-log.json; archive-writer performance fixes (no longer reopens the ZIP per file; skips recompressing already-compressed file types).

= 0.1.0 =
Initial MVP: manual backup, versioned manifest, SHA-256 verified WebDAV transfer, no retention/scheduling.
