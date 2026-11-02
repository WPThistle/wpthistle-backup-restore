<?php
/**
 * Plugin Name:       WPThistle Backup and Restore
 * Plugin URI:        https://github.com/WPThistle/wpthistle-backup-restore
 * Update URI:        https://github.com/WPThistle/wpthistle-backup-restore
 * Description:       Creates recoverable backups of this WordPress site (files + database) and transfers them to a user-controlled Nextcloud, ownCloud, or OpenCloud server over authenticated HTTPS WebDAV -- or packages them straight to your browser with zero cloud dependency. Manual or scheduled backups, retention pruning, and one-click in-place restore from either a configured server destination or an uploaded backup bundle.
 * Version:           2.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            WPThistle project
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpthistle-backup-restore
 */

namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicate-install guard. A manual zip re-upload without deleting the
 * prior folder first makes WordPress extract into a second folder (e.g.
 * wpthistle-backup-restore-1) instead of overwriting -- both folders then
 * declare the same "Plugin Name" header, both can end up active, and both
 * would otherwise require() this exact file content, fatally redeclaring
 * every function/class below. Bail out before declaring anything the
 * instant a second copy is detected, and tell the admin which folders are
 * involved instead of a white screen.
 */
if ( defined( 'WPTHISTLE_BACKUP_RESTORE_BOOTSTRAPPED' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>WPThistle Backup and Restore:</strong> '
				. esc_html__( 'a second copy of this plugin is active in a different folder -- usually left behind when a zip gets re-uploaded without deleting the previous folder first (WordPress names the new one with a "-1" suffix rather than overwriting). Running two copies at once causes conflicts. Delete the extra folder via FTP or your host\'s file manager, keeping only one, then reload this page.', 'wpthistle-backup-restore' )
				. '</p></div>';
		}
	);
	return;
}
define( 'WPTHISTLE_BACKUP_RESTORE_BOOTSTRAPPED', __FILE__ );

