#!/usr/bin/env bash
# Scheduled-backup notifications: proves Job_Orchestrator::maybe_send_scheduled_alert()
# and Notifications::dispatch_all()/dispatch_test() against the real
# stack, not mocks. A scheduled-origin job's real terminal transition
# (via the same create_job()/cron_advance() self-chaining path
# run-e2e-self-advance.sh already proves) must fire the right
# notification, a manual-origin job must never fire one even on the
# exact same failure, and each of the two "when" toggles must be
# respected independently. Email is proven fully end-to-end here.
# Discord stands in as the one third-party channel proven fully live
# (real HTTP dispatch, real request shape); the other 7 channels'
# exact request shapes are already exhaustively unit-tested
# (tests/unit/NotificationsTest.php), and share the identical
# send_request() dispatch code path Discord's own live proof here
# already exercises, so a second/third/.../eighth live mock would be
# testing the same mechanism again, not new risk.
#
# Runs against wp-restore (port 8082), never wp-source. Other suites
# depend on wp-source as a stable fixture, and this test mints its own
# real Nextcloud app password and deliberately toggles settings that
# would otherwise disturb it.
#
# Neither wp_mail() nor a real Discord webhook is actually reachable from
# this disposable stack (no MTA configured; no real webhook to send to),
# so two mu-plugins short-circuit the real calls and record what WOULD
# have gone out into WP options instead: `pre_wp_mail` for email,
# `pre_http_request` for every outbound notification call, which also
# includes Discord. Both are removed again at the end via `trap`, since
# mu-plugins persist on the wp-restore volume across runs.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
NC_BACKUP_USER=$(env_val NC_BACKUP_USER)
NC_BACKUP_USER_PASSWORD=$(env_val NC_BACKUP_USER_PASSWORD)
PREFIX="wp_"
BASE_URL="http://127.0.0.1:8082"
ADMIN_USER="wpadmin"
ADMIN_PASS="AlertEmailE2EAdminPW"
# wp-restore's Apache container and the wpcli container mount the same
# shared volume at DIFFERENT local paths (/var/www/html here vs.
# /var/www/html-restore for wpcli below). This must be the Apache-side
# path, since it's written via `docker exec` into that container
# directly, not through wpcli.
MU_PLUGIN_PATH="/var/www/html/wp-content/mu-plugins/wpthistle-test-mail-capture.php"
HTTP_MU_PLUGIN_PATH="/var/www/html/wp-content/mu-plugins/wpthistle-test-http-capture.php"

wpr() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container' || true
}

cleanup() {
  MSYS_NO_PATHCONV=1 docker exec wpguard-test-wp-restore-1 rm -f "$MU_PLUGIN_PATH" 2>/dev/null || true
  MSYS_NO_PATHCONV=1 docker exec wpguard-test-wp-restore-1 rm -f "$HTTP_MU_PLUGIN_PATH" 2>/dev/null || true
}
trap cleanup EXIT

