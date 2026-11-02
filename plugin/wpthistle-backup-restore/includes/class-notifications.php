<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-channel scheduled-backup notifications. Pure per-channel request
 * builders (no WP calls beyond __()/wp_json_encode(), both stubbed in the
 * unit-test shim), so each one's exact URL/headers/body shape is directly
 * unit-testable without a real network call. send_request() below is the
 * one place that actually talks to the network, deliberately never
 * tested against a live third-party service. See
 * tests/e2e/run-e2e-notifications.sh's pre_http_request mock for that.
 *
 * Every channel has at least one credential that's a real secret (a
 * webhook URL, an API token, or an ntfy topic name, all bearer-equivalent
 * on whoever's server receives them). None of that is ever logged: a
 * send failure is reduced to a channel name plus an HTTP status code or
 * the word "network error", never the underlying WP_Error message, which
 * can embed the secret itself (Telegram's bot token sits in the request
 * URL's own path). Job_Orchestrator's own logger is additionally given
 * every one of these secret values to redact as defense in depth, same
 * as it already does for the Nextcloud app password.
 */
class Notifications {

	const CHANNELS = array( 'email', 'discord', 'slack', 'pushover', 'pushbullet', 'ntfy', 'gotify', 'telegram', 'webhook' );

	/**
	 * Every credential value across every channel, for Logger's
	 * redaction list. Includes blanks; Logger itself ignores those.
	 *
	 * Vex security audit, 2026-11-02 (LOW-N-02): the Telegram bot token
	 * and Gotify app token are both embedded in their request URL via
	 * rawurlencode() (build_telegram_request()/build_gotify_request()
	 * above), which turns characters like ':' and '/' into '%3A'/'%2F'.
	 * Logger::redact()'s plain str_replace() against the raw value would
	 * never match that encoded form, so both are listed twice here: raw
	 * (matches if the plain value ever appears somewhere) and
	 * rawurlencode()'d (matches the form that actually appears in the
	 * one place these two tokens are ever embedded in a string at all).
	 */
	public static function all_secret_values(): array {
		$all = Settings::get_all();
		$telegram_token = (string) ( $all['notify_telegram_bot_token'] ?? '' );
		$gotify_token   = (string) ( $all['notify_gotify_token'] ?? '' );
		return array(
			(string) ( $all['notify_discord_webhook_url'] ?? '' ),
			(string) ( $all['notify_slack_webhook_url'] ?? '' ),
			(string) ( $all['notify_pushover_user_key'] ?? '' ),
			(string) ( $all['notify_pushover_api_token'] ?? '' ),
			(string) ( $all['notify_pushbullet_access_token'] ?? '' ),
			(string) ( $all['notify_ntfy_url'] ?? '' ),
			(string) ( $all['notify_ntfy_token'] ?? '' ),
			$gotify_token,
			rawurlencode( $gotify_token ),
			$telegram_token,
			rawurlencode( $telegram_token ),
			(string) ( $all['notify_webhook_url'] ?? '' ),
		);
	}

	/**
	 * Pure: the human-readable title/message pair every channel except
	 * the generic webhook sends (that one gets structured JSON instead,
	 * see build_webhook_request()). Generalizes what used to be
	 * Job_Orchestrator::build_alert_email()'s subject/body: "title" and
	 * "message" instead of "subject" and "body", since this is no longer
	 * email-specific.
	 */
	public static function build_alert_text( array $job, string $site_name, string $settings_url ): array {
		$ok = 'verified' === ( $job['status'] ?? '' );
		$title = $ok
			/* translators: %s: site name. */
			? sprintf( __( '[%s] Scheduled backup succeeded', 'wpthistle-backup-restore' ), $site_name )
			/* translators: %s: site name. */
			: sprintf( __( '[%s] Scheduled backup FAILED', 'wpthistle-backup-restore' ), $site_name );

		$lines   = array();
		$lines[] = $ok
			? __( 'A scheduled backup completed and was verified successfully.', 'wpthistle-backup-restore' )
			: __( 'A scheduled backup failed.', 'wpthistle-backup-restore' );
		$lines[] = '';
		/* translators: %s: backup ID. */
		$lines[] = sprintf( __( 'Backup ID: %s', 'wpthistle-backup-restore' ), $job['backup_id'] ?? '' );
		/* translators: %s: UTC timestamp. */
		$lines[] = sprintf( __( 'Finished: %s UTC', 'wpthistle-backup-restore' ), $job['updated_at'] ?? '' );
		if ( ! $ok && ! empty( $job['error'] ) ) {
			$lines[] = '';
			/* translators: %s: the error message the backup failed with. */
			$lines[] = sprintf( __( 'Error: %s', 'wpthistle-backup-restore' ), $job['error'] );
		}
		$lines[] = '';
		/* translators: %s: URL to this plugin's settings page. */
		$lines[] = sprintf( __( 'Manage this plugin: %s', 'wpthistle-backup-restore' ), $settings_url );

		return array( 'title' => $title, 'message' => implode( "\n", $lines ) );
	}

	/** Pure: truncates to $max_length, with a trailing "..." when it actually had to cut anything. */
	private static function truncate( string $text, int $max_length ): string {
		if ( strlen( $text ) <= $max_length ) {
			return $text;
		}
		return substr( $text, 0, max( 0, $max_length - 3 ) ) . '...';
	}

	/** Pure. Discord's own 2000-character content limit; allowed_mentions is empty so an @everyone/@here accidentally present in a backup error message can never actually ping anyone. */
	public static function build_discord_request( string $webhook_url, string $title, string $message ): array {
		$content = self::truncate( $title . "\n" . $message, 2000 );
		return array(
			'url'  => $webhook_url,
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'content'          => $content,
					'allowed_mentions' => array( 'parse' => array() ),
				) ),
			),
		);
	}

	/** Pure. Slack's mrkdwn format requires &, <, > escaped or the message renders wrong (or a literal link if an error message happens to contain something URL-shaped). */
	public static function build_slack_request( string $webhook_url, string $title, string $message ): array {
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $title . "\n" . $message );
		return array(
			'url'  => $webhook_url,
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'text' => $text ) ),
			),
		);
	}

	/** Pure. Pushover's own documented limits: title 250 chars, message 1024. */
	public static function build_pushover_request( string $user_key, string $api_token, string $title, string $message ): array {
		return array(
			'url'  => 'https://api.pushover.net/1/messages.json',
			'args' => array(
				'body' => array(
					'token'   => $api_token,
					'user'    => $user_key,
					'title'   => self::truncate( $title, 250 ),
					'message' => self::truncate( $message, 1024 ),
				),
			),
		);
	}

	/** Pure. Pushbullet's "note" push type; the access token is a header, not part of the body. */
	public static function build_pushbullet_request( string $access_token, string $title, string $message ): array {
		return array(
			'url'  => 'https://api.pushbullet.com/v2/pushes',
			'args' => array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Access-Token' => $access_token,
				),
				'body'    => wp_json_encode( array(
					'type'  => 'note',
					'title' => $title,
					'body'  => $message,
				) ),
			),
		);
	}

	/**
	 * Pure. $topic_url is the full subscribe URL as the admin would
	 * actually copy it (e.g. https://ntfy.sh/mytopic, or a self-hosted
	 * server's equivalent), split into server root + topic name here,
	 * and published via ntfy's JSON API against the server root, not the
	 * topic-path PUT/header convention: a non-ASCII site name in a
	 * `Title:` header breaks that form, JSON has no such restriction.
	 */
	public static function build_ntfy_request( string $topic_url, string $token, string $title, string $message ): array {
		$parts       = wp_parse_url( $topic_url );
		$scheme      = $parts['scheme'] ?? 'https';
		$host        = $parts['host'] ?? '';
		$port        = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$server_root = $scheme . '://' . $host . $port;
		$topic       = ltrim( $parts['path'] ?? '', '/' );

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return array(
			'url'  => $server_root . '/',
			'args' => array(
				'headers' => $headers,
				'body'    => wp_json_encode( array(
					'topic'   => $topic,
					'title'   => $title,
					'message' => $message,
				) ),
			),
		);
	}

	/** Pure. $server_url is the Gotify instance's own base URL; the app token is a query parameter, Gotify's own documented auth convention for this endpoint. */
	public static function build_gotify_request( string $server_url, string $token, string $title, string $message ): array {
		return array(
			'url'  => rtrim( $server_url, '/' ) . '/message?token=' . rawurlencode( $token ),
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'title'    => $title,
					'message'  => $message,
					'priority' => 5,
				) ),
			),
		);
	}

	/**
	 * Pure. Deliberately no parse_mode, plain text only. A backup ID or
	 * error message routinely contains underscores and asterisks; under
	 * Telegram's Markdown parser those are formatting characters, and an
	 * unbalanced one turns an ordinary notification into a 400.
	 */
	public static function build_telegram_request( string $bot_token, string $chat_id, string $title, string $message ): array {
		return array(
			'url'  => 'https://api.telegram.org/bot' . rawurlencode( $bot_token ) . '/sendMessage',
			'args' => array(
				'body' => array(
					'chat_id' => $chat_id,
					'text'    => $title . "\n" . $message,
				),
			),
		);
	}

	/**
	 * Pure. The one channel that gets structured JSON instead of prose --
	 * meant for automation (n8n, Home Assistant, Zapier, a custom
	 * listener), where fields matter more than a formatted sentence.
	 * schema_version lets a consumer handle a future field addition
	 * without guessing.
	 */
	public static function build_webhook_request( string $webhook_url, array $job, string $site_name ): array {
		$ok = 'verified' === ( $job['status'] ?? '' );
		return array(
			'url'  => $webhook_url,
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'schema_version' => 1,
					'event'          => 'test' === ( $job['status'] ?? '' ) ? 'test' : ( $ok ? 'backup_succeeded' : 'backup_failed' ),
					'site'           => $site_name,
					'backup_id'      => $job['backup_id'] ?? '',
					'status'         => $job['status'] ?? '',
					'finished_at'    => $job['updated_at'] ?? '',
					'error'          => $ok ? '' : ( $job['error'] ?? '' ),
					'plugin_version' => defined( 'WPTHISTLE_BACKUP_VERSION' ) ? WPTHISTLE_BACKUP_VERSION : '',
				) ),
			),
		);
	}

	/**
	 * The one place an actual network call happens. redirection => 0
	 * (security audit-grade paranoia, not yet reviewed by Vex): a 3xx
	 * response could otherwise carry this request's Authorization/
	 * Access-Token header, or a token embedded in the URL itself, to a
	 * completely different host. Returns true, a short safe-to-log
	 * string (never the raw error), or false.
	 *
	 * @return true|string
	 */
	private static function send_request( string $url, array $args ) {
		// Short on purpose: up to 8 third-party channels can be enabled
		// at once, dispatched one after another. A generous per-call
		// timeout multiplied by 8 risks the whole request hitting PHP's
		// own max_execution_time on a constrained host. 5s is still
		// generous for a small JSON POST to a healthy API.
		//
		// Vex security audit, 2026-11-02 (LOW-N-01): $args must be merged
		// FIRST and the security-relevant defaults SECOND, so redirection
		// and timeout always win regardless of what a channel builder
		// passes. array_merge() lets a later array's keys override an
		// earlier one's; the original order let $args silently override
		// redirection => 0, which exists specifically so a 3xx response
		// can never carry an Authorization/Access-Token header, or a
		// token embedded in the URL path (Telegram, Gotify), to a
		// different host. No channel builder sets either key today, but
		// nothing structurally prevented a future one from doing so by
		// accident.
		$args = array_merge( $args, array( 'timeout' => 5, 'redirection' => 0 ) );
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			// Deliberately generic: a WP_Error's own message can include
			// the request URL, which for Telegram embeds the bot token.
			return 'network error';
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return "HTTP {$code}";
		}
		return true;
	}

	/** Pure: the "Send Test Notification" button's message, deliberately worded so it's never mistaken for a real backup outcome. */
	public static function build_test_text( string $site_name ): array {
		return array(
			/* translators: %s: site name. */
			'title'   => sprintf( __( '[%s] Test notification', 'wpthistle-backup-restore' ), $site_name ),
			'message' => __( 'If you can see this, this channel is configured correctly. No backup actually ran.', 'wpthistle-backup-restore' ),
		);
	}

	/**
	 * Sends $job's notification to every channel Settings reports as
	 * enabled. One channel's failure never blocks another, each is
	 * independent, same as this method's own return shape being
	 * per-channel rather than one combined pass/fail. Used for a real
	 * scheduled backup's outcome (Job_Orchestrator); see dispatch_test()
	 * below for the settings page's "Send Test Notification" button,
	 * which shares send_to_every_enabled_channel() but sends distinctly-
	 * worded text instead of this method's real alert text.
	 *
	 * @return array<string, true|string> Channel => true, or a short safe-to-display/log failure reason.
	 */
	public static function dispatch_all( array $job ): array {
		$site_name    = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$settings_url = admin_url( 'admin.php?page=wpthistle-backup-restore' );
		$text         = self::build_alert_text( $job, $site_name, $settings_url );
		return self::send_to_every_enabled_channel( $job, $site_name, $text );
	}

	/**
	 * The "Send Test Notification" button: same per-channel send path as
	 * dispatch_all(), but with no real job to describe, so a synthetic
	 * one (status 'test', never a real job status) stands in. The
	 * generic webhook's build_webhook_request() recognizes it and sends
	 * event: "test" rather than claiming a backup actually ran.
	 *
	 * @return array<string, true|string>
	 */
	public static function dispatch_test(): array {
		$site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$job       = array( 'status' => 'test', 'backup_id' => 'TEST', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$text      = self::build_test_text( $site_name );
		return self::send_to_every_enabled_channel( $job, $site_name, $text );
	}

	/** @return array<string, true|string> */
	private static function send_to_every_enabled_channel( array $job, string $site_name, array $text ): array {
		$all     = Settings::get_all();
		$results = array();
		foreach ( Settings::get_enabled_notification_channels() as $channel ) {
			$results[ $channel ] = self::send_to_channel( $channel, $all, $job, $site_name, $text );
		}
		return $results;
	}

	/** @return true|string */
	private static function send_to_channel( string $channel, array $all, array $job, string $site_name, array $text ) {
		switch ( $channel ) {
			case 'email':
				$sent = wp_mail( get_option( 'admin_email' ), $text['title'], $text['message'] );
				return $sent ? true : 'send failed';
			case 'discord':
				$req = self::build_discord_request( $all['notify_discord_webhook_url'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'slack':
				$req = self::build_slack_request( $all['notify_slack_webhook_url'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'pushover':
				$req = self::build_pushover_request( $all['notify_pushover_user_key'], $all['notify_pushover_api_token'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'pushbullet':
				$req = self::build_pushbullet_request( $all['notify_pushbullet_access_token'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'ntfy':
				$req = self::build_ntfy_request( $all['notify_ntfy_url'], $all['notify_ntfy_token'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'gotify':
				$req = self::build_gotify_request( $all['notify_gotify_url'], $all['notify_gotify_token'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'telegram':
				$req = self::build_telegram_request( $all['notify_telegram_bot_token'], $all['notify_telegram_chat_id'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'webhook':
				$req = self::build_webhook_request( $all['notify_webhook_url'], $job, $site_name );
				return self::send_request( $req['url'], $req['args'] );
			default:
				return 'unknown channel';
		}
	}
}
