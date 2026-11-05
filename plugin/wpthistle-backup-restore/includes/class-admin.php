<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page + AJAX action handlers. Every handler checks capability
 * and nonce before doing anything else (threat-model T1/T2) — this is
 * the one place in the plugin that talks to $_POST / current_user_can().
 */
class Admin {

	const NONCE_ACTION = 'wpthistle_backup_admin';

	// Vex security audit finding LOW-N-04: nothing previously stopped a
	// script-automated, rapidly repeated "Send Test Notification" click
	// (a valid nonce + manage_options still being the only gate) from
	// hammering whichever third-party services are configured. A short
	// site-wide cooldown -- not per-user, since the point is capping
	// how often THIS SITE hits those outside services at all, regardless
	// of which admin is clicking -- closes that without meaningfully
	// slowing down a legitimate admin actually testing their setup.
	const NOTIFY_TEST_THROTTLE_KEY     = 'wpthistle_backup_notify_test_throttle';
	const NOTIFY_TEST_THROTTLE_SECONDS = 10;

	/**
	 * Phase 3, security audit finding LOW-DL-4: the download link previously reused
	 * NONCE_ACTION, the same nonce shared by every other action on this
	 * page (save settings, start backup, cancel job, start/upload
	 * restore) -- that value sits in a navigable GET URL (browser
	 * history, server access logs, Referer headers), a materially wider
	 * exposure surface than a POSTed nonce never leaves the request body.
	 * A nonce alone (without the session cookie) can't perform any
	 * privileged action, but least-privilege nonce scoping is cheap here.
	 */
	const NONCE_ACTION_DOWNLOAD = 'wpthistle_backup_download_bundle';

	/**
	 * Shared top-level menu slug for the whole WPThistle plugin family, not
	 * just this plugin. Every WPThistle plugin's admin.php should carry an
	 * identical copy of add_menu() below: whichever plugin's admin_menu
	 * callback runs first creates the 'WPThistle' parent (and immediately
	 * removes the duplicate landing submenu WP auto-adds under it, since
	 * no plugin owns a real page there), and every other WPThistle plugin
	 * just finds it already in $menu and adds its own submenu under it.
	 * No shared code loads across plugin boundaries -- this is the same
	 * "copy the idempotent snippet, let whichever loads first win"
	 * pattern WP libraries like Action Scheduler use, since separate
	 * plugin zips can't literally share a PHP class.
	 */
	const FAMILY_PARENT_SLUG = 'wpthistle';

