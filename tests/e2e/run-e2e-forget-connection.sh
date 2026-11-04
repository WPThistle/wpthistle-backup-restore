#!/usr/bin/env bash
# "Forget Connection" admin action (Connection tab): proves the real
# wpthistle_backup_forget_connection AJAX endpoint over REAL authenticated
# HTTP (not just a direct Settings::forget_connection() PHP call, which
# tests/unit/SettingsValidationTest.php already covers). Checks the auth
# gating for real (unauthenticated + bad-nonce must both be refused), and
# that a real save/forget/save-after-forget cycle only ever touches the
# six connection fields -- schedule_mode/schedule_days/retention/
# incremental must survive untouched, same invariant the unit test
# checks directly against Settings::forget_connection(), here checked
# end-to-end through the actual HTTP handler instead.
#
# Runs against wp-restore (port 8082), never wp-source -- other suites
# depend on wp-source's own already-configured real Nextcloud credentials
# as a stable fixture, and this test ends by clearing credentials, which
# would break every suite that runs after it if it shared that fixture.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$ROOT/docker"
SCRATCH="$ROOT/.e2e-forget-connection-scratch"
rm -rf "$SCRATCH" && mkdir -p "$SCRATCH"
cd "$DOCKER_DIR"

env_val() { grep "^$1=" .env | cut -d= -f2-; }
WP_RESTORE_DB_PASSWORD=$(env_val WP_RESTORE_DB_PASSWORD)
WP_RESTORE_DB_ROOT_PASSWORD=$(env_val WP_RESTORE_DB_ROOT_PASSWORD)
PREFIX=$(env_val WP_RESTORE_TABLE_PREFIX)
BASE_URL="http://127.0.0.1:8082"
ADMIN_USER="wpadmin"
ADMIN_PASS="ForgetConnE2EAdminPW"

wpr() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container' || true
}

echo "=== Setup: fresh WordPress + plugin on wp-restore, seeded with fake connection + real-looking schedule settings ==="
docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null
wpr core install --url="$BASE_URL" --title="Forget Connection E2E Test" \
  --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpthistle-backup-restore > /dev/null
wpr eval "
  \WPThistle\BackupRestore\Settings::save(array(
    'backend' => 'owncloud',
    'nextcloud_url' => 'https://fake-nc.example.test',
    'nextcloud_user' => 'fakeuser',
    'app_password' => 'fake-app-password',
    'remote_folder' => 'wpthistle-backups',
    'ca_bundle_path' => '',
    'schedule_mode' => 'tiered',
    'schedule_cadence' => 'monthly',
    'schedule_day_of_month' => 15,
    'schedule_days' => array(1,3,5),
    'retention_count' => 9,
    'retention_daily' => 3,
    'retention_weekly' => 2,
    'retention_monthly' => 6,
    'incremental_enabled' => true,
  ));
" > /dev/null
HAS_CREDS=$(wpr eval "echo \WPThistle\BackupRestore\Settings::has_credentials() ? 'yes' : 'no';")
[ "$HAS_CREDS" = "yes" ] || { echo "FAIL: seeded connection settings didn't take"; exit 1; }
echo "OK  seeded: owncloud/fake-nc.example.test + tiered schedule + custom retention"

echo
echo "=== Real authenticated HTTP login (cookie jar, not wp eval) ==="
COOKIES="$SCRATCH/cookies.txt"
# `|| true` on both: on at least this host, `curl -o /dev/null`
# intermittently exits nonzero (CURLE_WRITE_ERROR) even on a normal
# response -- a local git-bash/MSYS `/dev/null` quirk, not a real
# request failure. The actual login verification is the NONCE
# extraction below, which this must not be allowed to mask under
# `set -e`.
curl -s -c "$COOKIES" "$BASE_URL/wp-login.php" -o /dev/null || true
curl -s -c "$COOKIES" -b "$COOKIES" \
  --data-urlencode "log=$ADMIN_USER" --data-urlencode "pwd=$ADMIN_PASS" \
  -d "wp-submit=Log+In&redirect_to=$BASE_URL%2Fwp-admin%2F&testcookie=1" \
  "$BASE_URL/wp-login.php" -o /dev/null || true
PAGE=$(curl -s -c "$COOKIES" -b "$COOKIES" "$BASE_URL/wp-admin/admin.php?page=wpthistle-backup-restore")
[[ "$PAGE" == *"wpthistle_backup_forget_btn"* ]] || { echo "FAIL: could not load the authenticated settings page, or Forget Connection markup is missing (login failed?)"; exit 1; }
NONCE=$(echo "$PAGE" | sed -n 's/.*var nonce = "\([a-f0-9]*\)".*/\1/p')
[ -n "$NONCE" ] || { echo "FAIL: could not extract nonce from settings page"; exit 1; }
echo "OK  authenticated session established, nonce extracted"

