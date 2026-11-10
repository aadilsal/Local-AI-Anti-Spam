<?php
/**
 * Quarantine screen: the list, the reason view and their actions (design.md §3.2–3.4).
 *
 * The message body is shown as escaped plain text and links in it are never clickable
 * (security-analysis S1). Actions are POSTs with a nonce and a capability check (S2, S3).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Admin;

use LocalAIAntiSpam\Plugin;
use LocalAIAntiSpam\Quarantine\Repository;
use LocalAIAntiSpam\Support\Log;

/**
 * Renders the quarantine and handles release and delete.
 */
final class QuarantinePage {

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
	 * URL of one item's reason view.
	 *
	 * @param int $id Row ID.
	 * @return string
	 */
	public static function detail_url( int $id ): string {
		return add_query_arg( 'item', $id, admin_url( 'admin.php?page=' . Admin::SLUG ) );
	}

	/**
	 * `load-` hook: handle a posted action, then redirect (POST-redirect-GET).
	 *
	 * @return void
	 */
	public function load(): void {
		update_user_meta( get_current_user_id(), Admin::SEEN_META, gmdate( 'Y-m-d H:i:s' ) );

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the quarantine.', 'local-ai-anti-spam' ), 403 );
		}
		check_admin_referer( Admin::NONCE );