echo "=== Setup: fresh WordPress + plugin on wp-restore ==="
docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null
docker exec wpguard-test-wp-restore-1 sh -c "
  find /var/www/html/wp-content/plugins -mindepth 1 -maxdepth 1 ! -name wpthistle-backup-restore -exec rm -rf {} +
  rm -rf /var/www/html/wp-content/uploads/*
" || true
wpr core install --url="$BASE_URL" --title="Alert Email E2E Test" \
  --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpthistle-backup-restore > /dev/null

echo "=== Drop the wp_mail capture mu-plugin ==="
cat <<'PHP' > /tmp/wpthistle-test-mail-capture.php
<?php
add_filter( 'pre_wp_mail', function ( $return, $atts ) {
	$captured = get_option( 'wpthistle_test_captured_mail', array() );
	$captured[] = array(
		'to'      => $atts['to'],
		'subject' => $atts['subject'],
		'body'    => $atts['message'],
	);
	update_option( 'wpthistle_test_captured_mail', $captured, false );
	return true; // short-circuit the real send, no MTA in this stack anyway.
}, 10, 2 );
PHP
# A real `wp restore` later in the E2E battery needs to write its own
# files into this same mu-plugins directory as www-data (the Apache
# process's own user). `docker exec` runs as root by default, so
# `mkdir -p` here would otherwise leave a root-owned directory nothing
# else can write into afterward. Found live: it broke run-e2e-restore.sh
# when both ran back to back. chown immediately after creating it.
MSYS_NO_PATHCONV=1 docker exec -i wpguard-test-wp-restore-1 sh -c "mkdir -p \$(dirname $MU_PLUGIN_PATH) && chown www-data:www-data \$(dirname $MU_PLUGIN_PATH) && cat > $MU_PLUGIN_PATH && chown www-data:www-data $MU_PLUGIN_PATH" < /tmp/wpthistle-test-mail-capture.php
rm -f /tmp/wpthistle-test-mail-capture.php
wpr eval "update_option('wpthistle_test_captured_mail', array());" > /dev/null

echo "=== Drop the outbound-notification (pre_http_request) capture mu-plugin ==="
cat <<'PHP' > /tmp/wpthistle-test-http-capture.php
<?php
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	// Only intercept what this test cares about. The real Nextcloud
	// WebDAV/OCS calls this same job also makes must still actually
	// happen, or the backup itself would never reach a terminal state.
	if ( false === strpos( $url, 'discord.com' ) ) {
		return $preempt;
	}
	$captured = get_option( 'wpthistle_test_captured_http', array() );
	$captured[] = array(
		'url'     => $url,
		'headers' => $args['headers'] ?? array(),
		'body'    => $args['body'] ?? '',
	);
	update_option( 'wpthistle_test_captured_http', $captured, false );
	return array(
		'headers'  => array(),
		'body'     => '',
		'response' => array( 'code' => 204, 'message' => 'No Content' ),
		'cookies'  => array(),
		'filename' => null,
	);
}, 10, 3 );
PHP
MSYS_NO_PATHCONV=1 docker exec -i wpguard-test-wp-restore-1 sh -c "mkdir -p \$(dirname $HTTP_MU_PLUGIN_PATH) && chown www-data:www-data \$(dirname $HTTP_MU_PLUGIN_PATH) && cat > $HTTP_MU_PLUGIN_PATH && chown www-data:www-data $HTTP_MU_PLUGIN_PATH" < /tmp/wpthistle-test-http-capture.php
rm -f /tmp/wpthistle-test-http-capture.php
wpr eval "update_option('wpthistle_test_captured_http', array());" > /dev/null

captured_count() { wpr eval "echo count(get_option('wpthistle_test_captured_mail', array()));"; }
captured_json() { wpr eval "echo wp_json_encode(get_option('wpthistle_test_captured_mail', array()));"; }
reset_captured() { wpr eval "update_option('wpthistle_test_captured_mail', array()); update_option('wpthistle_test_captured_http', array());" > /dev/null; }
http_captured_count() { wpr eval "echo count(get_option('wpthistle_test_captured_http', array()));"; }
http_captured_json() { wpr eval "echo wp_json_encode(get_option('wpthistle_test_captured_http', array()));"; }

run_scheduled_job_to_terminal() {
  local job_id
  job_id=$(wpr eval "echo \WPThistle\BackupRestore\Job_Orchestrator::create_job('nextcloud', 'scheduled');")
  wpr eval "\WPThistle\BackupRestore\cron_advance('$job_id');" > /dev/null
  local status="queued"
  for i in $(seq 1 40); do
    status=$(wpr eval "echo \WPThistle\BackupRestore\Job_Store::get('$job_id')['status'];")
    [ "$status" = "verified" ] || [ "$status" = "failed" ] && break
    sleep 2
  done
  echo "$job_id|$status"
}

echo
echo "=== Case A: scheduled backup against an unreachable Nextcloud URL must fail and send exactly one FAILED alert ==="
wpr eval "
  \WPThistle\BackupRestore\Settings::save(array(
    'nextcloud_url' => 'https://nextcloud.invalid.wpguard-test',
    'nextcloud_user' => 'nobody',
    'app_password' => 'bogus',
    'remote_folder' => 'backups',
    'notify_on_failure' => '1',
    'notify_email_enabled' => '1',
  ));
" > /dev/null
RESULT_A=$(run_scheduled_job_to_terminal)
STATUS_A="${RESULT_A#*|}"
[ "$STATUS_A" = "failed" ] || { echo "FAIL: expected the job to fail against an unreachable URL, got '$STATUS_A'"; exit 1; }
COUNT_A=$(captured_count)
[ "$COUNT_A" = "1" ] || { echo "FAIL: expected exactly 1 captured email after a scheduled failure, got $COUNT_A. Captured: $(captured_json)"; exit 1; }
BODY_A=$(captured_json)
echo "$BODY_A" | grep -q '"to":"wpadmin@wpguard-test.local"' || { echo "FAIL: email must go to the site admin email. Got: $BODY_A"; exit 1; }
echo "$BODY_A" | grep -q 'FAILED' || { echo "FAIL: subject must say FAILED. Got: $BODY_A"; exit 1; }
echo "OK  scheduled failure sent exactly 1 alert, correct recipient, FAILED in subject"

echo
echo "=== Case B: the SAME failure via a MANUAL (AJAX) backup must send NO alert ==="
reset_captured
COOKIES="/tmp/alert-email-cookies.txt"
rm -f "$COOKIES"
curl -s -c "$COOKIES" "$BASE_URL/wp-login.php" -o /dev/null
curl -s -c "$COOKIES" -b "$COOKIES" \
  --data-urlencode "log=$ADMIN_USER" --data-urlencode "pwd=$ADMIN_PASS" \
  -d "wp-submit=Log+In&redirect_to=$BASE_URL%2Fwp-admin%2F&testcookie=1" \
  "$BASE_URL/wp-login.php" -o /dev/null
PAGE=$(curl -s -c "$COOKIES" -b "$COOKIES" "$BASE_URL/wp-admin/admin.php?page=wpthistle-backup-restore")
NONCE=$(echo "$PAGE" | sed -n 's/.*var nonce = "\([a-f0-9]*\)".*/\1/p')
[ -n "$NONCE" ] || { echo "FAIL: could not extract nonce from settings page"; exit 1; }
MANUAL_RESULT=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_start_backup&nonce=$NONCE&destination=nextcloud" "$BASE_URL/wp-admin/admin-ajax.php")
MANUAL_JOB_ID=$(echo "$MANUAL_RESULT" | sed -n 's/.*"job_id":"\([^"]*\)".*/\1/p')
[ -n "$MANUAL_JOB_ID" ] || { echo "FAIL: manual backup did not start. Response: $MANUAL_RESULT"; exit 1; }
# Same one-time kick run-e2e-self-advance.sh uses: a quiet test stack
# generates no further traffic to spawn WP's real pseudo-cron, so without
# this the wp_schedule_single_event() the AJAX handler registers would
# never actually fire within this test.
wpr eval "\WPThistle\BackupRestore\cron_advance('$MANUAL_JOB_ID');" > /dev/null
MANUAL_STATUS="queued"
for i in $(seq 1 40); do
  MANUAL_STATUS=$(wpr eval "echo \WPThistle\BackupRestore\Job_Store::get('$MANUAL_JOB_ID')['status'];")
  [ "$MANUAL_STATUS" = "verified" ] || [ "$MANUAL_STATUS" = "failed" ] && break
  sleep 2
