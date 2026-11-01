<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Settings;

function test_rejects_plain_http_url() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'http://example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups' ) );
	wpguard_assert( false === $result['ok'], 'plain http:// must be rejected' );
	wpguard_assert( isset( $result['errors']['nextcloud_url'] ), 'error must be reported on the nextcloud_url field' );
}

function test_accepts_https_url() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'valid https:// url with required fields must be accepted' );
}

function test_rejects_folder_traversal() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => '../../etc', 'app_password' => 'pw' ) );
	wpguard_assert( false === $result['ok'], 'remote_folder containing .. must be rejected' );
}

function test_blank_app_password_keeps_existing_value() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'existing-secret', 'ca_bundle_path' => '' ),
	);
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => '' ) );
	wpguard_assert( true === $result['ok'], 'save with blank password should succeed' );
	wpguard_assert_equals( 'existing-secret', Settings::get_app_password(), 'blank app_password submission must not clear the stored value' );
}

function test_rejects_missing_username() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => '', 'remote_folder' => 'backups' ) );
	wpguard_assert( false === $result['ok'], 'missing username must be rejected' );
}

// Phase 4 (day/time scheduling). The admin UI is always a fixed
// checkbox group (0-6) plus an <input type=time>, so these cover: both
// fields omitted entirely (an older stored option, or a non-UI caller),
// valid values, and tampering / a future form mismatch -- none of these
// should ever hard-fail a save, since scheduling is optional and must
// degrade to the safe (disabled) default.
function test_schedule_defaults_to_disabled_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without schedule fields must still succeed' );
	wpguard_assert_equals( array(), Settings::get_schedule_days(), 'omitted schedule_days must default to empty (disabled)' );
}

function test_accepts_valid_schedule_days() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_days' => array( 2, 5 ) ) );
	wpguard_assert( true === $result['ok'], 'valid schedule_days must be accepted' );
	wpguard_assert_equals( array( 2, 5 ), Settings::get_schedule_days(), 'the valid schedule_days must be stored and read back, sorted' );
}

function test_schedule_days_deduplicates_and_sorts() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_days' => array( 5, 1, 5, 1 ) ) );
	wpguard_assert( true === $result['ok'], 'duplicate schedule_days must not hard-fail the save' );
	wpguard_assert_equals( array( 1, 5 ), Settings::get_schedule_days(), 'duplicate days must be deduplicated and sorted ascending' );
}

function test_rejects_out_of_range_schedule_day_without_failing_save() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_days' => array( 1, 9, -1, 'not-a-day' ) ) );
	wpguard_assert( true === $result['ok'], 'an out-of-range schedule_days entry must not hard-fail the whole save' );
	wpguard_assert_equals( array( 1 ), Settings::get_schedule_days(), 'only the in-range (0-6) entries must survive' );
}

// Phase 5 (tiered retention). Off by default regardless of the numbers
// shown/stored -- an existing install, or a fresh one that never
// touches this section, must never start deleting backups via this
// path. The simple retention_count field above is untouched by any of
// this and keeps working exactly as before.
function test_tiered_retention_defaults_to_disabled_with_prefilled_numbers() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without any tiered-retention fields must still succeed' );
	wpguard_assert( false === Settings::get_retention_tiered_enabled(), 'tiered retention must default to disabled' );
	wpguard_assert_equals( array( 'daily' => 7, 'weekly' => 4, 'monthly' => 12 ), Settings::get_retention_tiers(), 'the pre-filled defaults must still be 7/4/12 even while disabled' );
}

function test_accepts_valid_tiered_retention_settings() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array(
		'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw',
		'schedule_mode' => 'tiered', 'retention_daily' => 5, 'retention_weekly' => 2, 'retention_monthly' => 6,
	) );
	wpguard_assert( true === $result['ok'], 'valid tiered retention settings must be accepted' );
	wpguard_assert( true === Settings::get_retention_tiered_enabled(), 'the enabled flag must be derived true from schedule_mode=tiered' );
	wpguard_assert_equals( array( 'daily' => 5, 'weekly' => 2, 'monthly' => 6 ), Settings::get_retention_tiers(), 'the valid tier counts must be stored and read back' );
}

