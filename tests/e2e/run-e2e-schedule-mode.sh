#!/usr/bin/env bash
# Phase 6 backup-plan dropdown regression test: maybe_run_scheduled_backup()
# in wpthistle-backup-restore.php must dispatch to the right trigger for
# Settings::get_schedule_mode()/get_schedule_cadence() -- tiered (every
# day, no day picker, claim_today() reused), scheduled+daily (unchanged,
# covered in depth by run-e2e-scheduling.sh), and scheduled+monthly (new
# claim_month()/Monthly_Trigger path). Pure day-of-month math (including
# "Last Day of the Month" across varying month lengths) is unit-tested
# directly in tests/unit/MonthlyTriggerTest.php; this test is about the
# real wp_options claims and the real job-start/dispatch path, not that
# math.
#
# Every settings-change-then-trigger step below happens inside ONE
# `wp eval` call, same discipline run-e2e-scheduling.sh uses -- boot()
# itself fires on every single WP-CLI invocation (by design: "any
# request of any kind" triggers the day/month check), so a SEPARATE
# wp-cli command (even a plain `option delete`) sitting between a
# settings change and the explicit trigger call would itself bootstrap
# WP and re-run the trigger against whatever was stored a moment
# earlier -- exactly the false failure this comment is here to prevent
# reintroducing. Deleting a claim option is therefore done via
# delete_option() inside the same combined eval, never as its own
# standalone wp-cli command.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_SOURCE_DB_PASSWORD=$(env_val WP_SOURCE_DB_PASSWORD)
WP_SOURCE_TABLE_PREFIX=$(env_val WP_SOURCE_TABLE_PREFIX)

wp_source() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-source -e WORDPRESS_DB_NAME=wp_source \
    -e WORDPRESS_DB_USER=wp_source -e WORDPRESS_DB_PASSWORD="$WP_SOURCE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$WP_SOURCE_TABLE_PREFIX" \
    wpcli wp --path=/var/www/html-source "$@" 2>&1 | grep -v '^ Container' || true
}

# $1 = PHP array literal body for Settings::save()'s $input argument.
# $2 = extra PHP statements to run BEFORE the save (e.g. delete_option()
#      calls), in the same eval so no stray wp-cli command's own boot()
#      firing can see stale settings in between.
save_and_trigger() {
  wp_source eval "
    ${2:-}
    \WPThistle\BackupRestore\Settings::save(array(
      'nextcloud_url' => \WPThistle\BackupRestore\Settings::get_nextcloud_url(),
      'nextcloud_user' => \WPThistle\BackupRestore\Settings::get_nextcloud_user(),
      'remote_folder' => \WPThistle\BackupRestore\Settings::get_remote_folder(),
      $1
    ));
    \WPThistle\BackupRestore\maybe_run_scheduled_backup();
  " > /dev/null
}

echo "=== Setup: force site timezone to UTC, disable scheduling first (so no stray boot() firing during cleanup can claim anything), clean job table and both claim options ==="
wp_source option update timezone_string '' > /dev/null
wp_source option update gmt_offset 0 > /dev/null
save_and_trigger "'schedule_mode' => 'scheduled', 'schedule_cadence' => 'daily', 'schedule_days' => array(),"
wp_source option delete wpthistle_backup_daily_claim > /dev/null 2>&1 || true
wp_source option delete wpthistle_backup_monthly_claim > /dev/null 2>&1 || true
wp_source db query "UPDATE ${WP_SOURCE_TABLE_PREFIX}wpthistle_backup_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true

TODAY_DATE=$(date -u +%Y-%m-%d)
TODAY_DOM=$(date -u +%-d)
TODAY_MONTH=$(date -u +%Y-%m)
TODAY_DOW=$(date -u +%w)
OTHER_DOM=$(( (TODAY_DOM % 28) + 1 ))
if [ "$OTHER_DOM" = "$TODAY_DOM" ]; then OTHER_DOM=$(( (OTHER_DOM % 28) + 1 )); fi
echo "today (UTC) date=$TODAY_DATE dom=$TODAY_DOM dow=$TODAY_DOW month=$TODAY_MONTH; a day-of-month NOT today = $OTHER_DOM"

echo
echo "=== Case A: tiered mode backs up today with NO day selected at all ==="
save_and_trigger "'schedule_mode' => 'tiered', 'schedule_days' => array(),"
CLAIM_A=$(wp_source eval 'echo get_option("wpthistle_backup_daily_claim", "none");')
[ "$CLAIM_A" = "$TODAY_DATE" ] || { echo "FAIL: tiered mode must claim today's date even with schedule_days empty, got '$CLAIM_A'"; exit 1; }
JOB_A=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_A" = "yes" ] || { echo "FAIL: tiered mode must start a real job regardless of day selection"; exit 1; }
FIRST_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
echo "OK  tiered mode claimed today and started a job ($FIRST_JOB_ID) with zero days selected"

