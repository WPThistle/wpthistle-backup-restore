<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage, validation, and the constant-override escape hatch.
 * ADR 0002 (credential storage), ADR 0007 (CA bundle).
 *
 * No WordPress function calls outside get_option/update_option/defined/
 * constant/wp_generate_password, so this class is cheap to exercise in
 * unit tests with a minimal WP shim.
 */
class Settings {

	const OPTION_KEY    = 'wpthistle_backup_settings';
	const SITE_ID_KEY   = 'wpthistle_backup_site_id';
	const APP_PW_CONST  = 'WPTHISTLE_BACKUP_APP_PASSWORD';
	const CA_BUNDLE_CONST = 'WPTHISTLE_BACKUP_CA_BUNDLE';
	// 2.0.0 rename (WPGuard -> WPThistle): a wp-config.php defining the
	// OLD constant name predates this release and isn't rewritten by the
	// plugin cutover (that file is outside anything this plugin touches),
	// so both the lock check and the read fall back to it rather than
	// silently un-locking a credential an admin deliberately pinned.
	const LEGACY_APP_PW_CONST    = 'WPGUARD_NC_APP_PASSWORD';
	const LEGACY_CA_BUNDLE_CONST = 'WPGUARD_NC_CA_BUNDLE';

	/**
	 * Phase 4 (day-based scheduling, replaces the old Disabled/Daily/
	 * Weekly dropdown, and -- as of Phase 5 -- the exact HH:MM slot that
	 * briefly replaced it): schedule_days is a list of ints 0 (Sunday)
	 * .. 6 (Saturday), PHP DateTime's own 'w' convention -- an empty
	 * array means disabled, chosen as the default (same reasoning the
	 * old 'disabled' default had) so activating or updating the plugin
	 * never silently starts recurring jobs an admin didn't ask for.
	 * There is deliberately no time-of-day field any more: an exact
	 * instant still needed a request to land near that one moment for
	 * WP-Cron's pseudo-cron to ever pick it up, which a genuinely quiet
	 * site could miss entirely. See Daily_Trigger and
	 * maybe_run_daily_backup() in the main plugin file -- any request at
	 * any time on a selected day is now early enough.
	 */
	const DEFAULT_RETENTION_DAILY = 7;
	const DEFAULT_RETENTION_WEEKLY = 4;
	const DEFAULT_RETENTION_MONTHLY = 12;

	/**
	 * Phase 6 (backup plan dropdown): replaces the Phase 5 state where
	 * tiered retention was its own independent checkbox that could be
	 * combined with day-of-week selection in a way that didn't make
	 * sense (e.g. "tiered daily retention" while only backing up two
	 * days a week isn't really daily retention at all). Now a single
	 * choice: 'scheduled' (an admin-picked cadence -- daily day-of-week,
	 * or monthly day-of-month -- plus the simple keep-last-N count) or
	 * 'tiered' (backs up every day, no day picker, GFS-style daily/
	 * weekly/monthly keep-counts instead of one flat count). See
	 * get_schedule_mode()/the migration note in get_all().
	 */
	const SCHEDULE_MODES = array( 'scheduled', 'tiered' );
	const SCHEDULE_CADENCES = array( 'daily', 'monthly' );

	/**
	 * Phase 3 (multi-backend): Nextcloud_Client is already a generic
	 * client for this shared WebDAV dialect (confirmed live against
	 * owncloud/server:10.15 and opencloud 7.2.4 via
	 * tests/e2e/run-e2e-multi-backend.sh; see STATUS.md and
	 * docs/threat-model.md T22), so no backend-specific branching exists
	 * anywhere in the transport layer. get_backend() has no consumer
	 * yet (security audit, 2026-10-21): the admin UI and error/diagnostic
	 * messages already use backend-neutral combined wording ("app
	 * password / app token", "the server") that covers all three
	 * backends without reading this value at all. Stored for the admin's
	 * own record and as the hook point should per-backend wording or doc
	 * links ever be added later; currently display-inert. Default stays
	 * 'nextcloud' either way.
	 */
	const BACKEND_CHOICES = array( 'nextcloud', 'owncloud', 'opencloud' );