done
[ "$MANUAL_STATUS" = "failed" ] || { echo "FAIL: expected the manual job to also fail against the same bad URL, got '$MANUAL_STATUS'"; exit 1; }
COUNT_B=$(captured_count)
[ "$COUNT_B" = "0" ] || { echo "FAIL: a MANUAL backup's failure must never send an alert (origin='manual'), got $COUNT_B captured. $(captured_json)"; exit 1; }
echo "OK  manual backup failed too, but sent 0 alerts (origin gate works)"

echo
echo "=== Case C: notify_on_failure toggled OFF must suppress the scheduled-failure alert ==="
reset_captured
wpr eval "
  \$s = \WPThistle\BackupRestore\Settings::get_all();
  \$s['notify_on_failure'] = false;
  update_option('wpthistle_backup_settings', \$s);
" > /dev/null
RESULT_C=$(run_scheduled_job_to_terminal)
STATUS_C="${RESULT_C#*|}"
[ "$STATUS_C" = "failed" ] || { echo "FAIL: expected the job to fail (toggle test), got '$STATUS_C'"; exit 1; }
COUNT_C=$(captured_count)
[ "$COUNT_C" = "0" ] || { echo "FAIL: notify_on_failure=false must suppress the failure alert entirely, got $COUNT_C captured"; exit 1; }
echo "OK  notify_on_failure=false correctly suppresses the alert"

