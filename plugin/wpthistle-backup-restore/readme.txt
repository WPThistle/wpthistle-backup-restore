=== WPThistle Backup and Restore ===
Contributors: ItsMNLOP
Donate link: https://buymeacoffee.com/ItsMNLOP
Tags: backup, restore, nextcloud, webdav, disaster-recovery
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.3.0
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

= Is my data safe with this, do I need to trust some third-party service? =

No third party is involved unless you already chose one yourself. Nextcloud, ownCloud, and OpenCloud are server software you (or your host) run. This plugin talks to storage you already control over your own HTTPS connection, the same trust relationship as using your own FTP or email account. Nothing is ever sent anywhere this plugin's author operates. If you'd rather not depend on any server at all, use "Download Now" instead and keep every backup entirely on your own computer.

= Is this free? Any catch? =

Free. No account, no license key, no feature paywall. The donate link in the plugin header is entirely optional.

= Where's my Nextcloud/ownCloud/OpenCloud password stored, can it leak? =

It's stored in your WordPress database (unless you lock it via a wp-config.php constant instead, see Installation above), never echoed back to you after you save it, and deliberately excluded from a backup's own exported database content, so a backup of your site can never also contain the key to your own storage account. Always generate a dedicated app password/app token for this (see the next question) rather than using your real account password.

= What's an "app password" / "app token," and why can't I just use my normal password? =

It's a separate credential, scoped to this one connection, that you generate under your Nextcloud/ownCloud/OpenCloud account's own Security settings. This plugin doesn't create it for you. If it's ever exposed, you revoke just that one credential and your real account password is untouched. Using your real password instead means any leak, from any angle, hands over your entire account, not just backup access.

= Does this back up absolutely everything? =