function test_tiered_retention_negative_or_non_numeric_clamps_to_zero() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array(
		'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw',
		'schedule_mode' => 'tiered', 'retention_daily' => -3, 'retention_weekly' => 'not-a-number', 'retention_monthly' => 9,
	) );
	wpguard_assert( true === $result['ok'], 'an invalid tier count must not hard-fail the whole save' );
	wpguard_assert_equals( array( 'daily' => 0, 'weekly' => 0, 'monthly' => 9 ), Settings::get_retention_tiers(), 'negative/non-numeric tier counts must clamp to 0, valid ones pass through' );
}

// Phase 6 (backup plan dropdown): replaces the standalone
// retention_tiered_enabled checkbox -- schedule_mode is now the single
// control, always a fixed <select> in the admin UI, same clamp-not-fail
// tolerance as backend/schedule above.
function test_schedule_mode_defaults_to_scheduled_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a schedule_mode field must still succeed' );
	wpguard_assert_equals( 'scheduled', Settings::get_schedule_mode(), 'an omitted schedule_mode must default to scheduled' );
	wpguard_assert( false === Settings::get_retention_tiered_enabled(), 'the derived tiered flag must be false under the default mode' );
}

function test_schedule_mode_rejects_unrecognized_value_without_failing_save() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_mode' => 'weekly-ish' ) );
	wpguard_assert( true === $result['ok'], 'an unrecognized schedule_mode must not hard-fail the whole save' );
	wpguard_assert_equals( 'scheduled', Settings::get_schedule_mode(), 'an unrecognized schedule_mode must clamp to the safe default, not be stored as-is' );
}

function test_schedule_cadence_defaults_to_daily_and_accepts_monthly() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a schedule_cadence field must still succeed' );
	wpguard_assert_equals( 'daily', Settings::get_schedule_cadence(), 'an omitted schedule_cadence must default to daily' );

	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_cadence' => 'monthly' ) );
	wpguard_assert( true === $result['ok'], 'a valid schedule_cadence must be accepted' );
	wpguard_assert_equals( 'monthly', Settings::get_schedule_cadence(), 'schedule_cadence=monthly must be stored and read back' );
}

function test_schedule_cadence_rejects_unrecognized_value_without_failing_save() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_cadence' => 'biweekly' ) );
	wpguard_assert( true === $result['ok'], 'an unrecognized schedule_cadence must not hard-fail the whole save' );
	wpguard_assert_equals( 'daily', Settings::get_schedule_cadence(), 'an unrecognized schedule_cadence must clamp to the safe default' );
}

function test_schedule_day_of_month_defaults_to_first_and_accepts_last() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a schedule_day_of_month field must still succeed' );
	wpguard_assert_equals( 1, Settings::get_schedule_day_of_month(), 'an omitted schedule_day_of_month must default to the 1st' );

	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_day_of_month' => 'last' ) );
	wpguard_assert( true === $result['ok'], 'schedule_day_of_month=last must be accepted' );
	wpguard_assert_equals( 'last', Settings::get_schedule_day_of_month(), '"last" must be stored and read back as the literal string' );

	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_day_of_month' => 17 ) );
	wpguard_assert( true === $result['ok'], 'a valid numeric schedule_day_of_month must be accepted' );
	wpguard_assert_equals( 17, Settings::get_schedule_day_of_month(), 'a valid day-of-month must be stored and read back' );
}

function test_schedule_day_of_month_out_of_range_clamps_to_first_without_failing_save() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'schedule_day_of_month' => 45 ) );
	wpguard_assert( true === $result['ok'], 'an out-of-range schedule_day_of_month must not hard-fail the whole save' );
	wpguard_assert_equals( 1, Settings::get_schedule_day_of_month(), 'an out-of-range schedule_day_of_month must clamp to the 1st' );
}

