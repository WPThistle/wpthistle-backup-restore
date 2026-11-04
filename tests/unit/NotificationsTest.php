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

// 2026-11-03: plugin identification (the owner's request -- notifications gave
// no indication which plugin/site sent them beyond a bracketed site name).

function test_build_alert_text_includes_plugin_line_and_context_fields() {
	$text = Notifications::build_alert_text( wpguard_test_job_verified(), 'My Site', 'https://example.test/settings' );
	wpguard_assert( str_contains( $text['message'], 'Plugin: WPThistle Backup and Restore' ), 'the message body must name the plugin, not just the site' );
	wpguard_assert( str_contains( $text['message'], 'Site: My Site' ), 'the message body must also carry a plain Site: line' );
	wpguard_assert_equals( 'WPThistle Backup and Restore', $text['plugin_name'], 'plugin_name must be present for the rich per-channel builders' );
	wpguard_assert_equals( true, $text['ok'], 'ok must be true for a verified job' );
	wpguard_assert_equals( false, $text['is_test'], 'is_test must be false for a real job' );
	wpguard_assert_equals( '20261102T120000Z-aabbccdd', $text['backup_id'], 'backup_id must be exposed as a plain context field too' );
}

function test_build_alert_text_email_subject_names_plugin_and_site() {
	$success = Notifications::build_alert_text( wpguard_test_job_verified(), 'My Site', 'https://example.test/' );
	wpguard_assert_equals( '[WPThistle] My Site: Scheduled backup succeeded', $success['email_subject'], 'subject format must be [WPThistle] Site: Result, per the approved format' );
	$failure = Notifications::build_alert_text( wpguard_test_job_failed(), 'My Site', 'https://example.test/' );
	wpguard_assert_equals( '[WPThistle] My Site: Scheduled backup FAILED', $failure['email_subject'], 'a failed job\'s subject must say FAILED, not succeeded' );
}

function test_build_test_text_includes_plugin_line_and_email_subject() {
	$text = Notifications::build_test_text( 'My Site' );
	wpguard_assert( str_contains( $text['message'], 'Plugin: WPThistle Backup and Restore' ), 'a test notification must also identify the plugin' );
	wpguard_assert_equals( true, $text['is_test'], 'is_test must be true for the synthetic test job' );
	wpguard_assert_equals( '', $text['backup_id'], 'a test send has no real backup_id to show' );
	wpguard_assert_equals( '[WPThistle] My Site: Test notification', $text['email_subject'], 'a test notification\'s email_subject must say Test notification, never succeeded/FAILED' );
}

function test_build_discord_request_without_extra_is_unchanged() {
	// Guards against a regression where adding $extra accidentally changed the default (no-$extra) shape.
	$req = Notifications::build_discord_request( 'https://discord.com/x', 'T', 'M' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( ! isset( $body['embeds'] ), 'no $extra means no embeds key at all, the original plain-content shape' );
}

function test_build_discord_request_with_icon_adds_embed_with_thumbnail_and_color() {
	$req = Notifications::build_discord_request( 'https://discord.com/x', 'Title', 'Message', array( 'icon_url' => 'https://example.test/icon.png', 'ok' => true, 'is_test' => false ) );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'https://example.test/icon.png', $body['embeds'][0]['thumbnail']['url'], 'the embed thumbnail must carry the given icon URL' );
	wpguard_assert_equals( 0x2e8b57, $body['embeds'][0]['color'], 'a successful job must use the green color' );
	wpguard_assert_equals( array(), $body['allowed_mentions']['parse'], 'adding an embed must not weaken the existing allowed_mentions protection' );
}

function test_build_discord_request_with_icon_uses_red_for_failure() {
	$req = Notifications::build_discord_request( 'https://discord.com/x', 'T', 'M', array( 'icon_url' => 'https://example.test/icon.png', 'ok' => false, 'is_test' => false ) );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 0xc0392b, $body['embeds'][0]['color'], 'a failed job must use the red color' );
}

function test_build_slack_request_without_extra_is_unchanged() {
	$req = Notifications::build_slack_request( 'https://hooks.slack.com/x', 'T', 'M' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( ! isset( $body['blocks'] ), 'no $extra means no blocks key, the original text-only shape' );
}

function test_build_slack_request_with_icon_adds_image_accessory() {
	$req = Notifications::build_slack_request( 'https://hooks.slack.com/x', 'T', 'M', array( 'icon_url' => 'https://example.test/icon.png' ) );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'https://example.test/icon.png', $body['blocks'][0]['accessory']['image_url'], 'the block accessory image must carry the given icon URL' );
	wpguard_assert( isset( $body['text'] ), 'the plain text fallback must still be present alongside blocks' );
}