		list( $op, $ids ) = self::posted_action();
		if ( '' === $op || array() === $ids ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Admin::SLUG ) );
			exit;
		}

		$done   = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			try {
				$ok = 'release' === $op
					? $this->plugin->releaser()->release( $id, get_current_user_id() )
					: $this->plugin->repository()->delete( $id );
			} catch ( \Throwable $error ) {
				Log::error( 'admin_' . $op, $error );
				$ok = false;
			}
			$ok ? ++$done : ++$failed;
		}

		$args = array(
			'page'        => Admin::SLUG,
			'laas_done'   => $op,
			'laas_ok'     => $done,
			'laas_failed' => $failed,
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The operation and row IDs from the posted form.
	 *
	 * @return array{0: string, 1: int[]}
	 */
	private static function posted_action(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in load() before this runs.
		if ( isset( $_POST['laas_row_action'] ) ) {
			$parts = explode( ':', sanitize_text_field( wp_unslash( $_POST['laas_row_action'] ) ), 2 );
			$op    = in_array( $parts[0], array( 'release', 'delete' ), true ) ? $parts[0] : '';
			return array( $op, isset( $parts[1] ) ? array( absint( $parts[1] ) ) : array() );
		}
		$op = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
			if ( in_array( $value, array( 'release', 'delete' ), true ) ) {
				$op = $value;
				break;
			}
		}
		$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		// phpcs:enable
		return array( $op, array_values( array_unique( $ids ) ) );
	}

	/**
	 * Page output: one item's reason view, or the list.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		echo '<style>' . self::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS from this class.
		$item_id = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		if ( $item_id > 0 ) {
			$this->render_detail( $item_id );
			return;
		}
		$this->render_list();
	}

	/**
	 * The list screen.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$status = isset( $_GET['status'] ) && Repository::RELEASED === $_GET['status'] ? Repository::RELEASED : Repository::HELD; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$table  = new QuarantineTable( $this->plugin->repository(), $status );
		$table->prepare_items();
		$days = (int) \LocalAIAntiSpam\Support\Settings::get( 'retention_days' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Quarantine', 'local-ai-anti-spam' ); ?></h1>
			<hr class="wp-header-end">
			<?php self::render_result_notice(); ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php $table->search_box( __( 'Search messages', 'local-ai-anti-spam' ), 'laas-search' ); ?>
			</form>
			<form method="post">
				<?php $table->display(); ?>
			</form>
			<p class="description">
				<?php
				/* translators: %d: number of days. */
				echo esc_html( sprintf( _n( 'Messages are deleted automatically after %d day.', 'Messages are deleted automatically after %d days.', $days, 'local-ai-anti-spam' ), $days ) );
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Change', 'local-ai-anti-spam' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Result of the last action, from the redirect's query arguments.
	 *
	 * @return void
	 */
	private static function render_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; values are cast.
		if ( ! isset( $_GET['laas_done'] ) ) {
			return;
		}
		$op     = sanitize_key( wp_unslash( $_GET['laas_done'] ) );
		$ok     = isset( $_GET['laas_ok'] ) ? absint( $_GET['laas_ok'] ) : 0;
		$failed = isset( $_GET['laas_failed'] ) ? absint( $_GET['laas_failed'] ) : 0;
		// phpcs:enable
		if ( 'release' === $op && $ok > 0 ) {
			echo '<div class="notice notice-success" tabindex="-1" id="laas-result"><p>';
			/* translators: %d: number of messages. */
			echo esc_html( sprintf( _n( '%d message delivered to its form\'s normal recipients.', '%d messages delivered to their forms\' normal recipients.', $ok, 'local-ai-anti-spam' ), $ok ) );
			echo '</p></div><script>document.getElementById("laas-result").focus();</script>';
		}
		if ( 'release' === $op && $failed > 0 ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of messages. */
					_n( "Couldn't deliver %d message. Your site's email may not be working. It's still in quarantine.", "Couldn't deliver %d messages. Your site's email may not be working. They're still in quarantine.", $failed, 'local-ai-anti-spam' ),
					$failed
				)
			);
			echo '</p></div>';
		}
		if ( 'delete' === $op && $ok > 0 ) {
			/* translators: %d: number of messages. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d message deleted.', '%d messages deleted.', $ok, 'local-ai-anti-spam' ), $ok ) ) . '</p></div>';
		}
	}

	/**
	 * The reason view for one item.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	private function render_detail( int $id ): void {
		$item = $this->plugin->repository()->find( $id );
		$back = admin_url( 'admin.php?page=' . Admin::SLUG );
		echo '<div class="wrap">';
		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( $back ), esc_html__( 'Back to quarantine', 'local-ai-anti-spam' ) );
		if ( null === $item ) {
			echo '<p>' . esc_html__( 'This message no longer exists. It may have been deleted after the retention period.', 'local-ai-anti-spam' ) . '</p></div>';
			return;
		}
		$name     = '' !== (string) $item['sender_name'] ? (string) $item['sender_name'] : __( '(no name)', 'local-ai-anti-spam' );
		$received = wp_date( 'j M Y, H:i', (int) strtotime( (string) $item['created_at'] . ' UTC' ) );
		$links    = preg_match_all( '#https?://|www\.#i', (string) $item['body'] );
		$t_high   = $this->threshold();
		?>
		<h1><?php echo esc_html( $name ); ?><?php echo '' !== (string) $item['sender_email'] ? ' &lt;' . esc_html( (string) $item['sender_email'] ) . '&gt;' : ''; ?></h1>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: form name, 2: score, 3: model version, 4: date. */
					__( '%1$s · Score %2$s · Model %3$s · Received %4$s', 'local-ai-anti-spam' ),
					self::form_label( (string) $item['source'], (string) $item['form_id'] ),
					number_format_i18n( (float) $item['score'], 2 ),
					(string) $item['model_version'],
					$received
				)
			);
			?>
		</p>
		<h2><?php esc_html_e( 'Message', 'local-ai-anti-spam' ); ?></h2>
		<pre class="laas-body"><?php echo esc_html( (string) $item['body'] ); ?></pre>

		<h2><?php esc_html_e( 'Why this was held', 'local-ai-anti-spam' ); ?></h2>
		<p>
			<?php
			if ( 'model' === $item['reason'] && null !== $t_high ) {
				echo esc_html(
					sprintf(
						/* translators: 1: this message's score, 2: the threshold. */
						__( 'The model scored this %1$s. Messages scoring %2$s or more are held.', 'local-ai-anti-spam' ),
						number_format_i18n( (float) $item['score'], 2 ),
						number_format_i18n( $t_high, 2 )
					)
				);
			} else {
				echo esc_html( self::why_line( (string) $item['reason'], array(), 0 ) );
			}
			?>
		</p>
		<?php if ( array() !== $item['top_features'] ) : ?>
			<p>
				<?php esc_html_e( 'Words that pushed it towards spam:', 'local-ai-anti-spam' ); ?>
				<?php
				$words = array();
				foreach ( (array) $item['top_features'] as $feature ) {
					$words[] = '<code>' . esc_html( self::readable_token( (string) ( $feature['token'] ?? '' ) ) ) . '</code>';
				}
				echo implode( ' · ', $words ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each token escaped above.
				?>
			</p>
		<?php endif; ?>
		<p>
			<?php
			/* translators: %d: number of links. */
			echo esc_html( 0 === $links ? __( 'Links: none', 'local-ai-anti-spam' ) : sprintf( _n( 'Links: %d (shown as text, not clickable)', 'Links: %d (shown as text, not clickable)', $links, 'local-ai-anti-spam' ), $links ) );
			?>
		</p>

		<form method="post" action="<?php echo esc_url( $back ); ?>">
			<?php wp_nonce_field( Admin::NONCE ); ?>
			<?php if ( Repository::HELD === $item['status'] ) : ?>
				<button type="submit" class="button button-primary" name="laas_row_action" value="<?php echo esc_attr( 'release:' . $id ); ?>"><?php esc_html_e( 'Not spam: deliver it', 'local-ai-anti-spam' ); ?></button>
			<?php else : ?>
				<?php
				/* translators: %s: date. */
				echo '<p>' . esc_html( sprintf( __( 'Released %s.', 'local-ai-anti-spam' ), wp_date( 'j M Y, H:i', (int) strtotime( (string) $item['released_at'] . ' UTC' ) ) ) ) . '</p>';
				?>
			<?php endif; ?>
			<button type="submit" class="button button-link-delete" name="laas_row_action" value="<?php echo esc_attr( 'delete:' . $id ); ?>"><?php esc_html_e( 'Delete', 'local-ai-anti-spam' ); ?></button>
		</form>
		</div>
		<?php
	}

	/**
	 * The spam threshold in use, or null if the model can't be read.
	 *
	 * @return float|null
	 */
	private function threshold(): ?float {
		try {
			$header = $this->plugin->load_model()->header();
			return min( 0.999, (float) $header['t_high'] + Plugin::t_high_offset() );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * "Contact form (Contact Form 7)" style label.
	 *
	 * @param string $source  Integration ID.
	 * @param string $form_id Form ID.
	 * @return string
	 */
	public static function form_label( string $source, string $form_id ): string {
		if ( 'cf7' === $source ) {
			$title = (int) $form_id > 0 ? get_the_title( (int) $form_id ) : '';
			/* translators: %s: form title. */
			return sprintf( __( '%s (Contact Form 7)', 'local-ai-anti-spam' ), '' !== $title ? $title : __( 'Contact form', 'local-ai-anti-spam' ) );
		}
		return Admin::integration_name( $source );
	}

	/**
	 * Score number plus a five-segment bar with a text alternative.
	 *
	 * @param float $score Score.
	 * @return string
	 */
	public static function score_html( float $score ): string {
		$filled = (int) max( 0, min( 5, round( $score * 5 ) ) );
		$bar    = str_repeat( '<span class="on"></span>', $filled ) . str_repeat( '<span></span>', 5 - $filled );
		$label  = sprintf(
			/* translators: %s: score. */
			__( 'Score %s out of 1', 'local-ai-anti-spam' ),
			number_format_i18n( $score, 2 )
		);
		return sprintf( '%s<br><span class="laas-bar" role="img" aria-label="%s">%s</span>', esc_html( number_format_i18n( $score, 2 ) ), esc_attr( $label ), $bar );
	}

	/**
	 * One line on why a message was held. Plain text; the caller escapes it.
	 *
	 * @param string                                $reason   Verdict reason.
	 * @param array<int, array<string, mixed>>|null $features Top features.
	 * @param int                                   $max      Words to list.
	 * @return string
	 */
	public static function why_line( string $reason, ?array $features, int $max ): string {
		if ( 'model' !== $reason ) {
			return 'error' === $reason ? __( 'Checks failed; held by mistake.', 'local-ai-anti-spam' ) : $reason;
		}
		$words = array();
		foreach ( (array) $features as $feature ) {
			$token = self::readable_token( (string) ( $feature['token'] ?? '' ) );
			if ( '' !== $token && ! in_array( $token, $words, true ) ) {
				$words[] = $token;
			}
			if ( count( $words ) >= $max ) {
				break;
			}
		}
		/* translators: %s: comma-separated words. */
		return array() === $words ? __( 'Model score', 'local-ai-anti-spam' ) : sprintf( __( 'Words: %s', 'local-ai-anti-spam' ), implode( ', ', $words ) );
	}

	/**
	 * Feature name in words: "w:backlinks" → "backlinks", "b:first_page" → "first page".
	 *
	 * @param string $token Feature name.
	 * @return string
	 */
	public static function readable_token( string $token ): string {
		$token = preg_replace( '/^[a-z]:/', '', $token );
		$map   = array(
			'__url__'   => 'a link',
			'__email__' => 'an email address',
			'__phone__' => 'a phone number',
			'__num__'   => 'a number',
		);
		if ( isset( $map[ $token ] ) ) {
			return $map[ $token ];
		}
		if ( preg_match( '/^__dom_(.+)__$/', $token, $m ) ) {
			return $m[1];
		}
		return trim( str_replace( '_', ' ', $token ) );
	}

	/**
	 * Styles for this screen only.
	 *
	 * @return string
	 */
	private static function css(): string {
		return '.laas-body{white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #c3c4c7;padding:12px;max-width:60em;font:13px/1.6 Consolas,Monaco,monospace}'
			. '.laas-bar{display:inline-flex;gap:2px}.laas-bar span{width:8px;height:8px;border:1px solid #50575e}.laas-bar span.on{background:#50575e}'
			. '.column-score{width:7em}.column-received{width:8em}.column-form{width:12em}.laas-email{color:#50575e}';
	}
}