// Phase 6 migration: an install that saved under Phase 5 (standalone
// retention_tiered_enabled checkbox, no schedule_mode key at all in the
// stored option) must land on 'tiered' once it upgrades, not silently
// reset to 'scheduled' and lose that explicit prior opt-in.
function test_legacy_tiered_enabled_without_schedule_mode_migrates_to_tiered_mode() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array(
			'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw',
			'retention_tiered_enabled' => true, 'retention_daily' => 5, 'retention_weekly' => 2, 'retention_monthly' => 6,
		),
	);
	wpguard_assert_equals( 'tiered', Settings::get_schedule_mode(), 'a pre-Phase-6 row with retention_tiered_enabled=true must read back as schedule_mode=tiered' );
}

function test_legacy_tiered_disabled_without_schedule_mode_migrates_to_scheduled_mode() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array(
			'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw',
			'retention_tiered_enabled' => false, 'schedule_days' => array( 2 ),
		),
	);
	wpguard_assert_equals( 'scheduled', Settings::get_schedule_mode(), 'a pre-Phase-6 row with retention_tiered_enabled=false must read back as schedule_mode=scheduled' );
	wpguard_assert_equals( array( 2 ), Settings::get_schedule_days(), 'its existing day selection must survive the migration untouched' );
}

// Phase 2 (retention). Pruning is destructive (permanently deletes
// backups), so these cover: the field is omitted (must default to 0 =
// keep-all, never silently enable deletion), a valid positive count,
// and a negative/non-numeric value clamping to 0 rather than erroring
// or being stored as something that could underflow a count elsewhere.
function test_retention_defaults_to_zero_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a retention_count field must still succeed' );
	wpguard_assert_equals( 0, Settings::get_retention_count(), 'an omitted retention_count must default to 0 (keep all)' );
}

function test_accepts_valid_retention_count() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'retention_count' => 14 ) );
	wpguard_assert( true === $result['ok'], 'a valid retention_count must be accepted' );
	wpguard_assert_equals( 14, Settings::get_retention_count(), 'the valid retention_count must be stored and read back' );
}

function test_rejects_negative_retention_count() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'retention_count' => -5 ) );
	wpguard_assert( true === $result['ok'], 'a negative retention_count must not hard-fail the whole save' );
	wpguard_assert_equals( 0, Settings::get_retention_count(), 'a negative retention_count must clamp to 0, not be stored as negative' );
}

// Phase 2 (incremental backups). A plain checkbox: must default to off
// (never silently start skipping files on an existing site after an
// update), accept an explicit on, and never hard-fail the save either
// way.
function test_incremental_defaults_to_disabled_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without an incremental_enabled field must still succeed' );
	wpguard_assert( false === Settings::get_incremental_enabled(), 'an omitted incremental_enabled field must default to disabled' );
}

function test_incremental_accepts_enabled() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'incremental_enabled' => '1' ) );
	wpguard_assert( true === $result['ok'], 'a truthy incremental_enabled must be accepted' );
	wpguard_assert( true === Settings::get_incremental_enabled(), 'incremental_enabled must be stored and read back as enabled' );
}

// Phase 3 (multi-backend): wording/doc-link hint only (Nextcloud_Client
// itself has no backend branching -- see its docblock) -- but still a
// fixed-choice <select>, same clamp-not-fail tolerance as schedule.
function test_backend_defaults_to_nextcloud_when_missing() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw' ) );
	wpguard_assert( true === $result['ok'], 'save without a backend field must still succeed' );
	wpguard_assert_equals( 'nextcloud', Settings::get_backend(), 'an omitted backend field must default to nextcloud' );
}

function test_backend_accepts_owncloud_and_opencloud() {
	foreach ( array( 'owncloud', 'opencloud' ) as $backend ) {
		$GLOBALS['__wpguard_test_options'] = array();
		$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'backend' => $backend ) );
		wpguard_assert( true === $result['ok'], "save with backend={$backend} must succeed" );
		wpguard_assert_equals( $backend, Settings::get_backend(), "backend={$backend} must be stored and read back unchanged" );
	}
}

