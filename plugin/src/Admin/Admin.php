<?php
/**
 * Admin wiring: capability, menu with "new since last visit" badge, list actions and the activation
 * notice (design.md §2, §3; architecture.md §8; security-analysis S1–S3).
 *
 * Every state change is a POST checked with a nonce and `laas_manage_quarantine`. Nothing changes
 * on GET, and no `wp_ajax_nopriv_*` handler exists.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Admin;

use LocalAIAntiSpam\Plugin;
use LocalAIAntiSpam\Quarantine\Installer;
use LocalAIAntiSpam\Support\Settings;

/**
 * Registers the admin screens and handles their actions.
 */
final class Admin {

	const CAP           = 'laas_manage_quarantine';
	const SLUG          = 'laas-quarantine';
	const SETTINGS_SLUG = 'laas-settings';
	const NONCE         = 'bulk-laas_items'; // WP_List_Table's bulk nonce for plural 'laas_items'.
	const SEEN_META     = 'laas_quarantine_seen';

	/**
	 * The plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant_capability' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( SettingsPage::class, 'register' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
	}

	/**
	 * Give `laas_manage_quarantine` to users with the base capability (filterable, so agencies can
	 * hand the quarantine to editors).
	 *
	 * @param array<string, bool> $allcaps The user's capabilities.
	 * @return array<string, bool>
	 */
	public function grant_capability( $allcaps ) {
		$base = (string) apply_filters( 'laas_quarantine_capability', 'manage_options' );
		if ( ! empty( $allcaps[ $base ] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	/**
	 * Top-level menu with the badge, and the two pages.
	 *
	 * @return void
	 */
	public function menu(): void {
		$new   = $this->new_since_last_visit();
		$badge = $new > 0
			/* translators: %s: number of new held messages. */
			? sprintf( ' <span class="awaiting-mod"><span class="pending-count" aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span></span>', number_format_i18n( $new ), esc_html( sprintf( _n( '%s new held message', '%s new held messages', $new, 'local-ai-anti-spam' ), number_format_i18n( $new ) ) ) )
			: '';

		$page = new QuarantinePage( $this->plugin );
		add_menu_page( __( 'Anti-Spam', 'local-ai-anti-spam' ), __( 'Anti-Spam', 'local-ai-anti-spam' ) . $badge, self::CAP, self::SLUG, array( $page, 'render' ), 'dashicons-shield', 80 );
		$hook = add_submenu_page( self::SLUG, __( 'Quarantine', 'local-ai-anti-spam' ), __( 'Quarantine', 'local-ai-anti-spam' ), self::CAP, self::SLUG, array( $page, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Anti-Spam Settings', 'local-ai-anti-spam' ), __( 'Settings', 'local-ai-anti-spam' ), 'manage_options', self::SETTINGS_SLUG, array( new SettingsPage( $this->plugin ), 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $page, 'load' ) );
		}
	}

	/**
	 * Held messages since this user last opened the quarantine.
	 *
	 * @return int
	 */
	private function new_since_last_visit(): int {
		try {
			$seen = (string) get_user_meta( get_current_user_id(), self::SEEN_META, true );
			return $this->plugin->repository()->count_held_since( '' !== $seen ? $seen : '1970-01-01 00:00:00' );
		} catch ( \Throwable $error ) {
			return 0;
		}
	}

	/**
	 * One-time notice after activation (design.md §3.1), on the plugins screen or dashboard.
	 *
	 * @return void
	 */
	public function activation_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! get_option( Installer::NOTICE_OPTION ) || ! current_user_can( self::CAP ) || ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		delete_option( Installer::NOTICE_OPTION );

		$names = array();
		foreach ( $this->plugin->integrations() as $id => $integration ) {
			if ( 'comment' !== $id && Settings::protects( $id ) ) {
				$names[] = self::integration_name( $id );
			}
		}
		$names[] = __( 'comments', 'local-ai-anti-spam' );
		$days    = (int) Settings::get( 'retention_days' );
		?>
		<div class="notice notice-success is-dismissible">
			<p><strong>
				<?php
				/* translators: %s: list of protected form plugins, e.g. "Contact Form 7 and comments". */
				echo esc_html( sprintf( __( 'Spam protection is on for %s.', 'local-ai-anti-spam' ), wp_sprintf( '%l', $names ) ) );
				?>
			</strong></p>
			<p>
				<?php esc_html_e( 'Checks run on this server: nothing is sent anywhere.', 'local-ai-anti-spam' ); ?>
				<?php
				/* translators: %d: number of days. */
				echo esc_html( sprintf( _n( 'Messages flagged as spam are held for %d day, never deleted straight away.', 'Messages flagged as spam are held for %d days, never deleted straight away.', $days, 'local-ai-anti-spam' ), $days ) );
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'View quarantine', 'local-ai-anti-spam' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Settings', 'local-ai-anti-spam' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Human name of an integration.
	 *
	 * @param string $id Integration ID.
	 * @return string
	 */
	public static function integration_name( string $id ): string {
		$names = array(
			'cf7'     => __( 'Contact Form 7', 'local-ai-anti-spam' ),
			'wpforms' => __( 'WPForms', 'local-ai-anti-spam' ),
			'comment' => __( 'Comments', 'local-ai-anti-spam' ),
		);
		return $names[ $id ] ?? $id;
	}
}
