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
	 * 2026-11-03: every channel's notification used to say only the site
	 * name, nothing identifying which plugin sent it -- indistinguishable
	 * from any other WordPress alert in a crowded inbox/channel once
	 * there's more than one WPThistle-family plugin or site in play. This
	 * is the one string every channel now surfaces somewhere (email
	 * subject, the new "Plugin:" body line, Discord/Slack footer text,
	 * the webhook's new "plugin" field).
	 */
	const PLUGIN_DISPLAY_NAME = 'WPThistle Backup and Restore';

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
	 * Pure. The fields every rich/structured builder below needs
	 * (result label for a subject/title, the raw backup_id/finished/
	 * error, and a plain ok/is_test pair to branch on) in one place, so
	 * build_alert_text(), build_email_subject(), build_email_html(), and
	 * every per-channel builder that accepts $extra derive the exact
	 * same facts from $job instead of each re-deriving "is this a
	 * success/failure/test" its own way.
	 */
	private static function job_context( array $job, string $site_name ): array {
		$ok      = 'verified' === ( $job['status'] ?? '' );
		$is_test = 'test' === ( $job['status'] ?? '' );
		return array(
			'plugin_name' => self::PLUGIN_DISPLAY_NAME,
			'site_name'   => $site_name,
			'ok'          => $ok,
			'is_test'     => $is_test,
			'result_label' => $is_test
				? __( 'Test notification', 'wpthistle-backup-restore' )
				: ( $ok ? __( 'Scheduled backup succeeded', 'wpthistle-backup-restore' ) : __( 'Scheduled backup FAILED', 'wpthistle-backup-restore' ) ),
			'backup_id'   => $is_test ? '' : (string) ( $job['backup_id'] ?? '' ),
			'finished'    => $is_test ? '' : (string) ( $job['updated_at'] ?? '' ),
			'error'       => ( ! $is_test && ! $ok ) ? (string) ( $job['error'] ?? '' ) : '',
		);
	}

	/**
	 * Pure: the human-readable title/message pair every channel except
	 * the generic webhook sends (that one gets structured JSON instead,
	 * see build_webhook_request()). Generalizes what used to be
	 * Job_Orchestrator::build_alert_email()'s subject/body: "title" and
	 * "message" instead of "subject" and "body", since this is no longer
	 * email-specific.
	 *
	 * 2026-11-03: also returns every job_context() field plus
	 * 'manage_url' and 'email_subject', merged in alongside 'title'/
	 * 'message' (never replacing them -- existing callers that only read
	 * 'title'/'message' are unaffected) so the richer per-channel
	 * builders (Discord/Slack/webhook/email) and the plain-text "Plugin:
	 * ..." line below can both be built from one call.
	 */
	public static function build_alert_text( array $job, string $site_name, string $settings_url ): array {
		$ctx   = self::job_context( $job, $site_name );
		$ok    = $ctx['ok'];
		/* translators: %s: site name. */
		$title = sprintf( $ok ? __( '[%s] Scheduled backup succeeded', 'wpthistle-backup-restore' ) : __( '[%s] Scheduled backup FAILED', 'wpthistle-backup-restore' ), $site_name );

		$lines   = array();
		$lines[] = $ok
			? __( 'A scheduled backup completed and was verified successfully.', 'wpthistle-backup-restore' )
			: __( 'A scheduled backup failed.', 'wpthistle-backup-restore' );
		$lines[] = '';
		/* translators: %s: plugin display name. */
		$lines[] = sprintf( __( 'Plugin: %s', 'wpthistle-backup-restore' ), self::PLUGIN_DISPLAY_NAME );
		/* translators: %s: site name. */
		$lines[] = sprintf( __( 'Site: %s', 'wpthistle-backup-restore' ), $site_name );
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

		/* translators: 1: plugin display name, 2: site name, 3: result label (e.g. "Scheduled backup succeeded"). */
		$email_subject = sprintf( __( '[%1$s] %2$s: %3$s', 'wpthistle-backup-restore' ), 'WPThistle', $site_name, $ctx['result_label'] );

		return array_merge( $ctx, array(
			'title'         => $title,
			'message'       => implode( "\n", $lines ),
			'manage_url'    => $settings_url,
			'email_subject' => $email_subject,
		) );
	}

	/**
	 * Pure. The only channel with a real subject line and an HTML body,
	 * so it's the only one that gets the wordmark + a details table
	 * instead of just a "Plugin: ..." text line. $logo_url is passed in
	 * rather than read from WPTHISTLE_BACKUP_URL here, same reason
	 * $site_name/$settings_url are parameters on every other builder:
	 * keeps this directly unit-testable with a fixed string, no WP
	 * constant/shim dependency. Every interpolated value is escaped with
	 * plain htmlspecialchars() rather than esc_html() for the same
	 * reason -- no WP function dependency needed to unit-test this.
	 * Inline styles only, table-based layout: the one layout approach
	 * that survives every major email client's CSS stripping.
	 */
	public static function build_email_html( array $context, string $logo_url ): string {
		/**
		 * Vex security audit, 2026-11-03 (LOW-V-01): htmlspecialchars()
		 * returns an empty string when the input contains an invalid UTF-8
		 * byte sequence and no substitution flag is given. A job error
		 * message derived from a filesystem path, OS error message, or a
		 * network response body can contain bytes outside valid UTF-8 on
		 * some hosting environments. Without ENT_SUBSTITUTE, that silently
		 * turns the entire Error row's value cell into the empty string in
		 * the email body -- the XSS risk (the original reason for
		 * htmlspecialchars here) stays closed, but the admin sees a blank
		 * Error field rather than the diagnostic they need. Fixed:
		 * ENT_SUBSTITUTE replaces any invalid sequence with the Unicode
		 * replacement character (U+FFFD), preserving the surrounding text.
		 */
		$e = function ( string $s ): string {
			return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		};
		$ok_color = $context['is_test'] ? '#3b82f6' : ( $context['ok'] ? '#2e8b57' : '#c0392b' );
		$rows     = array();
		$rows[]   = array( __( 'Plugin', 'wpthistle-backup-restore' ), $context['plugin_name'] );
		$rows[]   = array( __( 'Site', 'wpthistle-backup-restore' ), $context['site_name'] );
		$rows[]   = array( __( 'Result', 'wpthistle-backup-restore' ), $context['result_label'] );
		if ( '' !== $context['backup_id'] ) {
			$rows[] = array( __( 'Backup ID', 'wpthistle-backup-restore' ), $context['backup_id'] );
		}
		if ( '' !== $context['finished'] ) {
			$rows[] = array( __( 'Finished', 'wpthistle-backup-restore' ), $context['finished'] . ' UTC' );
		}
		if ( '' !== $context['error'] ) {
			$rows[] = array( __( 'Error', 'wpthistle-backup-restore' ), $context['error'] );
		}

		$row_html = '';
		foreach ( $rows as $row ) {
			$row_html .= '<tr><td style="padding:6px 12px;color:#666;font-size:13px;white-space:nowrap;vertical-align:top;">' . $e( $row[0] ) . '</td>'
				. '<td style="padding:6px 12px;color:#222;font-size:13px;word-break:break-word;">' . $e( $row[1] ) . '</td></tr>';
		}

		$manage_html = '';
		if ( '' !== $context['manage_url'] ) {
			$manage_html = '<p style="margin:20px 0 0;"><a href="' . $e( $context['manage_url'] ) . '" style="background:#4a5d4a;color:#fff;padding:9px 18px;border-radius:4px;text-decoration:none;font-size:13px;display:inline-block;">'
				. $e( __( 'Manage this plugin', 'wpthistle-backup-restore' ) ) . '</a></p>';
		}

		return '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;max-width:480px;">'
			. '<img src="' . $e( $logo_url ) . '" alt="' . $e( $context['plugin_name'] ) . '" style="height:36px;width:auto;display:block;margin-bottom:16px;">'
			. '<p style="font-size:15px;font-weight:600;color:' . $ok_color . ';margin:0 0 12px;">' . $e( $context['result_label'] ) . '</p>'
			. '<table role="presentation" style="border-collapse:collapse;width:100%;background:#f7f7f5;border-radius:6px;">' . $row_html . '</table>'
			. $manage_html
			. '</div>';
	}

	/**
	 * Pure: truncates to $max_length characters (not bytes), with a
	 * trailing "..." when it actually had to cut anything.
	 *
	 * Vex security audit, 2026-11-03 (LOW-V-02): the original strlen()/
	 * substr() pair operated on bytes, not characters. A title/message
	 * containing multibyte UTF-8 sequences (non-ASCII site names, non-
	 * ASCII error messages from filesystem/network operations) could have
	 * its truncation point fall in the middle of a multibyte character,
	 * producing an invalid UTF-8 byte sequence. For Telegram's sendPhoto
	 * caption specifically (form-encoded body, not JSON), that invalid
	 * byte reaches the API as-is and causes a 400. For channels whose
	 * builder JSON-encodes the truncated string, wp_json_encode() would
	 * have replaced the broken sequence with the Unicode replacement
	 * character, but mb_substr() is both more correct and avoids that
	 * silent mangling. Fixed: mb_strlen/mb_substr throughout.
	 */
	private static function truncate( string $text, int $max_length ): string {
		if ( mb_strlen( $text, 'UTF-8' ) <= $max_length ) {
			return $text;
		}
		return mb_substr( $text, 0, max( 0, $max_length - 3 ), 'UTF-8' ) . '...';
	}

	/**
	 * Pure. Discord's own 2000-character content limit; allowed_mentions
	 * is empty so an @everyone/@here accidentally present in a backup
	 * error message can never actually ping anyone -- true of the
	 * content field regardless of $extra, and Discord webhook embeds
	 * don't trigger mentions at all, so this protection isn't weakened
	 * by adding one below.
	 *
	 * 2026-11-03: $extra['icon_url'] (optional, default none -- omitting
	 * it keeps this call's old plain-content-only behavior byte-for-byte,
	 * which is what every pre-existing test below still exercises) adds
	 * a second, visual identification layer: an embed with the plugin's
	 * icon as a thumbnail, a success/failure/test color bar, and a
	 * footer naming the plugin. Embed description/title limits (4096/256)
	 * are Discord's own documented maximums.
	 */
	public static function build_discord_request( string $webhook_url, string $title, string $message, array $extra = array() ): array {
		$content = self::truncate( $title . "\n" . $message, 2000 );
		$body    = array(
			'content'          => $content,
			'allowed_mentions' => array( 'parse' => array() ),
		);
		if ( ! empty( $extra['icon_url'] ) ) {
			$color = ! empty( $extra['is_test'] ) ? 0x3b82f6 : ( ! empty( $extra['ok'] ) ? 0x2e8b57 : 0xc0392b );
			$body['embeds'] = array( array(
				'title'       => self::truncate( $title, 256 ),
				'description' => self::truncate( $message, 4096 ),
				'color'       => $color,
				'thumbnail'   => array( 'url' => $extra['icon_url'] ),
				'footer'      => array( 'text' => self::PLUGIN_DISPLAY_NAME ),
			) );
		}
		return array(
			'url'  => $webhook_url,
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			),
		);
	}

	/**
	 * Pure. Slack's mrkdwn format requires &, <, > escaped or the message
	 * renders wrong (or a literal link if an error message happens to
	 * contain something URL-shaped). The 'text' field is always sent
	 * (Slack's own fallback for notifications/screen readers, and what
	 * every pre-existing test below checks) regardless of $extra.
	 *
	 * 2026-11-03: $extra['icon_url'] (optional, default none) additionally
	 * sends a Block Kit 'blocks' section with the plugin icon as an
	 * accessory image, so Slack clients that render blocks show it
	 * visually; clients that don't still get the plain 'text' fallback
	 * unchanged.
	 */
	public static function build_slack_request( string $webhook_url, string $title, string $message, array $extra = array() ): array {
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $title . "\n" . $message );
		$body = array( 'text' => $text );
		if ( ! empty( $extra['icon_url'] ) ) {
			/**
			 * Vex security audit, 2026-11-03 (LOW-V-03): Slack documents
			 * a 3000-character limit for the text field inside a section
			 * block. Exceeding it causes Slack to reject the ENTIRE
			 * payload (not just the blocks portion) with an
			 * invalid_blocks error, silently dropping even the plain
			 * top-level 'text' field that the existing tests assert as
			 * the fallback. Fixed: truncate the block text to 3000 chars
			 * before including it. The top-level 'text' field (Slack's
			 * own notification fallback for screen readers / sidebar
			 * previews) is untruncated and unaffected by this limit.
			 */
			$body['blocks'] = array( array(
				'type'      => 'section',
				'text'      => array( 'type' => 'mrkdwn', 'text' => self::truncate( $text, 3000 ) ),
				'accessory' => array(
					'type'      => 'image',
					'image_url' => $extra['icon_url'],
					'alt_text'  => self::PLUGIN_DISPLAY_NAME,
				),
			) );
		}
		return array(
			'url'  => $webhook_url,
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			),
		);
	}

	/**
	 * Pure. Pushover's own documented limits: title 250 chars, message
	 * 1024. 2026-11-03: $extra['manage_url'] (optional, default none)
	 * adds Pushover's 'url'/'url_title' fields -- a clickable link shown
	 * under the notification. 2026-11-04: $extra['icon_base64']
	 * (optional, default none; see icon_attachment() below for where
	 * it's actually read from disk) adds Pushover's 'attachment_base64'/
	 * 'attachment_type' fields -- Pushover has no URL-based inline-image
	 * mechanism the way Discord/Slack/ntfy/Gotify/Telegram do, only a
	 * base64-encoded body field or a multipart upload. This function
	 * stays pure either way: it never touches disk itself, only embeds
	 * whatever bytes $extra already carries.
	 */
	public static function build_pushover_request( string $user_key, string $api_token, string $title, string $message, array $extra = array() ): array {
		$body = array(
			'token'   => $api_token,
			'user'    => $user_key,
			'title'   => self::truncate( $title, 250 ),
			'message' => self::truncate( $message, 1024 ),
		);
		if ( ! empty( $extra['manage_url'] ) ) {
			$body['url']       = $extra['manage_url'];
			$body['url_title'] = __( 'Manage WPThistle', 'wpthistle-backup-restore' );
		}
		if ( ! empty( $extra['icon_base64'] ) ) {
			$body['attachment_base64'] = $extra['icon_base64'];
			$body['attachment_type']   = empty( $extra['icon_mime'] ) ? 'image/png' : $extra['icon_mime'];
		}
		return array(
			'url'  => 'https://api.pushover.net/1/messages.json',
			'args' => array( 'body' => $body ),
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
	public static function build_ntfy_request( string $topic_url, string $token, string $title, string $message, array $extra = array() ): array {
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

		$json = array(
			'topic'   => $topic,
			'title'   => $title,
			'message' => $message,
		);
		// 2026-11-03: ntfy's own documented 'icon' field (a URL), optional
		// via $extra, default omitted -- every pre-existing test below
		// calls this with no $extra and gets the original body shape.
		if ( ! empty( $extra['icon_url'] ) ) {
			$json['icon'] = $extra['icon_url'];
		}

		return array(
			'url'  => $server_root . '/',
			'args' => array(
				'headers' => $headers,
				'body'    => wp_json_encode( $json ),
			),
		);
	}

	/**
	 * Pure. $server_url is the Gotify instance's own base URL; the app
	 * token is a query parameter, Gotify's own documented auth convention
	 * for this endpoint. 2026-11-03: $extra['icon_url'] (optional,
	 * default none) adds Gotify's own documented
	 * extras['client::notification']['bigImageUrl'] -- its client apps
	 * render this as a large image on the notification. Omitted
	 * entirely when not given, so every pre-existing test below sees the
	 * original body shape unchanged.
	 */
	public static function build_gotify_request( string $server_url, string $token, string $title, string $message, array $extra = array() ): array {
		$body = array(
			'title'    => $title,
			'message'  => $message,
			'priority' => 5,
		);
		if ( ! empty( $extra['icon_url'] ) ) {
			$body['extras'] = array(
				'client::notification' => array( 'bigImageUrl' => $extra['icon_url'] ),
			);
		}
		return array(
			'url'  => rtrim( $server_url, '/' ) . '/message?token=' . rawurlencode( $token ),
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			),
		);
	}

	/**
	 * Pure. Deliberately no parse_mode, plain text only (on both the
	 * sendMessage and sendPhoto paths below). A backup ID or error
	 * message routinely contains underscores and asterisks; under
	 * Telegram's Markdown parser those are formatting characters, and an
	 * unbalanced one turns an ordinary notification into a 400.
	 *
	 * 2026-11-03: $extra['icon_url'] (optional, default none) switches
	 * to Telegram's sendPhoto endpoint (photo by URL, Telegram fetches it
	 * server-side -- no file upload from this plugin), with the combined
	 * text as the caption. Telegram's caption limit (1024 chars) is
	 * shorter than sendMessage's text limit, hence the separate
	 * truncate() call on this path only. Omitting $extra keeps the exact
	 * original sendMessage shape every pre-existing test below checks.
	 */
	public static function build_telegram_request( string $bot_token, string $chat_id, string $title, string $message, array $extra = array() ): array {
		if ( ! empty( $extra['icon_url'] ) ) {
			return array(
				'url'  => 'https://api.telegram.org/bot' . rawurlencode( $bot_token ) . '/sendPhoto',
				'args' => array(
					'body' => array(
						'chat_id' => $chat_id,
						'photo'   => $extra['icon_url'],
						'caption' => self::truncate( $title . "\n" . $message, 1024 ),
					),
				),
			);
		}
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
	/**
	 * 2026-11-03: added 'plugin' (the display name, so a consumer logging
	 * these events doesn't have to hardcode it) and 'icon_url' (optional,
	 * default '' -- the generic webhook is consumed by automation, not
	 * a human inbox, so an icon is "here's a URL if you want it" rather
	 * than something rendered automatically like the other channels).
	 * schema_version stays 1: both are additive fields, not a breaking
	 * change to the existing shape.
	 */
	public static function build_webhook_request( string $webhook_url, array $job, string $site_name, string $icon_url = '' ): array {
		$ok = 'verified' === ( $job['status'] ?? '' );
		return array(
			'url'  => $webhook_url,
			'args' => array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'schema_version' => 1,
					'event'          => 'test' === ( $job['status'] ?? '' ) ? 'test' : ( $ok ? 'backup_succeeded' : 'backup_failed' ),
					'plugin'         => self::PLUGIN_DISPLAY_NAME,
					'site'           => $site_name,
					'backup_id'      => $job['backup_id'] ?? '',
					'status'         => $job['status'] ?? '',
					'finished_at'    => $job['updated_at'] ?? '',
					'error'          => $ok ? '' : ( $job['error'] ?? '' ),
					'plugin_version' => defined( 'WPTHISTLE_BACKUP_VERSION' ) ? WPTHISTLE_BACKUP_VERSION : '',
					'icon_url'       => $icon_url,
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

	/**
	 * Pure: the "Send Test Notification" button's message, deliberately
	 * worded so it's never mistaken for a real backup outcome. 2026-11-03:
	 * merges in job_context() of a synthetic 'test'-status job the same
	 * way build_alert_text() does, so the rich per-channel builders can
	 * treat a test send identically to a real one (just with is_test
	 * true and no backup_id/finished/error to show).
	 */
	public static function build_test_text( string $site_name ): array {
		$ctx = self::job_context( array( 'status' => 'test' ), $site_name );
		/* translators: %s: site name. */
		$title = sprintf( __( '[%s] Test notification', 'wpthistle-backup-restore' ), $site_name );
		$message = __( 'If you can see this, this channel is configured correctly. No backup actually ran.', 'wpthistle-backup-restore' )
			. "\n\n"
			/* translators: %s: plugin display name. */
			. sprintf( __( 'Plugin: %s', 'wpthistle-backup-restore' ), self::PLUGIN_DISPLAY_NAME )
			. "\n"
			/* translators: %s: site name. */
			. sprintf( __( 'Site: %s', 'wpthistle-backup-restore' ), $site_name );

		/* translators: 1: plugin display name, 2: site name, 3: result label. */
		$email_subject = sprintf( __( '[%1$s] %2$s: %3$s', 'wpthistle-backup-restore' ), 'WPThistle', $site_name, $ctx['result_label'] );

		return array_merge( $ctx, array(
			'title'         => $title,
			'message'       => $message,
			'manage_url'    => '',
			'email_subject' => $email_subject,
		) );
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
	 * 2026-11-03: the plugin's own icon, as a public URL the recipient's
	 * mail client / Discord / Slack / ntfy / Gotify / Telegram server
	 * fetches directly -- same WPTHISTLE_BACKUP_URL-based public-URL
	 * pattern this plugin already uses for the admin menu icon and the
	 * update-feed's plugin_info() icon (class-update-checker.php), not a
	 * new exposure. Computed here (impure: reads a constant), passed
	 * into the otherwise-pure builders as a plain string, same as
	 * $site_name/$settings_url already are.
	 */
	private static function icon_url(): string {
		return defined( 'WPTHISTLE_BACKUP_URL' ) ? WPTHISTLE_BACKUP_URL . 'assets/images/logo-icon-color.png' : '';
	}

	private static function wordmark_url(): string {
		return defined( 'WPTHISTLE_BACKUP_URL' ) ? WPTHISTLE_BACKUP_URL . 'assets/images/logo-wordmark-color.png' : '';
	}

	/**
	 * 2026-11-04: Pushover's only image mechanisms are attachment_base64
	 * (a base64-encoded file body in the same POST) or a multipart file
	 * upload -- no URL-based option, unlike every other image-capable
	 * channel here. Reads the plugin's own bundled icon (a fixed path
	 * under this plugin's own directory, never user input, so there's
	 * no traversal/upload surface here) fresh on every call rather than
	 * caching it: this runs at most once per dispatch (one Pushover send
	 * per backup/test-notification), and a long-running PHP-FPM worker
	 * caching a stale copy across an in-place plugin update/icon
	 * replacement is a worse failure mode than one extra small disk
	 * read. A missing or unreadable file degrades to no attachment
	 * (the notification still sends, just without the icon), never to a
	 * failed or skipped notification.
	 */
	private static function icon_attachment(): array {
		if ( ! defined( 'WPTHISTLE_BACKUP_DIR' ) ) {
			return array();
		}
		$raw = @file_get_contents( WPTHISTLE_BACKUP_DIR . 'assets/images/logo-icon-color.png' );
		if ( false === $raw || '' === $raw ) {
			return array();
		}
		return array(
			'base64' => base64_encode( $raw ),
			'mime'   => 'image/png',
		);
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

	/**
	 * @return true|string
	 *
	 * 2026-11-03: $text is now build_alert_text()/build_test_text()'s
	 * full merged context (job_context() fields + title/message/
	 * email_subject), not just a title/message pair -- $image_extra
	 * (icon_url + the ok/is_test flags Discord's color bar needs) is
	 * built once here and handed to every channel whose builder accepts
	 * it; a channel that ignores unknown $extra keys (all of them do)
	 * is unaffected by the two it doesn't use.
	 */
	private static function send_to_channel( string $channel, array $all, array $job, string $site_name, array $text ) {
		$image_extra = array( 'icon_url' => self::icon_url(), 'ok' => $text['ok'], 'is_test' => $text['is_test'] );
		switch ( $channel ) {
			case 'email':
				$headers = array( 'Content-Type: text/html; charset=UTF-8' );
				$body    = self::build_email_html( $text, self::wordmark_url() );
				$sent    = wp_mail( get_option( 'admin_email' ), $text['email_subject'], $body, $headers );
				return $sent ? true : 'send failed';
			case 'discord':
				$req = self::build_discord_request( $all['notify_discord_webhook_url'], $text['title'], $text['message'], $image_extra );
				return self::send_request( $req['url'], $req['args'] );
			case 'slack':
				$req    = self::build_slack_request( $all['notify_slack_webhook_url'], $text['title'], $text['message'], $image_extra );
				$result = self::send_request( $req['url'], $req['args'] );
				/**
				 * Vex security audit, 2026-11-03 (LOW-V-04): Slack rejects
				 * the ENTIRE message (including the plain top-level 'text'
				 * fallback) when blocks are present and the image URL is
				 * unreachable. This can happen on sites behind Cloudflare
				 * bot protection, behind a firewall, or running on
				 * localhost. Fall back to the no-$extra shape (plain text
				 * only) if the icon-enriched request fails, so the admin
				 * always gets at least the proven pre-change behavior.
				 */
				if ( true !== $result && ! empty( $image_extra['icon_url'] ) ) {
					$req = self::build_slack_request( $all['notify_slack_webhook_url'], $text['title'], $text['message'] );
					return self::send_request( $req['url'], $req['args'] );
				}
				return $result;
			case 'pushover':
				$po_extra = array( 'manage_url' => $text['manage_url'] );
				$icon     = self::icon_attachment();
				if ( ! empty( $icon ) ) {
					$po_extra['icon_base64'] = $icon['base64'];
					$po_extra['icon_mime']   = $icon['mime'];
				}
				$req    = self::build_pushover_request( $all['notify_pushover_user_key'], $all['notify_pushover_api_token'], $text['title'], $text['message'], $po_extra );
				$result = self::send_request( $req['url'], $req['args'] );
				/**
				 * Vex security audit, 2026-11-04 (LOW-V-06): no live
				 * request with a real Pushover account has ever actually
				 * exercised attachment_base64 (E2E Case L uses a
				 * pre_http_request mock) -- if Pushover's real API
				 * rejects the attachment-enriched request for any
				 * reason, the notification would be lost outright with
				 * no fallback, where before this feature it would have
				 * delivered. Same pattern as the existing Slack/Telegram
				 * fallbacks: retry without the icon fields (manage_url
				 * stays, since that path is already proven) on any
				 * non-2xx first response.
				 */
				if ( true !== $result && ! empty( $po_extra['icon_base64'] ) ) {
					$req = self::build_pushover_request( $all['notify_pushover_user_key'], $all['notify_pushover_api_token'], $text['title'], $text['message'], array( 'manage_url' => $text['manage_url'] ) );
					return self::send_request( $req['url'], $req['args'] );
				}
				return $result;
			case 'pushbullet':
				$req = self::build_pushbullet_request( $all['notify_pushbullet_access_token'], $text['title'], $text['message'] );
				return self::send_request( $req['url'], $req['args'] );
			case 'ntfy':
				$req = self::build_ntfy_request( $all['notify_ntfy_url'], $all['notify_ntfy_token'], $text['title'], $text['message'], $image_extra );
				return self::send_request( $req['url'], $req['args'] );
			case 'gotify':
				$req = self::build_gotify_request( $all['notify_gotify_url'], $all['notify_gotify_token'], $text['title'], $text['message'], $image_extra );
				return self::send_request( $req['url'], $req['args'] );
			case 'telegram':
				$req    = self::build_telegram_request( $all['notify_telegram_bot_token'], $all['notify_telegram_chat_id'], $text['title'], $text['message'], $image_extra );
				$result = self::send_request( $req['url'], $req['args'] );
				/**
				 * Vex security audit, 2026-11-03 (LOW-V-04): Telegram's
				 * sendPhoto endpoint requires Telegram's own servers to
				 * fetch the photo URL. On sites not publicly reachable
				 * (behind Cloudflare bot protection, Basic Auth, or a
				 * private network), Telegram returns 400 and no message
				 * is delivered -- sendMessage would have worked. Fall back
				 * to sendMessage (no $extra) if sendPhoto fails.
				 */
				if ( true !== $result && ! empty( $image_extra['icon_url'] ) ) {
					$req = self::build_telegram_request( $all['notify_telegram_bot_token'], $all['notify_telegram_chat_id'], $text['title'], $text['message'] );
					return self::send_request( $req['url'], $req['args'] );
				}
				return $result;
			case 'webhook':
				$req = self::build_webhook_request( $all['notify_webhook_url'], $job, $site_name, self::icon_url() );
				return self::send_request( $req['url'], $req['args'] );
			default:
				return 'unknown channel';
		}
	}
}