	/** Raw stored option (excludes constant-overridden fields' stored value when locked). */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = array(
			'backend'        => 'nextcloud',
			'nextcloud_url'  => '',
			'nextcloud_user' => '',
			'app_password'   => '',
			'remote_folder'  => '',
			'ca_bundle_path' => '',
			'schedule_days'  => array(),
			'schedule_cadence'      => 'daily',
			'schedule_day_of_month' => 1,
			'retention_count' => 0,
			// Tiered (GFS-style) retention, Phase 5: OFF by default
			// (retention_tiered_enabled false) regardless of these
			// numbers being pre-filled -- an existing install upgrading
			// into this must never start deleting backups it wasn't
			// already deleting, same "admin must explicitly opt in"
			// discipline retention_count itself has always had.
			// retention_count (above) is untouched and keeps working
			// exactly as before for anyone already using it; the two
			// schemes are mutually exclusive -- see
			// Job_Orchestrator::maybe_prune_old_backups().
			'retention_tiered_enabled' => false,
			'retention_daily'   => self::DEFAULT_RETENTION_DAILY,
			'retention_weekly'  => self::DEFAULT_RETENTION_WEEKLY,
			'retention_monthly' => self::DEFAULT_RETENTION_MONTHLY,
			'incremental_enabled' => false,
		);
		$merged = array_merge( $defaults, $stored );
		// Phase 6 migration: schedule_mode didn't exist before this
		// release -- an install upgrading from Phase 5, where tiered
		// retention was its own independent checkbox, must land on
		// whichever mode it was already effectively using (that
		// checkbox's last saved value), not silently reset to
		// 'scheduled' and lose an explicit prior opt-in. A fresh install
		// has no stored retention_tiered_enabled either, so this
		// correctly lands on the safe 'scheduled' default.
		if ( ! isset( $stored['schedule_mode'] ) ) {
			$merged['schedule_mode'] = ! empty( $merged['retention_tiered_enabled'] ) ? 'tiered' : 'scheduled';
		}
		return $merged;
	}

	/**
	 * Phase 2 (incremental backups), default false: an unchanged file
	 * (matched against the previous chain backup's manifest by size and
	 * mtime -- see Backup_Chain) is referenced rather than re-archived
	 * and re-uploaded. Defaults off for the same reason schedule
	 * defaults to 'disabled' and retention_count to 0 -- nothing about
	 * an existing site's backup/restore story changes unless an admin
	 * explicitly opts in.
	 */
	public static function get_incremental_enabled(): bool {
		return (bool) self::get_all()['incremental_enabled'];
	}

	public static function get_backend(): string {
		$value = (string) self::get_all()['backend'];
		return in_array( $value, self::BACKEND_CHOICES, true ) ? $value : 'nextcloud';
	}

	/** Sorted, de-duplicated list of ints 0 (Sunday) .. 6 (Saturday); empty = disabled. */
	public static function get_schedule_days(): array {
		$raw = self::get_all()['schedule_days'];
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $day ) {
			$day = is_numeric( $day ) ? (int) $day : -1;
			if ( $day >= 0 && $day <= 6 && ! in_array( $day, $clean, true ) ) {
				$clean[] = $day;
			}
		}
		sort( $clean );
		return $clean;
	}

	/**
	 * 0 = keep every backup forever (default -- pruning is destructive,
	 * so an admin must explicitly opt into a number rather than this
	 * plugin silently deleting anything on activation/update).
	 */
	public static function get_retention_count(): int {
		$value = self::get_all()['retention_count'];
		$int = is_numeric( $value ) ? (int) $value : 0;
		return max( 0, $int );
	}

	/** 'scheduled' (default) or 'tiered' -- see SCHEDULE_MODES' docblock above. */
	public static function get_schedule_mode(): string {
		$value = (string) self::get_all()['schedule_mode'];
		return in_array( $value, self::SCHEDULE_MODES, true ) ? $value : 'scheduled';
	}

	/** 'daily' (default) or 'monthly' -- only consulted while get_schedule_mode() is 'scheduled'. */
	public static function get_schedule_cadence(): string {
		$value = (string) self::get_all()['schedule_cadence'];
		return in_array( $value, self::SCHEDULE_CADENCES, true ) ? $value : 'daily';
	}

	/**
	 * @return int|string An int 1-31 (exact day-of-month -- a month
	 *   shorter than that day has no match that month, e.g. 31 in
	 *   April), or the string 'last' (always matches whatever the final
	 *   calendar day of the current month actually is). Only consulted
	 *   while get_schedule_cadence() is 'monthly'.
	 */
	public static function get_schedule_day_of_month() {
		$value = self::get_all()['schedule_day_of_month'];
		if ( 'last' === $value ) {
			return 'last';
		}
		$int = is_numeric( $value ) ? (int) $value : 1;
		return ( $int >= 1 && $int <= 31 ) ? $int : 1;
	}

	/** Derived from get_schedule_mode() -- false means the simple keep-last-N scheme is what runs, if anything -- see maybe_prune_old_backups(). */
	public static function get_retention_tiered_enabled(): bool {
		return 'tiered' === self::get_schedule_mode();
	}

	/** @return array{daily:int, weekly:int, monthly:int} Non-negative bucket counts; a non-numeric/negative stored value clamps to 0 for that tier. */
	public static function get_retention_tiers(): array {
		$all = self::get_all();
		$clamp = static function ( $v ): int {
			return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
		};
		return array(
			'daily'   => $clamp( $all['retention_daily'] ),
			'weekly'  => $clamp( $all['retention_weekly'] ),
			'monthly' => $clamp( $all['retention_monthly'] ),
		);
	}

	/** Which defined constant name is actually locking the app password, '' if neither is. */
	private static function active_app_pw_const(): string {
		if ( defined( self::APP_PW_CONST ) && constant( self::APP_PW_CONST ) !== '' ) {
			return self::APP_PW_CONST;
		}
		if ( defined( self::LEGACY_APP_PW_CONST ) && constant( self::LEGACY_APP_PW_CONST ) !== '' ) {
			return self::LEGACY_APP_PW_CONST;
		}
		return '';
	}

	/** Which defined constant name is actually locking the CA bundle path, '' if neither is. */
	private static function active_ca_bundle_const(): string {
		if ( defined( self::CA_BUNDLE_CONST ) && constant( self::CA_BUNDLE_CONST ) !== '' ) {
			return self::CA_BUNDLE_CONST;
		}
		if ( defined( self::LEGACY_CA_BUNDLE_CONST ) && constant( self::LEGACY_CA_BUNDLE_CONST ) !== '' ) {
			return self::LEGACY_CA_BUNDLE_CONST;
		}
		return '';
	}

	public static function is_app_password_locked(): bool {
		return '' !== self::active_app_pw_const();
	}

	public static function is_ca_bundle_locked(): bool {
		return '' !== self::active_ca_bundle_const();
	}

	public static function get_app_password(): string {
		$const = self::active_app_pw_const();
		if ( '' !== $const ) {
			return (string) constant( $const );
		}
		return (string) self::get_all()['app_password'];
	}

	public static function get_ca_bundle_path(): string {
		$const = self::active_ca_bundle_const();
		if ( '' !== $const ) {
			return (string) constant( $const );
		}
		return (string) self::get_all()['ca_bundle_path'];
	}

	public static function get_nextcloud_url(): string {
		return (string) self::get_all()['nextcloud_url'];
	}

	public static function get_nextcloud_user(): string {
		return (string) self::get_all()['nextcloud_user'];
	}

	public static function get_remote_folder(): string {
		return (string) self::get_all()['remote_folder'];
	}

	public static function has_credentials(): bool {
		return '' !== self::get_nextcloud_url()
			&& '' !== self::get_nextcloud_user()
			&& '' !== self::get_app_password();
	}

	/**
	 * Validate and persist admin-submitted settings.
	 *
	 * @param array $input Raw $_POST-derived values (already slashes-stripped).
	 * @return array{ok:bool, errors:array<string,string>}
	 */
	public static function save( array $input ): array {
		$errors  = array();
		$current = self::get_all();
		$clean   = array();

		// Always a <select> with a fixed option list in the admin UI (same
		// tolerance as schedule below) -- wording/doc-link hint only, so an
		// unrecognized value falls back to the safe, pre-existing default
		// rather than erroring the whole save.
		$backend = isset( $input['backend'] ) ? (string) $input['backend'] : 'nextcloud';
		$clean['backend'] = in_array( $backend, self::BACKEND_CHOICES, true ) ? $backend : 'nextcloud';

		$url = isset( $input['nextcloud_url'] ) ? trim( (string) $input['nextcloud_url'] ) : '';
		$url = rtrim( $url, '/' );
		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
			$errors['nextcloud_url'] = __( 'Nextcloud URL must start with https:// — plain HTTP is not permitted.', 'wpthistle-backup-restore' );
		} elseif ( ! self::is_syntactically_valid_destination_url( $url ) ) {
			$errors['nextcloud_url'] = __( 'That does not look like a valid URL.', 'wpthistle-backup-restore' );
		} else {
			$clean['nextcloud_url'] = sanitize_text_field( $url );
		}

		$user = isset( $input['nextcloud_user'] ) ? sanitize_text_field( trim( (string) $input['nextcloud_user'] ) ) : '';
		if ( '' === $user ) {
			$errors['nextcloud_user'] = __( 'Nextcloud username is required.', 'wpthistle-backup-restore' );
		} else {
			$clean['nextcloud_user'] = $user;
		}

		$folder = isset( $input['remote_folder'] ) ? trim( (string) $input['remote_folder'] ) : '';
		$folder = trim( $folder, '/' );
		if ( '' === $folder ) {
			$errors['remote_folder'] = __( 'Destination folder is required.', 'wpthistle-backup-restore' );
		} elseif ( ! preg_match( '#^[A-Za-z0-9._/-]+$#', $folder ) || false !== strpos( $folder, '..' ) ) {
			$errors['remote_folder'] = __( 'Destination folder may only contain letters, numbers, dots, dashes, underscores, and / as a separator, and may not contain ".."', 'wpthistle-backup-restore' );
		} else {
			$clean['remote_folder'] = $folder;
		}

		// App password: blank submission means "leave unchanged" (never clears it).
		if ( self::is_app_password_locked() ) {
			// security audit finding H-02: purge any previously option-stored value once
			// the constant override takes over. Leaving the old value in
			// wp_options meant switching to the "more secure" constant path
			// didn't actually revoke the old credential from the database.
			$clean['app_password'] = '';
		} else {
			$submitted = isset( $input['app_password'] ) ? (string) $input['app_password'] : '';
			$clean['app_password'] = ( '' === $submitted ) ? $current['app_password'] : $submitted;
		}

		// CA bundle path: optional, validated against the filesystem if provided.
		if ( self::is_ca_bundle_locked() ) {
			$clean['ca_bundle_path'] = $current['ca_bundle_path'];
		} else {
			$ca_path = isset( $input['ca_bundle_path'] ) ? trim( (string) $input['ca_bundle_path'] ) : '';
			if ( '' === $ca_path ) {
				$clean['ca_bundle_path'] = '';
			} else {
				$validation = CA_Bundle::validate_path( $ca_path );
				if ( true !== $validation ) {
					$errors['ca_bundle_path'] = $validation;
				} else {
					$clean['ca_bundle_path'] = $ca_path;
				}
			}
		}

		// schedule_days: a fixed set of checkboxes (values 0-6) in the
		// admin UI -- same clamp-not-fail tolerance as the old schedule
		// dropdown. An absent/malformed entry is simply dropped rather
		// than erroring the whole save; an empty result means disabled,
		// same as before.
		$days_raw = isset( $input['schedule_days'] ) && is_array( $input['schedule_days'] ) ? $input['schedule_days'] : array();
		$days_clean = array();
		foreach ( $days_raw as $day ) {
			$day = is_numeric( $day ) ? (int) $day : -1;
			if ( $day >= 0 && $day <= 6 && ! in_array( $day, $days_clean, true ) ) {
				$days_clean[] = $day;
			}
		}
		sort( $days_clean );
		$clean['schedule_days'] = $days_clean;

		// Phase 6: the backup-plan dropdown and its cadence sub-choice.
		// Both are always a fixed <select> in the admin UI -- same
		// clamp-not-fail tolerance as backend/schedule above.
		$mode = isset( $input['schedule_mode'] ) ? (string) $input['schedule_mode'] : 'scheduled';
		$clean['schedule_mode'] = in_array( $mode, self::SCHEDULE_MODES, true ) ? $mode : 'scheduled';

		$cadence = isset( $input['schedule_cadence'] ) ? (string) $input['schedule_cadence'] : 'daily';
		$clean['schedule_cadence'] = in_array( $cadence, self::SCHEDULE_CADENCES, true ) ? $cadence : 'daily';

		// schedule_day_of_month: an int 1-31, or the literal string
		// 'last' ("Last Day of the Month" option) -- same clamp-not-fail
		// tolerance; an invalid value falls back to the 1st rather than
		// erroring the whole save.
		$dom_raw = isset( $input['schedule_day_of_month'] ) ? $input['schedule_day_of_month'] : 1;
		if ( 'last' === $dom_raw ) {
			$clean['schedule_day_of_month'] = 'last';
		} else {
			$dom_int = is_numeric( $dom_raw ) ? (int) $dom_raw : 1;
			$clean['schedule_day_of_month'] = ( $dom_int >= 1 && $dom_int <= 31 ) ? $dom_int : 1;
		}

		// Retention count: 0 or a non-negative integer. Same
		// non-hard-fail tolerance as schedule above -- clamps to the
		// safe default (0 = keep all) rather than erroring the save,
		// since this is also always a constrained <input type=number
		// min=0> in the admin UI, not free text.
		$retention_raw = isset( $input['retention_count'] ) ? $input['retention_count'] : 0;
		$clean['retention_count'] = is_numeric( $retention_raw ) ? max( 0, (int) $retention_raw ) : 0;

		// Tiered retention: Phase 6 removed the independent checkbox --
		// this is now derived entirely from schedule_mode above (stored
		// too, purely so an old install's last value is still legible/
		// greppable and get_all()'s migration check above keeps working
		// for any row saved under this version or later).
		$clean['retention_tiered_enabled'] = ( 'tiered' === $clean['schedule_mode'] );
		// Same clamp-to-0 convention as retention_count above (never a
		// per-tier "default" like 7/4/12 on garbage input -- 0 is the
		// one value that's unambiguously safe regardless of what the
		// admin actually intended).
		$tier_clamp = static function ( $v ): int {
			return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
		};
		$clean['retention_daily'] = isset( $input['retention_daily'] ) ? $tier_clamp( $input['retention_daily'] ) : self::DEFAULT_RETENTION_DAILY;
		$clean['retention_weekly'] = isset( $input['retention_weekly'] ) ? $tier_clamp( $input['retention_weekly'] ) : self::DEFAULT_RETENTION_WEEKLY;
		$clean['retention_monthly'] = isset( $input['retention_monthly'] ) ? $tier_clamp( $input['retention_monthly'] ) : self::DEFAULT_RETENTION_MONTHLY;

		// Incremental backups: a plain checkbox, so any truthy submission
		// means "on" and an absent field (checkbox unchecked -- browsers
		// never submit an unchecked checkbox at all) means "off". There is
		// no "unrecognized value" case to clamp here, unlike schedule/
		// retention_count above.
		$clean['incremental_enabled'] = ! empty( $input['incremental_enabled'] );

		if ( ! empty( $errors ) ) {
			return array( 'ok' => false, 'errors' => $errors );
		}

		// ADR 0010: Nextcloud_Client::supports_chunked_upload_v2()
		// caches its probe result for a day, keyed by server URL --
		// invalidate both the old and new URL's cache on every save so
		// switching servers (or the same server gaining/losing support
		// across an upgrade) never has to wait out a stale cached probe.
		delete_transient( 'wpthistle_backup_chunk_v2_support_' . md5( $current['nextcloud_url'] ?? '' ) );
		delete_transient( 'wpthistle_backup_chunk_v2_support_' . md5( $clean['nextcloud_url'] ?? '' ) );

		update_option( self::OPTION_KEY, $clean, false );
		return array( 'ok' => true, 'errors' => array() );
	}

	/**
	 * "Forget Connection": clears only the six connection fields (backend,
	 * URL, username, app password, destination folder, CA bundle path),
	 * leaving schedule/retention/incremental settings exactly as they
	 * were. Deliberately NOT routed through save() above -- that method
	 * requires nextcloud_url/nextcloud_user/remote_folder to be non-empty
	 * and would reject this outright, and every one of its other fields
	 * falls back to a hardcoded default (not the current value) when
	 * absent from its $input, which would silently reset schedule/
	 * retention/incremental too if this just called save() with those
	 * fields omitted.
	 *
	 * A constant-locked app password isn't actually stored (already
	 * blanked at save() time, H-02); a constant-locked CA bundle path
	 * IS still preserved in wp_options by save() (see is_ca_bundle_locked()
	 * above), so this is the first thing that ever blanks it there.
	 * Either way the constant itself still wins on every read regardless
	 * of what's written here (Vex security audit, 2026-11-01, VX-1) --
	 * this only ever clears what's in wp_options, never the constant.
	 */
	public static function forget_connection(): void {
		$current = self::get_all();
		$clean   = array_merge(
			$current,
			array(
				'backend'        => 'nextcloud',
				'nextcloud_url'  => '',
				'nextcloud_user' => '',
				'app_password'   => '',
				'remote_folder'  => '',
				'ca_bundle_path' => '',
			)
		);
		delete_transient( 'wpthistle_backup_chunk_v2_support_' . md5( $current['nextcloud_url'] ?? '' ) );
		update_option( self::OPTION_KEY, $clean, false );
	}

	/**
	 * Deliberately NOT wp_http_validate_url(): that function's default
	 * private-IP/hostname blocklist (SSRF hardening meant for fetching
	 * arbitrary third-party URLs) would reject exactly the self-hosted,
	 * private-network Nextcloud instances this plugin exists to support
	 * (NFR-03: "restrict network targets as practical while supporting
	 * private Nextcloud"; threat-model T5 accepts this as an admin-only,
	 * fully trusted, explicitly-configured destination, not a general
	 * fetcher). This only checks that it's syntactically a URL with a
	 * host and no embedded credentials/control characters.
	 */
	private static function is_syntactically_valid_destination_url( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return false;
		}
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return false;
		}
		if ( false !== strpbrk( $parsed['host'], ":#?[]" ) ) {
			return false;
		}
		return true;
	}

	public static function ensure_site_id(): string {
		$existing = get_option( self::SITE_ID_KEY, '' );
		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'site';
		$slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) );
		$slug = trim( $slug, '-' );
		$suffix = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		$site_id = $slug . '-' . $suffix;
		update_option( self::SITE_ID_KEY, $site_id, true );
		return $site_id;
	}

	public static function get_site_id(): string {
		$id = get_option( self::SITE_ID_KEY, '' );
		return is_string( $id ) && '' !== $id ? $id : self::ensure_site_id();
	}

	/**
	 * Strips any line defining the app-password or CA-bundle constants
	 * (ADR 0002's escape hatch) out of wp-config.php content before it is
	 * archived (security audit finding H-02) — a backup that depends on the
	 * credential protecting it must not also contain that credential.
	 * Pure string transform, no I/O, so the orchestrator handles reading
	 * the real file and writing the redacted copy to the job's tmp dir.
	 */
	/**
	 * Matches one full PHP single- or double-quoted string literal
	 * (escape-aware), for use as define()'s second argument in a
	 * redaction regex.
	 *
	 * security audit finding NEW-MEDIUM-1 (re-verification, 2026-10-21): the previous
	 * `.*?` here was non-greedy, so it matched only up to the FIRST
	 * `);` anywhere in the value. Proven live: a credential value
	 * containing its own literal `);` fragment (plausible -- some
	 * hosting panels generate passwords from the full printable-ASCII
	 * set) left everything after that point in the archive unredacted,
	 * AND defeated the fail-closed strpos() re-check downstream in
	 * Job_Orchestrator::redacted_wp_config_copy(), since that check
	 * searches for the FULL secret string, which is no longer present
	 * once part of it was consumed by the partial match -- a redaction
	 * failure that silently looked like success. A real quoted-string
	 * match (tracking the actual quote/escape boundary, not "stop at
	 * the next close-paren") cannot be fooled by an inner `);` the same
	 * way. Written as a nowdoc specifically to avoid manually counting
	 * PHP-string-escape-of-regex-escape backslashes -- what's between
	 * the markers is the literal regex fragment, verbatim.
	 */
	private static function quoted_string_value_pattern(): string {
		return <<<'REGEX'
(?:'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")
REGEX;
	}

	public static function redact_wp_config_contents( string $content ): string {
		// 2.0.0 rename: must cover every constant name
		// active_app_pw_const()/active_ca_bundle_const() can read, not
		// just the current ones -- a site whose wp-config.php still
		// defines the LEGACY name (the exact scenario that fallback
		// exists for) would otherwise archive that line, plaintext
		// credential and all, completely unredacted.
		$names = preg_quote( self::APP_PW_CONST, '/' ) . '|' . preg_quote( self::CA_BUNDLE_CONST, '/' )
			. '|' . preg_quote( self::LEGACY_APP_PW_CONST, '/' ) . '|' . preg_quote( self::LEGACY_CA_BUNDLE_CONST, '/' );
		$pattern = '/define\s*\(\s*[\'"](?:' . $names . ')[\'"]\s*,\s*' . self::quoted_string_value_pattern() . '\s*\)\s*;/';
		return preg_replace( $pattern, '// wpthistle-backup-restore: line redacted from backup archive (credential constant).', $content );
	}

	/**
	 * Phase 3 (downloadable backups), security audit finding MEDIUM-DL-1: a backup
	 * destined for Nextcloud stays inside storage the admin already
	 * controls (documented, accepted residual exposure -- threat-model
	 * T9); a downloadable bundle leaves that custody model entirely the
	 * moment it lands in the admin's own Downloads folder, where it then
	 * also sits on the server itself for up to 24h (MEDIUM-DL-2/sweep
	 * TTL). redact_wp_config_contents() only ever stripped this plugin's
	 * OWN constants, never the site's real DB credentials -- proven live
	 * by the audit: a seeded DB_PASSWORD survived into the archived
	 * wp-config.php unchanged. Applied only to download-destination
	 * bundles (Job_Orchestrator::step_export_files()), never the
	 * Nextcloud path, to avoid silently changing that already-documented,
	 * already-accepted behavior. Deliberately does NOT strip
	 * AUTH_KEY/AUTH_SALT/etc or any other constant -- WP 2FA and
	 * encryption plugins store real recovery material keyed to those
	 * values, and stripping them would make a legitimate same-host
	 * restore from a downloaded bundle silently lose that data. The admin
	 * must re-enter DB_NAME/DB_USER/DB_PASSWORD/DB_HOST by hand when
	 * restoring a downloaded bundle onto different infrastructure --
	 * documented in docs/operations.md and docs/restore-manual.md.
	 */
	public static function redact_wp_config_db_credentials( string $content ): string {
		$db_consts = array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' );
		$names = implode( '|', array_map( static fn( $c ) => preg_quote( $c, '/' ), $db_consts ) );
		$pattern = '/define\s*\(\s*[\'"](?:' . $names . ')[\'"]\s*,\s*' . self::quoted_string_value_pattern() . '\s*\)\s*;/';
		return preg_replace( $pattern, '// wpthistle-backup-restore: line redacted from downloadable bundle (DB credential constant -- re-enter manually when restoring elsewhere).', $content );
	}
}