function test_backend_rejects_unrecognized_value_without_failing_save() {
	$GLOBALS['__wpguard_test_options'] = array();
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'pw', 'backend' => 'dropbox' ) );
	wpguard_assert( true === $result['ok'], 'an unrecognized backend must not hard-fail the whole save' );
	wpguard_assert_equals( 'nextcloud', Settings::get_backend(), 'an unrecognized backend must clamp to the safe default, not be stored as-is' );
}

// security audit finding H-02 regression coverage. redact_wp_config_contents() is a
// pure string transform (no WP functions), safe to test directly.
function test_redact_wp_config_strips_credential_constants() {
	$content = "<?php\n"
		. "define('WP_DEBUG', false);\n"
		. "define('WPTHISTLE_BACKUP_APP_PASSWORD', 'super-secret-app-pw');\n"
		. "define('WPTHISTLE_BACKUP_CA_BUNDLE', '/path/to/ca.crt');\n"
		. "define('DB_NAME', 'wordpress');\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false === strpos( $redacted, 'super-secret-app-pw' ), 'app-password constant value must not survive redaction' );
	wpguard_assert( false === strpos( $redacted, '/path/to/ca.crt' ), 'ca-bundle constant value must not survive redaction' );
	wpguard_assert( false !== strpos( $redacted, "define('WP_DEBUG', false);" ), 'unrelated defines must be preserved verbatim' );
	wpguard_assert( false !== strpos( $redacted, "define('DB_NAME', 'wordpress');" ), 'unrelated defines must be preserved verbatim' );
}

// 2.0.0 rename: the legacy WPGUARD_NC_* constant names are still a
// supported fallback (Settings::active_app_pw_const()/
// active_ca_bundle_const()) for a site whose wp-config.php hasn't been
// updated to the new names yet -- redaction must cover them too, or
// exactly the site that fallback exists for archives its real
// credential unredacted.
function test_redact_wp_config_strips_legacy_credential_constants() {
	$content = "<?php\n"
		. "define('WP_DEBUG', false);\n"
		. "define('WPGUARD_NC_APP_PASSWORD', 'legacy-secret-app-pw');\n"
		. "define('WPGUARD_NC_CA_BUNDLE', '/legacy/path/to/ca.crt');\n"
		. "define('DB_NAME', 'wordpress');\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false === strpos( $redacted, 'legacy-secret-app-pw' ), 'legacy app-password constant value must not survive redaction' );
	wpguard_assert( false === strpos( $redacted, '/legacy/path/to/ca.crt' ), 'legacy ca-bundle constant value must not survive redaction' );
	wpguard_assert( false !== strpos( $redacted, "define('WP_DEBUG', false);" ), 'unrelated defines must be preserved verbatim' );
	wpguard_assert( false !== strpos( $redacted, "define('DB_NAME', 'wordpress');" ), 'unrelated defines must be preserved verbatim' );
}

// security audit finding LOW-R1: documents the regex's known limitation (no fix
// intended here — the fail-closed check in
// Job_Orchestrator::redacted_wp_config_copy() catches THIS shape at
// archive time, since the literal value string is still present
// verbatim in the source text for strpos() to find. Nuance from the
// security re-verification, 2026-10-21 (raised alongside NEW-MEDIUM-1): that
// backstop does NOT cover every unusual define() shape equally — a
// concatenated value (`define('DB_PASSWORD', 'abc' . 'def');`) defeats
// BOTH the regex AND the fail-closed check, since the runtime-joined
// value never appears as one contiguous substring in the source text
// either. Accepted as the same class of gap as this one (no fix
// intended) on the same basis: no real wp-config.php from any
// mainstream WordPress installer or hosting panel has ever been
// observed defining a credential constant via string concatenation.
function test_redact_wp_config_known_gap_const_syntax_not_caught() {
	$content = "<?php\nconst WPTHISTLE_BACKUP_APP_PASSWORD = 'leaks-through-const-syntax';\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false !== strpos( $redacted, 'leaks-through-const-syntax' ), 'documents the known gap: const syntax is not matched by the define()-shaped regex' );
}

