#!/usr/bin/env bash
# Phase 5 self-chaining job advance regression test: this is the actual
# fix for a real production bug (a scheduled backup on a quiet site
# starting then stalling forever in 'running', since the old model
# only ever advanced a job on the NEXT external request to land -- see
# docs/operations.md). Exercises both halves against the real stack,
# not mocks:
#   1. ajax_self_advance()'s token gate, via real HTTP POSTs to
#      wp-source's actual admin-ajax.php (wrong token / missing
#      fields / unknown job_id -> rejected with no progress made;
#      correct token -> the job actually advances).
#   2. The real end-to-end proof: start a job, fire exactly ONE
#      cron_advance() call (simulating the one WP-Cron pseudo-cron
#      pickup a quiet site would still eventually get), then poll
#      WITHOUT driving any further advance() call ourselves -- if the
#      job reaches 'verified' purely from here, the self-chained
#      loopback requests did the rest unattended, proving a backup can
#      now complete on a site with zero further traffic.
# SECURITY NOTE (carried from the code itself): the self-advance
# endpoint is deliberately unauthenticated (wp_ajax_nopriv_), gated
# only by the per-job advance_token. This test exercises that gate's
# correctness but is not a substitute for the Vex security pass this
# feature still needs before shipping.
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

# Raw HTTP POST against wp-source's real admin-ajax.php, from a
# separate container on the same docker network (wp-source's service
# name is valid Docker-internal DNS from here -- already how
# WPTHISTLE_BACKUP_SELF_ADVANCE_URL itself resolves, see
# docker-compose.yml). Prints "<http_code>|<body>".
post_self_advance() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm --entrypoint sh wpcli -c \
    "curl -s -o /tmp/body -w '%{http_code}' -X POST http://wp-source/wp-admin/admin-ajax.php \
      --data-urlencode 'action=wpthistle_backup_self_advance' \
      --data-urlencode 'job_id=$1' \
      --data-urlencode 'token=$2' ; echo \"|\$(cat /tmp/body)\"" 2>&1 | grep -v '^ Container'
}

echo "=== Setup: clean job table ==="
wp_source db query "UPDATE ${WP_SOURCE_TABLE_PREFIX}wpthistle_backup_jobs SET status='failed' WHERE status IN ('queued','running','uploaded-unverified');" || true

echo
echo "=== Setup: start a real job directly (create_job() itself has no credentials gate) ==="
JOB_ID=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_ID" ] || { echo "FAIL: create_job() did not return a job_id"; exit 1; }
echo "job_id=$JOB_ID"

REAL_TOKEN=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['advance_token'];")
[ -n "$REAL_TOKEN" ] && [ "$REAL_TOKEN" != "" ] || { echo "FAIL: job has no advance_token -- Job_Store::create() should always generate one"; exit 1; }
echo "advance_token is present (${#REAL_TOKEN} chars, not printed)"

STEP_BEFORE=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['step'];")
UPDATED_BEFORE=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['updated_at'];")

echo
echo "=== Case A: missing job_id/token must be rejected (400), no progress made ==="
RESULT_A=$(post_self_advance "" "")
CODE_A="${RESULT_A%%|*}"
[ "$CODE_A" = "400" ] || { echo "FAIL: expected HTTP 400 for missing fields, got '$RESULT_A'"; exit 1; }
echo "OK  missing fields -> 400"

echo
echo "=== Case B: an unknown job_id must be rejected (403), same response shape as a wrong token (no information leak) ==="
RESULT_B=$(post_self_advance "job-0000000000000000" "0000000000000000000000000000000000000000000000000000000000000000")
CODE_B="${RESULT_B%%|*}"
[ "$CODE_B" = "403" ] || { echo "FAIL: expected HTTP 403 for an unknown job_id, got '$RESULT_B'"; exit 1; }
echo "OK  unknown job_id -> 403"

echo
echo "=== Case C: the real job_id with a WRONG token must be rejected (403), and the job must be untouched ==="
RESULT_C=$(post_self_advance "$JOB_ID" "0000000000000000000000000000000000000000000000000000000000000000")
CODE_C="${RESULT_C%%|*}"
[ "$CODE_C" = "403" ] || { echo "FAIL: expected HTTP 403 for a wrong token, got '$RESULT_C'"; exit 1; }
STEP_AFTER_C=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['step'];")
UPDATED_AFTER_C=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['updated_at'];")
[ "$STEP_AFTER_C" = "$STEP_BEFORE" ] && [ "$UPDATED_AFTER_C" = "$UPDATED_BEFORE" ] || { echo "FAIL: a wrong-token request must never advance the job (step $STEP_BEFORE -> $STEP_AFTER_C, updated_at $UPDATED_BEFORE -> $UPDATED_AFTER_C)"; exit 1; }
echo "OK  wrong token -> 403, job state provably untouched"

echo
echo "=== Case D: the real job_id with the CORRECT token must succeed (204) and actually advance the job ==="
RESULT_D=$(post_self_advance "$JOB_ID" "$REAL_TOKEN")
CODE_D="${RESULT_D%%|*}"
[ "$CODE_D" = "204" ] || { echo "FAIL: expected HTTP 204 for the correct token, got '$RESULT_D'"; exit 1; }
STEP_AFTER_D=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['step'];")
UPDATED_AFTER_D=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_ID')['updated_at'];")
[ "$UPDATED_AFTER_D" != "$UPDATED_BEFORE" ] || { echo "FAIL: the correct-token request should have actually invoked cron_advance() (updated_at did not change)"; exit 1; }
echo "OK  correct token -> 204, job genuinely advanced (step $STEP_BEFORE -> $STEP_AFTER_D)"

echo
echo "=== Cleanup Case A-D job ==="
wp_source eval "\WPThistle\BackupRestore\Job_Orchestrator::cancel('$JOB_ID');" > /dev/null

echo
echo "=== Case E (the real proof): one cron_advance() call, then NOTHING further driven by this test -- must still reach 'verified' on its own via self-chaining ==="
JOB_E=$(wp_source eval '
  $id = \WPThistle\BackupRestore\Job_Orchestrator::create_job();
  echo $id;
')
[ -n "$JOB_E" ] || { echo "FAIL: create_job() did not return a job_id for Case E"; exit 1; }
echo "job_id=$JOB_E -- firing exactly one cron_advance() call now (simulates the one WP-Cron pseudo-cron pickup a quiet site would still get)"
wp_source eval "\WPThistle\BackupRestore\cron_advance('$JOB_E');" > /dev/null

STATUS_E="queued"
for i in $(seq 1 40); do
  STATUS_E=$(wp_source eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_E')['status'];")
  echo "  poll $i: status=$STATUS_E"
  if [ "$STATUS_E" = "verified" ] || [ "$STATUS_E" = "failed" ]; then
    break
  fi
  sleep 3
done

[ "$STATUS_E" = "verified" ] || { echo "FAIL: expected the job to reach 'verified' purely via self-chaining after a single cron_advance() call, got '$STATUS_E'. This is the actual bug this feature exists to fix -- if this fails, self_chain_advance()'s loopback is not reaching wp-source (check WPTHISTLE_BACKUP_SELF_ADVANCE_URL in docker-compose.yml)."; exit 1; }
echo "OK  the job reached 'verified' with no further advance() call driven by this test -- self-chaining genuinely kept it moving unattended"

echo
echo "=== SELF-CHAINING JOB ADVANCE REGRESSION TEST PASSED ==="
