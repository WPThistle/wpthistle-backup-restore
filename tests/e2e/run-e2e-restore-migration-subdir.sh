#!/usr/bin/env bash
# Phase 2.5 follow-up (2.7.0 regression gap): the ordinary cross-domain
# migration test (run-e2e-restore-migration.sh) never exercised a
# subdirectory-shaped home_url on either side -- the exact shape the
# readme.txt FAQ and docs/operations.md explicitly promise is supported
# (different domain landing in a subdirectory) and explicitly promise is
# refused (same-host extension, either direction -- MEDIUM-MIG-3, fixed
# in 2.7.0). This was previously only unit-tested (DbMigratorTest.php);
# this script proves the same shapes live, through the real restore
# pipeline (real MySQL, shadow tables, serialization-safe rewrite,
# preflight refusal), end to end.
#
# Four scenarios:
#   1. https://old-site.example            -> http://127.0.0.1:8082/blog   expect VERIFIED (supported: different domain into a subdirectory)
#   2. https://old-site.example/blog       -> http://127.0.0.1:8082        expect VERIFIED (supported: different domain, old side has a path)
#   3. http://127.0.0.1:8082               -> http://127.0.0.1:8082/blog   expect REFUSED  (same host, extension -- MEDIUM-MIG-3 shape)
#   4. http://127.0.0.1:8082/blog          -> http://127.0.0.1:8082        expect REFUSED  (same host, reverse extension -- MEDIUM-MIG-3 itself)
#
# Sites are installed with --url pointing at the subdirectory path, but
# WordPress core files stay at the container docroot -- nothing here
# actually serves HTTP traffic at /blog (no Apache alias/rewrite is
# configured for that). The plugin only ever reads/writes home_url/
# siteurl as strings; it never touches the webserver's routing, so this
# is a representative test of the migration logic and of the real
# restore pipeline, but it does NOT prove a site physically reachable at
# a subdirectory URL serves correctly over HTTP. Scenario 1/2's HTTP
# checks are adjusted accordingly -- see inline comments at each.
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

if ! grep -q "^WP_RESTORE_TABLE_PREFIX=${PREFIX}$" .env; then
  sed -i "s/^WP_RESTORE_TABLE_PREFIX=.*/WP_RESTORE_TABLE_PREFIX=${PREFIX}/" .env
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env up -d wp-restore > /dev/null
fi