	/**
	 * Phase 4 (family hub landing page): the contract any WPThistle-family
	 * plugin hooks into to appear on the shared parent page's status
	 * grid. A card is a plain associative array:
	 *   slug         string  unique across the family (this plugin's: 'backup-restore')
	 *   title        string  plain text, no markup
	 *   icon         string  a 'dashicons-*' class name
	 *   status_class string  one of 'ok' | 'warn' | 'error' | 'neutral'
	 *   status_label string  plain text, no markup (e.g. "Backup verified")
	 *   detail       string  plain text, no markup (e.g. "2 hours ago")
	 *   url          string  this plugin's own settings page URL
	 * Every field is treated as plain text and escaped on render
	 * (esc_html/esc_url/a dashicons-class allowlist) -- a sibling
	 * WPThistle plugin's bug is still foreign input to this one once it
	 * crosses the filter, same as any other untrusted string this
	 * codebase renders.
	 */
	const STATUS_CARDS_FILTER = 'wpthistle_family_status_cards';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( self::STATUS_CARDS_FILTER, array( $this, 'add_own_status_card' ) );
		add_action( 'wp_ajax_wpthistle_backup_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wpthistle_backup_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wpthistle_backup_forget_connection', array( $this, 'ajax_forget_connection' ) );
		add_action( 'wp_ajax_wpthistle_backup_notify_test', array( $this, 'ajax_notify_test' ) );
		add_action( 'wp_ajax_wpthistle_backup_start_backup', array( $this, 'ajax_start_backup' ) );
		add_action( 'wp_ajax_wpthistle_backup_advance_job', array( $this, 'ajax_advance_job' ) );
		add_action( 'wp_ajax_wpthistle_backup_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_wpthistle_backup_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_wpthistle_backup_list_restorable_backups', array( $this, 'ajax_list_restorable_backups' ) );
		add_action( 'wp_ajax_wpthistle_backup_upload_restore_bundle', array( $this, 'ajax_upload_restore_bundle' ) );
		add_action( 'wp_ajax_wpthistle_backup_start_restore', array( $this, 'ajax_start_restore' ) );
		// Phase 3 (downloadable backups): a navigable GET, not AJAX --
		// fetch() would buffer the whole zip in browser memory before
		// handing it to the user, a real problem for a full-site backup.
		// The priv-only admin_post_ hook refuses an unauthenticated request
		// before this callback is even reached.
		add_action( 'admin_post_wpthistle_backup_download_bundle', array( $this, 'handle_download_bundle' ) );
		add_action( 'wp_ajax_wpthistle_backup_delete_download_bundle', array( $this, 'ajax_delete_download_bundle' ) );
	}

	/**
	 * The family-hub top-level menu icon: WP recolors a base64 SVG data URI
	 * to match whichever admin color scheme is active, the same way it
	 * recolors its own dashicons -- only works with a single-color SVG, which
	 * is exactly what the monochrome brand mark is for. Falls back to the
	 * stock dashicon if the bundled file is ever missing (e.g. a corrupted
	 * manual install), rather than breaking menu registration.
	 */
	private function menu_icon_data_uri(): string {
		$path = WPTHISTLE_BACKUP_DIR . 'assets/images/logo-icon-mono.svg';
		if ( ! file_exists( $path ) ) {
			return 'dashicons-shield';
		}
		$svg = file_get_contents( $path );
		if ( false === $svg ) {
			return 'dashicons-shield';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * A notification channel's credential field's own value is NEVER put
	 * into the page (same discipline as app_password: the input always
	 * renders value=""), so this is the only signal the admin gets for
	 * "is something already saved here" -- the placeholder text, not the
	 * field's content.
	 */
	private function secret_field_placeholder( string $current_value, string $empty_placeholder ): string {
		return '' !== $current_value ? __( 'Saved (leave blank to keep)', 'wpthistle-backup-restore' ) : $empty_placeholder;
	}

	/**
	 * Vex security audit finding LOW-N-03: pairs with Settings::save_secret_field()'s
	 * new `{$key}_clear` flag. The checkbox itself is only rendered when
	 * there is actually something saved to clear -- nothing to offer
	 * otherwise, and it keeps a field with nothing saved from growing a
	 * checkbox that would do nothing.
	 *
	 * 2026-11-03: the enclosing `<span id="..._clear_wrap">` is now ALWAYS
	 * rendered, checkbox present or not, so it's a stable insertion point
	 * syncSecretFieldUi() (below, JS) can fill in or empty out right after
	 * an AJAX save -- without this, a field that went from empty to saved
	 * (or saved to cleared) via Save Settings had no checkbox/placeholder
	 * update at all until a full page reload, since this PHP only ever
	 * ran once, at the page's initial server-side render.
	 */
	private function clear_secret_checkbox( string $field_id, string $current_value ): string {
		$inner = '';
		if ( '' !== $current_value ) {
			$inner = '<label style="margin-left:8px;font-weight:normal;"><input type="checkbox" id="wpthistle_backup_' . esc_attr( $field_id ) . '_clear"> '
				. esc_html__( 'Clear saved value', 'wpthistle-backup-restore' ) . '</label>';
		}
		return '<span id="wpthistle_backup_' . esc_attr( $field_id ) . '_clear_wrap">' . $inner . '</span>';
	}

	/**
	 * Vex security audit finding INFO-N-03: `! empty( $_POST[...] )`
	 * treats ANY non-empty string as truthy, including the literal
	 * string "false" -- harmless today since the JS only ever sends
	 * '1' or '', but a future refactor sending a raw JS boolean through
	 * URLSearchParams/$.param() would serialize `false` as "false" and
	 * silently clear every credential on every save. A strict '1'
	 * comparison makes the wire contract explicit instead of relying on
	 * today's JS never changing.
	 */
	private function clear_flag( string $key ): bool {
		return isset( $_POST[ $key ] ) && '1' === (string) $_POST[ $key ];
	}

	/**
	 * The one "Buy me a coffee" anchor markup, reused verbatim as the %s
	 * in six different printf() sentences across this file (Home, Connection,
	 * Backup Schedule, Advanced, and Notifications tabs, plus the family hub
	 * footer) -- each call site keeps its own distinct sentence (the owner's
	 * approved per-tab wording), only the repeated markup itself is shared
	 * here. The URL is the only part of this that must stay byte-identical;
	 * it's also referenced independently by readme.txt's Donate link and
	 * add_donate_row_meta() in wpthistle-backup-restore.php.
	 */
	private function coffee_link(): string {
		return '<a href="https://buymeacoffee.com/ItsMNLOP" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Buy me a coffee', 'wpthistle-backup-restore' ) . '</a>';
	}

	public function add_menu(): void {
		global $menu;
		$parent_exists = false;
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && self::FAMILY_PARENT_SLUG === $item[2] ) {
				$parent_exists = true;
				break;
			}
		}
		if ( ! $parent_exists ) {
			add_menu_page(
				__( 'WPThistle', 'wpthistle-backup-restore' ),
				__( 'WPThistle', 'wpthistle-backup-restore' ),
				'manage_options',
				self::FAMILY_PARENT_SLUG,
				// Phase 4: this used to be '' (no callback), so clicking the
				// parent menu item itself 404'd -- WP only auto-adds a
				// submenu matching the parent slug for a landing page, and
				// nothing rendered one. Whichever WPThistle plugin's
				// admin_menu callback runs first now owns the real family
				// hub page instead of a dead link, same "first one wins"
				// rule already governing who creates the parent at all.
				array( $this, 'render_family_hub' ),
				$this->menu_icon_data_uri()
			);
			// WP auto-adds a submenu item duplicating the parent label/slug;
			// render_family_hub() above is that page now, so this just
			// removes the duplicate auto-added entry from the submenu list
			// (the page itself still renders via the parent's own callback).
			remove_submenu_page( self::FAMILY_PARENT_SLUG, self::FAMILY_PARENT_SLUG );
		}
		add_submenu_page(
			self::FAMILY_PARENT_SLUG,
			__( 'WPThistle Backup and Restore', 'wpthistle-backup-restore' ),
			__( 'Backup and Restore', 'wpthistle-backup-restore' ),
			'manage_options',
			'wpthistle-backup-restore',
			array( $this, 'render_page' )
		);
	}

	/**
	 * The family hub landing page (top-level "WPThistle" click target).
	 * Renders a status card per installed WPThistle plugin, gathered via
	 * self::STATUS_CARDS_FILTER -- see that constant's docblock for the
	 * card contract. Every field from a card is treated as untrusted
	 * plain text regardless of origin (this plugin's own card included),
	 * same escaping discipline as render_page() below.
	 */
	public function render_family_hub(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cards = apply_filters( self::STATUS_CARDS_FILTER, array() );
		?>
		<div class="wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'WPThistle', 'wpthistle-backup-restore' ); ?></h1>
			<div style="padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid #dcdcde;">
				<img src="<?php echo esc_url( WPTHISTLE_BACKUP_URL . 'assets/images/logo-wordmark-color.png' ); ?>" alt="<?php esc_attr_e( 'WPThistle', 'wpthistle-backup-restore' ); ?>" style="height:42px;width:auto;display:block;">
				<p style="margin-top:8px;"><?php esc_html_e( 'A home base for every WPThistle plugin on this site. Each one below reports its own status; open a card to manage that plugin.', 'wpthistle-backup-restore' ); ?></p>
			</div>

			<?php if ( empty( $cards ) ) : ?>
				<p><em><?php esc_html_e( 'No WPThistle plugins reported a status card.', 'wpthistle-backup-restore' ); ?></em></p>
			<?php else : ?>
				<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:16px;">
					<?php foreach ( $cards as $card ) : ?>
						<?php
						$card        = wp_parse_args(
							$card,
							array(
								'slug'         => '',
								'title'        => __( '(untitled)', 'wpthistle-backup-restore' ),
								'icon'         => 'dashicons-admin-generic',
								// Optional: a URL to a real icon image, which takes priority
								// over the dashicons fallback below when present. Lets this
								// plugin's own card show its brand mark while any other
								// WPThistle plugin that hasn't set one yet still gets a
								// sensible dashicon instead of a broken image.
								'icon_image'   => '',
								'status_class' => 'neutral',
								'status_label' => '',
								'detail'       => '',
								'version'        => '',
								'version_ok'     => true,
								'update_version' => '',
								'url'          => '',
							)
						);
						$status_colors = array(
							'ok'      => '#46b450',
							'warn'    => '#ffb900',
							'error'   => '#dc3232',
							'neutral' => '#787c82',
						);
						$dot_color = $status_colors[ $card['status_class'] ] ?? $status_colors['neutral'];
						$icon      = preg_match( '/^dashicons-[a-z0-9-]+$/', (string) $card['icon'] ) ? $card['icon'] : 'dashicons-admin-generic';
						?>
						<a href="<?php echo esc_url( $card['url'] ); ?>" style="display:block;width:280px;padding:16px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;text-decoration:none;color:inherit;">
							<div style="display:flex;align-items:center;gap:8px;">
								<?php if ( '' !== $card['icon_image'] ) : ?>
									<img src="<?php echo esc_url( $card['icon_image'] ); ?>" alt="" style="width:24px;height:24px;object-fit:contain;">
								<?php else : ?>
									<span class="dashicons <?php echo esc_attr( $icon ); ?>" style="font-size:20px;"></span>
								<?php endif; ?>
								<strong style="font-size:14px;"><?php echo esc_html( $card['title'] ); ?></strong>
							</div>
							<div style="margin-top:10px;display:flex;align-items:center;gap:6px;">
								<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $dot_color ); ?>;"></span>
								<span><?php echo esc_html( $card['status_label'] ); ?></span>
							</div>
							<?php if ( '' !== $card['detail'] ) : ?>
								<p class="description" style="margin-top:6px;"><?php echo esc_html( $card['detail'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $card['version'] ) : ?>
								<p class="description" style="margin-top:2px;font-size:12px;color:<?php echo $card['version_ok'] ? '#46b450' : '#dc3232'; ?>;"><?php echo esc_html( $card['version'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $card['update_version'] ) : ?>
								<p class="description" style="margin-top:0;font-size:12px;color:#787c82;"><?php echo esc_html( $card['update_version'] ); ?></p>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="description" style="margin-top:24px;">
				<?php
				printf(
					/* translators: %s: "Buy me a coffee" link. */
					esc_html__( 'Built by a human, assembled by AI, running on coffee ☕ -- if WPThistle saved your bacon, the bots would not say no to a refill. %s', 'wpthistle-backup-restore' ),
					$this->coffee_link()
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * This plugin's own entry in the family hub's status grid. Mirrors
	 * render_page()'s "last verified backup" summary, but against the
	 * latest job of any outcome (not just the latest *verified* one) --
	 * the hub's job is to surface a stuck or failed backup, not just the
	 * last success.
	 */
	public function add_own_status_card( array $cards ): array {
		$url        = admin_url( 'admin.php?page=wpthistle-backup-restore' );
		$icon_image = WPTHISTLE_BACKUP_URL . 'assets/images/logo-icon-color.png';

		if ( ! Settings::has_credentials() ) {
			$cards[] = array(
				'slug'         => 'backup-restore',
				'title'        => __( 'Backup and Restore', 'wpthistle-backup-restore' ),
				'icon'         => 'dashicons-cloud-upload',
				'icon_image'   => $icon_image,
				'status_class' => 'neutral',
				'status_label' => __( 'Not configured', 'wpthistle-backup-restore' ),
				'detail'       => __( 'Connect a Nextcloud, ownCloud, or OpenCloud server to start backing up.', 'wpthistle-backup-restore' ),
				...$this->version_fields(),
				'url'          => $url,
			);
			return $cards;
		}

		$latest = Job_Store::get_latest();
		if ( null === $latest ) {
			$cards[] = array(
				'slug'         => 'backup-restore',
				'title'        => __( 'Backup and Restore', 'wpthistle-backup-restore' ),
				'icon'         => 'dashicons-cloud-upload',
				'icon_image'   => $icon_image,
				'status_class' => 'warn',
				'status_label' => __( 'No backup yet', 'wpthistle-backup-restore' ),
				'detail'       => __( 'Configured -- run your first backup.', 'wpthistle-backup-restore' ),
				...$this->version_fields(),
				'url'          => $url,
			);
			return $cards;
		}

		$status_map = array(
			'verified'           => array( 'ok', __( 'Backup verified', 'wpthistle-backup-restore' ) ),
			'failed'             => array( 'error', __( 'Last backup failed', 'wpthistle-backup-restore' ) ),
			'uploaded-unverified' => array( 'warn', __( 'Backup in progress', 'wpthistle-backup-restore' ) ),
			'running'            => array( 'warn', __( 'Backup in progress', 'wpthistle-backup-restore' ) ),
			'queued'             => array( 'warn', __( 'Backup in progress', 'wpthistle-backup-restore' ) ),
		);
		list( $status_class, $status_label ) = $status_map[ $latest['status'] ] ?? array( 'neutral', __( 'Unknown status', 'wpthistle-backup-restore' ) );

		$cards[] = array(
			'slug'         => 'backup-restore',
			'title'        => __( 'Backup and Restore', 'wpthistle-backup-restore' ),
			'icon'         => 'dashicons-cloud-upload',
			'icon_image'   => $icon_image,
			'status_class' => $status_class,
			'status_label' => $status_label,
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			'detail'       => sprintf( __( '%s ago', 'wpthistle-backup-restore' ), human_time_diff( strtotime( $latest['updated_at'] . ' UTC' ) ) ),
			...$this->version_fields(),
			'url'          => $url,
		);
		return $cards;
	}

	/**
	 * This plugin's own "Installed: vX.Y.Z" / "Latest: vX.Y.Z" pair for the
	 * family hub card. Reads WP core's own update_plugins transient (already
	 * populated by Update_Checker::check_for_update(), which already does
	 * the GitHub call + trusted-URL check) instead of making a second
	 * network request here just to show a version string.
	 */
	private function version_fields(): array {
		$basename    = plugin_basename( WPTHISTLE_BACKUP_DIR . 'wpthistle-backup-restore.php' );
		$updates     = get_site_transient( 'update_plugins' );
		$item        = is_object( $updates ) && isset( $updates->response[ $basename ] ) ? $updates->response[ $basename ] : null;
		$new_version = is_string( $item->new_version ?? null ) ? $item->new_version : '';

		return array(
			/* translators: %s: currently installed plugin version number. */
			'version'        => sprintf( __( 'Installed: v%s', 'wpthistle-backup-restore' ), WPTHISTLE_BACKUP_VERSION ),
			'version_ok'     => '' === $new_version,
			/* translators: %s: newer plugin version number available to update to. */
			'update_version' => '' !== $new_version ? sprintf( __( 'Latest: v%s', 'wpthistle-backup-restore' ), $new_version ) : '',
		);
	}

	private function require_capability_and_nonce(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'wpthistle-backup-restore' ) ), 403 );
			return false;
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed; please reload the page.', 'wpthistle-backup-restore' ) ), 403 );
			return false;
		}
		return true;
	}

	public function ajax_save_settings(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$input = array(
			'backend'        => isset( $_POST['backend'] ) ? sanitize_text_field( wp_unslash( $_POST['backend'] ) ) : 'nextcloud',
			'nextcloud_url'  => isset( $_POST['nextcloud_url'] ) ? wp_unslash( $_POST['nextcloud_url'] ) : '',
			'nextcloud_user' => isset( $_POST['nextcloud_user'] ) ? wp_unslash( $_POST['nextcloud_user'] ) : '',
			'app_password'   => isset( $_POST['app_password'] ) ? wp_unslash( $_POST['app_password'] ) : '',
			'remote_folder'  => isset( $_POST['remote_folder'] ) ? wp_unslash( $_POST['remote_folder'] ) : '',
			'ca_bundle_path' => isset( $_POST['ca_bundle_path'] ) ? wp_unslash( $_POST['ca_bundle_path'] ) : '',
			// Phase 4 (day/time scheduling): schedule_days arrives as a JSON-encoded
			// array of ints (0=Sunday..6=Saturday) from the checkbox group below --
			// Settings::save() re-validates/clamps every element regardless.
			'schedule_days'  => isset( $_POST['schedule_days'] ) ? (array) json_decode( sanitize_text_field( wp_unslash( $_POST['schedule_days'] ) ), true ) : array(),
			// Phase 6 (backup plan dropdown): replaces the standalone
			// retention_tiered_enabled checkbox -- Settings::save() now
			// derives that flag from schedule_mode itself.
			'schedule_mode'     => isset( $_POST['schedule_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_mode'] ) ) : 'scheduled',
			'schedule_cadence'  => isset( $_POST['schedule_cadence'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_cadence'] ) ) : 'daily',
			'schedule_day_of_month' => isset( $_POST['schedule_day_of_month'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_day_of_month'] ) ) : 1,
			'retention_count' => isset( $_POST['retention_count'] ) ? (int) $_POST['retention_count'] : 0,
			'retention_daily'   => isset( $_POST['retention_daily'] ) ? (int) $_POST['retention_daily'] : Settings::DEFAULT_RETENTION_DAILY,
			'retention_weekly'  => isset( $_POST['retention_weekly'] ) ? (int) $_POST['retention_weekly'] : Settings::DEFAULT_RETENTION_WEEKLY,
			'retention_monthly' => isset( $_POST['retention_monthly'] ) ? (int) $_POST['retention_monthly'] : Settings::DEFAULT_RETENTION_MONTHLY,
			'incremental_enabled' => isset( $_POST['incremental_enabled'] ) ? ! empty( $_POST['incremental_enabled'] ) : false,
			'notify_on_failure' => isset( $_POST['notify_on_failure'] ) ? ! empty( $_POST['notify_on_failure'] ) : false,
			'notify_on_success' => isset( $_POST['notify_on_success'] ) ? ! empty( $_POST['notify_on_success'] ) : false,
			'notify_email_enabled' => isset( $_POST['notify_email_enabled'] ) ? ! empty( $_POST['notify_email_enabled'] ) : false,
			// Every notification channel credential below follows app_password's
			// own convention: wp_unslash() only, no sanitize_text_field(),
			// since a webhook URL or token can legitimately contain
			// characters that would mangle under it.
			'notify_discord_enabled' => isset( $_POST['notify_discord_enabled'] ) ? ! empty( $_POST['notify_discord_enabled'] ) : false,
			'notify_discord_webhook_url' => isset( $_POST['notify_discord_webhook_url'] ) ? wp_unslash( $_POST['notify_discord_webhook_url'] ) : '',
			'notify_discord_webhook_url_clear' => $this->clear_flag( 'notify_discord_webhook_url_clear' ),
			'notify_slack_enabled' => isset( $_POST['notify_slack_enabled'] ) ? ! empty( $_POST['notify_slack_enabled'] ) : false,
			'notify_slack_webhook_url' => isset( $_POST['notify_slack_webhook_url'] ) ? wp_unslash( $_POST['notify_slack_webhook_url'] ) : '',
			'notify_slack_webhook_url_clear' => $this->clear_flag( 'notify_slack_webhook_url_clear' ),
			'notify_pushover_enabled' => isset( $_POST['notify_pushover_enabled'] ) ? ! empty( $_POST['notify_pushover_enabled'] ) : false,
			'notify_pushover_user_key' => isset( $_POST['notify_pushover_user_key'] ) ? wp_unslash( $_POST['notify_pushover_user_key'] ) : '',
			'notify_pushover_user_key_clear' => $this->clear_flag( 'notify_pushover_user_key_clear' ),
			'notify_pushover_api_token' => isset( $_POST['notify_pushover_api_token'] ) ? wp_unslash( $_POST['notify_pushover_api_token'] ) : '',
			'notify_pushover_api_token_clear' => $this->clear_flag( 'notify_pushover_api_token_clear' ),
			'notify_pushbullet_enabled' => isset( $_POST['notify_pushbullet_enabled'] ) ? ! empty( $_POST['notify_pushbullet_enabled'] ) : false,
			'notify_pushbullet_access_token' => isset( $_POST['notify_pushbullet_access_token'] ) ? wp_unslash( $_POST['notify_pushbullet_access_token'] ) : '',
			'notify_pushbullet_access_token_clear' => $this->clear_flag( 'notify_pushbullet_access_token_clear' ),
			'notify_ntfy_enabled' => isset( $_POST['notify_ntfy_enabled'] ) ? ! empty( $_POST['notify_ntfy_enabled'] ) : false,
			'notify_ntfy_url' => isset( $_POST['notify_ntfy_url'] ) ? wp_unslash( $_POST['notify_ntfy_url'] ) : '',
			'notify_ntfy_url_clear' => $this->clear_flag( 'notify_ntfy_url_clear' ),
			'notify_ntfy_token' => isset( $_POST['notify_ntfy_token'] ) ? wp_unslash( $_POST['notify_ntfy_token'] ) : '',
			'notify_ntfy_token_clear' => $this->clear_flag( 'notify_ntfy_token_clear' ),
			'notify_gotify_enabled' => isset( $_POST['notify_gotify_enabled'] ) ? ! empty( $_POST['notify_gotify_enabled'] ) : false,
			'notify_gotify_url' => isset( $_POST['notify_gotify_url'] ) ? wp_unslash( $_POST['notify_gotify_url'] ) : '',
			'notify_gotify_token' => isset( $_POST['notify_gotify_token'] ) ? wp_unslash( $_POST['notify_gotify_token'] ) : '',
			'notify_gotify_token_clear' => $this->clear_flag( 'notify_gotify_token_clear' ),
			'notify_telegram_enabled' => isset( $_POST['notify_telegram_enabled'] ) ? ! empty( $_POST['notify_telegram_enabled'] ) : false,
			'notify_telegram_bot_token' => isset( $_POST['notify_telegram_bot_token'] ) ? wp_unslash( $_POST['notify_telegram_bot_token'] ) : '',
			'notify_telegram_bot_token_clear' => $this->clear_flag( 'notify_telegram_bot_token_clear' ),
			'notify_telegram_chat_id' => isset( $_POST['notify_telegram_chat_id'] ) ? wp_unslash( $_POST['notify_telegram_chat_id'] ) : '',
			'notify_webhook_enabled' => isset( $_POST['notify_webhook_enabled'] ) ? ! empty( $_POST['notify_webhook_enabled'] ) : false,
			'notify_webhook_url' => isset( $_POST['notify_webhook_url'] ) ? wp_unslash( $_POST['notify_webhook_url'] ) : '',
			'notify_webhook_url_clear' => $this->clear_flag( 'notify_webhook_url_clear' ),
		);
		$result = Settings::save( $input );
		if ( $result['ok'] ) {
			// Phase 5/6 (day-based trigger, backup-plan dropdown): apply
			// a schedule change immediately rather than waiting for the
			// next page load to reconcile it.
			maybe_run_scheduled_backup();
			wp_send_json_success( array( 'message' => __( 'Settings saved.', 'wpthistle-backup-restore' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Please fix the errors below.', 'wpthistle-backup-restore' ), 'errors' => $result['errors'] ) );
		}
	}

	public function ajax_test_connection(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Enter and save the server URL, username, and app password / app token first.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		$logger = new Logger( array( Settings::get_app_password() ) );
		$client = Nextcloud_Client::from_settings( $logger );
		$result = $client->test_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'], 'reason' => $result['reason'] ) );
		}
	}

	public function ajax_forget_connection(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		Settings::forget_connection();
		wp_send_json_success( array( 'message' => __( 'Connection forgotten. Scheduled and Tiered backups will fail until reconnected.', 'wpthistle-backup-restore' ) ) );
	}

	/**
	 * Vex security audit, reopened LOW-N-04-RESIDUAL (2026-11-03,
	 * threat-model T28): the 2.4.1 "fix" below used to be
	 * add_option()-then-fallback, which looked atomic but wasn't past
	 * the very first call ever made. add_option() runs its own
	 * get_option() pre-check first -- once this option has any stored
	 * value at all (true after the first test-notification click,
	 * forever after), every later call skips the INSERT path entirely
	 * and falls straight into a plain read-compare-write with no
	 * atomicity, the exact TOCTOU shape this was supposed to close.
	 * Proven live: 6 of 12 concurrent calls won a race that should
	 * allow exactly 1.
	 *
	 * Fixed with a real compare-and-swap: INSERT IGNORE as the one-time
	 * claim (a true no-op on collision, unlike add_option()'s UPSERT,
	 * which returns truthy for a second caller even when its $now
	 * differs by only one second), then an UPDATE ... WHERE
	 * option_value = $existing as the steady-state claim -- only the
	 * caller whose UPDATE still finds the value it just read wins; any
	 * other concurrent caller gets 0 affected rows and false. The
	 * SELECT reads $wpdb directly rather than through get_option(),
	 * which would route through the object cache: under a persistent
	 * cache layer (Redis/Memcached) a cached stale read would make the
	 * WHERE clause never match, permanently locking the button out
	 * rather than just rate-limiting it.
	 */
	private static function claim_notify_test_throttle(): bool {
		global $wpdb;
		$now = time();
		if ( $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::NOTIFY_TEST_THROTTLE_KEY,
			(string) $now
		) ) > 0 ) {
			return true;
		}
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			self::NOTIFY_TEST_THROTTLE_KEY
		) );
		if ( null === $existing || $now - (int) $existing < self::NOTIFY_TEST_THROTTLE_SECONDS ) {
			return false;
		}
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) $now,
			self::NOTIFY_TEST_THROTTLE_KEY,
			$existing
		) ) > 0;
	}

	/**
	 * "Send Test Notification": the settings page's only way to verify
	 * all 8 third-party channels without waiting for a real scheduled
	 * backup to fail. Uses CURRENTLY SAVED settings (not whatever is
	 * sitting unsaved in the form), same as Test Connection already
	 * does, so an admin must Save first.
	 */
	public function ajax_notify_test(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( ! self::claim_notify_test_throttle() ) {
			wp_send_json_error( array( 'message' => __( 'Please wait a few seconds before sending another test.', 'wpthistle-backup-restore' ) ), 429 );
			return;
		}
		$results = Notifications::dispatch_test();
		if ( empty( $results ) ) {
			wp_send_json_error( array( 'message' => __( 'No notification channel is enabled yet. Turn one on and save first.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		$lines = array();
		foreach ( $results as $channel => $outcome ) {
			$lines[] = ucfirst( $channel ) . ': ' . ( true === $outcome ? __( 'OK', 'wpthistle-backup-restore' ) : (string) $outcome );
		}
		wp_send_json_success( array( 'message' => implode( ' | ', $lines ) ) );
	}

	public function ajax_start_backup(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		// Phase 3 (downloadable backups): 'download' is always offered,
		// Nextcloud configured or not -- 'nextcloud' (the default, for any
		// caller that predates this param) still requires credentials,
		// unchanged.
		$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : 'nextcloud';
		if ( ! in_array( $destination, array( 'nextcloud', 'download' ), true ) ) {
			$destination = 'nextcloud';
		}
		if ( 'nextcloud' === $destination && ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Configure and save your cloud storage destination before backing up to it, or use Download Now instead.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		$job_id = Job_Orchestrator::create_job( $destination );
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'A backup is already running.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/**
	 * Phase 3 (downloadable backups): streams the verified local bundle.
	 * Capability + nonce gated like every other handler here; job_id is
	 * the only request input, the actual file path is derived entirely
	 * from server-side job state, never from anything request-supplied.
	 */
	public function handle_download_bundle(): void {
		// Note: an anonymous request never reaches this method at all --
		// wp-admin/admin-post.php itself returns wp_die('', 400) for any
		// admin_post_{action} with no matching admin_post_nopriv_{action}
		// registered (deliberately not registered here), confirmed live.
		// The check below is still real defense in depth for a logged-in
		// user who lacks the capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpthistle-backup-restore' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_DOWNLOAD ) ) {
			wp_die( esc_html__( 'Security check failed; please reload the page and try again.', 'wpthistle-backup-restore' ), '', array( 'response' => 403 ) );
		}
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$job = '' !== $job_id ? Job_Store::get( $job_id ) : null;
		if ( null === $job || 'download' !== ( $job['destination'] ?? '' ) || 'verified' !== ( $job['status'] ?? '' ) ) {
			wp_die( esc_html__( 'No downloadable backup is available for that job.', 'wpthistle-backup-restore' ), '', array( 'response' => 404 ) );
		}

		$token = $job['checkpoint']['download_token'] ?? '';
		$dir = Job_Orchestrator::download_dir_for_token( $token );
		$base = realpath( Job_Orchestrator::download_base_dir() );
		$real_dir = '' !== $dir ? realpath( $dir ) : false;
		// Defense in depth: the token/path come entirely from server-side
		// checkpoint state, never from this request, but confirm the
		// resolved path still lands inside the one directory this
		// endpoint is ever allowed to serve from before trusting it.
		if ( false === $base || false === $real_dir || 0 !== strpos( $real_dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR ) ) {
			wp_die( esc_html__( 'Downloadable backup could not be located.', 'wpthistle-backup-restore' ), '', array( 'response' => 404 ) );
		}
		$bundle_path = $job['checkpoint']['download_bundle_path'] ?? '';
		$real_bundle = '' !== $bundle_path ? realpath( $bundle_path ) : false;
		if ( false === $real_bundle || 0 !== strpos( $real_bundle, $real_dir ) || ! is_file( $real_bundle ) ) {
			wp_die( esc_html__( 'Downloadable backup file is missing -- it may have expired (pending bundles are removed after 24 hours). Run a new backup.', 'wpthistle-backup-restore' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $real_bundle ) ) . '"' );
		header( 'Content-Length: ' . filesize( $real_bundle ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		@ini_set( 'zlib.output_compression', 'Off' );
		readfile( $real_bundle );
		exit;
	}

	/**
	 * Phase 3: POST + nonce -- an admin explicitly discarding a pending
	 * local bundle before its TTL. At most one pending bundle ever exists
	 * at a time (cap-at-one, enforced in Job_Orchestrator::create_job()),
	 * so purging unconditionally is equivalent to "delete job_id's bundle"
	 * without needing to resolve job_id to a specific directory first.
	 */
	public function ajax_delete_download_bundle(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		Job_Orchestrator::sweep_download_bundles( true );
		wp_send_json_success( array( 'message' => __( 'Local backup bundle deleted.', 'wpthistle-backup-restore' ) ) );
	}

	/** Phase 2 (one-click restore): a job_id may belong to either orchestrator; dispatched by checkpoint kind. */
	private function is_restore_job( ?array $job ): bool {
		return null !== $job && 'restore' === ( $job['checkpoint']['kind'] ?? 'backup' );
	}

	public function ajax_advance_job(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => 'Missing job_id.' ) );
			return;
		}
		$job = $this->is_restore_job( Job_Store::get( $job_id ) ) ? Restore_Orchestrator::advance( $job_id ) : Job_Orchestrator::advance( $job_id );
		wp_send_json_success( $this->public_job_view( $job ) );
	}

	public function ajax_job_status(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job = '' !== $job_id ? Job_Store::get( $job_id ) : Job_Store::get_latest();
		wp_send_json_success( $job ? $this->public_job_view( $job ) : array( 'status' => 'none' ) );
	}

	/** JOB-05 manual recovery: let an admin mark a stuck job failed instead of needing direct DB access. */
	public function ajax_cancel_job(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			$latest = Job_Store::get_latest();
			if ( null === $latest ) {
				wp_send_json_error( array( 'message' => __( 'No backup job found to cancel.', 'wpthistle-backup-restore' ) ) );
				return;
			}
			$job_id = $latest['job_id'];
		}
		$job = $this->is_restore_job( Job_Store::get( $job_id ) ) ? Restore_Orchestrator::cancel( $job_id ) : Job_Orchestrator::cancel( $job_id );

		// security audit finding LOW-C1: a lock-contended cancel (advance() is actively
		// running right now) changes nothing, and must not be reported as
		// success -- the admin would otherwise see "success" from the
		// fetch call while the job silently stayed running.
		if ( 'lock_contended' === ( $job['cancel_result'] ?? '' ) ) {
			wp_send_json_error( array_merge(
				$this->public_job_view( $job ),
				array( 'message' => __( 'Job is actively being advanced right now (AJAX or WP-Cron) -- nothing was changed. Wait a moment and try Cancel again.', 'wpthistle-backup-restore' ) )
			) );
			return;
		}
		wp_send_json_success( $this->public_job_view( $job ) );
	}

	/** Phase 2 (one-click restore): populates the "restore from Nextcloud" dropdown. */
	public function ajax_list_restorable_backups(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Configure and save your cloud storage destination first.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		$client = Nextcloud_Client::from_settings( new Logger( array( Settings::get_app_password() ) ) );
		$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
		$list = Retention::list_verified( $client, $parent_rel );
		if ( is_wp_error( $list ) ) {
			wp_send_json_error( array( 'message' => $list->get_error_message() ) );
			return;
		}
		// Newest first for the dropdown; list_verified() returns oldest first.
		wp_send_json_success( array( 'backups' => array_reverse( $list ) ) );
	}

	/**
	 * Phase 2 (one-click restore): accepts a single uploaded .zip bundle
	 * (manifest.json + COMPLETE.json + database.sql.gz + files.part-*.zip
	 * at its root -- a full/self-contained backup only; an incremental
	 * chain needs the Nextcloud source to reach its ancestor backups).
	 * Extracted once, into a freshly wiped, guarded holding directory --
	 * has_running_job() already prevents two restores (and therefore two
	 * uploads) from being prepared concurrently.
	 */
	public function ajax_upload_restore_bundle(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( Job_Store::has_running_job() ) {
			wp_send_json_error( array( 'message' => __( 'A backup or restore is already running.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		if ( empty( $_FILES['bundle'] ) || UPLOAD_ERR_OK !== ( $_FILES['bundle']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded, or the upload failed.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		$tmp_upload = $_FILES['bundle']['tmp_name'];
		if ( ! is_uploaded_file( $tmp_upload ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'wpthistle-backup-restore' ) ) );
			return;
		}

		// security audit finding MEDIUM-UPLOAD-1: a fresh, random-token subdirectory
		// per upload (never the fixed, guessable path this used to be),
		// deleted by Restore_Orchestrator once its content has been
		// staged into the job's own tmp dir (or on an earlier failure)
		// -- see Restore_Orchestrator::new_upload_token()'s docblock.
		$token = Restore_Orchestrator::new_upload_token();
		$holding = Restore_Orchestrator::upload_dir_for_token( $token );
		wp_mkdir_p( $holding );

		$result = Restore_Orchestrator::extract_bundle_zip( $tmp_upload, $holding );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( array(
			'message'      => __( 'Bundle uploaded and extracted; ready to verify.', 'wpthistle-backup-restore' ),
			'upload_token' => $token,
		) );
	}

	/** Phase 2 (one-click restore): starts the restore job itself; everything before this was read-only prep. */
	public function ajax_start_restore(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$source_type = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$confirm = ! empty( $_POST['confirm_url_mismatch'] );

		if ( 'upload' === $source_type ) {
			$upload_token = isset( $_POST['upload_token'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_token'] ) ) : '';
			$holding = Restore_Orchestrator::upload_dir_for_token( $upload_token );
			if ( '' === $holding || ! file_exists( $holding . 'manifest.json' ) ) {
				wp_send_json_error( array( 'message' => __( 'Upload a backup bundle first.', 'wpthistle-backup-restore' ) ) );
				return;
			}
			$manifest = json_decode( (string) file_get_contents( $holding . 'manifest.json' ), true );
			$backup_id = is_array( $manifest ) ? (string) ( $manifest['backup_id'] ?? '' ) : '';
			if ( '' === $backup_id ) {
				wp_send_json_error( array( 'message' => __( 'Uploaded bundle\'s manifest.json has no backup_id.', 'wpthistle-backup-restore' ) ) );
				return;
			}
			$source = array( 'type' => 'upload', 'upload_dir' => $holding );
		} else {
			$backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
			if ( '' === $backup_id ) {
				wp_send_json_error( array( 'message' => __( 'Choose a backup to restore.', 'wpthistle-backup-restore' ) ) );
				return;
			}
			$source = array( 'type' => 'nextcloud' );
		}

		$job_id = Restore_Orchestrator::create_restore_job( $source, $backup_id, $confirm );
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'A backup or restore is already running.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		// security audit finding HIGH-AJAX-1: backups get a WP-Cron fallback so a
		// closed browser tab doesn't strand them; restore had none at
		// all until now, the one place a stranded job matters most.
		wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/** Strips anything that shouldn't reach the browser even redacted-in-principle (defense in depth). */
	private function public_job_view( array $job ): array {
		return array(
			'job_id'             => $job['job_id'] ?? '',
			'backup_id'          => $job['backup_id'] ?? '',
			'status'             => $job['status'] ?? '',
			'step'               => $job['step'] ?? '',
			'error'              => $job['error'] ?? '',
			'log'                => array_slice( $job['log'] ?? array(), -10 ),
			'kind'               => 'restore' === ( $job['checkpoint']['kind'] ?? 'backup' ) ? 'restore' : 'backup',
			// Phase 3: never exposes the bundle's real filesystem path --
			// just enough for the UI to show/hide the download link and
			// build the admin_post.php URL from job_id + nonce.
			'destination'        => $job['destination'] ?? 'nextcloud',
			'download_available' => 'download' === ( $job['destination'] ?? '' )
				&& 'verified' === ( $job['status'] ?? '' )
				&& ! empty( $job['checkpoint']['download_token'] ),
		);
	}

	/**
	 * Phase 5: a plain-English "did my backup actually run" answer,
	 * right on the settings page -- no WP-CLI, no SQL, no CLI of any
	 * kind needed by an end user ever again for this. Against
	 * Job_Store::get_latest() (any outcome), not get_latest_verified()
	 * (success only) -- the whole point is surfacing a stuck or failed
	 * backup, which a "last verified" line can never show by its own
	 * nature (there is no later verified row to point at while one is
	 * stuck).
	 */
	private function describe_latest_job_status(): array {
		$job = Job_Store::get_latest();
		if ( null === $job ) {
			return array( 'class' => 'neutral', 'text' => __( 'No backup yet.', 'wpthistle-backup-restore' ) );
		}
		$status = $job['status'] ?? '';
		if ( 'verified' === $status ) {
			return array(
				'class' => 'ok',
				/* translators: %s: UTC timestamp. */
				'text'  => sprintf( __( 'Last backup: succeeded at %s UTC.', 'wpthistle-backup-restore' ), $job['updated_at'] ),
			);
		}
		if ( 'failed' === $status ) {
			return array(
				'class' => 'error',
				/* translators: %s: error message. */
				'text'  => sprintf( __( 'Last backup: failed — %s', 'wpthistle-backup-restore' ), $job['error'] ?: __( '(no error message recorded)', 'wpthistle-backup-restore' ) ),
			);
		}
		if ( in_array( $status, array( 'queued', 'running', 'uploaded-unverified' ), true ) ) {
			// Job_Orchestrator::STALE_LOCK_SECONDS is the same threshold
			// the automatic stale-lock reaper itself uses to decide
			// "abandoned, not just slow" -- reusing it here means this
			// line and the reaper's own eventual action always agree.
			$age = time() - strtotime( $job['updated_at'] . ' UTC' );
			if ( $age < Job_Orchestrator::STALE_LOCK_SECONDS ) {
				return array(
					'class' => 'warn',
					/* translators: %s: step name, e.g. "export_files". */
					'text'  => sprintf( __( 'Last backup: currently running (step: %s).', 'wpthistle-backup-restore' ), $job['step'] ?? '?' ),
				);
			}
			return array(
				'class' => 'error',
				/* translators: 1: start time, 2: last-progress time, both UTC. */
				'text'  => sprintf( __( 'Last backup: stalled — started at %1$s UTC, no progress since %2$s UTC. It will be automatically recovered shortly, or use Cancel / Reset Stuck Job below.', 'wpthistle-backup-restore' ), $job['created_at'], $job['updated_at'] ),
			);
		}
		return array( 'class' => 'neutral', 'text' => __( 'Last backup: unknown status.', 'wpthistle-backup-restore' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Phase 3: housekeeping sweep on every settings-page load, same as
		// the hourly cron -- catches a TTL-expired bundle promptly even on
		// a low-traffic site where the cron's own pseudo-cron trigger
		// might lag.
		Job_Orchestrator::sweep_download_bundles();
		$settings = Settings::get_all();
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		$download_nonce = wp_create_nonce( self::NONCE_ACTION_DOWNLOAD );
		$locked_pw = Settings::is_app_password_locked();
		$locked_ca = Settings::is_ca_bundle_locked();
		$latest_verified = Job_Store::get_latest_verified();
		// security audit finding LOW-RETAIN-1: a chain-aware retention prune that
		// keeps failing (e.g. a kept backup's manifest.json went
		// missing) fails closed -- correctly deletes nothing -- but
		// that's invisible unless someone reads job logs line by line.
		// Surfaced here instead; cleared automatically the next time
		// pruning succeeds (Job_Orchestrator::maybe_prune_old_backups()).
		$retention_error = get_option( Job_Orchestrator::RETENTION_ERROR_OPTION, '' );
		$latest_job_status = $this->describe_latest_job_status();
		// Phase 4 (day-based scheduling): PHP DateTime's own 'w'
		// convention (0=Sunday..6=Saturday), matching
		// Daily_Trigger::is_due_today()'s $days.
		$weekday_labels = array(
			0 => __( 'Sun', 'wpthistle-backup-restore' ),
			1 => __( 'Mon', 'wpthistle-backup-restore' ),
			2 => __( 'Tue', 'wpthistle-backup-restore' ),
			3 => __( 'Wed', 'wpthistle-backup-restore' ),
			4 => __( 'Thu', 'wpthistle-backup-restore' ),
			5 => __( 'Fri', 'wpthistle-backup-restore' ),
			6 => __( 'Sat', 'wpthistle-backup-restore' ),
		);
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				<img src="<?php echo esc_url( WPTHISTLE_BACKUP_URL . 'assets/images/logo-icon-color.png' ); ?>" alt="" style="width:28px;height:28px;">
				<?php esc_html_e( 'WPThistle Backup and Restore', 'wpthistle-backup-restore' ); ?>
			</h1>

			<?php
			$status_colors = array( 'ok' => '#46b450', 'warn' => '#ffb900', 'error' => '#dc3232', 'neutral' => '#787c82' );
			$dot_color = $status_colors[ $latest_job_status['class'] ] ?? $status_colors['neutral'];
			?>
			<p style="display:flex;align-items:center;gap:6px;">
				<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $dot_color ); ?>;"></span>
				<strong><?php echo esc_html( $latest_job_status['text'] ); ?></strong>
			</p>

			<?php if ( $retention_error ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Retention pruning is not completing.', 'wpthistle-backup-restore' ); ?></strong>
					<?php esc_html_e( 'Old backups are NOT being deleted (nothing was deleted either, by design) until this is resolved:', 'wpthistle-backup-restore' ); ?>
					<?php echo esc_html( $retention_error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $latest_verified ) : ?>
				<p><strong><?php esc_html_e( 'Last verified backup:', 'wpthistle-backup-restore' ); ?></strong>
				<?php echo esc_html( $latest_verified['backup_id'] . ' (' . $latest_verified['updated_at'] . ' UTC)' ); ?></p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No verified backup yet.', 'wpthistle-backup-restore' ); ?></em></p>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper" id="wpthistle_backup_tabs">
				<a href="#" class="nav-tab nav-tab-active" data-tab="home"><?php esc_html_e( 'Home', 'wpthistle-backup-restore' ); ?></a>
				<a href="#" class="nav-tab" data-tab="connection"><?php esc_html_e( 'Connection', 'wpthistle-backup-restore' ); ?></a>
				<a href="#" class="nav-tab" data-tab="schedule"><?php esc_html_e( 'Backup Schedule', 'wpthistle-backup-restore' ); ?></a>
				<a href="#" class="nav-tab" data-tab="notifications"><?php esc_html_e( 'Notifications', 'wpthistle-backup-restore' ); ?></a>
				<a href="#" class="nav-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'wpthistle-backup-restore' ); ?></a>
			</h2>

			<div id="wpthistle_backup_tab_home" class="wpthistle-backup-tab-panel">
			<h2><?php esc_html_e( 'Manual backup', 'wpthistle-backup-restore' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_backup_now"><?php esc_html_e( 'Back Up Now', 'wpthistle-backup-restore' ); ?></button>
				<button type="button" class="button" id="wpthistle_backup_download_now"><?php esc_html_e( 'Download Now', 'wpthistle-backup-restore' ); ?></button>
				<button type="button" class="button" id="wpthistle_backup_cancel_job"><?php esc_html_e( 'Cancel / Reset Stuck Job', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Back Up Now sends this backup to your configured cloud storage destination. Download Now instead packages the same backup into one .zip and lets you save it straight to your own computer -- no cloud storage configuration required. A pending download is NOT deleted automatically just because you clicked Download (the server cannot confirm your browser received every byte) -- it stays on this server, guarded against direct web access, until you click "Delete local copy" below, start another Download Now backup (which replaces it), or 24 hours pass, whichever comes first.', 'wpthistle-backup-restore' ); ?></p>
			<p class="description"><?php esc_html_e( 'If a backup never completes (e.g. the site had no other traffic to trigger the WP-Cron fallback), use Cancel to mark it failed so a new backup can start. Already-uploaded parts of a cancelled job are left on Nextcloud untouched.', 'wpthistle-backup-restore' ); ?></p>
			<div id="wpthistle_backup_job_status" role="status"></div>
			<p id="wpthistle_backup_job_download_row" style="display:none;"><a class="button button-primary" id="wpthistle_backup_job_download_link" href="#"><?php esc_html_e( 'Download backup (.zip)', 'wpthistle-backup-restore' ); ?></a> <button type="button" class="button" id="wpthistle_backup_job_download_delete"><?php esc_html_e( 'Delete local copy', 'wpthistle-backup-restore' ); ?></button></p>
			<pre id="wpthistle_backup_job_log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;"></pre>

			<h2><?php esc_html_e( 'Restore', 'wpthistle-backup-restore' ); ?></h2>
			<p class="description"><strong><?php esc_html_e( 'This overwrites this site\'s current content and database with a chosen backup, in place.', 'wpthistle-backup-restore' ); ?></strong>
			<?php esc_html_e( 'Nothing is ever permanently deleted by it — anything it replaces is renamed aside (database tables under a "wtbak…" prefix, files into a timestamped rollback folder) for manual recovery, never dropped outright. Still: back up anything you cannot afford to lose before using this on a site that matters.', 'wpthistle-backup-restore' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Source', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="radio" name="wpthistle_backup_restore_source" value="nextcloud" checked> <?php esc_html_e( 'Choose from configured cloud storage', 'wpthistle-backup-restore' ); ?></label>
						<select id="wpthistle_backup_restore_backup_list" style="margin-left:8px;"><option value=""><?php esc_html_e( '— loading —', 'wpthistle-backup-restore' ); ?></option></select>
						<br><br>
						<label><input type="radio" name="wpthistle_backup_restore_source" value="upload"> <?php esc_html_e( 'Upload a backup bundle', 'wpthistle-backup-restore' ); ?></label>
						<input type="file" id="wpthistle_backup_restore_upload_file" accept=".zip" style="margin-left:8px;">
						<button type="button" class="button" id="wpthistle_backup_restore_upload_btn"><?php esc_html_e( 'Upload', 'wpthistle-backup-restore' ); ?></button>
						<span id="wpthistle_backup_restore_upload_result"></span>
						<p class="description"><?php esc_html_e( 'A single .zip containing manifest.json, COMPLETE.json, database.sql.gz, and files.part-*.zip at its root. Only supports a self-contained (full) backup — an incremental backup\'s ancestor parts can only be reached via the Nextcloud source.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_restore_confirm_text"><?php esc_html_e( 'Confirm', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="text" id="wpthistle_backup_restore_confirm_text" class="regular-text" placeholder="RESTORE">
						<p class="description"><?php esc_html_e( 'Type RESTORE to enable the button below.', 'wpthistle-backup-restore' ); ?></p>
						<label><input type="checkbox" id="wpthistle_backup_restore_confirm_url"> <?php esc_html_e( 'This backup is from a different site -- migrate its URLs to this site\'s address', 'wpthistle-backup-restore' ); ?></label>
						<p class="description"><?php esc_html_e( 'Leave unchecked to restore this backup onto the exact site it came from. See the Migrate section under the Advanced tab for what checking this actually does and what it does not cover.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_restore_start" disabled><?php esc_html_e( 'Restore Now', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<div id="wpthistle_backup_restore_status" role="status"></div>
			<pre id="wpthistle_backup_restore_log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;"></pre>
			<p class="description" style="margin-top:24px;">
				<?php
				printf(
					/* translators: %s: "Buy me a coffee" link. */
					esc_html__( 'If this backup just saved you a bad day, a coffee goes a long way. %s', 'wpthistle-backup-restore' ),
					$this->coffee_link()
				);
				?>
			</p>
			</div>
			<div id="wpthistle_backup_tab_connection" class="wpthistle-backup-tab-panel" style="display:none;">
			<h2><?php esc_html_e( 'Connection', 'wpthistle-backup-restore' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="wpthistle_backup_backend"><?php esc_html_e( 'Cloud storage type', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<select id="wpthistle_backup_backend">
							<option value="nextcloud" <?php selected( $settings['backend'], 'nextcloud' ); ?>><?php esc_html_e( 'Nextcloud', 'wpthistle-backup-restore' ); ?></option>
							<option value="owncloud" <?php selected( $settings['backend'], 'owncloud' ); ?>><?php esc_html_e( 'ownCloud (classic, 10.x)', 'wpthistle-backup-restore' ); ?></option>
							<option value="opencloud" <?php selected( $settings['backend'], 'opencloud' ); ?>><?php esc_html_e( 'OpenCloud', 'wpthistle-backup-restore' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'All three speak the same WebDAV dialect this plugin uses, verified directly against each. The connection and every backup/restore step work identically regardless of which you pick -- this is for your own record (the credential field below already covers all three without needing to know which one is selected).', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_url"><?php esc_html_e( 'Server URL (https only)', 'wpthistle-backup-restore' ); ?></label></th>
					<td><input type="url" id="wpthistle_backup_url" value="<?php echo esc_attr( $settings['nextcloud_url'] ); ?>" class="regular-text" placeholder="https://your-server.example.com"></td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_user"><?php esc_html_e( 'Username (dedicated, non-admin account recommended)', 'wpthistle-backup-restore' ); ?></label></th>
					<td><input type="text" id="wpthistle_backup_user" value="<?php echo esc_attr( $settings['nextcloud_user'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_pw"><?php esc_html_e( 'App password / app token', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="password" id="wpthistle_backup_pw" value="" class="regular-text" autocomplete="new-password" <?php echo $locked_pw ? 'disabled' : ''; ?> placeholder="<?php echo esc_attr( $locked_pw ? __( 'Set via WPTHISTLE_BACKUP_APP_PASSWORD constant', 'wpthistle-backup-restore' ) : __( 'leave blank to keep current value', 'wpthistle-backup-restore' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Never echoed back. Generate a dedicated app password (Nextcloud/ownCloud) or app token (OpenCloud) under your account\'s Security settings — do not use your main account password.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_folder"><?php esc_html_e( 'Destination folder', 'wpthistle-backup-restore' ); ?></label></th>
					<td><input type="text" id="wpthistle_backup_folder" value="<?php echo esc_attr( $settings['remote_folder'] ); ?>" class="regular-text" placeholder="wpthistle-backups"></td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_ca"><?php esc_html_e( 'Custom CA bundle path (advanced, optional)', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="text" id="wpthistle_backup_ca" value="<?php echo esc_attr( $settings['ca_bundle_path'] ); ?>" class="regular-text" <?php echo $locked_ca ? 'disabled' : ''; ?> placeholder="/absolute/path/to/private-ca.pem">
						<p class="description"><?php esc_html_e( 'Only for a private CA you operate (e.g. self-hosted Nextcloud). Leave blank for a normal publicly trusted certificate — this never weakens TLS verification, it only adds a trusted root.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_save_connection"><?php esc_html_e( 'Save Settings', 'wpthistle-backup-restore' ); ?></button>
				<button type="button" class="button" id="wpthistle_backup_test"><?php esc_html_e( 'Test Connection', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<div id="wpthistle_backup_connection_result" role="status"></div>

			<h2><?php esc_html_e( 'Forget Connection', 'wpthistle-backup-restore' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Clears the server URL, username, app password / app token, destination folder, and CA bundle path stored on this site. Nothing on your Nextcloud, ownCloud, or OpenCloud server is touched or deleted; this only clears what this site remembers about how to reach it. Scheduled and Tiered backups will fail until you reconnect. A credential set via a wp-config.php constant cannot be cleared from here. It keeps working regardless of what this clears, so remove or blank the constant itself instead.', 'wpthistle-backup-restore' ); ?></p>
			<p>
				<input type="text" id="wpthistle_backup_forget_confirm_text" class="regular-text" placeholder="FORGET">
				<button type="button" class="button" id="wpthistle_backup_forget_btn" disabled><?php esc_html_e( 'Forget Connection', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Type FORGET to enable the button above.', 'wpthistle-backup-restore' ); ?></p>
			<div id="wpthistle_backup_forget_result" role="status"></div>

			<p class="description" style="margin-top:24px;">
				<?php
				printf(
					/* translators: %s: "Buy me a coffee" link. */
					esc_html__( 'No paywall, no upsells, just a working connection. If that is worth something to you: %s', 'wpthistle-backup-restore' ),
					$this->coffee_link()
				);
				?>
			</p>
			</div>
			<div id="wpthistle_backup_tab_schedule" class="wpthistle-backup-tab-panel" style="display:none;">
			<h2><?php esc_html_e( 'Backup Schedule', 'wpthistle-backup-restore' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="wpthistle_backup_schedule_mode"><?php esc_html_e( 'Backup plan', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<select id="wpthistle_backup_schedule_mode">
							<option value="scheduled" <?php selected( $settings['schedule_mode'], 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'wpthistle-backup-restore' ); ?></option>
							<option value="tiered" <?php selected( $settings['schedule_mode'], 'tiered' ); ?>><?php esc_html_e( 'Tiered', 'wpthistle-backup-restore' ); ?></option>
						</select>
						<p class="description" id="wpthistle_backup_schedule_mode_desc_scheduled"><?php esc_html_e( 'I choose which day(s) (or which day of the month), and a flat number of backups is kept.', 'wpthistle-backup-restore' ); ?></p>
						<p class="description" id="wpthistle_backup_schedule_mode_desc_tiered"><?php esc_html_e( 'Backs up every day -- no day picker, since its "daily" tier would not mean daily otherwise -- and keeps a GFS-style rolling set instead: so many most-recent days, so many most-recent weeks, so many most-recent months.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr id="wpthistle_backup_cadence_row">
					<th><label for="wpthistle_backup_schedule_cadence"><?php esc_html_e( 'Cadence', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<select id="wpthistle_backup_schedule_cadence">
							<option value="daily" <?php selected( $settings['schedule_cadence'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'wpthistle-backup-restore' ); ?></option>
							<option value="monthly" <?php selected( $settings['schedule_cadence'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'wpthistle-backup-restore' ); ?></option>
						</select>
						<p class="description" id="wpthistle_backup_schedule_cadence_desc_daily"><?php esc_html_e( 'Choose day(s) of the week below.', 'wpthistle-backup-restore' ); ?></p>
						<p class="description" id="wpthistle_backup_schedule_cadence_desc_monthly"><?php esc_html_e( 'Choose a day of the month below.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr id="wpthistle_backup_daily_row">
					<th><?php esc_html_e( 'Run on these days', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Run on these days', 'wpthistle-backup-restore' ); ?></legend>
							<?php foreach ( $weekday_labels as $day_index => $day_label ) : ?>
								<label style="margin-right:10px;"><input type="checkbox" class="wpthistle_backup_schedule_day" value="<?php echo esc_attr( (string) $day_index ); ?>" <?php checked( in_array( $day_index, $settings['schedule_days'], true ) ); ?>> <?php echo esc_html( $day_label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'No days checked = disabled (manual only). On a selected day, the backup runs on whichever request happens to reach this site first that day -- a page view, a crawler, even this site\'s own hourly housekeeping check -- rather than at one exact time, so a genuinely quiet site never misses a narrow window. Skips a run rather than overlapping if a backup is still genuinely in progress -- it simply waits for the next selected day.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr id="wpthistle_backup_monthly_row">
					<th><label for="wpthistle_backup_schedule_day_of_month"><?php esc_html_e( 'Run on this day of the month', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<select id="wpthistle_backup_schedule_day_of_month">
							<?php for ( $d = 1; $d <= 31; $d++ ) : ?>
								<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $settings['schedule_day_of_month'], $d ); ?>><?php echo esc_html( (string) $d ); ?></option>
							<?php endfor; ?>
							<option value="last" <?php selected( $settings['schedule_day_of_month'], 'last' ); ?>><?php esc_html_e( 'Last Day of the Month', 'wpthistle-backup-restore' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'A fixed day (e.g. 31) simply has no match in a shorter month -- that month is skipped entirely. Choose "Last Day of the Month" instead if you always want one, regardless of length.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr id="wpthistle_backup_retention_count_row">
					<th><label for="wpthistle_backup_retention"><?php esc_html_e( 'Keep last N backups', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="number" id="wpthistle_backup_retention" min="0" step="1" value="<?php echo esc_attr( (string) $settings['retention_count'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( '0 = keep every backup forever (default). A positive number deletes the oldest backup folder(s) on Nextcloud right after each new backup verifies successfully, once more than that many exist. This permanently deletes data — only increase from 0 once you are comfortable with the number chosen.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr id="wpthistle_backup_tiered_row">
					<th><?php esc_html_e( 'Tiered retention', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<p>
							<label for="wpthistle_backup_retention_daily"><?php esc_html_e( 'Keep daily for', 'wpthistle-backup-restore' ); ?></label>
							<input type="number" id="wpthistle_backup_retention_daily" min="0" step="1" value="<?php echo esc_attr( (string) $settings['retention_daily'] ); ?>" class="small-text"> <?php esc_html_e( 'day(s)', 'wpthistle-backup-restore' ); ?>
							&nbsp;&nbsp;
							<label for="wpthistle_backup_retention_weekly"><?php esc_html_e( 'keep weekly for', 'wpthistle-backup-restore' ); ?></label>
							<input type="number" id="wpthistle_backup_retention_weekly" min="0" step="1" value="<?php echo esc_attr( (string) $settings['retention_weekly'] ); ?>" class="small-text"> <?php esc_html_e( 'week(s)', 'wpthistle-backup-restore' ); ?>
							&nbsp;&nbsp;
							<label for="wpthistle_backup_retention_monthly"><?php esc_html_e( 'keep monthly for', 'wpthistle-backup-restore' ); ?></label>
							<input type="number" id="wpthistle_backup_retention_monthly" min="0" step="1" value="<?php echo esc_attr( (string) $settings['retention_monthly'] ); ?>" class="small-text"> <?php esc_html_e( 'month(s)', 'wpthistle-backup-restore' ); ?>
						</p>
						<p class="description"><?php esc_html_e( 'Keeps one backup per day for the daily window, one per calendar week for the weekly window, and one per calendar month for the monthly window; a backup can count toward more than one window at once. There is no fixed "day of week" or "day of month" to miss -- whichever backup actually exists in a given day/week/month represents it, so a quiet day or a short month is never a problem.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_incremental"><?php esc_html_e( 'Incremental file backups', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_incremental" <?php checked( $settings['incremental_enabled'] ); ?>> <?php esc_html_e( 'Skip re-uploading files unchanged since the last backup', 'wpthistle-backup-restore' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default. When on, a file whose size and modified time match the previous backup is referenced instead of re-archived and re-uploaded — the usual speed-up on a site with many files. A full backup is still taken periodically (every 7 backups or 7 days, whichever comes first) regardless of this setting. Every individual backup remains independently verified before being marked complete; see Operations docs for how restore works with this on.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_save"><?php esc_html_e( 'Save Settings', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<div id="wpthistle_backup_settings_result" role="status"></div>

			<p class="description" style="margin-top:24px;">
				<?php
				printf(
					/* translators: %s: "Buy me a coffee" link. */
					esc_html__( 'Set it and forget it, no subscription required. A coffee helps keep it that way: %s', 'wpthistle-backup-restore' ),
					$this->coffee_link()
				);
				?>
			</p>
			</div>
			<div id="wpthistle_backup_tab_notifications" class="wpthistle-backup-tab-panel" style="display:none;">
			<h2><?php esc_html_e( 'Notifications', 'wpthistle-backup-restore' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Everything here is off until you turn it on. Whatever you enable below is told the site name, the backup ID, when it finished, (on failure) the error text, and a link back to this settings page. The custom webhook additionally gets this plugin\'s version number. Nothing else about this site.', 'wpthistle-backup-restore' ); ?></p>

			<h3><?php esc_html_e( 'When to notify', 'wpthistle-backup-restore' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Scheduled backups', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_on_failure" <?php checked( $settings['notify_on_failure'] ); ?>> <?php esc_html_e( 'Notify on failure', 'wpthistle-backup-restore' ); ?></label><br>
						<label><input type="checkbox" id="wpthistle_backup_notify_on_success" <?php checked( $settings['notify_on_success'] ); ?>> <?php esc_html_e( 'Also notify on success', 'wpthistle-backup-restore' ); ?></label>
						<p class="description"><?php esc_html_e( 'Governs every channel below together. Only ever sent for an automatic scheduled/tiered backup, never a manual "Back Up Now" click, since you\'re already watching that happen in real time.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Where to send it', 'wpthistle-backup-restore' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Email', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_email_enabled" <?php checked( $settings['notify_email_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label>
						<p class="description"><?php esc_html_e( 'Sent to this site\'s admin email, via whatever mail delivery WordPress already has configured. This plugin does not add its own.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Discord', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_discord_enabled" <?php checked( $settings['notify_discord_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_discord_webhook_url"><?php esc_html_e( 'Webhook URL', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_discord_webhook_url" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'https://discord.com/api/webhooks/...', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_discord_webhook_url'], __( 'https://discord.com/api/webhooks/...', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_discord_webhook_url', $settings['notify_discord_webhook_url'] ); ?>
						<p class="description"><?php esc_html_e( 'Create an incoming webhook under your Discord channel\'s Integrations settings, then paste its URL here.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Slack', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_slack_enabled" <?php checked( $settings['notify_slack_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_slack_webhook_url"><?php esc_html_e( 'Webhook URL', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_slack_webhook_url" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'https://hooks.slack.com/services/...', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_slack_webhook_url'], __( 'https://hooks.slack.com/services/...', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_slack_webhook_url', $settings['notify_slack_webhook_url'] ); ?>
						<p class="description"><?php esc_html_e( 'Create an incoming webhook app in your Slack workspace, then paste its webhook URL here.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Pushover', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_pushover_enabled" <?php checked( $settings['notify_pushover_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_pushover_user_key"><?php esc_html_e( 'User Key', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_pushover_user_key" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'from your Pushover dashboard', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_pushover_user_key'], __( 'from your Pushover dashboard', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_pushover_user_key', $settings['notify_pushover_user_key'] ); ?><br>
						<label for="wpthistle_backup_notify_pushover_api_token"><?php esc_html_e( 'API Token', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_pushover_api_token" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'from your own registered application', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_pushover_api_token'], __( 'from your own registered application', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_pushover_api_token', $settings['notify_pushover_api_token'] ); ?>
						<p class="description"><?php esc_html_e( 'Register your own free application at pushover.net/apps/build to get an API Token; your User Key is on your Pushover dashboard.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Pushbullet', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_pushbullet_enabled" <?php checked( $settings['notify_pushbullet_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_pushbullet_access_token"><?php esc_html_e( 'Access Token', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_pushbullet_access_token" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'from Account Settings', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_pushbullet_access_token'], __( 'from Account Settings', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_pushbullet_access_token', $settings['notify_pushbullet_access_token'] ); ?>
						<p class="description"><?php esc_html_e( 'Generate an Access Token under your Pushbullet account\'s Settings.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'ntfy', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_ntfy_enabled" <?php checked( $settings['notify_ntfy_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_ntfy_url"><?php esc_html_e( 'Topic URL', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_ntfy_url" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'https://ntfy.sh/your-topic-name', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_ntfy_url'], __( 'https://ntfy.sh/your-topic-name', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_ntfy_url', $settings['notify_ntfy_url'] ); ?><br>
						<label for="wpthistle_backup_notify_ntfy_token"><?php esc_html_e( 'Access Token (optional)', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_ntfy_token" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'only for a private/self-hosted topic', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_ntfy_token'], __( 'only for a private/self-hosted topic', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_ntfy_token', $settings['notify_ntfy_token'] ); ?>
						<p class="description"><?php esc_html_e( 'The full URL you would use to subscribe to a topic, on ntfy.sh or your own self-hosted server. If using the public ntfy.sh service, pick an unguessable topic name; anyone who knows it can read or publish to it.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Gotify', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_gotify_enabled" <?php checked( $settings['notify_gotify_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_gotify_url"><?php esc_html_e( 'Server URL', 'wpthistle-backup-restore' ); ?></label>
						<input type="text" id="wpthistle_backup_notify_gotify_url" value="<?php echo esc_attr( $settings['notify_gotify_url'] ); ?>" class="regular-text" placeholder="https://gotify.example.com"><br>
						<label for="wpthistle_backup_notify_gotify_token"><?php esc_html_e( 'App Token', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_gotify_token" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'created under Gotify\'s Apps section', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_gotify_token'], __( 'created under Gotify\'s Apps section', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_gotify_token', $settings['notify_gotify_token'] ); ?>
						<p class="description"><?php esc_html_e( 'Your self-hosted Gotify server\'s base URL, and an application token created under its Apps section.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Telegram', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_telegram_enabled" <?php checked( $settings['notify_telegram_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_telegram_bot_token"><?php esc_html_e( 'Bot Token', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_telegram_bot_token" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'from @BotFather', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_telegram_bot_token'], __( 'from @BotFather', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_telegram_bot_token', $settings['notify_telegram_bot_token'] ); ?><br>
						<label for="wpthistle_backup_notify_telegram_chat_id"><?php esc_html_e( 'Chat ID', 'wpthistle-backup-restore' ); ?></label>
						<input type="text" id="wpthistle_backup_notify_telegram_chat_id" value="<?php echo esc_attr( $settings['notify_telegram_chat_id'] ); ?>" class="regular-text" placeholder="123456789">
						<p class="description"><?php esc_html_e( 'Message @BotFather on Telegram to create a bot and get its token. For the Chat ID: message your new bot once, then visit https://api.telegram.org/bot<token>/getUpdates in a browser to find your numeric chat id in the response.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Custom webhook', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_notify_webhook_enabled" <?php checked( $settings['notify_webhook_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'wpthistle-backup-restore' ); ?></label><br>
						<label for="wpthistle_backup_notify_webhook_url"><?php esc_html_e( 'Webhook URL', 'wpthistle-backup-restore' ); ?></label>
						<input type="password" id="wpthistle_backup_notify_webhook_url" value="" class="regular-text" autocomplete="new-password" data-empty-hint="<?php echo esc_attr( __( 'https://...', 'wpthistle-backup-restore' ) ); ?>" placeholder="<?php echo esc_attr( $this->secret_field_placeholder( $settings['notify_webhook_url'], __( 'https://...', 'wpthistle-backup-restore' ) ) ); ?>">
						<?php echo $this->clear_secret_checkbox( 'notify_webhook_url', $settings['notify_webhook_url'] ); ?>
						<p class="description"><?php esc_html_e( 'Posts a structured JSON payload (event, site, backup ID, status, error) to this URL instead of a formatted message. Useful for automation tools like n8n, Home Assistant, or a custom listener. Covers anything not named above.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_notify_save"><?php esc_html_e( 'Save Settings', 'wpthistle-backup-restore' ); ?></button>
				<button type="button" class="button" id="wpthistle_backup_notify_test"><?php esc_html_e( 'Send Test Notification', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<div id="wpthistle_backup_notify_result" role="status"></div>

			<p class="description" style="margin-top:24px;">
				<?php
				printf(
					/* translators: %s: "Buy me a coffee" link. */
					esc_html__( 'Eight channels, zero of them costing you a subscription. %s', 'wpthistle-backup-restore' ),
					$this->coffee_link()
				);
				?>
			</p>
			</div>
			<div id="wpthistle_backup_tab_advanced" class="wpthistle-backup-tab-panel" style="display:none;">
			<h2><?php esc_html_e( 'Advanced', 'wpthistle-backup-restore' ); ?></h2>

			<h3><?php esc_html_e( 'Migrate to a new domain', 'wpthistle-backup-restore' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A simple, same-site domain move: take a backup already made on the OLD site, then restore it here, on the NEW site. Every URL in the backup (post content, widget settings, and everywhere else WordPress stores one) is rewritten to this site\'s own domain before anything is restored live. Everything else -- content, users, credentials, settings -- carries over exactly as it was.', 'wpthistle-backup-restore' ); ?></p>
			<div class="notice notice-info inline" style="margin:0 0 16px;padding:8px 12px;">
				<p style="margin:0.5em 0;"><strong><?php esc_html_e( 'What this is not:', 'wpthistle-backup-restore' ); ?></strong> <?php esc_html_e( 'this is a one-time domain move, not a staging <-> production sync tool. It does not support the source and target having different credentials, does not merge or preserve the target\'s own settings, and overwrites this site\'s content and database in place -- the same full restore the Home tab performs, just with URL rewriting confirmed.', 'wpthistle-backup-restore' ); ?></p>
			</div>
			<ol>
				<li><?php esc_html_e( 'On the OLD site, open WPThistle Backup and Restore -> Home and take a backup (or use one already taken).', 'wpthistle-backup-restore' ); ?></li>
				<li><?php esc_html_e( 'Install WPThistle Backup and Restore fresh on the NEW domain, and connect it to the same cloud storage (or have the backup .zip ready to upload).', 'wpthistle-backup-restore' ); ?></li>
				<li><?php esc_html_e( 'Come back here, on the NEW site, and click the button below -- it jumps to the Home tab\'s Restore section with the migration box already checked.', 'wpthistle-backup-restore' ); ?></li>
			</ol>
			<p><button type="button" class="button button-primary" id="wpthistle_backup_migrate_go"><?php esc_html_e( 'Start migration in Restore', 'wpthistle-backup-restore' ); ?></button></p>

			<h3><?php esc_html_e( 'Guaranteed scheduling (optional, advanced)', 'wpthistle-backup-restore' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'The schedule above runs on whichever request reaches this site first on a selected day, and now also self-advances once started (no longer needing further site traffic to finish) -- in practice this covers almost every site. The one remaining edge case: a site with truly zero requests of any kind for an entire selected day. For that, an outside scheduler can visit this URL periodically instead:', 'wpthistle-backup-restore' ); ?>
			</p>
			<p><input type="text" readonly onclick="this.select();" value="<?php echo esc_url( site_url( 'wp-cron.php' ) ); ?>" class="large-text code"></p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to cron-job.org. */
					esc_html__( 'This is entirely optional and happens outside this plugin: create a free account at %s, add a new cron job, paste the URL above as its target, and set it to run every 5-15 minutes. This plugin never contacts cron-job.org or any other outside service itself -- it only reads a request when one arrives at the URL above, from that service or anywhere else. Nothing is sent anywhere unless you set this up yourself, and there is nothing to undo here if you do: to stop, just delete the job on whichever service you used.', 'wpthistle-backup-restore' ),
					'<a href="https://cron-job.org/en/" target="_blank" rel="noopener noreferrer">cron-job.org</a>'
				);
				?>
			</p>

			<p class="description" style="margin-top:24px;">
				<?php
				printf(
					/* translators: %s: "Buy me a coffee" link. */
					esc_html__( 'None of this phones home, and none of it ever will. %s', 'wpthistle-backup-restore' ),
					$this->coffee_link()
				);
				?>
			</p>

			</div>
		</div>
		<script>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var downloadNonce = <?php echo wp_json_encode( $download_nonce ); ?>;
			// 2026-11-03: same two strings PHP already uses for
			// secret_field_placeholder()/clear_secret_checkbox() above,
			// passed through once so syncSecretFieldUi() (below) can
			// reproduce their exact server-rendered markup/text after an
			// AJAX save, instead of a full page reload being the only way
			// to see a just-saved field's placeholder/checkbox update.
			var SAVED_PLACEHOLDER_TEXT = <?php echo wp_json_encode( __( 'Saved (leave blank to keep)', 'wpthistle-backup-restore' ) ); ?>;
			var CLEAR_LABEL_TEXT = <?php echo wp_json_encode( __( 'Clear saved value', 'wpthistle-backup-restore' ) ); ?>;
			var currentJobId = null;
			var currentRestoreJobId = null;
			var currentUploadToken = null;

			function post(action, extra) {
				var body = new URLSearchParams(Object.assign({ action: action, nonce: nonce }, extra || {}));
				return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); });
			}

			var settingsDirty = false;
			var settingsFieldIds = [
				'wpthistle_backup_backend', 'wpthistle_backup_url', 'wpthistle_backup_user', 'wpthistle_backup_pw',
				'wpthistle_backup_folder', 'wpthistle_backup_ca',
				'wpthistle_backup_schedule_mode', 'wpthistle_backup_schedule_cadence', 'wpthistle_backup_schedule_day_of_month',
				'wpthistle_backup_retention', 'wpthistle_backup_retention_daily', 'wpthistle_backup_retention_weekly',
				'wpthistle_backup_retention_monthly', 'wpthistle_backup_incremental',
				'wpthistle_backup_notify_on_failure', 'wpthistle_backup_notify_on_success',
				'wpthistle_backup_notify_email_enabled',
				'wpthistle_backup_notify_discord_enabled', 'wpthistle_backup_notify_discord_webhook_url',
				'wpthistle_backup_notify_slack_enabled', 'wpthistle_backup_notify_slack_webhook_url',
				'wpthistle_backup_notify_pushover_enabled', 'wpthistle_backup_notify_pushover_user_key', 'wpthistle_backup_notify_pushover_api_token',
				'wpthistle_backup_notify_pushbullet_enabled', 'wpthistle_backup_notify_pushbullet_access_token',
				'wpthistle_backup_notify_ntfy_enabled', 'wpthistle_backup_notify_ntfy_url', 'wpthistle_backup_notify_ntfy_token',
				'wpthistle_backup_notify_gotify_enabled', 'wpthistle_backup_notify_gotify_url', 'wpthistle_backup_notify_gotify_token',
				'wpthistle_backup_notify_telegram_enabled', 'wpthistle_backup_notify_telegram_bot_token', 'wpthistle_backup_notify_telegram_chat_id',
				'wpthistle_backup_notify_webhook_enabled', 'wpthistle_backup_notify_webhook_url'
			];
			settingsFieldIds.forEach(function(id){
				var el = document.getElementById(id);
				el.addEventListener('input', function(){ settingsDirty = true; });
				el.addEventListener('change', function(){ settingsDirty = true; });
			});

			// Vex security audit finding LOW-N-03: each "Clear saved value"
			// checkbox is only rendered when that field actually has
			// something saved, so it may not exist in the DOM at all --
			// unlike settingsFieldIds above, every lookup here is guarded.
			// Deliberately NOT part of settingsFieldIds/lastSavedValues:
			// there is nothing to "revert" a clear-intent checkbox back
			// to, it just gets unchecked again on cancel or after a
			// successful save.
			var clearCheckboxIds = [
				'wpthistle_backup_notify_discord_webhook_url_clear',
				'wpthistle_backup_notify_slack_webhook_url_clear',
				'wpthistle_backup_notify_pushover_user_key_clear', 'wpthistle_backup_notify_pushover_api_token_clear',
				'wpthistle_backup_notify_pushbullet_access_token_clear',
				'wpthistle_backup_notify_ntfy_url_clear', 'wpthistle_backup_notify_ntfy_token_clear',
				'wpthistle_backup_notify_gotify_token_clear',
				'wpthistle_backup_notify_telegram_bot_token_clear',
				'wpthistle_backup_notify_webhook_url_clear'
			];
			function clearCheckboxValue(id) {
				var el = document.getElementById(id);
				return (el && el.checked) ? '1' : '';
			}
			function resetClearCheckboxes() {
				clearCheckboxIds.forEach(function(id){
					var el = document.getElementById(id);
					if (el) { el.checked = false; }
				});
			}

			/**
			 * 2026-11-03 (the owner's report: the "Clear saved value" checkbox
			 * and "Saved (leave blank to keep)" placeholder only ever
			 * appeared after a full page reload, never right after an
			 * AJAX save). Settings::save_secret_field() was always
			 * correct server-side -- this was purely the DOM never being
			 * told the save happened, since clear_secret_checkbox()/
			 * secret_field_placeholder() above only ever run once, at
			 * the page's initial server render.
			 *
			 * $clearedFieldIds is captured by the caller BEFORE
			 * resetClearCheckboxes() runs (which unchecks every _clear
			 * box), since that's the only record of which fields were
			 * actually cleared in the save that just completed.
			 */
			function syncSecretFieldUi(clearedFieldIds) {
				clearCheckboxIds.forEach(function(clearId){
					var baseId = clearId.replace(/_clear$/, '');
					var input  = document.getElementById(baseId);
					var wrap   = document.getElementById(baseId + '_clear_wrap');
					if (!input || !wrap) { return; }
					if (clearedFieldIds.indexOf(baseId) !== -1) {
						// Cleared this save: back to the empty-field hint, no checkbox left to offer.
						input.placeholder = input.getAttribute('data-empty-hint') || '';
						input.value = '';
						wrap.innerHTML = '';
					} else if ('' !== input.value) {
						// A real value was just typed and saved: show it as saved, never the typed text itself.
						input.placeholder = SAVED_PLACEHOLDER_TEXT;
						input.value = '';
						wrap.innerHTML = '<label style="margin-left:8px;font-weight:normal;"><input type="checkbox" id="' + baseId + '_clear"> ' + CLEAR_LABEL_TEXT + '</label>';
						var newCheckbox = document.getElementById(clearId);
						if (newCheckbox) { newCheckbox.addEventListener('change', function(){ settingsDirty = true; }); }
					}
					// Else: untouched this save (still blank and not cleared, or already saved and left alone) -- nothing to update.
				});
			}
			clearCheckboxIds.forEach(function(id){
				var el = document.getElementById(id);
				if (el) { el.addEventListener('change', function(){ settingsDirty = true; }); }
			});
			Array.prototype.forEach.call(document.querySelectorAll('.wpthistle_backup_schedule_day'), function(el){
				el.addEventListener('change', function(){ settingsDirty = true; });
			});

			// Vex security audit (2026-11-01, VX-4): snapshotted so "leave
			// without saving" can actually revert the fields, not just
			// silently clear the dirty flag while leaving the abandoned
			// edits sitting in the DOM for the next Save click to commit
			// by accident. Re-captured after every successful save, so a
			// later revert always goes back to what's really stored, not
			// just what was on the page at initial load.
			var lastSavedValues = {};
			function captureSavedValues() {
				settingsFieldIds.forEach(function(id){
					var el = document.getElementById(id);
					lastSavedValues[id] = (el.type === 'checkbox') ? el.checked : el.value;
				});
				Array.prototype.forEach.call(document.querySelectorAll('.wpthistle_backup_schedule_day'), function(el){
					lastSavedValues['day_' + el.value] = el.checked;
				});
			}
			function revertToLastSaved() {
				settingsFieldIds.forEach(function(id){
					var el = document.getElementById(id);
					if (el.type === 'checkbox') {
						el.checked = lastSavedValues[id];
					} else {
						el.value = lastSavedValues[id];
					}
				});
				Array.prototype.forEach.call(document.querySelectorAll('.wpthistle_backup_schedule_day'), function(el){
					el.checked = lastSavedValues['day_' + el.value];
				});
				resetClearCheckboxes();
				updateScheduleVisibility();
			}
			captureSavedValues();

			window.addEventListener('beforeunload', function(e){
				if (!settingsDirty) {
					return;
				}
				e.preventDefault();
				e.returnValue = '';
			});

			var tabLinks = document.querySelectorAll('#wpthistle_backup_tabs .nav-tab');
			var tabPanels = {
				home: document.getElementById('wpthistle_backup_tab_home'),
				connection: document.getElementById('wpthistle_backup_tab_connection'),
				schedule: document.getElementById('wpthistle_backup_tab_schedule'),
				notifications: document.getElementById('wpthistle_backup_tab_notifications'),
				advanced: document.getElementById('wpthistle_backup_tab_advanced')
			};
			Array.prototype.forEach.call(tabLinks, function(link){
				link.addEventListener('click', function(e){
					e.preventDefault();
					if (settingsDirty) {
						if (!window.confirm(<?php echo wp_json_encode( __( 'You have unsaved changes on this tab. Leave without saving?', 'wpthistle-backup-restore' ) ); ?>)) {
							return;
						}
						revertToLastSaved();
					}
					settingsDirty = false;
					// Vera QA F-01, 2026-11-04: the Migrate tab's shortcut
					// pre-arms the URL-mismatch checkbox below, which stayed
					// silently checked if the admin then wandered off to a
					// DIFFERENT tab without completing the restore -- a stale
					// armed state they'd have no reason to expect on a later
					// return to Home. Clearing it on every away-navigation
					// (not on Home itself, so staying on or returning to Home
					// to actually use it doesn't get undone by this) keeps the
					// checkbox only ever reflecting a choice the admin is
					// actively making right now.
					if ( 'home' !== link.dataset.tab ) {
						var confirmUrlBox = document.getElementById('wpthistle_backup_restore_confirm_url');
						if ( confirmUrlBox.checked ) {
							confirmUrlBox.checked = false;
							confirmUrlBox.dispatchEvent(new Event('change'));
						}
					}
					Array.prototype.forEach.call(tabLinks, function(l){ l.classList.remove('nav-tab-active'); });
					link.classList.add('nav-tab-active');
					Object.keys(tabPanels).forEach(function(key){ tabPanels[key].style.display = 'none'; });
					tabPanels[link.dataset.tab].style.display = '';
				});
			});

			function updateScheduleVisibility() {
				var mode = document.getElementById('wpthistle_backup_schedule_mode').value;
				var cadence = document.getElementById('wpthistle_backup_schedule_cadence').value;
				var scheduled = (mode === 'scheduled');
				document.getElementById('wpthistle_backup_cadence_row').style.display = scheduled ? '' : 'none';
				document.getElementById('wpthistle_backup_daily_row').style.display = (scheduled && cadence === 'daily') ? '' : 'none';
				document.getElementById('wpthistle_backup_monthly_row').style.display = (scheduled && cadence === 'monthly') ? '' : 'none';
				document.getElementById('wpthistle_backup_retention_count_row').style.display = scheduled ? '' : 'none';
				document.getElementById('wpthistle_backup_tiered_row').style.display = scheduled ? 'none' : '';
				document.getElementById('wpthistle_backup_schedule_mode_desc_scheduled').style.display = scheduled ? '' : 'none';
				document.getElementById('wpthistle_backup_schedule_mode_desc_tiered').style.display = scheduled ? 'none' : '';
				document.getElementById('wpthistle_backup_schedule_cadence_desc_daily').style.display = (cadence === 'daily') ? '' : 'none';
				document.getElementById('wpthistle_backup_schedule_cadence_desc_monthly').style.display = (cadence === 'monthly') ? '' : 'none';
			}
			document.getElementById('wpthistle_backup_schedule_mode').addEventListener('change', updateScheduleVisibility);
			document.getElementById('wpthistle_backup_schedule_cadence').addEventListener('change', updateScheduleVisibility);
			updateScheduleVisibility();

			function saveSettings(resultElId) {
				post('wpthistle_backup_save_settings', {
					backend: document.getElementById('wpthistle_backup_backend').value,
					nextcloud_url: document.getElementById('wpthistle_backup_url').value,
					nextcloud_user: document.getElementById('wpthistle_backup_user').value,
					app_password: document.getElementById('wpthistle_backup_pw').value,
					remote_folder: document.getElementById('wpthistle_backup_folder').value,
					ca_bundle_path: document.getElementById('wpthistle_backup_ca').value,
					schedule_mode: document.getElementById('wpthistle_backup_schedule_mode').value,
					schedule_cadence: document.getElementById('wpthistle_backup_schedule_cadence').value,
					schedule_day_of_month: document.getElementById('wpthistle_backup_schedule_day_of_month').value,
					schedule_days: JSON.stringify(Array.prototype.slice.call(document.querySelectorAll('.wpthistle_backup_schedule_day:checked')).map(function(el){ return parseInt(el.value, 10); })),
					retention_count: document.getElementById('wpthistle_backup_retention').value,
					retention_daily: document.getElementById('wpthistle_backup_retention_daily').value,
					retention_weekly: document.getElementById('wpthistle_backup_retention_weekly').value,
					retention_monthly: document.getElementById('wpthistle_backup_retention_monthly').value,
					incremental_enabled: document.getElementById('wpthistle_backup_incremental').checked ? '1' : '',
					notify_on_failure: document.getElementById('wpthistle_backup_notify_on_failure').checked ? '1' : '',
					notify_on_success: document.getElementById('wpthistle_backup_notify_on_success').checked ? '1' : '',
					notify_email_enabled: document.getElementById('wpthistle_backup_notify_email_enabled').checked ? '1' : '',
					notify_discord_enabled: document.getElementById('wpthistle_backup_notify_discord_enabled').checked ? '1' : '',
					notify_discord_webhook_url: document.getElementById('wpthistle_backup_notify_discord_webhook_url').value,
					notify_discord_webhook_url_clear: clearCheckboxValue('wpthistle_backup_notify_discord_webhook_url_clear'),
					notify_slack_enabled: document.getElementById('wpthistle_backup_notify_slack_enabled').checked ? '1' : '',
					notify_slack_webhook_url: document.getElementById('wpthistle_backup_notify_slack_webhook_url').value,
					notify_slack_webhook_url_clear: clearCheckboxValue('wpthistle_backup_notify_slack_webhook_url_clear'),
					notify_pushover_enabled: document.getElementById('wpthistle_backup_notify_pushover_enabled').checked ? '1' : '',
					notify_pushover_user_key: document.getElementById('wpthistle_backup_notify_pushover_user_key').value,
					notify_pushover_user_key_clear: clearCheckboxValue('wpthistle_backup_notify_pushover_user_key_clear'),
					notify_pushover_api_token: document.getElementById('wpthistle_backup_notify_pushover_api_token').value,
					notify_pushover_api_token_clear: clearCheckboxValue('wpthistle_backup_notify_pushover_api_token_clear'),
					notify_pushbullet_enabled: document.getElementById('wpthistle_backup_notify_pushbullet_enabled').checked ? '1' : '',
					notify_pushbullet_access_token: document.getElementById('wpthistle_backup_notify_pushbullet_access_token').value,
					notify_pushbullet_access_token_clear: clearCheckboxValue('wpthistle_backup_notify_pushbullet_access_token_clear'),
					notify_ntfy_enabled: document.getElementById('wpthistle_backup_notify_ntfy_enabled').checked ? '1' : '',
					notify_ntfy_url: document.getElementById('wpthistle_backup_notify_ntfy_url').value,
					notify_ntfy_url_clear: clearCheckboxValue('wpthistle_backup_notify_ntfy_url_clear'),
					notify_ntfy_token: document.getElementById('wpthistle_backup_notify_ntfy_token').value,
					notify_ntfy_token_clear: clearCheckboxValue('wpthistle_backup_notify_ntfy_token_clear'),
					notify_gotify_enabled: document.getElementById('wpthistle_backup_notify_gotify_enabled').checked ? '1' : '',
					notify_gotify_url: document.getElementById('wpthistle_backup_notify_gotify_url').value,
					notify_gotify_token: document.getElementById('wpthistle_backup_notify_gotify_token').value,
					notify_gotify_token_clear: clearCheckboxValue('wpthistle_backup_notify_gotify_token_clear'),
					notify_telegram_enabled: document.getElementById('wpthistle_backup_notify_telegram_enabled').checked ? '1' : '',
					notify_telegram_bot_token: document.getElementById('wpthistle_backup_notify_telegram_bot_token').value,
					notify_telegram_bot_token_clear: clearCheckboxValue('wpthistle_backup_notify_telegram_bot_token_clear'),
					notify_telegram_chat_id: document.getElementById('wpthistle_backup_notify_telegram_chat_id').value,
					notify_webhook_enabled: document.getElementById('wpthistle_backup_notify_webhook_enabled').checked ? '1' : '',
					notify_webhook_url: document.getElementById('wpthistle_backup_notify_webhook_url').value,
					notify_webhook_url_clear: clearCheckboxValue('wpthistle_backup_notify_webhook_url_clear')
				}).then(function(res){
					document.getElementById(resultElId).textContent = res.data.message;
					if (res.success) {
						// Captured BEFORE resetClearCheckboxes() unchecks everything --
						// this is the only record of which fields this save actually cleared.
						var clearedFieldIds = clearCheckboxIds.filter(function(id){
							var el = document.getElementById(id);
							return el && el.checked;
						}).map(function(id){ return id.replace(/_clear$/, ''); });
						settingsDirty = false;
						resetClearCheckboxes();
						// Vex security audit, 2026-11-03 (LOW-V-05):
						// syncSecretFieldUi MUST run before captureSavedValues.
						// captureSavedValues snapshots el.value for every field
						// in settingsFieldIds, which includes the 10 password-type
						// credential inputs. If captureSavedValues runs first, a
						// field that was just typed into and saved still holds its
						// plaintext value -- that value is then captured into
						// lastSavedValues and later written back to the input by
						// revertToLastSaved() on the next tab-switch-without-saving,
						// exposing the credential in the DOM. syncSecretFieldUi
						// sets input.value = '' for every just-saved or just-cleared
						// field first; captureSavedValues running after it only ever
						// snapshots '' for all secret fields -- the correct revert
						// target regardless.
						syncSecretFieldUi(clearedFieldIds);
						captureSavedValues();
					}
				});
			}
			document.getElementById('wpthistle_backup_save').addEventListener('click', function(){
				saveSettings('wpthistle_backup_settings_result');
			});
			document.getElementById('wpthistle_backup_notify_save').addEventListener('click', function(){
				saveSettings('wpthistle_backup_notify_result');
			});
			document.getElementById('wpthistle_backup_save_connection').addEventListener('click', function(){
				saveSettings('wpthistle_backup_connection_result');
			});

			document.getElementById('wpthistle_backup_test').addEventListener('click', function(){
				document.getElementById('wpthistle_backup_connection_result').textContent = 'Testing...';
				post('wpthistle_backup_test_connection', {}).then(function(res){
					document.getElementById('wpthistle_backup_connection_result').textContent = res.data.message;
				});
			});

			document.getElementById('wpthistle_backup_notify_test').addEventListener('click', function(){
				document.getElementById('wpthistle_backup_notify_result').textContent = 'Sending...';
				post('wpthistle_backup_notify_test', {}).then(function(res){
					document.getElementById('wpthistle_backup_notify_result').textContent = res.data.message;
				});
			});

			function updateForgetButtonState() {
				document.getElementById('wpthistle_backup_forget_btn').disabled =
					document.getElementById('wpthistle_backup_forget_confirm_text').value.trim() !== 'FORGET';
			}
			document.getElementById('wpthistle_backup_forget_confirm_text').addEventListener('input', updateForgetButtonState);

			document.getElementById('wpthistle_backup_forget_btn').addEventListener('click', function(){
				post('wpthistle_backup_forget_connection', {}).then(function(res){
					document.getElementById('wpthistle_backup_forget_result').textContent = res.data.message;
					if (res.success) {
						document.getElementById('wpthistle_backup_backend').value = 'nextcloud';
						document.getElementById('wpthistle_backup_url').value = '';
						document.getElementById('wpthistle_backup_user').value = '';
						document.getElementById('wpthistle_backup_pw').value = '';
						document.getElementById('wpthistle_backup_folder').value = '';
						document.getElementById('wpthistle_backup_ca').value = '';
						document.getElementById('wpthistle_backup_forget_confirm_text').value = '';
						updateForgetButtonState();
						settingsDirty = false;
						captureSavedValues();
					}
				});
			});

			function renderJobInto(statusId, logId, job) {
				document.getElementById(statusId).textContent = 'Status: ' + job.status + ' (step: ' + job.step + ')' + (job.error ? ' — ' + job.error : '');
				document.getElementById(logId).textContent = (job.log || []).map(function(e){ return e.time_utc + ' [' + e.severity + '] ' + e.message; }).join('\n');
			}

			function renderJob(job) {
				currentJobId = job.job_id || currentJobId;
				renderJobInto('wpthistle_backup_job_status', 'wpthistle_backup_job_log', job);
				var row = document.getElementById('wpthistle_backup_job_download_row');
				if (job.download_available) {
					var url = ajaxUrl.replace('admin-ajax.php', 'admin-post.php') +
						'?action=wpthistle_backup_download_bundle&job_id=' + encodeURIComponent(job.job_id) + '&nonce=' + encodeURIComponent(downloadNonce);
					document.getElementById('wpthistle_backup_job_download_link').href = url;
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			}

			function pollJob(jobId) {
				post('wpthistle_backup_advance_job', { job_id: jobId }).then(function(res){
					var job = res.data;
					renderJob(job);
					if (job.status !== 'verified' && job.status !== 'failed') {
						setTimeout(function(){ pollJob(jobId); }, 500);
					}
				}).catch(function(){
					// A single failed poll (network blip, a transient
					// 5xx) must not silently stop polling forever -- the
					// WP-Cron fallback keeps the job itself moving
					// regardless; this just needs to keep checking.
					document.getElementById('wpthistle_backup_job_status').textContent = 'Network error polling for status -- the backup may still be running via WP-Cron. Retrying...';
					setTimeout(function(){ pollJob(jobId); }, 2000);
				});
			}

			function renderRestoreJob(job) {
				currentRestoreJobId = job.job_id || currentRestoreJobId;
				renderJobInto('wpthistle_backup_restore_status', 'wpthistle_backup_restore_log', job);
			}

			function pollRestoreJob(jobId) {
				post('wpthistle_backup_advance_job', { job_id: jobId }).then(function(res){
					var job = res.data;
					renderRestoreJob(job);
					if (job.status !== 'verified' && job.status !== 'failed') {
						setTimeout(function(){ pollRestoreJob(jobId); }, 500);
					}
				}).catch(function(){
					// security audit finding HIGH-AJAX-1: a single failed poll (a
					// transient error response, a timed-out request --
					// plausible mid-restore, when apply_files can
					// legitimately take a while) must not silently stop
					// polling forever. The WP-Cron fallback keeps the
					// restore itself moving regardless; this just needs
					// to keep checking rather than leave the admin
					// looking at a frozen status box with no indication
					// anything is still happening.
					document.getElementById('wpthistle_backup_restore_status').textContent = 'Network error polling for status -- the restore may still be running via WP-Cron. Retrying...';
					setTimeout(function(){ pollRestoreJob(jobId); }, 2000);
				});
			}

			function startBackup(destination) {
				document.getElementById('wpthistle_backup_job_download_row').style.display = 'none';
				post('wpthistle_backup_start_backup', { destination: destination }).then(function(res){
					if (res.success) {
						pollJob(res.data.job_id);
					} else {
						document.getElementById('wpthistle_backup_job_status').textContent = res.data.message;
					}
				});
			}
			document.getElementById('wpthistle_backup_backup_now').addEventListener('click', function(){
				startBackup('nextcloud');
			});
			document.getElementById('wpthistle_backup_download_now').addEventListener('click', function(){
				startBackup('download');
			});
			document.getElementById('wpthistle_backup_job_download_delete').addEventListener('click', function(){
				if (!currentJobId) { return; }
				if (!window.confirm('Delete this local backup bundle? This cannot be undone.')) { return; }
				post('wpthistle_backup_delete_download_bundle', { job_id: currentJobId }).then(function(res){
					document.getElementById('wpthistle_backup_job_download_row').style.display = 'none';
					document.getElementById('wpthistle_backup_job_status').textContent = res.data.message;
				});
			});

			document.getElementById('wpthistle_backup_cancel_job').addEventListener('click', function(){
				if (!window.confirm('Cancel the current/stuck backup job? This marks it failed so a new backup can start. Already-uploaded parts on Nextcloud are left untouched. This cannot be undone.')) {
					return;
				}
				post('wpthistle_backup_cancel_job', currentJobId ? { job_id: currentJobId } : {}).then(function(res){
					if (res.success) {
						renderJob(res.data);
					} else {
						document.getElementById('wpthistle_backup_job_status').textContent = res.data.message;
					}
				});
			});

			// Resume reporting an in-progress job on page load -- previously
			// the status box stayed blank after navigating away and back,
			// even though the job itself (advanced by the WP-Cron fallback)
			// was likely still running; this looked identical to a crashed
			// backup with no way to tell the difference without checking
			// Nextcloud or the DB directly. The latest job may be a
			// backup OR a restore (Job_Store::get_latest() doesn't
			// distinguish) -- routed to the matching UI section by kind.
			post('wpthistle_backup_job_status', {}).then(function(res){
				var job = res.data;
				if (job.status && job.status !== 'none') {
					if (job.kind === 'restore') {
						renderRestoreJob(job);
						if (job.status !== 'verified' && job.status !== 'failed') {
							pollRestoreJob(job.job_id);
						}
					} else {
						renderJob(job);
						if (job.status !== 'verified' && job.status !== 'failed') {
							pollJob(job.job_id);
						}
					}
				}
			});

			// --- Restore ---

			function selectedRestoreSource() {
				var checked = document.querySelector('input[name="wpthistle_backup_restore_source"]:checked');
				return checked ? checked.value : 'nextcloud';
			}

			function loadRestorableBackups() {
				var select = document.getElementById('wpthistle_backup_restore_backup_list');
				post('wpthistle_backup_list_restorable_backups', {}).then(function(res){
					select.innerHTML = '';
					if (!res.success) {
						var opt = document.createElement('option');
						opt.textContent = res.data.message || 'Could not list backups';
						select.appendChild(opt);
						return;
					}
					if (!res.data.backups.length) {
						var none = document.createElement('option');
						none.textContent = '— no backups found —';
						select.appendChild(none);
						return;
					}
					res.data.backups.forEach(function(id){
						var opt = document.createElement('option');
						opt.value = id;
						opt.textContent = id;
						select.appendChild(opt);
					});
				});
			}
			loadRestorableBackups();

			document.getElementById('wpthistle_backup_restore_upload_btn').addEventListener('click', function(){
				var fileInput = document.getElementById('wpthistle_backup_restore_upload_file');
				if (!fileInput.files.length) {
					document.getElementById('wpthistle_backup_restore_upload_result').textContent = 'Choose a .zip file first.';
					return;
				}
				var body = new FormData();
				body.append('action', 'wpthistle_backup_upload_restore_bundle');
				body.append('nonce', nonce);
				body.append('bundle', fileInput.files[0]);
				document.getElementById('wpthistle_backup_restore_upload_result').textContent = 'Uploading...';
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); })
					.then(function(res){
						document.getElementById('wpthistle_backup_restore_upload_result').textContent = res.data.message;
						if (res.success) {
							currentUploadToken = res.data.upload_token;
						}
					});
			});

			function updateRestoreButtonState() {
				var confirmed = document.getElementById('wpthistle_backup_restore_confirm_text').value.trim() === 'RESTORE';
				document.getElementById('wpthistle_backup_restore_start').disabled = !confirmed;
			}
			document.getElementById('wpthistle_backup_restore_confirm_text').addEventListener('input', updateRestoreButtonState);

			document.getElementById('wpthistle_backup_migrate_go').addEventListener('click', function(){
				var confirmUrlBox = document.getElementById('wpthistle_backup_restore_confirm_url');
				confirmUrlBox.checked = true;
				confirmUrlBox.dispatchEvent(new Event('change'));
				Array.prototype.forEach.call(tabLinks, function(l){ l.classList.remove('nav-tab-active'); });
				var homeLink = document.querySelector('#wpthistle_backup_tabs .nav-tab[data-tab="home"]');
				if (homeLink) { homeLink.classList.add('nav-tab-active'); }
				Object.keys(tabPanels).forEach(function(key){ tabPanels[key].style.display = 'none'; });
				tabPanels.home.style.display = '';
				// Vera QA F-02, 2026-11-04: honor prefers-reduced-motion
				// rather than always animating this scroll.
				var noMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				document.getElementById('wpthistle_backup_restore_confirm_text').scrollIntoView( { behavior: noMotion ? 'instant' : 'smooth', block: 'center' } );
				document.getElementById('wpthistle_backup_restore_confirm_text').focus();
			});

			document.getElementById('wpthistle_backup_restore_start').addEventListener('click', function(){
				var source = selectedRestoreSource();
				var payload = {
					source: source,
					confirm_url_mismatch: document.getElementById('wpthistle_backup_restore_confirm_url').checked ? '1' : ''
				};
				if (source === 'nextcloud') {
					var backupId = document.getElementById('wpthistle_backup_restore_backup_list').value;
					if (!backupId) {
						document.getElementById('wpthistle_backup_restore_status').textContent = 'Choose a backup first.';
						return;
					}
					payload.backup_id = backupId;
				} else {
					if (!currentUploadToken) {
						document.getElementById('wpthistle_backup_restore_status').textContent = 'Upload a backup bundle first.';
						return;
					}
					payload.upload_token = currentUploadToken;
				}
				if (!window.confirm('This will overwrite this site\'s current content and database with the chosen backup, in place. Continue?')) {
					return;
				}
				post('wpthistle_backup_start_restore', payload).then(function(res){
					if (res.success) {
						pollRestoreJob(res.data.job_id);
					} else {
						document.getElementById('wpthistle_backup_restore_status').textContent = res.data.message;
					}
				});
			});
		})();
		</script>
		<?php
	}
}