function test_build_ntfy_request_with_icon_adds_icon_field() {
	$req = Notifications::build_ntfy_request( 'https://ntfy.sh/topic', '', 'T', 'M', array( 'icon_url' => 'https://example.test/icon.png' ) );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'https://example.test/icon.png', $body['icon'], 'ntfy\'s documented icon field must carry the given URL' );
}

function test_build_ntfy_request_without_extra_omits_icon_field() {
	$req = Notifications::build_ntfy_request( 'https://ntfy.sh/topic', '', 'T', 'M' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( ! isset( $body['icon'] ), 'no $extra means no icon field at all' );
}

function test_build_gotify_request_with_icon_adds_extras_big_image_url() {
	$req = Notifications::build_gotify_request( 'https://gotify.example.com', 'tok', 'T', 'M', array( 'icon_url' => 'https://example.test/icon.png' ) );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'https://example.test/icon.png', $body['extras']['client::notification']['bigImageUrl'], "Gotify's documented extras shape for a large notification image" );
}

function test_build_telegram_request_with_icon_switches_to_sendphoto() {
	$req = Notifications::build_telegram_request( '123:ABC', '98765', 'Title', 'Message', array( 'icon_url' => 'https://example.test/icon.png' ) );
	wpguard_assert_equals( 'https://api.telegram.org/bot123%3AABC/sendPhoto', $req['url'], 'an icon_url must switch the endpoint to sendPhoto' );
	wpguard_assert_equals( 'https://example.test/icon.png', $req['args']['body']['photo'], 'the photo field must carry the given icon URL' );
	wpguard_assert( str_contains( $req['args']['body']['caption'], 'Title' ) && str_contains( $req['args']['body']['caption'], 'Message' ), 'the caption must carry both title and message' );
	wpguard_assert( ! isset( $req['args']['body']['parse_mode'] ), 'the sendPhoto path must stay parse_mode-free too, same reasoning as sendMessage' );
}

function test_build_telegram_request_caption_truncates_to_1024() {
	$req = Notifications::build_telegram_request( '1:A', '1', 'T', str_repeat( 'x', 2000 ), array( 'icon_url' => 'https://example.test/icon.png' ) );
	wpguard_assert( strlen( $req['args']['body']['caption'] ) <= 1024, "Telegram's own caption limit (shorter than its message limit) must be respected on the sendPhoto path" );
}

function test_build_pushover_request_with_manage_url_adds_url_fields() {
	$req = Notifications::build_pushover_request( 'u', 't', 'T', 'M', array( 'manage_url' => 'https://example.test/wp-admin/admin.php?page=x' ) );
	wpguard_assert_equals( 'https://example.test/wp-admin/admin.php?page=x', $req['args']['body']['url'], 'the url field must carry the manage link' );
	wpguard_assert( isset( $req['args']['body']['url_title'] ), 'url_title must be present alongside url' );
}

function test_build_pushover_request_without_extra_omits_url_fields() {
	$req = Notifications::build_pushover_request( 'u', 't', 'T', 'M' );
	wpguard_assert( ! isset( $req['args']['body']['url'] ) && ! isset( $req['args']['body']['url_title'] ), 'no $extra means no url/url_title fields at all' );
}

function test_build_pushover_request_with_icon_base64_adds_attachment_fields() {
	$req = Notifications::build_pushover_request( 'u', 't', 'T', 'M', array( 'icon_base64' => 'ZmFrZS1wbmctYnl0ZXM=', 'icon_mime' => 'image/png' ) );
	wpguard_assert_equals( 'ZmFrZS1wbmctYnl0ZXM=', $req['args']['body']['attachment_base64'], 'attachment_base64 must carry the given base64 data verbatim' );
	wpguard_assert_equals( 'image/png', $req['args']['body']['attachment_type'], 'attachment_type must carry the given mime type' );
}