// Everything below is wrapped in this `if ( true )` deliberately: PHP
// early-binds (declares at compile time, ignoring runtime control flow)
// any function/class declared unconditionally at a file's top level,
// which would silently defeat the guard above -- the `return` on a
// duplicate require happens at runtime, too late to stop a declaration
// the compiler already bound before executing a single statement.
// Wrapping defers every declaration in here to actual runtime
// execution, which the guard does correctly prevent. Verified directly
// (not merely reasoned about): a duplicate require without this wrapper
// still fatals with "Cannot redeclare function ...".
if ( true ) {

define( 'WPTHISTLE_BACKUP_VERSION', '2.4.0' );
// Phase 2 (incremental backups): bumped 1 -> 2. Every manifest this
// plugin writes from here on -- full or incremental alike -- carries
// new backup_mode/base_backup_id/parent_backup_id/files fields.
// validate_format_version() rejects anything newer than what's running,
// so this forces any older restore tooling (written against the
// format_version 1 docs) to refuse touching a backup it doesn't fully
// understand, rather than silently restoring a partial file set.
// Restoring an OLDER format_version 1 backup is unaffected -- only
// forward-compatibility is the thing being deliberately broken here.
define( 'WPTHISTLE_BACKUP_FORMAT_VERSION', 2 );
define( 'WPTHISTLE_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPTHISTLE_BACKUP_URL', plugin_dir_url( __FILE__ ) );
// Phase 3 (downloadable backups): bumped 1 -> 2, adds the jobs table's
// own `destination` column (see class-job-store.php's create() docblock).
// Phase 5 (self-chaining job advance): bumped 2 -> 3, adds the jobs
// table's own advance_token/advance_chain_count columns (see
// class-job-store.php's create() docblock).
define( 'WPTHISTLE_BACKUP_DB_SCHEMA_VERSION', 4 );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WPThistle\\BackupRestore\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = WPTHISTLE_BACKUP_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

// define(), not `const` -- a namespaced `const` is bound at compile
// time unconditionally, which can't sit inside the `if ( true )`
// duplicate-install guard wrapper below (PHP parse error) and, left
// outside it, would fatal on redeclare the same way the functions
// would. define() is a plain runtime call, so it's guarded correctly
// like everything else in the wrapper; bare references to these names
// elsewhere in this namespace still resolve via PHP's standard
// fallback-to-global-constant behavior.
define( 'STALE_LOCK_CHECK_HOOK', 'wpthistle_backup_stale_lock_check' );

// Phase 5 (self-chaining job advance): a hard cap on how many times a
// single job can re-trigger itself via self_chain_advance() below --
// defense in depth so a bug here (or an attacker who somehow obtained
// a valid advance_token) can never spin a job forever. Once reached,
// the job falls back to the pre-existing passive WP-Cron path (the
// wp_schedule_single_event() call cron_advance() always makes
// regardless), same as before this feature existed.
define( 'WPTHISTLE_BACKUP_SELF_ADVANCE_MAX_CHAIN', 200 );

function activate() {
	migrate_from_legacy_wpguard();
	migrate_schedule_to_day_time();
	migrate_clear_legacy_scheduled_backup_hook();
	Job_Store::install_or_upgrade_schema();
	Settings::ensure_site_id();
	sync_stale_lock_check();
}

/**
 * Phase 4 (day/time scheduling): migrates the old Disabled/Daily/Weekly
 * dropdown (Settings::OPTION_KEY's 'schedule' field) into the new
 * schedule_days[] field. Non-destructive and runs once -- a no-op the
 * instant 'schedule_days' exists in storage, including on every
 * activation after the first. Must run before
 * Job_Store::install_or_upgrade_schema() so the very first sync after an
 * upgrade already sees the migrated shape, not the old one.
 *
 * Where there's a real in-flight recurring event from the old scheme
 * (the common case -- an existing site with scheduling already on), its
 * own anchor day-of-week is read back, so an admin's existing schedule
 * survives the upgrade with the same days rather than silently resetting
 * to a default. (Phase 5 dropped the exact time-of-day field entirely --
 * see Daily_Trigger's docblock -- so only the day-of-week is worth
 * recovering here now; an intermediate Phase-4-only install's
 * schedule_time value, if present, is simply left in storage, unread by
 * anything from here on.)
 */
function migrate_schedule_to_day_time(): void {
	$stored = get_option( Settings::OPTION_KEY, array() );
	if ( ! is_array( $stored ) || array_key_exists( 'schedule_days', $stored ) ) {
		return; // Already migrated, or a genuinely fresh install with no old shape to carry over.
	}

	$old = isset( $stored['schedule'] ) ? (string) $stored['schedule'] : 'disabled';
	unset( $stored['schedule'] );

	if ( 'disabled' === $old || '' === $old ) {
		$stored['schedule_days'] = array();
		update_option( Settings::OPTION_KEY, $stored, false );
		return;
	}

	// Literal hook name, not a constant: this is reading a possible
	// leftover scheduled event from BEFORE Phase 5 removed the
	// SCHEDULED_BACKUP_HOOK constant/mechanism entirely -- a one-time
	// historical migration read, independent of whether anything in the
	// current codebase still defines or hooks that name.
	$event = wp_get_scheduled_event( 'wpthistle_backup_scheduled_backup' );
	$anchor_day = $event ? (int) ( new \DateTimeImmutable( '@' . $event->timestamp ) )->setTimezone( wp_timezone() )->format( 'w' ) : 0;

	$stored['schedule_days'] = ( 'daily' === $old ) ? array( 0, 1, 2, 3, 4, 5, 6 ) : array( $anchor_day );
	update_option( Settings::OPTION_KEY, $stored, false );
}

/**
 * Phase 5: an install upgrading from the brief exact-HH:MM single-event
 * scheduling model (or the still-earlier recurring-event model, via
 * migrate_from_legacy_wpguard()'s own clear of the old WPGuard-named
 * hook) may have a real event still sitting in wp_options('cron') under
 * the old hook name. Nothing hooks that name any more, so it would just
 * fire into the void -- harmless, but this cleans it up rather than
 * leaving a dead entry behind. Idempotent/cheap (a clear of an
 * already-absent hook is a no-op), so safe to call on every
 * activate(), not just once.
 */
function migrate_clear_legacy_scheduled_backup_hook(): void {
	wp_clear_scheduled_hook( 'wpthistle_backup_scheduled_backup' );
}

/**
 * 2.0.0 rename (WPGuard Nextcloud Backup -> WPThistle Backup and
 * Restore): migrates data from the old plugin's storage into this
 * plugin's own. Non-destructive -- nothing under the old names is
 * deleted here; that only happens if the old plugin is itself
 * uninstalled via its own uninstall.php, which this never touches. Safe
 * cutover order: deactivate the old plugin (data stays on disk/in the
 * DB), activate this one (this function runs and migrates it), verify,
 * THEN delete the old plugin's files.
 *
 * A no-op on a brand-new install (nothing old to find) and on every
 * activation after the first (the new settings option is already
 * present, so this returns immediately).
 */
function migrate_from_legacy_wpguard(): void {
	if ( false !== get_option( Settings::OPTION_KEY, false ) ) {
		return;
	}
	$legacy_settings = get_option( 'wpguard_nc_settings', false );
	if ( false === $legacy_settings ) {
		return; // No legacy plugin data found -- genuinely a fresh install.
	}
	// Vex security audit (2026-10-23, non-blocking LOW): Settings::save()
	// always passes autoload=false for this credential-bearing option;
	// this migration path must match, not leave it autoloading (WP's
	// default) into every request's alloptions cache until the admin
	// next opens the settings page and saves.
	update_option( Settings::OPTION_KEY, $legacy_settings, false );

	$legacy_site_id = get_option( 'wpguard_nc_site_id', false );
	if ( false !== $legacy_site_id ) {
		update_option( Settings::SITE_ID_KEY, $legacy_site_id );
	}

	$legacy_schema_version = get_option( 'wpguard_nc_db_schema_version', false );
	if ( false !== $legacy_schema_version ) {
		// Job_Store::install_or_upgrade_schema() runs right after this
		// (still inside activate()) and reads this exact option to decide
		// whether dbDelta() needs to run -- carrying the old value over
		// means it correctly sees "already current" once the table itself
		// is renamed below, rather than creating a second, empty table.
		update_option( 'wpthistle_backup_db_schema_version', $legacy_schema_version );
	}

	$legacy_chain_state = get_option( 'wpguard_nc_chain_state', false );
	if ( false !== $legacy_chain_state ) {
		update_option( Backup_Chain::STATE_OPTION, $legacy_chain_state );
	}

	$legacy_retention_error = get_option( 'wpguard_nc_retention_last_error', false );
	if ( false !== $legacy_retention_error ) {
		update_option( Job_Orchestrator::RETENTION_ERROR_OPTION, $legacy_retention_error );
	}

	global $wpdb;
	$old_table = $wpdb->prefix . 'wpguard_nc_jobs';
	$new_table = Job_Store::table_name();
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- both names are
	// fixed constant-derived strings, never user input.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table ) {
		$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
		// Vex security audit (2026-10-23, non-blocking MEDIUM): a
		// completed restore job's checkpoint holds the real app
		// password/token in its captured_options snapshot, needed only
		// up to the point it was re-applied -- stripped here for every
		// job this migration just carried over, same as every future
		// job gets stripped at its own terminal transition
		// (restore_swap()/fail()) from here on.
		$job_ids = $wpdb->get_col( "SELECT job_id FROM `{$new_table}` WHERE status IN ( 'verified', 'failed' )" );
		foreach ( $job_ids as $job_id ) {
			$job = Job_Store::get( $job_id );
			if ( null === $job || ! isset( $job['checkpoint']['captured_options'] ) ) {
				continue;
			}
			$cp = $job['checkpoint'];
			unset( $cp['captured_options'] );
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
		}
	}

	// Orphaned entries under the old hook names would otherwise sit in
	// wp_options('cron') forever -- nothing listens for them post-rename.
	// sync_stale_lock_check()/sync_scheduled_backup(), called right after
	// this inside activate(), schedule fresh events under the new names
	// regardless of whether this clears anything.
	wp_clear_scheduled_hook( 'wpguard_nc_stale_lock_check' );
	wp_clear_scheduled_hook( 'wpguard_nc_scheduled_backup' );
	// wpguard_nc_cron_advance is scheduled per-job (wp_schedule_single_event,
	// not a single recurring hook), so there can be several pending events
	// to clear here, not just one.
	wp_clear_scheduled_hook( 'wpguard_nc_cron_advance' );
}