// security audit finding NEW-MEDIUM-1 (re-verification, 2026-10-21): proven live -- the
// previous .*? pattern was non-greedy and stopped at the FIRST ');'
// anywhere in the value, so a credential containing its own literal
// ');' left everything after that point unredacted AND defeated the
// fail-closed strpos() re-check downstream (which looks for the FULL
// secret -- no longer present once part of it was consumed by the
// partial match). Covers both quote styles since the fix is shared by
// redact_wp_config_contents() and redact_wp_config_db_credentials().
function test_redact_wp_config_survives_value_containing_close_paren_semicolon() {
	$content = "<?php\ndefine('WPTHISTLE_BACKUP_APP_PASSWORD', 'first);remaining');\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false === strpos( $redacted, 'remaining' ), 'a value containing its own ");" must not leave a fragment unredacted' );
	wpguard_assert( false === strpos( $redacted, 'first' ), 'the whole value must be gone, not just the tail after the embedded ");"' );
}

function test_redact_wp_config_survives_double_quoted_value_containing_close_paren_semicolon() {
	$content = '<?php' . "\n" . 'define("WPTHISTLE_BACKUP_APP_PASSWORD", "first);remaining");' . "\n";
	$redacted = Settings::redact_wp_config_contents( $content );
	wpguard_assert( false === strpos( $redacted, 'remaining' ), 'a double-quoted value containing its own ");" must not leave a fragment unredacted' );
}

// Phase 3, security audit finding MEDIUM-DL-1: proven live against a real wp-config.php
// that this redaction did not previously exist at all -- a downloadable
// bundle's wp-config.php leaked the real DB_PASSWORD unchanged.
function test_redact_wp_config_db_credentials_strips_db_constants() {
	$content = "<?php\n"
		. "define('WP_DEBUG', false);\n"
		. "define('DB_NAME', 'wordpress');\n"
		. "define('DB_USER', 'wp_user');\n"
		. "define('DB_PASSWORD', 'real-db-secret');\n"
		. "define('DB_HOST', 'db.internal.example.com');\n"
		. "define('AUTH_KEY', 'leave-me-alone-auth-key');\n";
	$redacted = Settings::redact_wp_config_db_credentials( $content );
	wpguard_assert( false === strpos( $redacted, 'wordpress' ), 'DB_NAME value must not survive' );
	wpguard_assert( false === strpos( $redacted, 'wp_user' ), 'DB_USER value must not survive' );
	wpguard_assert( false === strpos( $redacted, 'real-db-secret' ), 'DB_PASSWORD value must not survive' );
	wpguard_assert( false === strpos( $redacted, 'db.internal.example.com' ), 'DB_HOST value must not survive' );
	wpguard_assert( false !== strpos( $redacted, "define('WP_DEBUG', false);" ), 'unrelated defines must be preserved verbatim' );
	wpguard_assert( false !== strpos( $redacted, 'leave-me-alone-auth-key' ), 'AUTH_KEY must be deliberately left untouched -- 2FA/encryption plugins depend on it surviving a same-host restore' );
}

// security audit finding NEW-MEDIUM-1 (re-verification, 2026-10-21): the exact proof
// case from the audit -- a DB_PASSWORD containing its own literal ");"
// (plausible: some hosting panels generate passwords from the full
// printable-ASCII set) bypassed redaction AND defeated the fail-closed
// strpos() re-check in Job_Orchestrator::redacted_wp_config_copy().
function test_redact_wp_config_db_credentials_survives_password_containing_close_paren_semicolon() {
	$content = "<?php\ndefine('DB_PASSWORD', 'first);remaining');\n";
	$redacted = Settings::redact_wp_config_db_credentials( $content );
	wpguard_assert( false === strpos( $redacted, 'remaining' ), 'a DB_PASSWORD value containing its own ");" must not leave a fragment unredacted' );
	wpguard_assert( false === strpos( $redacted, 'first' ), 'the whole value must be gone, not just the tail' );
}