wpr() {
  MSYS_NO_PATHCONV=1 docker compose -p wpguard-test --env-file .env run --rm \
    -e WORDPRESS_DB_HOST=db-restore -e WORDPRESS_DB_NAME=wp_restore \
    -e WORDPRESS_DB_USER=wp_restore -e WORDPRESS_DB_PASSWORD="$WP_RESTORE_DB_PASSWORD" \
    -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    wpcli wp --path=/var/www/html-restore "$@" 2>&1 | grep -v '^ Container' || true
}
fresh_db() {
  docker compose -p wpguard-test exec -T db-restore mariadb -u root -p"$WP_RESTORE_DB_ROOT_PASSWORD" \
    -e "DROP DATABASE IF EXISTS wp_restore; CREATE DATABASE wp_restore; GRANT ALL ON wp_restore.* TO 'wp_restore'@'%';" > /dev/null
}
configure_nextcloud() {
  APP_PASSWORD=$(curl -sk --cacert certs/wpguard-test-ca.crt -u "$NC_BACKUP_USER:$NC_BACKUP_USER_PASSWORD" \
    -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
    https://127.0.0.1:8443/ocs/v2.php/core/getapppassword | sed -n 's/.*"apppassword":"\([^"]*\)".*/\1/p')
  wpr eval "
    \WPThistle\BackupRestore\Settings::save(array(
      'nextcloud_url' => 'https://nc-proxy.wpguard-test.local',
      'nextcloud_user' => '$NC_BACKUP_USER',
      'app_password' => '$APP_PASSWORD',
      'remote_folder' => 'wpguard-backups',
      'ca_bundle_path' => '/usr/local/share/ca-certificates/wpguard-test-ca.crt',
    ));
  " > /dev/null
}

# --- Scenario 1: different domain, landing in a subdirectory (expect VERIFIED) ---
echo "=== SCENARIO 1: https://old-site.example -> http://127.0.0.1:8082/blog (expect VERIFIED) ==="
fresh_db
wpr core install --url="https://old-site.example" --title="Old Site" \
  --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpthistle-backup-restore > /dev/null
configure_nextcloud
POST_ID=$(wpr post create --post_title="Welcome" --post_status=publish \
  --post_content='<p>Visit our <a href="https://old-site.example/legacy-page">main page</a>, or the <a href="http://old-site.example/even-older-page">archived version</a>.</p><p>Email us at admin@old-site.example for help.</p>' \
  --porcelain)
wpr eval "update_option( 'widget_text-2', array( 'title' => 'Links', 'text' => 'See https://old-site.example/widget-target for details.' ) );" > /dev/null
echo "OK  old site seeded"

wpr wpthistle-backup-restore run > /dev/null 2>&1 || true
RESULT_A=""
for attempt in 1 2 3 4 5; do
  RESULT_A=$(wpr eval '$j = \WPThistle\BackupRestore\Job_Store::get_latest(); echo ($j["backup_id"] ?? "") . "|" . ($j["status"] ?? "");')
  [ -n "$RESULT_A" ] && [[ "$RESULT_A" == *"|"* ]] && break
  sleep 2
done
BACKUP_1="${RESULT_A%%|*}"
[ "${RESULT_A##*|}" = "verified" ] || { echo "FAIL: scenario 1 backup did not verify, got '${RESULT_A##*|}'"; exit 1; }
SITE_ID_1=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_site_id();")
echo "OK  scenario 1 backup ($BACKUP_1) verified, site_id $SITE_ID_1"

fresh_db
wpr core install --url="http://127.0.0.1:8082/blog" --title="New Site" \
  --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
HOME_CHECK_1=$(wpr eval "echo home_url();")
[ "$HOME_CHECK_1" = "http://127.0.0.1:8082/blog" ] || { echo "FAIL: scenario 1 new site's home_url came back as '$HOME_CHECK_1', not the intended subdirectory URL -- wp-cli or WP_HOME must have flattened it"; exit 1; }
wpr plugin activate wpthistle-backup-restore > /dev/null
configure_nextcloud
echo "OK  new site installed at http://127.0.0.1:8082/blog, home_url confirmed"

HOLDING_1="/var/www/html-restore/wp-content/migration-test-upload-s1/"
wpr eval "
  \$client = \WPThistle\BackupRestore\Nextcloud_Client::from_settings( new \WPThistle\BackupRestore\Logger( array( \WPThistle\BackupRestore\Settings::get_app_password() ) ) );
  \$parent_rel = \$client->relative_path( '$SITE_ID_1/backups/$BACKUP_1' );
  \$holding = '$HOLDING_1';
  wp_mkdir_p( \$holding );
  foreach ( array( 'manifest.json', 'COMPLETE.json' ) as \$name ) {
    \$client->download_to_file( \$parent_rel . '/' . \$name, \$holding . \$name, 8 * 1024 * 1024 );
  }
  \$manifest = json_decode( file_get_contents( \$holding . 'manifest.json' ), true );
  foreach ( (array) ( \$manifest['components'] ?? array() ) as \$component ) {
    \$client->download_to_file( \$parent_rel . '/' . \$component['path'], \$holding . \$component['path'], (int) \$component['bytes'] + 1024 );
  }
  echo 'done';
" > /dev/null

JOB_1=$(wpr eval "echo \WPThistle\BackupRestore\Restore_Orchestrator::create_restore_job(array('type'=>'upload','upload_dir'=>'$HOLDING_1'), '$BACKUP_1', true);")
[ -n "$JOB_1" ] || { echo "FAIL: scenario 1 could not create restore job"; exit 1; }
for i in $(seq 1 200); do
  RESULT=$(wpr eval "\$j = \WPThistle\BackupRestore\Restore_Orchestrator::advance('$JOB_1'); echo \$j['step'] . '|' . \$j['status'];")
  [[ "$RESULT" == *"|verified"* ]] && break
  if [[ "$RESULT" == *"|failed"* ]]; then echo "FAIL: scenario 1 restore job failed: $RESULT"; exit 1; fi
done
FINAL_1=$(wpr eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_1')['status'];")
[ "$FINAL_1" = "verified" ] || { echo "FAIL: scenario 1 restore did not reach verified, got '$FINAL_1'"; exit 1; }
echo "OK  scenario 1 restore reached verified"

NEW_SITEURL_1=$(wpr option get siteurl)
NEW_HOME_1=$(wpr option get home)
[ "$NEW_SITEURL_1" = "http://127.0.0.1:8082/blog" ] || { echo "FAIL: scenario 1 siteurl is '$NEW_SITEURL_1'"; exit 1; }
[ "$NEW_HOME_1" = "http://127.0.0.1:8082/blog" ] || { echo "FAIL: scenario 1 home is '$NEW_HOME_1'"; exit 1; }
echo "OK  scenario 1 siteurl and home both correctly carry the /blog suffix"

POST_CONTENT_1=$(wpr post get "$POST_ID" --field=content)
[[ "$POST_CONTENT_1" == *"http://127.0.0.1:8082/blog/legacy-page"* ]] || { echo "FAIL: scenario 1 https link not migrated correctly: $POST_CONTENT_1"; exit 1; }
[[ "$POST_CONTENT_1" == *"http://127.0.0.1:8082/blog/even-older-page"* ]] || { echo "FAIL: scenario 1 scheme-mismatched link not migrated correctly: $POST_CONTENT_1"; exit 1; }
[[ "$POST_CONTENT_1" == *"admin@old-site.example"* ]] || { echo "FAIL: scenario 1 bare-hostname email was altered: $POST_CONTENT_1"; exit 1; }
echo "OK  scenario 1 links correctly gained the /blog suffix; bare-hostname mention untouched"

WIDGET_CHECK_1=$(wpr eval "
  \$u = get_option( 'widget_text-2' );
  if ( ! is_array( \$u ) ) { echo 'CORRUPT'; exit; }
  echo ( false !== strpos( \$u['text'], 'http://127.0.0.1:8082/blog/widget-target' ) ) ? 'OK' : 'WRONG:' . \$u['text'];
")
[ "$WIDGET_CHECK_1" = "OK" ] || { echo "FAIL: scenario 1 serialized widget option: $WIDGET_CHECK_1"; exit 1; }
echo "OK  scenario 1 serialized widget option survived with the /blog suffix"

# No Apache alias exists for /blog in this test stack -- a GET to
# .../blog/anything would 404. What's provable over real HTTP here is
# that WordPress's own not-logged-in redirect (built from the just-
# migrated siteurl, not something wp-cli touches) correctly names the
# subdirectory and never bounces toward the old domain. Deliberately not
# following the redirect -- its target isn't actually routed.
REDIRECT_1=$(curl -sI http://127.0.0.1:8082/wp-admin/ 2>&1 | grep -i '^location:' || true)
[[ "$REDIRECT_1" != *"old-site.example"* ]] || { echo "FAIL: scenario 1 redirect still points at old domain: $REDIRECT_1"; exit 1; }
[[ "$REDIRECT_1" == *"127.0.0.1:8082/blog/wp-login.php"* ]] || { echo "FAIL: scenario 1 redirect doesn't name the new subdirectory login path: $REDIRECT_1"; exit 1; }
echo "OK  scenario 1 wp-admin's not-logged-in redirect correctly names 127.0.0.1:8082/blog/wp-login.php"
echo

# --- Scenario 2: different domain, old side has a path (expect VERIFIED) ---
echo "=== SCENARIO 2: https://old-site.example/blog -> http://127.0.0.1:8082 (expect VERIFIED) ==="
fresh_db
wpr core install --url="https://old-site.example/blog" --title="Old Site" \
  --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
HOME_CHECK_2OLD=$(wpr eval "echo home_url();")
[ "$HOME_CHECK_2OLD" = "https://old-site.example/blog" ] || { echo "FAIL: scenario 2 old site's home_url came back as '$HOME_CHECK_2OLD'"; exit 1; }
wpr plugin activate wpthistle-backup-restore > /dev/null
configure_nextcloud
POST_ID_2=$(wpr post create --post_title="Welcome" --post_status=publish \
  --post_content='<p>Visit our <a href="https://old-site.example/blog/page">main page</a>. Also see our <a href="https://old-site.example/blogroll">blogroll</a> and our <a href="https://old-site.example/shop">shop</a>.</p>' \
  --porcelain)
echo "OK  old site seeded at https://old-site.example/blog, with sibling-looking /blogroll and /shop links that must NOT be touched"

wpr wpthistle-backup-restore run > /dev/null 2>&1 || true
RESULT_B=""
for attempt in 1 2 3 4 5; do
  RESULT_B=$(wpr eval '$j = \WPThistle\BackupRestore\Job_Store::get_latest(); echo ($j["backup_id"] ?? "") . "|" . ($j["status"] ?? "");')
  [ -n "$RESULT_B" ] && [[ "$RESULT_B" == *"|"* ]] && break
  sleep 2
done
BACKUP_2="${RESULT_B%%|*}"
[ "${RESULT_B##*|}" = "verified" ] || { echo "FAIL: scenario 2 backup did not verify, got '${RESULT_B##*|}'"; exit 1; }
SITE_ID_2=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_site_id();")
echo "OK  scenario 2 backup ($BACKUP_2) verified, site_id $SITE_ID_2"

fresh_db
wpr core install --url="http://127.0.0.1:8082" --title="New Site" \
  --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
wpr plugin activate wpthistle-backup-restore > /dev/null
configure_nextcloud
echo "OK  new site installed at http://127.0.0.1:8082"

HOLDING_2="/var/www/html-restore/wp-content/migration-test-upload-s2/"
wpr eval "
  \$client = \WPThistle\BackupRestore\Nextcloud_Client::from_settings( new \WPThistle\BackupRestore\Logger( array( \WPThistle\BackupRestore\Settings::get_app_password() ) ) );
  \$parent_rel = \$client->relative_path( '$SITE_ID_2/backups/$BACKUP_2' );
  \$holding = '$HOLDING_2';
  wp_mkdir_p( \$holding );
  foreach ( array( 'manifest.json', 'COMPLETE.json' ) as \$name ) {
    \$client->download_to_file( \$parent_rel . '/' . \$name, \$holding . \$name, 8 * 1024 * 1024 );
  }
  \$manifest = json_decode( file_get_contents( \$holding . 'manifest.json' ), true );
  foreach ( (array) ( \$manifest['components'] ?? array() ) as \$component ) {
    \$client->download_to_file( \$parent_rel . '/' . \$component['path'], \$holding . \$component['path'], (int) \$component['bytes'] + 1024 );
  }
  echo 'done';
" > /dev/null

JOB_2=$(wpr eval "echo \WPThistle\BackupRestore\Restore_Orchestrator::create_restore_job(array('type'=>'upload','upload_dir'=>'$HOLDING_2'), '$BACKUP_2', true);")
[ -n "$JOB_2" ] || { echo "FAIL: scenario 2 could not create restore job"; exit 1; }
for i in $(seq 1 200); do
  RESULT=$(wpr eval "\$j = \WPThistle\BackupRestore\Restore_Orchestrator::advance('$JOB_2'); echo \$j['step'] . '|' . \$j['status'];")
  [[ "$RESULT" == *"|verified"* ]] && break
  if [[ "$RESULT" == *"|failed"* ]]; then echo "FAIL: scenario 2 restore job failed: $RESULT"; exit 1; fi
done
FINAL_2=$(wpr eval "echo \WPThistle\BackupRestore\Job_Store::get('$JOB_2')['status'];")
[ "$FINAL_2" = "verified" ] || { echo "FAIL: scenario 2 restore did not reach verified, got '$FINAL_2'"; exit 1; }
echo "OK  scenario 2 restore reached verified"

NEW_SITEURL_2=$(wpr option get siteurl)
[ "$NEW_SITEURL_2" = "http://127.0.0.1:8082" ] || { echo "FAIL: scenario 2 siteurl is '$NEW_SITEURL_2'"; exit 1; }
echo "OK  scenario 2 siteurl correctly dropped the /blog suffix"

POST_CONTENT_2=$(wpr post get "$POST_ID_2" --field=content)
[[ "$POST_CONTENT_2" == *'href="http://127.0.0.1:8082/page"'* ]] || { echo "FAIL: scenario 2 /blog/page link not migrated correctly: $POST_CONTENT_2"; exit 1; }
[[ "$POST_CONTENT_2" == *'href="https://old-site.example/blogroll"'* ]] || { echo "FAIL: scenario 2 sibling /blogroll link was wrongly touched (boundary lookahead failed live): $POST_CONTENT_2"; exit 1; }
[[ "$POST_CONTENT_2" == *'href="https://old-site.example/shop"'* ]] || { echo "FAIL: scenario 2 sibling /shop link was wrongly touched (boundary lookahead failed live): $POST_CONTENT_2"; exit 1; }
echo "OK  scenario 2: /blog/page correctly migrated; sibling /blogroll and /shop correctly survived untouched -- live proof of the boundary lookahead with a path on the old side"

LOGIN_REDIRECT_2=$(curl -sI http://127.0.0.1:8082/wp-admin/ 2>&1 | grep -i '^location:' || true)
[[ "$LOGIN_REDIRECT_2" != *"old-site.example"* ]] || { echo "FAIL: scenario 2 redirect still points at old domain: $LOGIN_REDIRECT_2"; exit 1; }
[[ "$LOGIN_REDIRECT_2" == *"127.0.0.1:8082"* ]] || { echo "FAIL: scenario 2 redirect doesn't point at the new site: $LOGIN_REDIRECT_2"; exit 1; }
LOGIN_PAGE_2=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8082/wp-login.php) || true
[ "$LOGIN_PAGE_2" = "200" ] || { echo "FAIL: scenario 2 wp-login.php returned $LOGIN_PAGE_2"; exit 1; }
echo "OK  scenario 2 wp-admin redirect and wp-login.php both confirmed live over real HTTP (core sits at the docroot here, so this is a full, routable check)"
echo

# --- Scenarios 3 & 4: same-host extension, either direction (expect REFUSED) ---
run_refusal_scenario() {
  local LABEL="$1" OLD_URL="$2" NEW_URL="$3"
  echo "=== $LABEL: $OLD_URL -> $NEW_URL (expect REFUSED, MEDIUM-MIG-3 shape) ==="
  fresh_db
  wpr core install --url="$OLD_URL" --title="Old Shape" \
    --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
  wpr plugin activate wpthistle-backup-restore > /dev/null
  configure_nextcloud
  wpr post create --post_title="Welcome" --post_status=publish --post_content='<p>hello</p>' --porcelain > /dev/null

  wpr wpthistle-backup-restore run > /dev/null 2>&1 || true
  local RESULT=""
  for attempt in 1 2 3 4 5; do
    RESULT=$(wpr eval '$j = \WPThistle\BackupRestore\Job_Store::get_latest(); echo ($j["backup_id"] ?? "") . "|" . ($j["status"] ?? "");')
    [ -n "$RESULT" ] && [[ "$RESULT" == *"|"* ]] && break
    sleep 2
  done
  local BACKUP="${RESULT%%|*}"
  [ "${RESULT##*|}" = "verified" ] || { echo "FAIL: $LABEL backup did not verify, got '${RESULT##*|}'"; exit 1; }
  local SITE_ID
  SITE_ID=$(wpr eval "echo \WPThistle\BackupRestore\Settings::get_site_id();")
  echo "OK  $LABEL backup ($BACKUP) verified, site_id $SITE_ID"

  fresh_db
  wpr core install --url="$NEW_URL" --title="New Shape" \
    --admin_user=wpadmin --admin_password=MigrationE2EAdminPW --admin_email=wpadmin@wpguard-test.local --skip-email > /dev/null
  wpr plugin activate wpthistle-backup-restore > /dev/null
  configure_nextcloud
  # Sentinel: proof the refusal leaves the new site completely untouched.
  wpr option update wpthistle_migration_sentinel "untouched-$LABEL" > /dev/null
  SENTINEL_POST=$(wpr post create --post_title="New Shape Sentinel" --post_status=publish --post_content='<p>sentinel</p>' --porcelain)

  local HOLDING="/var/www/html-restore/wp-content/migration-test-upload-${LABEL// /_}/"
  wpr eval "
    \$client = \WPThistle\BackupRestore\Nextcloud_Client::from_settings( new \WPThistle\BackupRestore\Logger( array( \WPThistle\BackupRestore\Settings::get_app_password() ) ) );
    \$parent_rel = \$client->relative_path( '$SITE_ID/backups/$BACKUP' );
    \$holding = '$HOLDING';
    wp_mkdir_p( \$holding );
    foreach ( array( 'manifest.json', 'COMPLETE.json' ) as \$name ) {
      \$client->download_to_file( \$parent_rel . '/' . \$name, \$holding . \$name, 8 * 1024 * 1024 );
    }
    \$manifest = json_decode( file_get_contents( \$holding . 'manifest.json' ), true );
    foreach ( (array) ( \$manifest['components'] ?? array() ) as \$component ) {
      \$client->download_to_file( \$parent_rel . '/' . \$component['path'], \$holding . \$component['path'], (int) \$component['bytes'] + 1024 );
    }
    echo 'done';
  " > /dev/null

  local JOB
  JOB=$(wpr eval "echo \WPThistle\BackupRestore\Restore_Orchestrator::create_restore_job(array('type'=>'upload','upload_dir'=>'$HOLDING'), '$BACKUP', true);")
  [ -n "$JOB" ] || { echo "FAIL: $LABEL could not create restore job"; exit 1; }

  # create_restore_job() always returns a job id string and advance()
  # always returns the job row array -- the guard's WP_Error is caught
  # internally by fail(), which stores its get_error_message() text (not
  # the code) into the job row's 'error' field and leaves 'status'
  # 'failed'. Match on that message text instead of an error code.
  local REFUSED=0
  for i in $(seq 1 200); do
    RESULT=$(wpr eval "\$j = \WPThistle\BackupRestore\Restore_Orchestrator::advance('$JOB'); echo \$j['step'] . '|' . \$j['status'];")
    [[ "$RESULT" == *"|verified"* ]] && { echo "FAIL: $LABEL restore reached verified -- expected a refusal"; exit 1; }
    if [[ "$RESULT" == *"|failed"* ]]; then
      REFUSED=1
      break
    fi
  done
  [ "$REFUSED" = "1" ] || { echo "FAIL: $LABEL restore neither failed nor verified within the step budget"; exit 1; }

  local JOB_ROW
  JOB_ROW=$(wpr eval "\$j = \WPThistle\BackupRestore\Job_Store::get('$JOB'); echo (\$j['step'] ?? '') . '|' . (\$j['error'] ?? '');")
  [[ "$JOB_ROW" == "restore_preflight|"* ]] || { echo "FAIL: $LABEL did not fail at restore_preflight (still read-only): $JOB_ROW"; exit 1; }
  [[ "$JOB_ROW" == *"Refusing to migrate URLs for this restore rather than risk corrupting them on a retry."* ]] || { echo "FAIL: $LABEL failed for the wrong reason: $JOB_ROW"; exit 1; }
  echo "OK  $LABEL failed at restore_preflight (still read-only) with the symmetric idempotency-guard refusal"

  local SITEURL_AFTER SENTINEL_AFTER SENTINEL_POST_AFTER OLD_POST_PRESENT
  SITEURL_AFTER=$(wpr option get siteurl)
  [ "$SITEURL_AFTER" = "$NEW_URL" ] || { echo "FAIL: $LABEL new site's siteurl changed to '$SITEURL_AFTER' despite the refusal"; exit 1; }
  SENTINEL_AFTER=$(wpr option get wpthistle_migration_sentinel)
  [ "$SENTINEL_AFTER" = "untouched-$LABEL" ] || { echo "FAIL: $LABEL sentinel option was altered despite the refusal: $SENTINEL_AFTER"; exit 1; }
  SENTINEL_POST_AFTER=$(wpr post get "$SENTINEL_POST" --field=post_title 2>/dev/null || echo "MISSING")
  [ "$SENTINEL_POST_AFTER" = "New Shape Sentinel" ] || { echo "FAIL: $LABEL sentinel post was altered/removed despite the refusal"; exit 1; }
  OLD_POST_PRESENT=$(wpr post list --post_type=post --field=post_title 2>/dev/null | grep -c '^Welcome$' || true)
  [ "$OLD_POST_PRESENT" = "0" ] || { echo "FAIL: $LABEL old site's 'Welcome' post is present on the new site despite the refusal -- the refusal did not actually stop the restore"; exit 1; }
  echo "OK  $LABEL new site confirmed completely untouched: siteurl unchanged, sentinel option and post intact, old site's content absent"
  echo
}

run_refusal_scenario "SCENARIO 3" "http://127.0.0.1:8082" "http://127.0.0.1:8082/blog"
run_refusal_scenario "SCENARIO 4" "http://127.0.0.1:8082/blog" "http://127.0.0.1:8082"

echo "=== RESTORE MIGRATION SUBDIRECTORY TEST (ALL 4 SCENARIOS) PASSED ==="