/**
 * the security audit delta audit, 2026-10-20 (OBS-1): register_activation_hook() only
 * fires on a fresh activation, not on a plugin update while already
 * active -- a site upgrading in place would silently lose the hourly
 * safety-net cron (the create_job()-triggered reap path still works
 * regardless) until it happened to be deactivated and reactivated.
 * Idempotent and cheap like sync_scheduled_backup(), so also called
 * from boot() on every load, not just activate().
 */
function sync_stale_lock_check(): void {
	if ( ! wp_next_scheduled( STALE_LOCK_CHECK_HOOK ) ) {
		wp_schedule_event( time(), 'hourly', STALE_LOCK_CHECK_HOOK );
	}
}

function deactivate() {
	wp_clear_scheduled_hook( STALE_LOCK_CHECK_HOOK );
	// Phase 3 (downloadable backups): the hourly sweep this deactivates
	// is the only thing that would otherwise ever clean up a pending
	// local bundle holding a full DB dump -- purge unconditionally
	// rather than leave one sitting on disk for as long as the plugin
	// stays deactivated.
	Job_Orchestrator::sweep_download_bundles( true );
}

/**
 * Phase 5 (day-based trigger, replaces the exact-HH:MM single-event
 * model): checked on every boot() -- i.e. every request of any kind,
 * including WP-Cron's own pseudo-cron requests -- rather than relying
 * on a WP-Cron event landing near one specific instant. If today
 * (site-local calendar date) is a selected schedule day and hasn't
 * already been claimed, this claims it (exactly once, race-free across
 * concurrent requests -- see claim_today()) and starts a backup.
 *
 * The claim happens BEFORE create_job() is even attempted, and
 * unconditionally once claimed -- same "never retry-storm, just wait
 * for the next matching day" philosophy the old run_scheduled_backup()
 * had for its re-arm: if credentials are missing or a backup is
 * already running, today's slot is simply skipped, not retried on
 * every subsequent page load for the rest of the day.
 */
