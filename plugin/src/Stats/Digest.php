<?php
/**
 * The digest email (design.md §3.10, F15). Plain text: no HTML to escape, and links from spam are
 * replaced with "[link]". Review links open wp-admin, which needs a login; nothing in the email
 * releases a message (security-analysis S4).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Stats;

use LocalAIAntiSpam\Quarantine\Repository;
use LocalAIAntiSpam\Support\Log;
use LocalAIAntiSpam\Support\Settings;

/**
 * Sends the daily or weekly summary of held messages.
 */
final class Digest {

	const LAST_OPTION = 'laas_digest_last';
	const LIST_ROWS   = 5;
	const EXCERPT     = 60;

	/**
	 * The quarantine.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository The quarantine.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Daily cron: send when due and something new was held. Returns whether a mail went out.
	 *
	 * @param bool $force Send now regardless of the schedule (used by tests).
	 * @return bool
	 */
	public function run( bool $force = false ): bool {
		try {
			$frequency = (string) Settings::get( 'digest' );
			if ( 'off' === $frequency && ! $force ) {
				return false;
			}
			$last = (string) get_option( self::LAST_OPTION, '' );
			if ( '' === $last ) {
				// First run: start counting from now rather than mailing the whole history.
				update_option( self::LAST_OPTION, gmdate( 'Y-m-d H:i:s' ), false );
				if ( ! $force ) {
					return false;
				}
				$last = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
			}
			$period = 'daily' === $frequency ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
			if ( ! $force && strtotime( $last . ' UTC' ) + $period - HOUR_IN_SECONDS > time() ) {
				return false; // Not due yet (an hour's slack for cron drift).
			}
			$new = $this->repository->count_held_since( $last );
			if ( 0 === $new ) {
				return false; // Only send when something new is held; keep counting from $last.
			}
			$mail = self::compose( $new, $this->repository->held_since( $last, self::LIST_ROWS ), $period );
			$sent = wp_mail( self::recipient(), $mail['subject'], $mail['body'] );
			if ( $sent ) {
				update_option( self::LAST_OPTION, gmdate( 'Y-m-d H:i:s' ), false );
			}
			return $sent;
		} catch ( \Throwable $error ) {
			Log::error( 'digest', $error );
			return false;
		}
	}

	/**
	 * Recipient: the setting, or the site admin.
	 *
	 * @return string
	 */
	private static function recipient(): string {
		$to = sanitize_email( (string) Settings::get( 'digest_to' ) );
		return '' !== $to ? $to : (string) get_option( 'admin_email' );
	}

	/**
	 * Subject and plain-text body.
	 *
	 * @param int                              $count  New held messages.
	 * @param array<int, array<string, mixed>> $rows   Lowest-scoring of them.
	 * @param int                              $period Seconds the digest covers.
	 * @return array{subject: string, body: string}
	 */
	public static function compose( int $count, array $rows, int $period ): array {
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$when  = DAY_IN_SECONDS === $period ? __( 'today', 'local-ai-anti-spam' ) : __( 'this week', 'local-ai-anti-spam' );
		$queue = admin_url( 'admin.php?page=laas-quarantine' );

		/* translators: 1: site name, 2: number of messages, 3: "today" or "this week". */
		$subject = sprintf( _n( '[%1$s] %2$d message held as spam %3$s', '[%1$s] %2$d messages held as spam %3$s', $count, 'local-ai-anti-spam' ), $site, $count, $when );

		/* translators: 1: number of messages, 2: site name, 3: "today" or "this week". */
		$lines   = array( sprintf( _n( '%1$d message was held as spam on %2$s %3$s.', '%1$d messages were held as spam on %2$s %3$s.', $count, 'local-ai-anti-spam' ), $count, $site, $when ) );
		$lines[] = __( 'Most likely to be real (lowest score first):', 'local-ai-anti-spam' );
		$lines[] = '';
		foreach ( $rows as $row ) {
			$name    = '' !== (string) $row['sender_name'] ? (string) $row['sender_name'] : (string) $row['sender_email'];
			$lines[] = sprintf( '  %s: "%s"  score %s', self::clean( $name, 40 ), self::clean( (string) $row['subject_line'], self::EXCERPT ), number_format_i18n( (float) $row['score'], 2 ) );
			$lines[] = '    ' . add_query_arg( 'item', (int) $row['id'], $queue );
		}
		if ( $count > count( $rows ) ) {
			/* translators: %d: number of further messages. */
			$lines[] = sprintf( __( '  …and %d more.', 'local-ai-anti-spam' ), $count - count( $rows ) );
		}
		$lines[] = '';
		/* translators: %s: quarantine URL. */
		$lines[] = sprintf( __( 'Open the quarantine: %s', 'local-ai-anti-spam' ), $queue );
		$days    = (int) Settings::get( 'retention_days' );
		/* translators: %d: number of days. */
		$lines[] = sprintf( _n( 'Held messages are deleted after %d day.', 'Held messages are deleted after %d days.', $days, 'local-ai-anti-spam' ), $days );
		/* translators: %s: settings URL. */
		$lines[] = sprintf( __( 'Change digest settings: %s', 'local-ai-anti-spam' ), admin_url( 'admin.php?page=laas-settings' ) );

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $lines ) . "\n",
		);
	}

	/**
	 * One line of untrusted text: no links, no newlines, truncated.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function clean( string $text, int $max ): string {
		$text = (string) preg_replace( '#\b(?:https?://|www\.)\S+#i', '[link]', $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		$cut  = wp_html_excerpt( $text, $max );
		return $cut === $text ? $text : rtrim( $cut ) . '…';
	}
}