function test_build_pushover_request_with_icon_base64_defaults_mime_to_png() {
	$req = Notifications::build_pushover_request( 'u', 't', 'T', 'M', array( 'icon_base64' => 'ZmFrZS1wbmctYnl0ZXM=' ) );
	wpguard_assert_equals( 'image/png', $req['args']['body']['attachment_type'], 'omitting icon_mime must default to image/png, not be missing' );
}

function test_build_pushover_request_without_icon_base64_omits_attachment_fields() {
	$req = Notifications::build_pushover_request( 'u', 't', 'T', 'M', array( 'manage_url' => 'https://example.test/' ) );
	wpguard_assert( ! isset( $req['args']['body']['attachment_base64'] ) && ! isset( $req['args']['body']['attachment_type'] ), 'no icon_base64 means no attachment fields at all, even with other $extra keys present' );
}

function test_build_webhook_request_includes_plugin_name_and_icon_url() {
	$req = Notifications::build_webhook_request( 'https://n8n.example.com/hook', wpguard_test_job_verified(), 'My Site', 'https://example.test/icon.png' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( 'WPThistle Backup and Restore', $body['plugin'], 'the webhook payload must name the plugin' );
	wpguard_assert_equals( 'https://example.test/icon.png', $body['icon_url'], 'the webhook payload must carry the given icon URL' );
}

function test_build_webhook_request_icon_url_defaults_to_empty_string() {
	$req = Notifications::build_webhook_request( 'https://n8n.example.com/hook', wpguard_test_job_verified(), 'My Site' );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert_equals( '', $body['icon_url'], 'omitting the icon_url argument must default to an empty string, not be missing or null' );
}

function test_build_email_html_includes_wordmark_and_details_table() {
	$text = Notifications::build_alert_text( wpguard_test_job_failed(), 'My <Site>', 'https://example.test/settings' );
	$html = Notifications::build_email_html( $text, 'https://example.test/wordmark.png' );
	wpguard_assert( str_contains( $html, 'https://example.test/wordmark.png' ), 'the wordmark image URL must be in the markup' );
	wpguard_assert( str_contains( $html, 'WPThistle Backup and Restore' ), 'the plugin name must appear in the details table' );
	wpguard_assert( str_contains( $html, 'My &lt;Site&gt;' ), 'a site name containing HTML-significant characters must be escaped, not injected raw' );
	wpguard_assert( str_contains( $html, 'Could not connect.' ), "the real error text must appear for a failed job's email body" );
	wpguard_assert( str_contains( $html, 'https://example.test/settings' ), 'the manage-plugin link must be present' );
}

function test_build_email_html_success_omits_error_row() {
	$text = Notifications::build_alert_text( wpguard_test_job_verified(), 'My Site', 'https://example.test/' );
	$html = Notifications::build_email_html( $text, 'https://example.test/wordmark.png' );
	wpguard_assert( ! str_contains( $html, '>Error<' ), 'a successful job\'s email body must not render an Error row at all' );
}

// Vex security audit, 2026-11-03 (LOW-V-01): htmlspecialchars() without ENT_SUBSTITUTE
// returns an empty string on invalid UTF-8 input, silently dropping the Error row's value.
function test_build_email_html_invalid_utf8_in_error_message_does_not_drop_error_cell() {
	// "\xff" is not valid UTF-8. Without ENT_SUBSTITUTE, htmlspecialchars() returns '' for
	// the entire Error value cell, so the admin sees a blank "Error" row. With ENT_SUBSTITUTE,
	// the invalid byte is replaced with the Unicode replacement character and the surrounding
	// text is preserved.
	$job  = array( 'status' => 'failed', 'backup_id' => 'TEST', 'updated_at' => '2026-11-03 00:00:00', 'error' => "disk full at path/to/\xff/dir" );
	$text = Notifications::build_alert_text( $job, 'My Site', 'https://example.test/' );
	$html = Notifications::build_email_html( $text, 'https://example.test/wordmark.png' );
	wpguard_assert( str_contains( $html, 'disk full at path' ), 'the valid UTF-8 portions of an error message must still appear after ENT_SUBSTITUTE replaces the bad byte -- without ENT_SUBSTITUTE htmlspecialchars returns empty string and the whole cell goes blank' );
	wpguard_assert( mb_check_encoding( $html, 'UTF-8' ), 'the full HTML output must be valid UTF-8 regardless of the error message content' );
}

// Vex security audit, 2026-11-03 (LOW-V-02): truncate() used strlen()/substr() (byte ops).
// A multibyte character straddling the cut point produces an invalid UTF-8 tail byte that
// Telegram's form-encoded sendPhoto body passes as-is, causing a 400.
function test_truncate_multibyte_does_not_split_a_multibyte_character() {
	// Build a string of 1020 ASCII 'a's + two 'é' chars (each 2 bytes) + 5 'b's = 1027 chars,
	// 1029 bytes. The 1024-char Telegram caption limit: truncate() must cut at 1021 chars,
	// producing 1020 'a's + 1 'é' + '...' (1024 chars), where 'é' is never split mid-byte.
	$text = str_repeat( 'a', 1020 ) . "\xc3\xa9\xc3\xa9" . str_repeat( 'b', 5 );
	$req  = Notifications::build_telegram_request( '1:A', '1', 'T', $text, array( 'icon_url' => 'https://example.test/icon.png' ) );
	$caption = $req['args']['body']['caption'];
	wpguard_assert( mb_check_encoding( $caption, 'UTF-8' ), 'truncating at a multibyte character boundary must produce valid UTF-8 -- byte-based substr produces an orphaned 0xc3 lead byte here, which Telegram\'s API rejects with 400' );
	wpguard_assert( mb_strlen( $caption, 'UTF-8' ) <= 1024, 'caption must respect Telegram\'s 1024-character limit' );
}

// Vex security audit, 2026-11-03 (LOW-V-03): Slack rejects the ENTIRE message (including
// the plain top-level 'text' field that pre-existing tests rely on as a fallback) when a
// blocks section text exceeds 3000 characters. The block text must be capped separately.
function test_build_slack_request_with_icon_block_text_is_capped_at_3000_chars() {
	$long_message = str_repeat( 'x', 4000 );
	$req  = Notifications::build_slack_request( 'https://hooks.slack.com/x', 'T', $long_message, array( 'icon_url' => 'https://example.test/icon.png' ) );
	$body = json_decode( $req['args']['body'], true );
	wpguard_assert( mb_strlen( $body['blocks'][0]['text']['text'], 'UTF-8' ) <= 3000, 'Slack rejects the whole message (including the plain-text fallback) when section block text exceeds 3000 chars; block text must be independently truncated' );
	// The top-level text field (Slack notification preview / screen reader fallback) is NOT
	// subject to the block-text limit and must carry the full untruncated version.
	wpguard_assert( mb_strlen( $body['text'], 'UTF-8' ) > 3000, 'the top-level text field (not inside blocks) must not be truncated by the 3000-char block limit' );
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
	'test_build_alert_text_includes_plugin_line_and_context_fields',
	'test_build_alert_text_email_subject_names_plugin_and_site',
	'test_build_test_text_includes_plugin_line_and_email_subject',
	'test_build_discord_request_without_extra_is_unchanged',
	'test_build_discord_request_with_icon_adds_embed_with_thumbnail_and_color',
	'test_build_discord_request_with_icon_uses_red_for_failure',
	'test_build_slack_request_without_extra_is_unchanged',
	'test_build_slack_request_with_icon_adds_image_accessory',
	'test_build_ntfy_request_with_icon_adds_icon_field',
	'test_build_ntfy_request_without_extra_omits_icon_field',
	'test_build_gotify_request_with_icon_adds_extras_big_image_url',
	'test_build_telegram_request_with_icon_switches_to_sendphoto',
	'test_build_telegram_request_caption_truncates_to_1024',
	'test_build_pushover_request_with_manage_url_adds_url_fields',
	'test_build_pushover_request_without_extra_omits_url_fields',
	'test_build_pushover_request_with_icon_base64_adds_attachment_fields',
	'test_build_pushover_request_with_icon_base64_defaults_mime_to_png',
	'test_build_pushover_request_without_icon_base64_omits_attachment_fields',
	'test_build_webhook_request_includes_plugin_name_and_icon_url',
	'test_build_webhook_request_icon_url_defaults_to_empty_string',
	'test_build_email_html_includes_wordmark_and_details_table',
	'test_build_email_html_success_omits_error_row',
	'test_build_email_html_invalid_utf8_in_error_message_does_not_drop_error_cell',
	'test_truncate_multibyte_does_not_split_a_multibyte_character',
	'test_build_slack_request_with_icon_block_text_is_capped_at_3000_chars',
);