// Defines a real PHP constant — must run last in this file (specifically,
// after test_legacy_app_password_constant_still_locks below, which
// needs WPTHISTLE_BACKUP_APP_PASSWORD still undefined so
// active_app_pw_const() actually falls through to the legacy name),
// since PHP constants cannot be undefined and would otherwise lock
// every test after it. run-all.php sorts files alphabetically and
// UpdateCheckerTest.php now sorts after this file, but defines no
// constants, so the ordering requirement here is still satisfied in
// practice. The execution order that matters is the returned array
// below, not this function's physical position in the file.
function test_locked_app_password_purges_stored_wp_options_value() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => 'old-option-stored-secret', 'ca_bundle_path' => '' ),
	);
	define( 'WPTHISTLE_BACKUP_APP_PASSWORD', 'constant-sourced-secret' );
	wpguard_assert( Settings::is_app_password_locked(), 'defining the constant must lock the app password' );
	$result = Settings::save( array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups' ) );
	wpguard_assert( true === $result['ok'], 'save must still succeed while the password is constant-locked' );
	wpguard_assert_equals( 'constant-sourced-secret', Settings::get_app_password(), 'get_app_password() must read the constant while locked' );
	$stored = Settings::get_all();
	wpguard_assert_equals( '', $stored['app_password'], 'the stale wp_options value must be purged once the constant takes over (H-02)' );
}

// "Forget Connection" admin action: must clear only the six connection
// fields, leaving schedule/retention/incremental exactly as they were.
// Deliberately NOT routed through save() (see forget_connection()'s own
// docblock) -- this is the regression test for that distinction.
function test_forget_connection_clears_connection_fields_only() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array(
			'backend'              => 'owncloud',
			'nextcloud_url'        => 'https://nc.example.com',
			'nextcloud_user'       => 'u',
			'app_password'         => 'secret',
			'remote_folder'        => 'backups',
			'ca_bundle_path'       => '/etc/ssl/private-ca.pem',
			'schedule_mode'        => 'tiered',
			'schedule_cadence'     => 'monthly',
			'schedule_day_of_month' => 15,
			'schedule_days'        => array( 1, 3, 5 ),
			'retention_count'      => 9,
			'retention_daily'      => 3,
			'retention_weekly'     => 2,
			'retention_monthly'    => 6,
			'incremental_enabled'  => true,
		),
	);
	Settings::forget_connection();
	$after = Settings::get_all();

	wpguard_assert_equals( 'nextcloud', $after['backend'], 'backend must reset to the default' );
	wpguard_assert_equals( '', $after['nextcloud_url'], 'nextcloud_url must be cleared' );
	wpguard_assert_equals( '', $after['nextcloud_user'], 'nextcloud_user must be cleared' );
	wpguard_assert_equals( '', $after['app_password'], 'app_password must be cleared' );
	wpguard_assert_equals( '', $after['remote_folder'], 'remote_folder must be cleared' );
	wpguard_assert_equals( '', $after['ca_bundle_path'], 'ca_bundle_path must be cleared' );

	wpguard_assert_equals( 'tiered', $after['schedule_mode'], 'schedule_mode must NOT be touched by forgetting the connection' );
	wpguard_assert_equals( 'monthly', $after['schedule_cadence'], 'schedule_cadence must NOT be touched' );
	wpguard_assert_equals( 15, $after['schedule_day_of_month'], 'schedule_day_of_month must NOT be touched' );
	wpguard_assert_equals( array( 1, 3, 5 ), $after['schedule_days'], 'schedule_days must NOT be touched' );
	wpguard_assert_equals( 9, $after['retention_count'], 'retention_count must NOT be touched' );
	wpguard_assert_equals( 3, $after['retention_daily'], 'retention_daily must NOT be touched' );
	wpguard_assert_equals( 2, $after['retention_weekly'], 'retention_weekly must NOT be touched' );
	wpguard_assert_equals( 6, $after['retention_monthly'], 'retention_monthly must NOT be touched' );
	wpguard_assert( true === $after['incremental_enabled'], 'incremental_enabled must NOT be touched' );
}