Yes. Your full database (every table under your WordPress install's own prefix, not just core tables, so other plugins' data is included) plus everything under wp-content/ (themes, plugins, uploads, all of it), plus wp-config.php and .htaccess if present. WordPress's own core files (wp-admin/, wp-includes/, the loader scripts in your site's root) are deliberately left out. They're identical, regenerable files for whatever WordPress version you're running (recorded in the backup's own manifest), not anything unique to your site, so a restore re-downloads them for free instead of this plugin backing up the same unchanged files on every single run.

= Does wp-config.php get backed up with my secrets still in it? =

With two different rules depending on where it's going. Any app password/app token this plugin itself stored via the constant-override option is always stripped first, on every destination. Your database credentials (DB_NAME/DB_USER/DB_PASSWORD/DB_HOST) are additionally stripped for a "Download Now" bundle, since that file is leaving your server's custody entirely. They are NOT stripped for a Nextcloud/ownCloud/OpenCloud backup, which is documented, deliberate behavior: you already trust that storage with your entire site, and you'd need those credentials intact to actually use the backup from there. If that tradeoff doesn't sit right for your setup, use Download Now and keep the bundle somewhere you control directly.

= Is there a size limit? Will this work for a big site? =

No hard limit built into the plugin itself. Large files upload in resumable chunks (so a dropped connection mid-upload doesn't mean starting that file over on Nextcloud/ownCloud), and the whole backup processes in small time-boxed batches so it doesn't hit your host's PHP execution-time limit. A very large site still takes proportionally longer and uses proportionally more of your storage quota and server resources while it runs. There's just no artificial cap on top of that.

= What even is Nextcloud/ownCloud/OpenCloud, do I have to sign up for something? =

They're self-hosted file-server software. Think "a private Dropbox you or your host runs," not a company account you sign up for through this plugin. Many hosting panels offer a one-click Nextcloud install, and some hosts bundle one already. If you don't have one and don't want to set one up, you don't need one. Use "Download Now" instead, which needs none of this.

= What's the difference between a Nextcloud destination and "Download Now"? Which should I use? =

Download Now sends a zip straight to your browser. Zero setup, zero ongoing dependency, but it's on you to actually move that file somewhere safe afterward, since nothing else keeps a copy once it's on your computer. A Nextcloud/ownCloud/OpenCloud destination needs that one-time setup but then runs unattended on a schedule with no further action from you, and, importantly, keeps backups on a different server than the one being backed up, which matters if that server ever fails outright. Most people want a cloud destination for ongoing scheduled backups, and Download Now for an occasional one-off "before I do something risky" backup.

= How do I actually restore a backup if something goes wrong? =

The Restore section of the settings page: pick a backup (from your configured cloud destination, or upload a bundle you downloaded earlier), type RESTORE to confirm, then click Restore Now. It replaces your live files and database with the backup's contents in place. There's no separate staging site involved.

= Does restoring a backup log me out or break two-factor authentication? =

No. Real restores, including full production drills, have kept two-factor auth state intact. Your login session itself may still get invalidated as a side effect of the database swap (sessions live in the database), so you might need to log back in once, but your 2FA setup survives the restore.

= Can I undo a restore if I picked the wrong backup? =

There's no one-click undo, but nothing a restore replaces is ever deleted outright: the database tables it swaps out are renamed aside (not dropped), and the files it replaces move into a timestamped rollback folder, specifically so you, or whoever manages your server, can manually recover the previous state via phpMyAdmin/FTP/your host's file manager if a restore turns out to be the wrong call. Read the warning on the Restore screen before using it on a site that matters, regardless.

= Can I restore a backup onto a different domain, e.g. to migrate my site? =

Yes. Check "this backup is from a different site" when restoring, and every URL stored anywhere in the backup (post content, widget settings, serialized data, everywhere WordPress stores a URL) is rewritten to the new site's own address before anything goes live, so logging into the migrated site actually works afterward.

= How do I know a backup actually succeeded? =

The settings page shows a plain-English status (succeeded / running / stalled / failed) for the most recent backup. No server access or technical tools needed. A backup is never marked succeeded until every uploaded piece has been read back from storage and its checksum independently verified against what was actually sent, so "succeeded" means confirmed, not just "the upload calls didn't error."

= What happens if my internet connection drops in the middle of a backup? =

For a Nextcloud or ownCloud destination, an interrupted large file resumes from where it left off rather than re-uploading the whole thing from scratch. OpenCloud doesn't support that resume dialect yet, so an interrupted file there restarts from the beginning on retry. Either way the backup eventually completes and gets independently verified; OpenCloud on a flaky connection just costs more re-transferred data getting there.

= Does running a backup slow down my site? =

To some degree, yes. Archiving files and uploading to your storage destination uses your own server's CPU, disk, and bandwidth while it runs, same as any backup tool would. It's processed in small time-boxed batches specifically so it doesn't hit your host's execution-time limit and die mid-job, but that's a reliability measure, not a promise of zero impact. There's no time-of-day setting to dodge your busiest hours with directly; a scheduled backup starts on whichever request happens to reach your site first on a selected day, at whatever time that turns out to be.

If your site is quiet overnight, you can still get close to a chosen time by setting up the optional overnight ping described in "Guaranteed scheduling" below: point a free service like cron-job.org at your site's wp-cron.php URL, set it to run once around, say, 2:00AM, and on a backup day that becomes the latest the backup will start, since any earlier visitor would trigger it sooner. It's a nudge toward a quiet hour, not an exact scheduled time.

= What if I forget to check on it, will I know if backups silently stop working? =

Honestly, not yet. There's no email or other alert today if a backup fails or stalls; the plain-English status on the settings page is something you check, not something that reaches out to you. As of 2.1.0, a backup that successfully starts is very unlikely to just quietly die (it advances itself to completion rather than depending on visitor traffic), but nothing currently taps you on the shoulder if it never started in the first place or genuinely fails. Worth checking in on the status page occasionally rather than assuming silence means success.

= If I uninstall the plugin, do my existing backups get deleted too? =

No. Uninstalling removes this plugin's own settings and local job history from your WordPress site, but anything already uploaded to your Nextcloud/ownCloud/OpenCloud destination stays exactly where it is. This plugin only ever adds to that storage, never deletes from it on uninstall. Remove those backups from your cloud account directly if you want them gone too.

= Will updating the plugin wipe my settings or schedule? =

No. Your settings, schedule, and backup/job history live in your WordPress database, entirely separate from the plugin's code files. Updating, deactivating, and reactivating the plugin never touches that stored data.

= What's the actual difference between "Scheduled" and "Tiered"? =

Scheduled only ever attempts a backup on the day(s) you pick. On every
other day, nothing runs at all. Tiered has no day picker because it
attempts a backup every single day, with no exceptions; the daily/
weekly/monthly keep-counts only control how many of those get kept
afterward, not whether that day's backup happens in the first place.

This matters most if you set Tiered's "keep daily" count to 0: backups
still run every day (full bandwidth/server load/Nextcloud API cost each
time), and pruning simply deletes most of them again right away, keeping
only whichever one represents the current week/month. If you only want
a backup on specific day(s), and never want the server to do backup work
on the others, use Scheduled instead. Tiered will always cost more than
that, even configured down to the same number of backups kept.

= I set all three Tiered counts (daily/weekly/monthly) to 0, does that delete everything? =

Setting just ONE tier to 0 (covered above) is not the same as setting
ALL THREE to 0. Here's the simple version of how it works.

Every day a new backup finishes, the plugin asks itself a fresh
question: "Of every backup I have right now, which one is the newest
for today, the newest for this week, and the newest for this month?"
Whichever backups answer "yes" to one of those get kept. Everything
else gets deleted right then, every single time. There's no holding
pen where a backup waits around and later gets promoted into the
weekly or monthly group. It's just asked fresh, from scratch, every
time a backup finishes.

A "week" here is defined as Monday through Sunday.

Picture one backup a day, for a week, with daily turned off (0) and
weekly set to keep 4 weeks:

  Mon    Tue    Wed    Thu    Fri    Sat    Sun
  B1     B2     B3     B4     B5     B6     B7
  gone   gone   gone   gone   gone   gone   KEPT (for now)

Each new day's backup knocks the day before it out of the "newest this
week" spot, so the older one gets deleted. Only the very last backup
made before the week ends (Sunday) survives, because once that week is
over, nothing can ever beat it for "newest in that week" again.

Setting ALL THREE tiers to 0 is a completely different switch. The
plugin checks for that exact combination first, before any of the
above even starts, and simply refuses to delete anything at all, no
matter how many backups pile up. That's the only way to turn pruning
off entirely.

0 in just one box means "skip that question, let the other boxes
decide." 0 in all three boxes means "don't delete anything, period."

= What happens if I click "Back Up Now" after today's scheduled or tiered backup already ran? =

It starts a second, independent backup right alongside it. Clicking
Back Up Now never checks whether today's slot was already claimed, and
isn't blocked by it either way. The only thing Back Up Now refuses is a
backup that's currently running (any backup, scheduled/tiered/manual).
That returns "A backup is already running." instead of starting a new
one.

What happens to that extra backup afterward depends on your plan. Under
Tiered, nothing changes long-term: the daily tier only ever keeps the
most recent backup per calendar day, so the manual one simply becomes
that day's kept copy once pruning runs again. Under Scheduled's flat
keep-last-N count, there's no such dedupe. Both backups count as
separate entries toward N, so you'll reach your kept-count limit one
backup sooner than expected.

== Changelog ==

= 2.3.0 =
Settings page split into four tabs (Home, Connection, Backup Schedule, Advanced) instead of one long scrolling page; Home now defaults to Manual backup and Restore, since that's what most visits are for once a site is set up. Save Settings has a button on both the Connection and Backup Schedule tabs now, and switching tabs or leaving the page with unsaved changes prompts for confirmation first.

New "Forget Connection" action on the Connection tab: type FORGET to clear the stored server URL, username, app password/token, folder, and CA bundle path, without touching your schedule or retention settings. Useful if credentials need clearing for some reason without resetting everything else. A credential set via a wp-config.php constant keeps working regardless, since this only clears what the database remembers.

The WPThistle family hub card for this plugin now shows the installed version, colored green or red depending on whether an update is available, and the latest version number when one is.

Fix: the daily/monthly/tiered scheduling triggers now check that credentials are actually configured before claiming that day's or month's backup slot. Previously, a site with no connection configured yet (or one that just used Forget Connection) could burn that slot with no backup made and no way to retry until the next period.

Docs: the FAQ entry explaining Tiered retention's zero-value behavior got an ELI10 rewrite with a diagram, and now states plainly that a week here runs Monday through Sunday.

= 2.2.2 =
Docs only: added an FAQ section explaining the real difference between Scheduled and Tiered (Tiered backs up every day regardless of its keep-counts, including a "keep daily" of 0; Scheduled only ever runs on the day(s) picked). Lives on the plugin's "View details" page, not the Settings screen, so it's there for whoever wants it without adding more text to the settings UI itself.

= 2.2.1 =
UI fix: the Backup Plan and Cadence dropdowns' option text was long enough to get clipped in the dropdown box itself. Options are now short ("Scheduled"/"Tiered", "Daily"/"Monthly"); the fuller explanation moved to description text below the dropdown that switches to match whichever option is currently selected.

= 2.2.0 =
Backup plan is now one dropdown instead of two settings that could silently contradict each other (picking specific days of the week while tiered retention was also on, which made the "daily" tier not actually daily). Choose Scheduled: pick a cadence (daily day-of-week, or now also monthly day-of-month, including a "Last Day of the Month" option for shorter months) plus a simple keep-last-N count. Or choose Tiered: backs up every day, no day picker, GFS-style daily/weekly/monthly keep-counts. Existing installs carry their current setup over automatically (day-of-week + keep-last-N users land on Scheduled/Daily with their days intact; anyone who had the old standalone tiered-retention checkbox on lands on Tiered). Nothing changes behavior on upgrade without being resaved.

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