function maybe_run_daily_backup(): void {
	$days = Settings::get_schedule_days();
	if ( empty( $days ) ) {
		return;
	}
	// Vex security audit (2026-11-01, VX-2): checked BEFORE claim_today(), not
	// after. Claiming first and checking credentials only inside
	// start_claimed_backup() meant a site with no (or just-forgotten)
	// credentials would burn today's slot on the very first request
	// and never get a real retry today even if reconnected minutes
	// later. Still re-checked inside start_claimed_backup() too (cheap,
	// harmless) as defense against the narrow window where credentials
	// are forgotten between this check and the claim succeeding.
	if ( ! Settings::has_credentials() ) {
		return;
	}
	$tz = wp_timezone();
	$now = time();
	if ( ! Daily_Trigger::is_due_today( $days, $now, $tz ) ) {
		return;
	}
	$today = Daily_Trigger::today( $now, $tz );
	if ( ! claim_today( $today['date'] ) ) {
		return; // Already claimed/attempted today (by this or another concurrent request).
	}
	start_claimed_backup();
}

/**
 * Phase 6 ("Scheduled" mode's monthly cadence): same day-based philosophy
 * as maybe_run_daily_backup() above, keyed by day-of-month instead of
 * day-of-week -- see Monthly_Trigger and Settings::get_schedule_day_of_month().
 */
