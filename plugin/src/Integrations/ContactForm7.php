<?php
/**
 * Contact Form 7, through its `wpcf7_spam` filter (checked against CF7 6.2.4.1, includes/submission.php).
 *
 * Returning true from `wpcf7_spam` makes CF7 skip its mail. CF7 keeps no copy, so spam is stored
 * in the quarantine with the posted values, and release() rebuilds CF7's mail from the form's
 * current `mail` and `mail_2` templates (review note R6).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Integrations;

use LocalAIAntiSpam\Classifier\Pipeline;
use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Quarantine\Repository;
use LocalAIAntiSpam\Support\Log;

/**
 * Classifies CF7 submissions and releases held ones.
 */
final class ContactForm7 implements Integration, Releasable {

	const ID = 'cf7';

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
	 * Whether CF7 is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7_Submission' );
	}

	/**
	 * Add the hook. Priority 20: after CF7's own checks, reCAPTCHA, Turnstile and Akismet.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wpcf7_spam', array( $this, 'filter_spam' ), 20, 2 );
	}

	/**
	 * `wpcf7_spam`: true holds the message.
	 *
	 * @param bool   $spam       Whether an earlier check already flagged it.
	 * @param object $submission WPCF7_Submission.
	 * @return bool
	 */
	public function filter_spam( $spam, $submission ) {
		if ( $spam || ! is_object( $submission ) || ! method_exists( $submission, 'get_contact_form' ) ) {
			return $spam; // Already spam to CF7: not ours to judge or store.
		}
		try {
			$form   = $submission->get_contact_form();
			$fields = Cf7Fields::map( self::field_types( $form ), (array) $submission->get_posted_data() );
			$item   = new Submission(
				self::ID,
				$fields['text'],
				$fields['email'],
				$fields['name'],
				(string) $form->id(),
				(string) $submission->get_meta( 'remote_ip' ),
				array(
					'form_id' => (int) $form->id(),
					'fields'  => $fields['stored'],
					'meta'    => array(
						'timestamp'  => (int) $submission->get_meta( 'timestamp' ),
						'remote_ip'  => (string) $submission->get_meta( 'remote_ip' ),
						'user_agent' => (string) $submission->get_meta( 'user_agent' ),
						'url'        => (string) $submission->get_meta( 'url' ),
						'post_id'    => (int) $submission->get_meta( 'container_post_id' ),
					),
				)
			);

			$verdict = $this->pipeline->classify( $item );
			if ( \LocalAIAntiSpam\Classifier\Verdict::BORDERLINE === $verdict->label ) {
				self::tag_subject();
			}
			if ( ! $verdict->is_spam() ) {
				return false;
			}
			$id = $this->repository->hold( $item, $verdict, self::body( $fields['stored'] ) );
			if ( 0 === $id ) {
				return false; // Could not store it, so don't lose it: deliver (fail open).
			}
			if ( method_exists( $submission, 'add_spam_log' ) ) {
				$submission->add_spam_log(
					array(
						'agent'  => 'local-ai-anti-spam',
						'reason' => sprintf( 'Held for review (score %.2f).', $verdict->score ),
					)
				);
			}
			do_action( 'laas_quarantined', $id, $verdict, $item );
			return true;
		} catch ( \Throwable $error ) {
			Log::error( 'cf7', $error );
			return $spam; // Fail open.
		}
	}

	/**
	 * Send the form's mails for a held message, as CF7 would have.
	 *
	 * @param array<string, mixed> $item Quarantine row.
	 * @return bool
	 */
	public function release( array $item ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}
		$payload = (array) $item['payload'];
		$form    = \WPCF7_ContactForm::get_instance( (int) ( $payload['form_id'] ?? 0 ) );
		if ( ! $form ) {
			return false;
		}
		$fields  = (array) ( $payload['fields'] ?? array() );
		$special = self::special_tags( (array) ( $payload['meta'] ?? array() ), $form );

		$templates = array( 'mail' => $form->prop( 'mail' ) );
		$mail_2    = $form->prop( 'mail_2' );
		if ( is_array( $mail_2 ) && ! empty( $mail_2['active'] ) ) {
			$templates['mail_2'] = $mail_2;
		}
		$sent = false;
		foreach ( $templates as $name => $template ) {
			$mail = Cf7Fields::compose( (array) $template, $fields, $special );
			$ok   = wp_mail( $mail['recipient'], $mail['subject'], $mail['body'], $mail['headers'] );
			if ( 'mail' === $name ) {
				$sent = $ok; // CF7 counts a submission as sent when the main mail goes out.
			}
		}
		return $sent;
	}

	/**
	 * Borderline: deliver, but prefix the main mail's subject with the possible-spam tag (design.md
	 * §3.7). Only the site owner's mail (`mail`), never the autoresponder (`mail_2`).
	 *
	 * @return void
	 */
	private static function tag_subject(): void {
		$tag = trim( (string) \LocalAIAntiSpam\Support\Settings::get( 'subject_tag' ) );
		if ( '' === $tag ) {
			return;
		}
		add_filter(
			'wpcf7_mail_components',
			static function ( $components, $form = null, $mail = null ) use ( $tag ) {
				$is_main = is_object( $mail ) && method_exists( $mail, 'name' ) && 'mail' === $mail->name();
				if ( $is_main && is_array( $components ) && isset( $components['subject'] ) && 0 !== strpos( (string) $components['subject'], $tag ) ) {
					$components['subject'] = $tag . ' ' . $components['subject'];
				}
				return $components;
			},
			10,
			3
		);
	}

	/**
	 * Field name => CF7 base type, from the form's tags.
	 *
	 * @param object $form WPCF7_ContactForm.
	 * @return array<string, string>
	 */
	private static function field_types( $form ): array {
		$types = array();
		foreach ( (array) $form->scan_form_tags() as $tag ) {
			if ( ! empty( $tag->name ) ) {
				$types[ (string) $tag->name ] = (string) $tag->basetype;
			}
		}
		return $types;
	}

	/**
	 * Every stored field as "name: value" lines, for the detail view (shown as plain text).
	 *
	 * @param array<string, mixed> $fields Stored fields.
	 * @return string
	 */
	private static function body( array $fields ): string {
		$lines = array();
		foreach ( $fields as $name => $value ) {
			$lines[] = $name . ': ' . Cf7Fields::flatten( $value );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Values for CF7's special mail tags, from the stored submission and the site.
	 *
	 * @param array<string, mixed> $meta Stored submission meta.
	 * @param object               $form WPCF7_ContactForm.
	 * @return array<string, string>
	 */
	private static function special_tags( array $meta, $form ): array {
		$time = (int) ( $meta['timestamp'] ?? time() );
		$post = (int) ( $meta['post_id'] ?? 0 );
		return array(
			'_remote_ip'          => (string) ( $meta['remote_ip'] ?? '' ),
			'_user_agent'         => (string) ( $meta['user_agent'] ?? '' ),
			'_url'                => (string) ( $meta['url'] ?? '' ),
			'_date'               => wp_date( (string) get_option( 'date_format' ), $time ),
			'_time'               => wp_date( (string) get_option( 'time_format' ), $time ),
			'_site_title'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'_site_description'   => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'_site_url'           => home_url(),
			'_site_admin_email'   => (string) get_bloginfo( 'admin_email' ),
			'_contact_form_title' => (string) $form->title(),
			'_post_id'            => $post ? (string) $post : '',
			'_post_title'         => $post ? get_the_title( $post ) : '',
			'_post_url'           => $post ? (string) get_permalink( $post ) : '',
		);
	}
}