echo
echo "=== Case A: unauthenticated request must be refused, not clear anything ==="
# No wp_ajax_nopriv_ counterpart is registered for this action (same as
# every other privileged action in this plugin) -- WP core itself
# intercepts a logged-out request with wp_die('0', ..., 400) before our
# own capability check ever runs, so 400 (not this plugin's own 403) is
# the correct expectation here.
CODE_NOAUTH=$(curl -s -o "$SCRATCH/noauth.json" -w '%{http_code}' -d "action=wpthistle_backup_forget_connection&nonce=$NONCE" "$BASE_URL/wp-admin/admin-ajax.php")
[ "$CODE_NOAUTH" = "400" ] || { echo "FAIL: unauthenticated forget-connection request must return 400 (no nopriv handler registered), got $CODE_NOAUTH ($(cat "$SCRATCH/noauth.json"))"; exit 1; }
HAS_CREDS_AFTER_A=$(wpr eval "echo \WPThistle\BackupRestore\Settings::has_credentials() ? 'yes' : 'no';")
[ "$HAS_CREDS_AFTER_A" = "yes" ] || { echo "FAIL: an unauthenticated request must not have cleared the connection"; exit 1; }
echo "OK  unauthenticated request refused (400, no nopriv handler), connection untouched"

echo
echo "=== Case B: authenticated but wrong nonce must be refused, not clear anything ==="
CODE_BADNONCE=$(curl -s -c "$COOKIES" -b "$COOKIES" -o "$SCRATCH/badnonce.json" -w '%{http_code}' -d "action=wpthistle_backup_forget_connection&nonce=deadbeef" "$BASE_URL/wp-admin/admin-ajax.php")
[ "$CODE_BADNONCE" = "403" ] || { echo "FAIL: wrong-nonce forget-connection request must return 403, got $CODE_BADNONCE ($(cat "$SCRATCH/badnonce.json"))"; exit 1; }
HAS_CREDS_AFTER_B=$(wpr eval "echo \WPThistle\BackupRestore\Settings::has_credentials() ? 'yes' : 'no';")
[ "$HAS_CREDS_AFTER_B" = "yes" ] || { echo "FAIL: a wrong-nonce request must not have cleared the connection"; exit 1; }
echo "OK  wrong-nonce request refused (403), connection untouched"

echo
echo "=== Case C: real authenticated + correctly-nonced request clears only the connection fields ==="
RESP_C=$(curl -s -c "$COOKIES" -b "$COOKIES" -d "action=wpthistle_backup_forget_connection&nonce=$NONCE" "$BASE_URL/wp-admin/admin-ajax.php")
[[ "$RESP_C" == *'"success":true'* ]] || { echo "FAIL: authenticated, correctly-nonced forget-connection request must succeed, got: $RESP_C"; exit 1; }
echo "OK  request succeeded: $RESP_C"

AFTER=$(wpr eval "
  \$s = \WPThistle\BackupRestore\Settings::get_all();
  echo json_encode(\$s);
")
echo "AFTER: $AFTER"

HAS_CREDS_AFTER_C=$(wpr eval "echo \WPThistle\BackupRestore\Settings::has_credentials() ? 'yes' : 'no';")
[ "$HAS_CREDS_AFTER_C" = "no" ] || { echo "FAIL: has_credentials() must be false after a real forget-connection request"; exit 1; }

SCHEDULE_MODE_AFTER=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_all()['schedule_mode'];")
[ "$SCHEDULE_MODE_AFTER" = "tiered" ] || { echo "FAIL: schedule_mode must survive forgetting the connection, got '$SCHEDULE_MODE_AFTER'"; exit 1; }
RETENTION_DAILY_AFTER=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_all()['retention_daily'];")
[ "$RETENTION_DAILY_AFTER" = "3" ] || { echo "FAIL: retention_daily must survive forgetting the connection, got '$RETENTION_DAILY_AFTER'"; exit 1; }
INCREMENTAL_AFTER=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_all()['incremental_enabled'] ? 'yes' : 'no';")
[ "$INCREMENTAL_AFTER" = "yes" ] || { echo "FAIL: incremental_enabled must survive forgetting the connection, got '$INCREMENTAL_AFTER'"; exit 1; }
echo "OK  connection cleared (has_credentials=no); schedule_mode=tiered, retention_daily=3, incremental_enabled=yes all survived untouched"

echo
echo "=== All forget-connection E2E cases passed ==="