function maybe_run_monthly_backup(): void {
	// Vex security audit (2026-11-01, VX-2): see maybe_run_daily_backup()'s
	// matching comment; checked before claim_month() for the same reason.
	if ( ! Settings::has_credentials() ) {
		return;
	}
	$day = Settings::get_schedule_day_of_month();
	$tz = wp_timezone();
	$now = time();
	if ( ! Monthly_Trigger::is_due_today( $day, $now, $tz ) ) {
		return;
	}
	$today = Monthly_Trigger::today( $now, $tz );
	if ( ! claim_month( $today['month_key'] ) ) {
		return; // Already claimed/attempted this month (by this or another concurrent request).
	}
	start_claimed_backup();
}

/**
 * Phase 6 ("Tiered" mode): backs up every day, no day picker at all --
 * tiered (GFS-style) retention's "daily" tier assumes continuous daily
 * cadence as its finest granularity, so letting an admin also restrict
 * which days trigger a backup while in this mode would make that tier's
 * name a lie. Reuses the same per-date claim as the daily cadence above
 * (the two modes are mutually exclusive, so sharing DAILY_CLAIM_OPTION is
 * safe -- only one of maybe_run_daily_backup()/maybe_run_tiered_backup()
 * is ever reachable for a given site's current settings).
 */
function maybe_run_tiered_backup(): void {
	// Vex security audit (2026-11-01, VX-2): see maybe_run_daily_backup()'s
	// matching comment; checked before claim_today() for the same reason.
	if ( ! Settings::has_credentials() ) {
		return;
	}
	$tz = wp_timezone();
	$today = Daily_Trigger::today( time(), $tz );
	if ( ! claim_today( $today['date'] ) ) {
		return;
	}
	start_claimed_backup();
}

/**
 * Dispatches to whichever of the three trigger functions above matches
 * the admin's current backup-plan choice. This, not maybe_run_daily_backup()
 * directly, is what boot() and the settings-save handler call -- see
 * Settings::get_schedule_mode()/get_schedule_cadence().
 */
function maybe_run_scheduled_backup(): void {
	if ( 'tiered' === Settings::get_schedule_mode() ) {
		maybe_run_tiered_backup();
		return;
	}
	if ( 'monthly' === Settings::get_schedule_cadence() ) {
		maybe_run_monthly_backup();
		return;
	}
	maybe_run_daily_backup();
}

/** Shared tail end of every trigger above, once its own period is successfully claimed. */
function start_claimed_backup(): void {
	if ( Settings::has_credentials() ) {
		$job_id = Job_Orchestrator::create_job( 'nextcloud', 'scheduled' );
		if ( '' !== $job_id ) {
			wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );
		}
	}
}

// define(), not `const` -- same duplicate-install-guard reasoning as
// STALE_LOCK_CHECK_HOOK above: a namespaced `const` can't sit inside
// the `if ( true )` wrapper this whole file lives in.
define( 'DAILY_CLAIM_OPTION', 'wpthistle_backup_daily_claim' );
define( 'MONTHLY_CLAIM_OPTION', 'wpthistle_backup_monthly_claim' );

/**
 * Atomically claims $today_key ("Y-m-d", site-local) as "today's slot
 * has been handled" -- true only for the one request that actually
 * wins the claim, false for every other concurrent or later request on
 * the same day. Two primitives, in order:
 *
 * 1. add_option() is a plain INSERT that fails if the row already
 *    exists (the options table's UNIQUE KEY on option_name) -- wins
 *    unconditionally the very first time this option is ever created
 *    on a site, which covers a fresh install's first-ever claim.
 * 2. Every claim after that: the option already exists (yesterday's,
 *    or an earlier day's, date), so add_option() fails every time from
 *    here on. The conditional UPDATE below is the real atomic
 *    primitive for this case -- it only flips the stored value when it
 *    doesn't already equal $today_key, and MySQL's row-level locking
 *    during the UPDATE means at most one concurrent request's UPDATE
 *    actually matches that WHERE clause and reports a row changed; the
 *    rest see 0 rows affected and correctly treat that as "someone else
 *    already claimed today."
 *
 * A plain read-then-write (read the option, compare, write) would have
 * a race window between the read and the write that two concurrent
 * requests on a real site (two visitors in the same second) could both
 * pass through -- this has none.
 */
