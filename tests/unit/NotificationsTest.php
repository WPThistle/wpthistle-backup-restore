<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Notifications;

// Covers only the pure request builders (exact URL/headers/body shape,
// and each channel's own documented formatting limits). The actual
// network call (send_request()/dispatch_all()) needs a real WP HTTP
// stack and is exercised instead by tests/e2e/run-e2e-alert-emails.sh's
// pre_http_request mock.

function wpguard_test_job_verified() {
	return array( 'status' => 'verified', 'backup_id' => '20261102T120000Z-aabbccdd', 'updated_at' => '2026-11-02 12:00:30' );
}
function wpguard_test_job_failed() {
	return array( 'status' => 'failed', 'backup_id' => '20261102T120000Z-aabbccdd', 'updated_at' => '2026-11-02 12:00:30', 'error' => 'Could not connect.' );
}

function test_build_discord_request_posts_json_with_content_and_no_mentions() {
	$req = Notifications::build_discord_request( 'https://discord.com/api/webhooks/1/abc', 'Title', 'Body text' );
	wpguard_assert_equals( 'https://discord.com/api/webhooks/1/abc', $req['url'], 'must post to the webhook URL as given' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( str_contains( $body['content'], 'Title' ) && str_contains( $body['content'], 'Body text' ), 'content must include both title and message' );
	wpguard_assert_equals( array(), $body['allowed_mentions']['parse'], 'allowed_mentions must be empty so an @everyone in an error message can never actually ping anyone' );
}

function test_build_discord_request_truncates_to_2000_chars() {
	$req = Notifications::build_discord_request( 'https://discord.com/x', 'T', str_repeat( 'a', 3000 ) );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( strlen( $body['content'] ) <= 2000, "Discord's own hard limit is 2000 characters, got " . strlen( $body['content'] ) );
}

function test_build_slack_request_escapes_ampersand_and_angle_brackets() {
	$req = Notifications::build_slack_request( 'https://hooks.slack.com/x', 'T', 'A & B <tag> done' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( str_contains( $body['text'], '&amp;' ) && str_contains( $body['text'], '&lt;tag&gt;' ), "Slack's mrkdwn format requires &, <, > escaped or rendering breaks" );
}

function test_build_pushover_request_is_form_body_with_token_and_user() {
	$req = Notifications::build_pushover_request( 'user-key-123', 'api-token-456', 'Title', 'Message' );
	wpguard_assert_equals( 'https://api.pushover.net/1/messages.json', $req['url'], 'wrong Pushover endpoint' );
	wpguard_assert_equals( 'api-token-456', $req['args']['body']['token'], 'token field must carry the API token' );
	wpguard_assert_equals( 'user-key-123', $req['args']['body']['user'], 'user field must carry the user key' );
}

function test_build_pushover_request_truncates_to_documented_limits() {
	$req = Notifications::build_pushover_request( 'u', 't', str_repeat( 'x', 500 ), str_repeat( 'y', 2000 ) );
	wpguard_assert( strlen( $req['args']['body']['title'] ) <= 250, 'Pushover title limit is 250 characters' );
	wpguard_assert( strlen( $req['args']['body']['message'] ) <= 1024, 'Pushover message limit is 1024 characters' );
}

function test_build_pushbullet_request_sends_access_token_as_header_not_body() {
	$req = Notifications::build_pushbullet_request( 'secret-token', 'Title', 'Message' );
	wpguard_assert_equals( 'secret-token', $req['args']['headers']['Access-Token'], 'Pushbullet auth is a header, not a body field' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( ! isset( $body['token'] ) && ! isset( $body['access_token'] ), 'the token must never also be duplicated into the JSON body' );
	wpguard_assert_equals( 'note', $body['type'], 'Pushbullet push type must be "note"' );
}

function test_build_ntfy_request_splits_server_root_from_topic() {
	$req = Notifications::build_ntfy_request( 'https://ntfy.sh/my-private-topic', '', 'Title', 'Message' );
	wpguard_assert_equals( 'https://ntfy.sh/', $req['url'], 'must publish to the server root, not the topic path' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'my-private-topic', $body['topic'], 'topic must be split out of the subscribe URL' );
	wpguard_assert( ! isset( $req['args']['headers']['Authorization'] ), 'no Authorization header when no token is configured' );
}

function test_build_ntfy_request_adds_bearer_token_when_present() {
	$req = Notifications::build_ntfy_request( 'https://ntfy.example.com/topic', 'tk_abc', 'T', 'M' );
	wpguard_assert_equals( 'Bearer tk_abc', $req['args']['headers']['Authorization'], 'a configured token must be sent as a Bearer header' );
}

function test_build_ntfy_request_uses_json_not_title_header() {
	$req = Notifications::build_ntfy_request( 'https://ntfy.sh/t', '', 'Título con ñ', 'Message' );
	wpguard_assert( ! isset( $req['args']['headers']['Title'] ), 'title must travel in the JSON body, never a Title: header, since a non-ASCII site name breaks an HTTP header value' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'Título con ñ', $body['title'], 'the JSON body must carry the real title text unescaped' );
}

function test_build_gotify_request_puts_token_in_query_string() {
	$req = Notifications::build_gotify_request( 'https://gotify.example.com', 'my-token', 'Title', 'Message' );
	wpguard_assert_equals( 'https://gotify.example.com/message?token=my-token', $req['url'], "Gotify's documented auth convention for this endpoint is a token query parameter" );
}

function test_build_gotify_request_strips_trailing_slash_from_server_url() {
	$req = Notifications::build_gotify_request( 'https://gotify.example.com/', 'tok', 'T', 'M' );
	wpguard_assert_equals( 'https://gotify.example.com/message?token=tok', $req['url'], 'a trailing slash on the saved server URL must not produce a double slash' );
}

function test_build_telegram_request_has_no_parse_mode() {
	$req = Notifications::build_telegram_request( '123:ABC', '98765', 'Title', 'Backup 20261102_abc failed: *oops*' );
	wpguard_assert( ! isset( $req['args']['body']['parse_mode'] ), 'parse_mode must never be set: an underscore or asterisk in a backup ID/error message would otherwise trip Markdown parsing into a 400' );
	wpguard_assert_equals( '98765', $req['args']['body']['chat_id'], 'chat_id must be sent as given' );
}

function test_build_telegram_request_puts_bot_token_in_url_path() {
	$req = Notifications::build_telegram_request( '123:ABC-token', '1', 'T', 'M' );
	wpguard_assert_equals( 'https://api.telegram.org/bot123%3AABC-token/sendMessage', $req['url'], 'the bot token is part of the URL path for this API, exactly why send_request() must never log the raw URL on failure' );
}

function test_build_webhook_request_success_shape() {
	$req = Notifications::build_webhook_request( 'https://n8n.example.com/hook', wpguard_test_job_verified(), 'My Site' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 1, $body['schema_version'], 'schema_version must be present so a consumer can handle future field additions' );
	wpguard_assert_equals( 'backup_succeeded', $body['event'], 'a verified job must report the backup_succeeded event' );
	wpguard_assert_equals( '', $body['error'], 'a success event must carry an empty error field, not omit it or echo stale data' );
}

function test_build_webhook_request_failure_shape_includes_error() {
	$req = Notifications::build_webhook_request( 'https://n8n.example.com/hook', wpguard_test_job_failed(), 'My Site' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'backup_failed', $body['event'], 'a failed job must report the backup_failed event' );
	wpguard_assert_equals( 'Could not connect.', $body['error'], 'a failure event must carry the real error text' );
}

function test_build_alert_text_success_has_no_error_line() {
	$text = Notifications::build_alert_text( wpguard_test_job_verified(), 'My Site', 'https://example.test/settings' );
	wpguard_assert( str_contains( $text['title'], 'succeeded' ), 'a verified job must say succeeded in the title' );
	wpguard_assert( ! str_contains( $text['message'], 'Error:' ), 'a success message must never include an Error: line' );
}

function test_build_alert_text_failure_includes_error_and_settings_link() {
	$text = Notifications::build_alert_text( wpguard_test_job_failed(), 'My Site', 'https://example.test/settings' );
	wpguard_assert( str_contains( $text['title'], 'FAILED' ), 'a failed job must say FAILED plainly in the title' );
	wpguard_assert( str_contains( $text['message'], 'Could not connect.' ), 'the real error text must be in the message' );
	wpguard_assert( str_contains( $text['message'], 'https://example.test/settings' ), 'the message must link back to the settings page' );
}

function test_build_test_text_is_distinguishable_from_a_real_alert() {
	$text = Notifications::build_test_text( 'My Site' );
	wpguard_assert( str_contains( $text['title'], 'Test notification' ), 'a test notification title must never read like a real backup outcome' );
	wpguard_assert( ! str_contains( $text['title'], 'succeeded' ) && ! str_contains( $text['title'], 'FAILED' ), 'a test must never be mistaken for a real success/failure alert' );
}

function test_build_webhook_request_reports_test_event_for_a_synthetic_test_job() {
	$job = array( 'status' => 'test', 'backup_id' => 'TEST', 'updated_at' => '2026-11-02 12:00:00' );
	$req = Notifications::build_webhook_request( 'https://n8n.example.com/hook', $job, 'My Site' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'test', $body['event'], 'a synthetic test job must report event "test", never backup_succeeded/backup_failed' );
}

// Vex security audit, 2026-11-02 (LOW-N-02): Telegram's bot token and
// Gotify's app token are both embedded in their request URL via
// rawurlencode(), which Logger::redact()'s plain str_replace() against
// the raw value would never match. all_secret_values() must list both
// forms so a log entry that somehow contained the constructed URL still
// gets the token stripped.
function test_all_secret_values_includes_rawurlencoded_telegram_and_gotify_tokens() {
	$GLOBALS['__wpguard_test_options'] = array(
		'wpthistle_backup_settings' => array(
			'notify_telegram_bot_token' => '123:ABC-def/ghi',
			'notify_gotify_token'       => 'tok with/slash',
		),
	);
	$secrets = Notifications::all_secret_values();
	wpguard_assert( in_array( '123:ABC-def/ghi', $secrets, true ), 'the raw Telegram bot token must still be listed' );
	wpguard_assert( in_array( rawurlencode( '123:ABC-def/ghi' ), $secrets, true ), 'the rawurlencode()\'d Telegram bot token (the form actually embedded in its request URL) must also be listed' );
	wpguard_assert( in_array( 'tok with/slash', $secrets, true ), 'the raw Gotify token must still be listed' );
	wpguard_assert( in_array( rawurlencode( 'tok with/slash' ), $secrets, true ), 'the rawurlencode()\'d Gotify token (the form actually embedded in its request URL) must also be listed' );
}

function test_build_alert_text_failure_without_error_message_omits_error_line() {
	$job = array( 'status' => 'failed', 'backup_id' => 'x', 'updated_at' => 'x' );
	$text = Notifications::build_alert_text( $job, 'My Site', 'https://example.test/' );
	wpguard_assert( ! str_contains( $text['message'], 'Error:' ), 'a missing error message must not produce a dangling "Error:" label with nothing after it' );
}

return array(
	'test_build_discord_request_posts_json_with_content_and_no_mentions',
	'test_build_discord_request_truncates_to_2000_chars',
	'test_build_slack_request_escapes_ampersand_and_angle_brackets',
	'test_build_pushover_request_is_form_body_with_token_and_user',
	'test_build_pushover_request_truncates_to_documented_limits',
	'test_build_pushbullet_request_sends_access_token_as_header_not_body',
	'test_build_ntfy_request_splits_server_root_from_topic',
	'test_build_ntfy_request_adds_bearer_token_when_present',
	'test_build_ntfy_request_uses_json_not_title_header',
	'test_build_gotify_request_puts_token_in_query_string',
	'test_build_gotify_request_strips_trailing_slash_from_server_url',
	'test_build_telegram_request_has_no_parse_mode',
	'test_build_telegram_request_puts_bot_token_in_url_path',
	'test_build_webhook_request_success_shape',
	'test_build_webhook_request_failure_shape_includes_error',
	'test_build_alert_text_success_has_no_error_line',
	'test_build_alert_text_failure_includes_error_and_settings_link',
	'test_build_alert_text_failure_without_error_message_omits_error_line',
	'test_build_test_text_is_distinguishable_from_a_real_alert',
	'test_build_webhook_request_reports_test_event_for_a_synthetic_test_job',
	'test_all_secret_values_includes_rawurlencoded_telegram_and_gotify_tokens',
);
