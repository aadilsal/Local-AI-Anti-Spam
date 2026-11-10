<?php
/**
 * WPForms, through its `wpforms_process_honeypot` filter (checked against WPForms Lite 2.0.4,
 * includes/class-process.php). The filter runs after fields are formatted and before any entry or
 * email; a non-empty return makes WPForms log the reason and stop silently, so the visitor sees
 * the usual confirmation and no notification is sent.
 *
 * Release re-sends the form's notifications through WPForms' own `entry_email()` with the stored
 * fields (architecture.md §5).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Integrations;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Quarantine\Repository;
use LocalAIAntiSpam\Support\Log;

/**
 * Classifies WPForms submissions and releases held ones.
 */
final class WpForms implements Integration, Releasable {

	const ID = 'wpforms';

	/** WPForms field types that must never be classified or stored. */
	const SECRET_TYPES = array( 'password', 'hidden', 'credit-card', 'stripe-credit-card', 'square', 'authorize_net', 'paypal-commerce', 'payment-total', 'payment-single', 'payment-multiple', 'payment-checkbox', 'payment-select', 'captcha', 'signature', 'file-upload' );

	/**
	 * The pipeline.
	 *
	 * @var Pipeline
	 */
	private $pipeline;

	/**
	 * The quarantine.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Pipeline   $pipeline   The pipeline.
	 * @param Repository $repository The quarantine.
	 */
	public function __construct( Pipeline $pipeline, Repository $repository ) {
		$this->pipeline   = $pipeline;
		$this->repository = $repository;
	}

	/**
	 * Short ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Whether WPForms is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'wpforms' );
	}

	/**
	 * Add the hook, after WPForms' own honeypot check.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wpforms_process_honeypot', array( $this, 'filter_honeypot' ), 20, 4 );
	}

	/**
	 * Map WPForms' formatted fields for the classifier, keeping only safe fields.
	 *
	 * @param array<int|string, array<string, mixed>> $fields Formatted fields: id => [name, value, id, type].
	 * @return array{text: string, email: ?string, name: ?string, stored: array<string, mixed>, kept: array<int|string, array<string, mixed>>}
	 */
	public static function map( array $fields ): array {
		$types  = array();
		$posted = array();
		$kept   = array();
		foreach ( $fields as $id => $field ) {
			$type = (string) ( $field['type'] ?? 'text' );
			if ( in_array( $type, self::SECRET_TYPES, true ) || 0 === strpos( $type, 'payment' ) ) {
				continue;
			}
			$label = trim( (string) ( $field['name'] ?? '' ) );
			$key   = ( '' !== $label ? $label : 'Field' ) . ( isset( $posted[ $label ] ) || '' === $label ? ' #' . $id : '' );
			// Types Cf7Fields doesn't treat as free text ("name", "select", ...) are stored only.
			$types[ $key ]  = 'phone' === $type ? 'tel' : $type;
			$posted[ $key ] = $field['value'] ?? '';
			$kept[ $id ]    = $field;
		}
		$mapped = Cf7Fields::map( $types, $posted );
		if ( null === $mapped['name'] ) {
			foreach ( $fields as $field ) {
				if ( 'name' === ( $field['type'] ?? '' ) && '' !== trim( (string) ( $field['value'] ?? '' ) ) ) {
					$mapped['name'] = trim( (string) $field['value'] );
					break;
				}
			}
		}
		$mapped['kept'] = $kept;
		return $mapped;
	}

	/**
	 * `wpforms_process_honeypot`: a non-empty string stops the submission.
	 *
	 * @param string|false         $honeypot  Earlier honeypot result.
	 * @param array<mixed>         $fields    Formatted fields.
	 * @param array<string, mixed> $entry     Raw submission.
	 * @param array<string, mixed> $form_data Form data.
	 * @return string|false
	 */
	public function filter_honeypot( $honeypot, $fields, $entry, $form_data ) {
		if ( ! empty( $honeypot ) || ! is_array( $fields ) || ! is_array( $form_data ) ) {
			return $honeypot; // Already caught by WPForms.
		}
		try {
			$form_id = (int) ( $form_data['id'] ?? 0 );
			$mapped  = self::map( $fields );
			$ip      = function_exists( 'wpforms_get_ip' ) ? (string) wpforms_get_ip() : '';
			$item    = new Submission(
				self::ID,
				$mapped['text'],
				$mapped['email'],
				$mapped['name'],
				(string) $form_id,
				'' !== $ip ? $ip : null,
				array(
					'form_id' => $form_id,
					'fields'  => $mapped['kept'],
				)
			);
			$verdict = $this->pipeline->classify( $item );
			if ( ! $verdict->is_spam() ) {
				return $honeypot;
			}
			$body = array();
			foreach ( $mapped['stored'] as $label => $value ) {
				$body[] = $label . ': ' . Cf7Fields::flatten( $value );
			}
			$id = $this->repository->hold( $item, $verdict, implode( "\n", $body ) );
			if ( 0 === $id ) {
				return $honeypot; // Could not store it, so deliver it (fail open).
			}
			do_action( 'laas_quarantined', $id, $verdict, $item );
			return sprintf( 'Local AI Anti-Spam: held for review (score %.2f)', $verdict->score );
		} catch ( \Throwable $error ) {
			Log::error( 'wpforms', $error );
			return $honeypot; // Fail open.
		}
	}

	/**
	 * Send the form's notifications for a held message.
	 *
	 * @param array<string, mixed> $item Quarantine row.
	 * @return bool
	 */
	public function release( array $item ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}
		$payload = (array) $item['payload'];
		$form_id = (int) ( $payload['form_id'] ?? 0 );
		$form    = wpforms()->obj( 'form' );
		$process = wpforms()->obj( 'process' );
		$data    = $form ? $form->get( $form_id, array( 'content_only' => true ) ) : null;
		if ( ! is_array( $data ) || empty( $data['settings']['notification_enable'] ) || ! $process ) {
			return false; // No form, or notifications are off: nothing would be delivered.
		}
		$fields = (array) ( $payload['fields'] ?? array() );
		$values = array();
		foreach ( $fields as $id => $field ) {
			$values[ $id ] = $field['value'] ?? '';
		}
		// entry_email() returns nothing, so count successful sends while it runs.
		$sent    = 0;
		$hook    = static function () use ( &$sent ) {
			++$sent;
		};
		$counter = static function ( $result ) use ( &$sent ) {
			if ( null !== $result && false !== $result ) {
				++$sent; // A mail plugin (or the test double) sent it and short-circuited wp_mail.
			}
			return $result;
		};
		add_action( 'wp_mail_succeeded', $hook );
		add_filter( 'pre_wp_mail', $counter, PHP_INT_MAX );
		$process->entry_email(
			$fields,
			array(
				'id'     => $form_id,
				'fields' => $values,
			),
			$data,
			0,
			'entry'
		);
		remove_action( 'wp_mail_succeeded', $hook );
		remove_filter( 'pre_wp_mail', $counter, PHP_INT_MAX );
		return $sent > 0;
	}
}