function claim_today( string $today_key ): bool {
	return claim_period( DAILY_CLAIM_OPTION, $today_key );
}

/** Same atomic claim as claim_today() above, keyed by "Y-m" month instead of "Y-m-d" date -- see maybe_run_monthly_backup(). */
function claim_month( string $month_key ): bool {
	return claim_period( MONTHLY_CLAIM_OPTION, $month_key );
}

function claim_period( string $option_name, string $key ): bool {
	if ( add_option( $option_name, $key, '', false ) ) {
		return true;
	}
	global $wpdb;
	$affected = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value <> %s",
			$key,
			$option_name,
			$key
		)
	);
	return $affected > 0;
}

/**
 * Plugins-list-screen row meta -- a BuyMeACoffee link alongside the
 * plugin's own "View details"/"Visit plugin site" row (the owner's approved
 * placement, 2026-10-23: readme.txt Donate link, this row-meta entry,
 * and a one-line footer on the family hub page only -- never an admin
 * notice/nag).
 */
add_filter( 'plugin_row_meta', __NAMESPACE__ . '\\add_donate_row_meta', 10, 2 );
function add_donate_row_meta( array $links, string $file ): array {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="https://buymeacoffee.com/ItsMNLOP" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Buy me a coffee', 'wpthistle-backup-restore' ) . '</a>';
	}
	return $links;
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

function boot() {
	Job_Store::install_or_upgrade_schema();

	if ( is_admin() ) {
		( new Admin() )->register();
	}

	// Phase 2 (self-hosted update feed, item 4): registered unconditionally,
	// not just is_admin() -- WP's own update-check cron
	// (`wp_update_plugins`, hooked to pre_set_site_transient_update_plugins)
	// runs outside admin context too. The GitHub API call itself only
	// happens when that transient is actually being rebuilt, and is
	// transient-cached for 12h beyond that -- this registration alone adds
	// no new outbound requests or cron events. No request input of any
	// kind (security audit, 2026-10-20) reaches either hooked method -- the
	// only data path in is the TLS-authenticated GitHub API response.
	( new Update_Checker( plugin_basename( __FILE__ ) ) )->register();

	add_action( 'wpthistle_backup_cron_advance', __NAMESPACE__ . '\\cron_advance' );

	// Phase 5 (self-chaining job advance, SECURITY: not yet audited --
	// see cron_advance()/self_chain_advance()'s docblocks): deliberately
	// also registered _nopriv_ -- the request this answers is a
	// background loopback this site fires at itself, never a logged-in
	// browser request, so it carries no WP auth cookie at all. Security
	// here is entirely the per-job advance_token (hash_equals()-checked
	// inside ajax_self_advance()), not capability/nonce.
	add_action( 'wp_ajax_wpthistle_backup_self_advance', __NAMESPACE__ . '\\ajax_self_advance' );
	add_action( 'wp_ajax_nopriv_wpthistle_backup_self_advance', __NAMESPACE__ . '\\ajax_self_advance' );

	// JOB-05 (Phase 2) safety net: Job_Orchestrator::create_job() already
	// reaps a stale job the moment anyone next tries to back up, but a
	// site that nobody visits for a while would otherwise leave a stuck
	// job sitting untouched until someone does. This recurring hourly
	// event (itself still WP-Cron pseudo-cron -- fires on the next
	// traffic after it's due, same limitation as the advance-chain
	// fallback) clears it proactively instead of waiting for that.
	add_action( STALE_LOCK_CHECK_HOOK, __NAMESPACE__ . '\\Job_Orchestrator::reap_stale_lock' );

	// Phase 3 (downloadable backups): same hourly safety net, TTL-only
	// (no $purge_all arg passed, so an unexpired pending bundle survives
	// until an admin downloads/deletes it or it genuinely goes stale).
	add_action( STALE_LOCK_CHECK_HOOK, __NAMESPACE__ . '\\Job_Orchestrator::sweep_download_bundles' );

	// Idempotent reconciliation on every load (OBS-1: covers an
	// in-place plugin update, which activate() alone would miss), in
	// case either schedule was ever changed or lost through a path
	// other than the admin save handler. migrate_schedule_to_day_time()
	// is a no-op once already migrated, same OBS-1 reasoning as the
	// other two.
	migrate_schedule_to_day_time();
	migrate_clear_legacy_scheduled_backup_hook();
	sync_stale_lock_check();
	maybe_run_scheduled_backup();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'wpthistle-backup-restore', new CLI_Command() );
	}
}

