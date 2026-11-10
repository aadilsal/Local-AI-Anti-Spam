<?php
/**
 * WordPress privacy tools (C6): export and erase held messages by sender email, and suggested
 * privacy-policy text.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Privacy;

use LocalAIAntiSpam\Quarantine\Repository;

/**
 * Registers the exporter, the eraser and the policy text.
 */
final class Privacy {

	const PER_PAGE = 50;

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
	 * Add the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function add_exporter( $exporters ) {
		$exporters['local-ai-anti-spam'] = array(
			'exporter_friendly_name' => __( 'Messages held as spam', 'local-ai-anti-spam' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function add_eraser( $erasers ) {
		$erasers['local-ai-anti-spam'] = array(
			'eraser_friendly_name' => __( 'Messages held as spam', 'local-ai-anti-spam' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export one page of a sender's held messages.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$rows = $this->repository->by_email( (string) $email, max( 1, (int) $page ), self::PER_PAGE );
		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'local-ai-anti-spam',
				'group_label' => __( 'Messages held as spam', 'local-ai-anti-spam' ),
				'item_id'     => 'laas-quarantine-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Received', 'local-ai-anti-spam' ),
						'value' => (string) $row['created_at'] . ' UTC',
					),
					array(
						'name'  => __( 'Form', 'local-ai-anti-spam' ),
						'value' => (string) $row['source'],
					),
					array(
						'name'  => __( 'Status', 'local-ai-anti-spam' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Name', 'local-ai-anti-spam' ),
						'value' => (string) $row['sender_name'],
					),
					array(
						'name'  => __( 'Email', 'local-ai-anti-spam' ),
						'value' => (string) $row['sender_email'],
					),
					array(
						'name'  => __( 'IP address', 'local-ai-anti-spam' ),
						'value' => (string) $row['sender_ip'],
					),
					array(
						'name'  => __( 'Message', 'local-ai-anti-spam' ),
						'value' => (string) $row['body'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase a sender's held messages.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused: everything goes in one pass).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page.
		$removed = $this->repository->delete_by_email( (string) $email );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for the site's privacy policy.
	 *
	 * @return void
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Messages sent through the forms and comments on this site are checked for spam by a model that runs on this website\'s own server. No message is sent to any outside service for this check.', 'local-ai-anti-spam' ) . '</p>'
			. '<p>' . esc_html__( 'Messages judged to be spam are held for review for a limited time (30 days by default) and then deleted automatically. A held message keeps the name, email address, IP address (if that setting is on) and text that were submitted.', 'local-ai-anti-spam' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Local AI Anti-Spam', 'local-ai-anti-spam' ), wp_kses_post( $text ) );
	}
}