echo
echo "=== Mint a real Nextcloud app password for the success cases ==="
APP_PASSWORD=$(curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$NC_BACKUP_USER_PASSWORD" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://127.0.0.1:8443/ocs/v2.php/core/getapppassword | sed -n 's/.*"apppassword":"\([^"]*\)".*/\1/p')
[ -n "$APP_PASSWORD" ] || { echo "FAIL: could not mint a Nextcloud app password"; exit 1; }
wpr eval "
  \WPThistle\BackupRestore\Settings::save(array(
    'nextcloud_url' => 'https://nc-proxy.wpguard-test.local',
    'nextcloud_user' => '$NC_BACKUP_USER',
    'app_password' => '$APP_PASSWORD',
    'remote_folder' => 'wpguard-backups',
    'ca_bundle_path' => '/usr/local/share/ca-certificates/wpguard-test-ca.crt',
    'notify_email_enabled' => '1',
  ));
" > /dev/null
wpr eval "
  \$s = \WPThistle\BackupRestore\Settings::get_all();
  \$s['notify_on_failure'] = true;
  \$s['notify_on_success'] = false;
  update_option('wpthistle_backup_settings', \$s);
" > /dev/null

echo
echo "=== Case D: a real scheduled SUCCESS with notify_on_success OFF (default) must send nothing ==="
reset_captured
RESULT_D=$(run_scheduled_job_to_terminal)
STATUS_D="${RESULT_D#*|}"
[ "$STATUS_D" = "verified" ] || { echo "FAIL: expected a real backup against real credentials to succeed, got '$STATUS_D'"; exit 1; }
COUNT_D=$(captured_count)
[ "$COUNT_D" = "0" ] || { echo "FAIL: notify_on_success=false must suppress the success alert, got $COUNT_D captured"; exit 1; }
echo "OK  scheduled success with the success toggle off sends nothing"

echo
echo "=== Case E: notify_on_success toggled ON must send exactly one succeeded alert ==="
reset_captured
wpr eval "
  \$s = \WPThistle\BackupRestore\Settings::get_all();
  \$s['notify_on_success'] = true;
  update_option('wpthistle_backup_settings', \$s);
" > /dev/null
RESULT_E=$(run_scheduled_job_to_terminal)
STATUS_E="${RESULT_E#*|}"
[ "$STATUS_E" = "verified" ] || { echo "FAIL: expected success, got '$STATUS_E'"; exit 1; }
COUNT_E=$(captured_count)
[ "$COUNT_E" = "1" ] || { echo "FAIL: expected exactly 1 captured email, got $COUNT_E. $(captured_json)"; exit 1; }
BODY_E=$(captured_json)
echo "$BODY_E" | grep -q 'succeeded' || { echo "FAIL: subject must say succeeded. Got: $BODY_E"; exit 1; }
echo "OK  scheduled success with the toggle on sends exactly 1 alert, correct wording"

echo
echo "=== Case F: a real MANUAL success (same credentials) must still send nothing, even with both toggles on ==="
reset_captured
PAGE2=$(curl -s -c "$COOKIES" -b "$COOKIES" "$BASE_URL/wp-admin/admin.php?page=wpthistle-backup-restore")
NONCE2=$(echo "$PAGE2" | sed -n 's/.*var nonce = "\([a-f0-9]*\)".*/\1/p')
MANUAL_RESULT_2=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_start_backup&nonce=$NONCE2&destination=nextcloud" "$BASE_URL/wp-admin/admin-ajax.php")
MANUAL_JOB_ID_2=$(echo "$MANUAL_RESULT_2" | sed -n 's/.*"job_id":"\([^"]*\)".*/\1/p')
[ -n "$MANUAL_JOB_ID_2" ] || { echo "FAIL: second manual backup did not start. Response: $MANUAL_RESULT_2"; exit 1; }
wpr eval "\WPThistle\BackupRestore\cron_advance('$MANUAL_JOB_ID_2');" > /dev/null
MANUAL_STATUS_2="queued"
for i in $(seq 1 40); do
  MANUAL_STATUS_2=$(wpr eval "echo \WPThistle\BackupRestore\Job_Store::get('$MANUAL_JOB_ID_2')['status'];")
  [ "$MANUAL_STATUS_2" = "verified" ] || [ "$MANUAL_STATUS_2" = "failed" ] && break
  sleep 2
done
[ "$MANUAL_STATUS_2" = "verified" ] || { echo "FAIL: expected the manual job to succeed too, got '$MANUAL_STATUS_2'"; exit 1; }
COUNT_F=$(captured_count)
[ "$COUNT_F" = "0" ] || { echo "FAIL: a manual success must never send an alert, got $COUNT_F captured"; exit 1; }
echo "OK  manual success sent 0 alerts, even with both toggles on"

echo
echo "=== Case G: claim_alert()'s atomic guard: Case E's job already had its alert claimed and sent; claiming it again must fail ==="
LAST_JOB_ID="${RESULT_E%%|*}"
SECOND_CLAIM=$(wpr eval "echo \WPThistle\BackupRestore\Job_Store::claim_alert('$LAST_JOB_ID') ? '1' : '0';")
[ "$SECOND_CLAIM" = "0" ] || { echo "FAIL: claiming an already-alerted job a second time must return false, got '$SECOND_CLAIM'"; exit 1; }
echo "OK  a job whose alert was already sent cannot be claimed again (claim_alert returns false)"

echo
echo "=== Case H: a real scheduled SUCCESS with Discord enabled dispatches a real HTTP POST with the right shape ==="
reset_captured
wpr eval "
  \$s = \WPThistle\BackupRestore\Settings::get_all();
  \$s['notify_on_success'] = true;
  \$s['notify_discord_enabled'] = true;
  \$s['notify_discord_webhook_url'] = 'https://discord.com/api/webhooks/123/abc';
  update_option('wpthistle_backup_settings', \$s);
" > /dev/null
RESULT_H=$(run_scheduled_job_to_terminal)
STATUS_H="${RESULT_H#*|}"
[ "$STATUS_H" = "verified" ] || { echo "FAIL: expected success, got '$STATUS_H'"; exit 1; }
HCOUNT_H=$(http_captured_count)
[ "$HCOUNT_H" = "1" ] || { echo "FAIL: expected exactly 1 captured Discord POST, got $HCOUNT_H. $(http_captured_json)"; exit 1; }
HBODY_H=$(http_captured_json)
# wp_json_encode() escapes forward slashes as \/ by default; -F (fixed
# string, no regex) sidesteps having to escape that pattern ourselves.
echo "$HBODY_H" | grep -qF 'discord.com\/api\/webhooks\/123\/abc' || { echo "FAIL: must POST to the configured webhook URL. Got: $HBODY_H"; exit 1; }
echo "$HBODY_H" | grep -q 'succeeded' || { echo "FAIL: Discord content must mention the success. Got: $HBODY_H"; exit 1; }
echo "$HBODY_H" | grep -qF 'application' || { echo "FAIL: must send Content-Type: application/json. Got: $HBODY_H"; exit 1; }
echo "OK  scheduled success dispatched a real HTTP POST to Discord with the right URL, headers, and content"

echo
echo "=== Case I: \"Send Test Notification\" dispatches to every enabled channel (email + Discord here) via the real AJAX endpoint ==="
reset_captured
PAGE3=$(curl -s -c "$COOKIES" -b "$COOKIES" "$BASE_URL/wp-admin/admin.php?page=wpthistle-backup-restore")
NONCE3=$(echo "$PAGE3" | sed -n 's/.*var nonce = "\([a-f0-9]*\)".*/\1/p')
[ -n "$NONCE3" ] || { echo "FAIL: could not extract nonce for the test-notification call"; exit 1; }
TEST_RESULT=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_notify_test&nonce=$NONCE3" "$BASE_URL/wp-admin/admin-ajax.php")
echo "$TEST_RESULT" | grep -q '"success":true' || { echo "FAIL: Send Test Notification must succeed. Response: $TEST_RESULT"; exit 1; }
echo "$TEST_RESULT" | grep -qi 'Email: OK' || { echo "FAIL: test result must report Email: OK. Response: $TEST_RESULT"; exit 1; }
echo "$TEST_RESULT" | grep -qi 'Discord: OK' || { echo "FAIL: test result must report Discord: OK. Response: $TEST_RESULT"; exit 1; }
TEST_MAIL_COUNT=$(captured_count)
TEST_HTTP_COUNT=$(http_captured_count)
[ "$TEST_MAIL_COUNT" = "1" ] || { echo "FAIL: expected exactly 1 test email captured, got $TEST_MAIL_COUNT"; exit 1; }
[ "$TEST_HTTP_COUNT" = "1" ] || { echo "FAIL: expected exactly 1 test Discord POST captured, got $TEST_HTTP_COUNT"; exit 1; }
TEST_MAIL_BODY=$(captured_json)
echo "$TEST_MAIL_BODY" | grep -q 'Test notification' || { echo "FAIL: the test email must be clearly labeled as a test, not read like a real backup outcome. Got: $TEST_MAIL_BODY"; exit 1; }
echo "OK  Send Test Notification dispatched to both enabled channels, clearly labeled as a test, not a real backup outcome"

echo
echo "=== Case J (Vex LOW-N-04): an immediate repeat 'Send Test Notification' click must be rejected by the cooldown ==="
THROTTLE_RESULT=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_notify_test&nonce=$NONCE3" "$BASE_URL/wp-admin/admin-ajax.php")
echo "$THROTTLE_RESULT" | grep -q '"success":false' || { echo "FAIL: an immediate repeat test-notification call must be rejected. Response: $THROTTLE_RESULT"; exit 1; }
echo "$THROTTLE_RESULT" | grep -qi 'wait' || { echo "FAIL: the rejection must explain why (wait a few seconds). Response: $THROTTLE_RESULT"; exit 1; }
echo "OK  an immediate repeat test-notification call is rejected by the cooldown"

echo
echo "=== Case J (continued): once the 10s cooldown passes, Send Test Notification works again ==="
sleep 11
reset_captured
AFTER_COOLDOWN=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_notify_test&nonce=$NONCE3" "$BASE_URL/wp-admin/admin-ajax.php")
echo "$AFTER_COOLDOWN" | grep -q '"success":true' || { echo "FAIL: test-notification must work again once the cooldown has passed. Response: $AFTER_COOLDOWN"; exit 1; }
echo "OK  test-notification works again once the cooldown has passed"

echo
echo "=== Case J (parallel burst, Vex T28 LOW-N-04-RESIDUAL reopened): N genuinely simultaneous claim calls after the cooldown has elapsed must allow exactly 1 through ==="
# Real regression test for the exact bug Vex found and fixed: the 2.4.1
# "fix" below used add_option() alone, which is only atomic on an
# option's very first-ever insert -- every call after that fell through
# to a plain read-compare-write with no atomicity. Proven live by Vex via
# a barrier-synchronized PHP harness (6 of 12 truly concurrent calls won
# a race that should allow exactly 1); this reproduces that same
# barrier-synchronization approach as a standing E2E case, not a curl
# burst (HTTP/process scheduling jitter could serialize curl requests
# enough to never actually exercise the race, giving a false pass).
sleep 11
BURST_DIR=$(mktemp -d)
BARRIER=$(date -d '+5 seconds' +%s.%N)
for i in $(seq 1 12); do
  wpr eval-file /fixtures/notify-throttle-claim.php "$BARRIER" > "$BURST_DIR/r$i.log" 2>&1 &
done
wait
BURST_WINS=$(grep -l '^WIN' "$BURST_DIR"/r*.log | wc -l | tr -d ' ')
BURST_TOTAL=$(ls "$BURST_DIR"/r*.log | wc -l | tr -d ' ')
rm -rf "$BURST_DIR"
[ "$BURST_TOTAL" = "12" ] || { echo "FAIL: expected 12 burst processes to report in, got $BURST_TOTAL"; exit 1; }
[ "$BURST_WINS" = "1" ] || { echo "FAIL: expected exactly 1 of 12 genuinely simultaneous claim calls to win, got $BURST_WINS. This is the exact race Vex proved live (6/12 wins against the old add_option()-only guard)."; exit 1; }
echo "OK  exactly 1 of 12 genuinely simultaneous claim calls won the claim (real compare-and-swap holds under true concurrency)"

echo
echo "=== Case K (Vex LOW-N-03): the 'Clear saved value' flag actually blanks a saved credential via the real Save Settings endpoint ==="
PAGE4=$(curl -s -c "$COOKIES" -b "$COOKIES" "$BASE_URL/wp-admin/admin.php?page=wpthistle-backup-restore")
NONCE4=$(echo "$PAGE4" | sed -n 's/.*var nonce = "\([a-f0-9]*\)".*/\1/p')
[ -n "$NONCE4" ] || { echo "FAIL: could not extract nonce for the clear-flag save"; exit 1; }
SAVE_WITH_SECRET=$(curl -s -c "$COOKIES" -b "$COOKIES" --data-urlencode "action=wpthistle_backup_save_settings" --data-urlencode "nonce=$NONCE4" \
  --data-urlencode "nextcloud_url=https://nc-proxy.wpguard-test.local" --data-urlencode "nextcloud_user=$NC_BACKUP_USER" --data-urlencode "app_password=$APP_PASSWORD" --data-urlencode "remote_folder=wpguard-backups" \
  --data-urlencode "notify_discord_enabled=1" --data-urlencode "notify_discord_webhook_url=https://discord.com/api/webhooks/123/abc" \
  "$BASE_URL/wp-admin/admin-ajax.php")
echo "$SAVE_WITH_SECRET" | grep -q '"success":true' || { echo "FAIL: setup save for the clear-flag case failed. Response: $SAVE_WITH_SECRET"; exit 1; }
BEFORE_CLEAR=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_all()['notify_discord_webhook_url'];")
[ "$BEFORE_CLEAR" = "https://discord.com/api/webhooks/123/abc" ] || { echo "FAIL: webhook URL wasn't actually saved before the clear attempt. Got: '$BEFORE_CLEAR'"; exit 1; }
SAVE_WITH_CLEAR=$(curl -s -c "$COOKIES" -b "$COOKIES" --data-urlencode "action=wpthistle_backup_save_settings" --data-urlencode "nonce=$NONCE4" \
  --data-urlencode "nextcloud_url=https://nc-proxy.wpguard-test.local" --data-urlencode "nextcloud_user=$NC_BACKUP_USER" --data-urlencode "app_password=$APP_PASSWORD" --data-urlencode "remote_folder=wpguard-backups" \
  --data-urlencode "notify_discord_enabled=1" --data-urlencode "notify_discord_webhook_url=" --data-urlencode "notify_discord_webhook_url_clear=1" \
  "$BASE_URL/wp-admin/admin-ajax.php")
echo "$SAVE_WITH_CLEAR" | grep -q '"success":true' || { echo "FAIL: the clear-flag save itself failed. Response: $SAVE_WITH_CLEAR"; exit 1; }
AFTER_CLEAR=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_all()['notify_discord_webhook_url'];")
[ "$AFTER_CLEAR" = "" ] || { echo "FAIL: the clear flag must blank the saved webhook URL via the real Save Settings endpoint, got '$AFTER_CLEAR'"; exit 1; }
echo "OK  the clear flag blanks a previously saved notification credential via the real Save Settings endpoint"

echo
echo "=== NOTIFICATIONS E2E TEST PASSED ==="