echo
echo "=== Case B: tiered mode a second call the same day does not reclaim/restart ==="
save_and_trigger "'schedule_mode' => 'tiered', 'schedule_days' => array(),"
SECOND_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
[ "$SECOND_JOB_ID" = "$FIRST_JOB_ID" ] || { echo "FAIL: a second same-day tiered call must not start a new job; got '$SECOND_JOB_ID'"; exit 1; }
echo "OK  second same-day tiered call correctly skipped"
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$FIRST_JOB_ID');" > /dev/null

echo
echo "=== Case C: scheduled+monthly, today's day-of-month selected, claims the month and starts a job ==="
save_and_trigger "'schedule_mode' => 'scheduled', 'schedule_cadence' => 'monthly', 'schedule_day_of_month' => $TODAY_DOM, 'schedule_days' => array()," \
  "delete_option('wpthistle_backup_daily_claim');"
CLAIM_C=$(wp_source eval 'echo get_option("wpthistle_backup_monthly_claim", "none");')
[ "$CLAIM_C" = "$TODAY_MONTH" ] || { echo "FAIL: scheduled+monthly must claim this month exactly ($TODAY_MONTH), got '$CLAIM_C'"; exit 1; }
JOB_C=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_C" = "yes" ] || { echo "FAIL: scheduled+monthly must start a real job when today matches the configured day-of-month"; exit 1; }
THIRD_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
echo "OK  scheduled+monthly claimed this month and started a job ($THIRD_JOB_ID)"

echo
echo "=== Case D: scheduled+monthly a second call the same month does not reclaim/restart ==="
save_and_trigger "'schedule_mode' => 'scheduled', 'schedule_cadence' => 'monthly', 'schedule_day_of_month' => $TODAY_DOM, 'schedule_days' => array(),"
FOURTH_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
[ "$FOURTH_JOB_ID" = "$THIRD_JOB_ID" ] || { echo "FAIL: a second same-month call must not start a new job; got '$FOURTH_JOB_ID'"; exit 1; }
echo "OK  second same-month call correctly skipped"
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$THIRD_JOB_ID');" > /dev/null

echo
echo "=== Case E: scheduled+monthly with a day-of-month that is NOT today never claims or starts, even right after clearing a stale claim ==="
save_and_trigger "'schedule_mode' => 'scheduled', 'schedule_cadence' => 'monthly', 'schedule_day_of_month' => $OTHER_DOM, 'schedule_days' => array()," \
  "delete_option('wpthistle_backup_monthly_claim');"
CLAIM_E=$(wp_source eval 'echo get_option("wpthistle_backup_monthly_claim", "none");')
[ "$CLAIM_E" = "none" ] || { echo "FAIL: a day-of-month that is not today must never claim the month, got '$CLAIM_E'"; exit 1; }
echo "OK  non-matching day-of-month -> no claim, no job"

echo
echo "=== Case F: scheduled+daily still dispatches correctly through maybe_run_scheduled_backup() (not just maybe_run_daily_backup() directly) ==="
save_and_trigger "'schedule_mode' => 'scheduled', 'schedule_cadence' => 'daily', 'schedule_days' => array( $TODAY_DOW ),"
CLAIM_F=$(wp_source eval 'echo get_option("wpthistle_backup_daily_claim", "none");')
[ "$CLAIM_F" = "$TODAY_DATE" ] || { echo "FAIL: scheduled+daily dispatch must claim today's date, got '$CLAIM_F'"; exit 1; }
JOB_F=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::has_running_job() ? "yes" : "no";')
[ "$JOB_F" = "yes" ] || { echo "FAIL: scheduled+daily dispatch must start a real job"; exit 1; }
FIFTH_JOB_ID=$(wp_source eval 'echo \WPThistle\BackupRestore\Job_Store::get_latest()["job_id"];')
echo "OK  scheduled+daily dispatched correctly through maybe_run_scheduled_backup()"
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$FIFTH_JOB_ID');" > /dev/null

echo
echo "=== Cleanup: disable scheduling, clear both claims ==="
save_and_trigger "'schedule_mode' => 'scheduled', 'schedule_cadence' => 'daily', 'schedule_days' => array()," \
  "delete_option('wpthistle_backup_daily_claim'); delete_option('wpthistle_backup_monthly_claim');"

echo
echo "=== SCHEDULE-MODE DISPATCH REGRESSION TEST PASSED ==="
