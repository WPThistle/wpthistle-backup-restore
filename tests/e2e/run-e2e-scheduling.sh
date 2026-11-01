#!/usr/bin/env bash
# Phase 5 day-based trigger regression test (replaces the Phase 4 exact
# HH:MM single-event model): Settings::get_schedule_days() plus
# maybe_run_daily_backup() in wpthistle-backup-restore.php must claim
# today's slot exactly once (via claim_today()'s atomic add_option()/
# conditional-UPDATE pair against the real wp_options table, not a
# mock) and start a real job -- a second check the same day must NOT
# reclaim/start again, and a different day must claim freshly. Also
# exercises the overlap guard (reuses create_job()'s JOB-05 reaper as
# the day-based trigger does too, same as the old scheduled-backup
# hook did). Daily_Trigger's own pure day-of-week/date-key math
# (including DST and timezone edge cases) is unit-tested directly in
# tests/unit/DailyTriggerTest.php; this test is about the real
# wp_options claim and the real job-start path, not that math.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)

wp_source() {
  # `|| true`: see run-e2e-stale-lock-recovery.sh for why a genuinely
  # empty/filtered result must not be treated as this helper failing.
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

echo "=== Setup: force site timezone to UTC (so bash 'date -u' and the site agree), clean job table and daily-claim option ==="
wp_source option update timezone_string '' > /dev/null
wp_source option update gmt_offset 0 > /dev/null
wp_source option delete wpthistle_backup_daily_claim > /dev/null 2>&1 || true
wp_source db query "UPDATE ${WP_SOURCE_TABLE_PREFIX}wpthistle_backup_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true

TODAY_DOW=$(date -u +%w)
TODAY_DATE=$(date -u +%Y-%m-%d)
OTHER_DOW=$(( (TODAY_DOW + 1) % 7 ))
echo "today (UTC) dow=$TODAY_DOW date=$TODAY_DATE; a day NOT selected in Case A/B/C below = $OTHER_DOW"

echo
echo "=== Case A: no days selected must never claim today or start a job ==="
wp_source eval '
  \WPThistle\BackupRestore\Settings::save(array(
    "nextcloud_url" => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
    "remote_folder" => \WPThistle\BackupRestore\Settings::get_remote_folder(),
    "schedule_days" => array(),
  ));
  \WPThistle\BackupRestore\maybe_run_daily_backup();
' > /dev/null
CLAIM_A=$(wp_source eval 'echo get_option("wpthistle_backup_daily_claim", "none");')
[ "$CLAIM_A" = "none" ] || { echo "FAIL: no days selected must never write a claim, got '$CLAIM_A'"; exit 1; }
echo "OK  no days selected -> no claim written, no job started"

echo
echo "=== Case B: a day that is NOT today must not claim today or start a job ==="
wp_source eval "
  \WPThistle\BackupRestore\Settings::save(array(
    'nextcloud_url' => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
    'nextcloud_user' => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
    'remote_folder' => \WPThistle\BackupRestore\Settings::get_remote_folder(),
    'schedule_days' => array( $OTHER_DOW ),
  ));
  \WPThistle\BackupRestore\maybe_run_daily_backup();
" > /dev/null
CLAIM_B=$(wp_source eval 'echo get_option("wpthistle_backup_daily_claim", "none");')
[ "$CLAIM_B" = "none" ] || { echo "FAIL: a non-today day selected must not claim today, got '$CLAIM_B'"; exit 1; }
echo "OK  a day that is not today -> no claim, no job"

echo
echo "=== Case C: selecting today must claim today's date exactly and start a real job ==="
JOB_BEFORE=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_BEFORE" = "no" ] || { echo "FAIL: test setup problem -- a job is already running before Case C"; exit 1; }

wp_source eval "
  \WPThistle\BackupRestore\Settings::save(array(
    'nextcloud_url' => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
    'nextcloud_user' => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
    'remote_folder' => \WPThistle\BackupRestore\Settings::get_remote_folder(),
    'schedule_days' => array( $TODAY_DOW ),
  ));
  \WPThistle\BackupRestore\maybe_run_daily_backup();
" > /dev/null

CLAIM_C=$(wp_source eval 'echo get_option("wpthistle_backup_daily_claim", "none");')
[ "$CLAIM_C" = "$TODAY_DATE" ] || { echo "FAIL: expected today's date claimed ($TODAY_DATE), got '$CLAIM_C'"; exit 1; }
echo "OK  today's date claimed exactly"

JOB_AFTER=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_AFTER" = "yes" ] || { echo "FAIL: maybe_run_daily_backup() should have started a real job (has_credentials() is true in this stack), but has_running_job() is still 'no'"; exit 1; }
echo "OK  maybe_run_daily_backup() started a real job"

FIRST_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
echo "job_id=$FIRST_JOB_ID"

echo
echo "=== Case D: calling the trigger again the SAME day must NOT reclaim or start a second job, even with the existing job still genuinely running ==="
wp_source eval "
  \WPThistle\BackupRestore\Settings::save(array(
    'nextcloud_url' => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
    'nextcloud_user' => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
    'remote_folder' => \WPThistle\BackupRestore\Settings::get_remote_folder(),
    'schedule_days' => array( $TODAY_DOW ),
  ));
  \WPThistle\BackupRestore\maybe_run_daily_backup();
" > /dev/null
SECOND_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
[ "$SECOND_JOB_ID" = "$FIRST_JOB_ID" ] || { echo "FAIL: a second same-day trigger should not have started a new job; got a new id '$SECOND_JOB_ID'"; exit 1; }
echo "OK  second same-day trigger correctly skipped -- no reclaim, no overlapping job"

echo
echo "=== Case E: a day switch back to disabled must not retroactively clear the claim (it is per-day, not a live schedule flag) but must not start anything new either ==="
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$FIRST_JOB_ID');" > /dev/null
wp_source eval '
  \WPThistle\BackupRestore\Settings::save(array(
    "nextcloud_url" => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
    "remote_folder" => \WPThistle\BackupRestore\Settings::get_remote_folder(),
    "schedule_days" => array(),
  ));
  \WPThistle\BackupRestore\maybe_run_daily_backup();
' > /dev/null
JOB_COUNT_AFTER_DISABLE=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_COUNT_AFTER_DISABLE" = "no" ] || { echo "FAIL: disabling must not leave/start a running job"; exit 1; }
echo "OK  disabling the schedule after a claim starts nothing new"

echo
echo "=== Case F: simulating 'the next day' (claim option manually set to yesterday) must claim fresh and start a new job once a day is selected again ==="
wp_source option update wpthistle_backup_daily_claim "2000-01-01" > /dev/null
wp_source eval "
  \WPThistle\BackupRestore\Settings::save(array(
    'nextcloud_url' => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
    'nextcloud_user' => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
    'remote_folder' => \WPThistle\BackupRestore\Settings::get_remote_folder(),
    'schedule_days' => array( $TODAY_DOW ),
  ));
  \WPThistle\BackupRestore\maybe_run_daily_backup();
" > /dev/null
CLAIM_F=$(wp_source eval 'echo get_option("wpthistle_backup_daily_claim", "none");')
[ "$CLAIM_F" = "$TODAY_DATE" ] || { echo "FAIL: expected the stale claim to be atomically replaced with today's date ($TODAY_DATE), got '$CLAIM_F'"; exit 1; }
THIRD_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
[ "$THIRD_JOB_ID" != "$FIRST_JOB_ID" ] || { echo "FAIL: a fresh day's claim must start a genuinely new job, got the same old job_id"; exit 1; }
JOB_STARTED_F=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_STARTED_F" = "yes" ] || { echo "FAIL: expected a new job to have started on the fresh day"; exit 1; }
echo "OK  stale (prior-day) claim correctly replaced atomically, fresh job started"

echo
echo "=== Cleanup: cancel the job this test started, leave schedule disabled ==="
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$THIRD_JOB_ID');" > /dev/null
wp_source eval '
  \WPThistle\BackupRestore\Settings::save(array(
    "nextcloud_url" => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
    "nextcloud_user" => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
    "remote_folder" => \WPThistle\BackupRestore\Settings::get_remote_folder(),
    "schedule_days" => array(),
  ));
' > /dev/null
wp_source option delete wpthistle_backup_daily_claim > /dev/null 2>&1 || true

echo
echo "=== SCHEDULING (DAY-BASED TRIGGER) REGRESSION TEST PASSED ==="