/**
 * WP-Cron fallback so a closed browser tab doesn't strand a job
 * (ADR 0003). Advances until terminal or until a time budget is spent,
 * then reschedules itself for jobs still in progress.
 *
 * security audit finding HIGH-AJAX-1 (one-click restore): this previously called
 * Job_Orchestrator::advance() unconditionally -- fine as long as only
 * backups ever scheduled this event, but restore had no cron fallback
 * registered at all, meaning a closed tab or a network blip during the
 * one phase where that matters most (restore_apply_db onward, the
 * point of no return) left the job stuck until the stale-lock reaper's
 * timeout, with no automated way to finish it. Dispatched by
 * checkpoint kind here so restore jobs now get the exact same
 * closed-tab protection backups always have.
 */
function cron_advance( string $job_id ) {
	$deadline = time() + 20;
	while ( time() < $deadline ) {
		$job_row = Job_Store::get( $job_id );
		$is_restore = null !== $job_row && 'restore' === ( $job_row['checkpoint']['kind'] ?? 'backup' );
		$job = $is_restore ? Restore_Orchestrator::advance( $job_id ) : Job_Orchestrator::advance( $job_id );
		if ( in_array( $job['status'] ?? '', array( 'verified', 'failed', 'not_found' ), true ) ) {
			return;
		}
	}
	// Passive fallback, unchanged from before Phase 5: if nothing else
	// ever pings this job again (self-chaining below exhausted,
	// unreachable, or its request silently fails), the next real
	// request's WP-Cron pseudo-cron still eventually picks this back
	// up, same safety net this line has always provided.
	wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );

	self_chain_advance( $job_id );
}

/**
 * Phase 5 (the actual fix for a backup/restore stalling forever on a
 * genuinely quiet site): fires an immediate, non-blocking loopback
 * request back to this same site so the job keeps advancing right
 * away, instead of only the passive fallback above -- which still
 * requires SOME other request (a real visitor, a search-engine
 * crawler, an admin opening wp-admin) to land before WP-Cron's own
 * pseudo-cron will ever pick the scheduled event back up. A site with
 * no traffic at all overnight has nothing to generate that "other
 * request" -- this is the gap that actually caused a real missed
 * scheduled backup (see docs/operations.md). Same fire-and-forget
 * technique WP core's own spawn_cron() uses internally for exactly
 * this reason.
 *
 * SECURITY: Vex-audited 2026-11-01, no CRITICAL/HIGH. This opens a new
 * unauthenticated-reachable code path (ajax_self_advance(), registered
 * _nopriv_) whose only protection is the per-job advance_token (256-bit
 * random, generated once at Job_Store::create(), hash_equals()-compared,
 * never exposed to the browser the way job_id is) plus the
 * WPTHISTLE_BACKUP_SELF_ADVANCE_MAX_CHAIN cap below. Two LOW findings
 * fixed as part of that audit: advance_token is now cleared on every
 * terminal (verified/failed) transition in both orchestrators (dead
 * jobs' tokens stop working), and ajax_self_advance() itself now checks
 * terminal status and the chain cap before calling cron_advance(), so an
 * external caller can't bypass either just by hitting the endpoint
 * directly. Remaining MEDIUM finding (no rate limiting on this
 * unauthenticated endpoint) is a server-level fix, not a plugin one --
 * see docs/threat-model.md.
 */