// Calling it with nothing configured yet must be a safe no-op, not a
// notice/warning from touching keys that were never set.
function test_forget_connection_is_safe_when_nothing_is_configured() {
	$GLOBALS['__wpguard_test_options'] = array();
	Settings::forget_connection();
	wpguard_assert( ! Settings::has_credentials(), 'forgetting an already-blank connection must not error, and credentials must still read as absent' );
}

// 2.0.0 rename: a site whose wp-config.php still defines the LEGACY
// constant name (not yet updated to WPTHISTLE_BACKUP_APP_PASSWORD)
// must still lock correctly through the fallback.
function test_legacy_app_password_constant_still_locks() {
	$GLOBALS['__wpguard_test_options'] = array(
		Settings::OPTION_KEY => array( 'nextcloud_url' => 'https://nc.example.com', 'nextcloud_user' => 'u', 'remote_folder' => 'backups', 'app_password' => '', 'ca_bundle_path' => '' ),
	);
	define( 'WPGUARD_NC_APP_PASSWORD', 'legacy-constant-sourced-secret' );
	wpguard_assert( Settings::is_app_password_locked(), 'the legacy constant name must still lock the app password' );
	wpguard_assert_equals( 'legacy-constant-sourced-secret', Settings::get_app_password(), 'get_app_password() must fall back to reading the legacy constant' );
}

return array(
	'test_rejects_plain_http_url',
	'test_accepts_https_url',
	'test_rejects_folder_traversal',
	'test_blank_app_password_keeps_existing_value',
	'test_rejects_missing_username',
	'test_schedule_defaults_to_disabled_when_missing',
	'test_accepts_valid_schedule_days',
	'test_schedule_days_deduplicates_and_sorts',
	'test_rejects_out_of_range_schedule_day_without_failing_save',
	'test_retention_defaults_to_zero_when_missing',
	'test_accepts_valid_retention_count',
	'test_rejects_negative_retention_count',
	'test_tiered_retention_defaults_to_disabled_with_prefilled_numbers',
	'test_accepts_valid_tiered_retention_settings',
	'test_tiered_retention_negative_or_non_numeric_clamps_to_zero',
	'test_schedule_mode_defaults_to_scheduled_when_missing',
	'test_schedule_mode_rejects_unrecognized_value_without_failing_save',
	'test_schedule_cadence_defaults_to_daily_and_accepts_monthly',
	'test_schedule_cadence_rejects_unrecognized_value_without_failing_save',
	'test_schedule_day_of_month_defaults_to_first_and_accepts_last',
	'test_schedule_day_of_month_out_of_range_clamps_to_first_without_failing_save',
	'test_legacy_tiered_enabled_without_schedule_mode_migrates_to_tiered_mode',
	'test_legacy_tiered_disabled_without_schedule_mode_migrates_to_scheduled_mode',
	'test_incremental_defaults_to_disabled_when_missing',
	'test_incremental_accepts_enabled',
	'test_backend_defaults_to_nextcloud_when_missing',
	'test_backend_accepts_owncloud_and_opencloud',
	'test_backend_rejects_unrecognized_value_without_failing_save',
	'test_redact_wp_config_strips_credential_constants',
	'test_redact_wp_config_strips_legacy_credential_constants',
	'test_redact_wp_config_known_gap_const_syntax_not_caught',
	'test_redact_wp_config_survives_value_containing_close_paren_semicolon',
	'test_redact_wp_config_survives_double_quoted_value_containing_close_paren_semicolon',
	'test_redact_wp_config_db_credentials_strips_db_constants',
	'test_redact_wp_config_db_credentials_survives_password_containing_close_paren_semicolon',
	'test_forget_connection_clears_connection_fields_only',
	'test_forget_connection_is_safe_when_nothing_is_configured',
	'test_legacy_app_password_constant_still_locks',
	'test_locked_app_password_purges_stored_wp_options_value',
);