function self_chain_advance( string $job_id ): void {
	$job = Job_Store::get( $job_id );
	if ( null === $job || '' === ( $job['advance_token'] ?? '' ) ) {
		return; // No token (e.g. a job created before this feature shipped) -- rely on the passive fallback only.
	}
	if ( (int) ( $job['advance_chain_count'] ?? 0 ) >= WPTHISTLE_BACKUP_SELF_ADVANCE_MAX_CHAIN ) {
		return; // Cap reached -- fall back to the passive WP-Cron path from here on.
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Job_Store::table_name() . ' SET advance_chain_count = advance_chain_count + 1 WHERE job_id = %s', $job_id ) );

	wp_remote_post(
		self_advance_url(),
		array(
			'timeout'   => 0.5,
			'blocking'  => false,
			// Same reasoning as WP core's own spawn_cron(): a loopback
			// to this exact same server, over whatever scheme/port it's
			// actually configured with -- a self-signed or otherwise
			// non-publicly-trusted local cert here would otherwise make
			// every self-chained advance silently fail to even connect.
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => array(
				'action'  => 'wpthistle_backup_self_advance',
				'job_id'  => $job_id,
				'token'   => $job['advance_token'],
			),
		)
	);
}

/**
 * The loopback target for self_chain_advance() above. Defaults to this
 * site's own admin-ajax.php (admin_url() -- the real-world correct
 * choice: a production site's own configured siteurl/home IS the
 * address it can reach itself at). Overridable via the
 * WPTHISTLE_BACKUP_SELF_ADVANCE_URL constant or the
 * wpthistle_backup_self_advance_url filter -- needed by the Docker test
 * stack, where siteurl deliberately points at a host-side published
 * port (e.g. http://127.0.0.1:8081) that is NOT reachable from inside
 * the container's own network namespace; see docker-compose.yml.
 */
function self_advance_url(): string {
	if ( defined( 'WPTHISTLE_BACKUP_SELF_ADVANCE_URL' ) && '' !== WPTHISTLE_BACKUP_SELF_ADVANCE_URL ) {
		return (string) WPTHISTLE_BACKUP_SELF_ADVANCE_URL;
	}
	return apply_filters( 'wpthistle_backup_self_advance_url', admin_url( 'admin-ajax.php' ) );
}

/**
 * Handles the self-chained loopback fired by self_chain_advance().
 * Deliberately NOT gated by current_user_can()/nonce (require_capability_and_nonce()
 * in class-admin.php is for the logged-in admin's own browser actions;
 * this request is never that) -- gated entirely by hash_equals()
 * against the job's own advance_token. No information leak on any
 * failure path: an unknown job_id, a job with no token, and a wrong
 * token all produce the exact same 403 response.
 */
function ajax_self_advance(): void {
	$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
	$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
	if ( '' === $job_id || '' === $token ) {
		status_header( 400 );
		exit;
	}
	$job = Job_Store::get( $job_id );
	$real_token = (string) ( $job['advance_token'] ?? '' );
	if ( null === $job || '' === $real_token || ! hash_equals( $real_token, $token ) ) {
		status_header( 403 );
		exit;
	}
	// Vex audit LOW-2: a terminal job's token stays valid until the
	// fixes above null it out on the next deploy, and self_chain_advance()'s
	// own chain cap is never consulted here (it only increments itself) --
	// an external caller with a valid token could otherwise replay a dead
	// job indefinitely, or drive a live one past WPTHISTLE_BACKUP_SELF_ADVANCE_MAX_CHAIN
	// entirely. Same terminal-status list cron_advance() itself checks.
	if ( in_array( $job['status'] ?? '', array( 'verified', 'failed', 'not_found' ), true ) ) {
		status_header( 204 );
		exit;
	}
	if ( (int) ( $job['advance_chain_count'] ?? 0 ) >= WPTHISTLE_BACKUP_SELF_ADVANCE_MAX_CHAIN ) {
		status_header( 204 );
		exit;
	}
	cron_advance( $job_id );
	status_header( 204 );
	exit;
}

} // end duplicate-install guard wrapper (if ( true ) above activate())
