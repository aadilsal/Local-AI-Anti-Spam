<?php
/**
 * The quarantine list (design.md §3.2). Lowest score first, so likely real enquiries sit at the top.
 *
 * Everything from a submission is escaped at output (security-analysis S1).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Admin;

use LocalAIAntiSpam\Quarantine\Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table over the quarantine.
 */
final class QuarantineTable extends \WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * The quarantine.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * 'held' or 'released'.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository The quarantine.
	 * @param string     $status     View.
	 */
	public function __construct( Repository $repository, string $status ) {
		parent::__construct(
			array(
				'singular' => 'laas_item',
				'plural'   => 'laas_items',
				'ajax'     => false,
			)
		);
		$this->repository = $repository;
		$this->status     = $status;
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'sender'   => __( 'From', 'local-ai-anti-spam' ),
			'message'  => __( 'Message', 'local-ai-anti-spam' ),
			'form'     => __( 'Form', 'local-ai-anti-spam' ),
			'score'    => __( 'Score', 'local-ai-anti-spam' ),
			'why'      => __( 'Why', 'local-ai-anti-spam' ),
			'received' => __( 'Received', 'local-ai-anti-spam' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'score'    => array( 'score', false ),
			'received' => array( 'created_at', true ),
		);
	}

	/**
	 * Load the page of rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list parameters.
		$default = Repository::HELD === $this->status ? 'score' : 'created_at';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : $default;
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : ( 'score' === $orderby ? 'asc' : 'desc' );
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		$page = $this->repository->page( $this->status, $this->get_pagenum(), self::PER_PAGE, $orderby, $order, $search );

		$this->items           = $page['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'sender' );
		$this->set_pagination_args(
			array(
				'total_items' => $page['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Views: held and released, with counts.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$counts = $this->repository->counts();
		$labels = array(
			/* translators: %s: count. */
			Repository::HELD     => __( 'Held <span class="count">(%s)</span>', 'local-ai-anti-spam' ),
			/* translators: %s: count. */
			Repository::RELEASED => __( 'Released <span class="count">(%s)</span>', 'local-ai-anti-spam' ),
		);
		$views  = array();
		foreach ( $labels as $status => $label ) {
			$url              = add_query_arg( 'status', $status, admin_url( 'admin.php?page=' . Admin::SLUG ) );
			$current          = $status === $this->status ? ' class="current" aria-current="page"' : '';
			$views[ $status ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $current, sprintf( $label, number_format_i18n( $counts[ $status ] ?? 0 ) ) );
		}
		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		$actions = array();
		if ( Repository::HELD === $this->status ) {
			$actions['release'] = __( 'Not spam: deliver', 'local-ai-anti-spam' );
		}
		$actions['delete'] = __( 'Delete permanently', 'local-ai-anti-spam' );
		return $actions;
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( Repository::HELD === $this->status ) {
			/* translators: %d: number of days. */
			echo esc_html( sprintf( __( "Nothing in quarantine. Spam that's caught will be held here for %d days, so you can check for real enquiries.", 'local-ai-anti-spam' ), (int) \LocalAIAntiSpam\Support\Settings::get( 'retention_days' ) ) );
		} else {
			esc_html_e( 'No released messages.', 'local-ai-anti-spam' );
		}
	}

	/**
	 * Checkbox.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Sender name and email, with the row actions.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_sender( $item ) {
		$id      = (int) $item['id'];
		$name    = '' !== (string) $item['sender_name'] ? (string) $item['sender_name'] : __( '(no name)', 'local-ai-anti-spam' );
		$out     = '<strong>' . esc_html( $name ) . '</strong>';
		$out    .= '' !== (string) $item['sender_email'] ? '<br><span class="laas-email">' . esc_html( (string) $item['sender_email'] ) . '</span>' : '';
		$actions = array();
		if ( Repository::HELD === $this->status ) {
			$actions['release'] = self::action_button( 'release', $id, __( 'Not spam', 'local-ai-anti-spam' ) );
		}
		$actions['view']   = sprintf( '<a href="%s">%s</a>', esc_url( QuarantinePage::detail_url( $id ) ), esc_html__( 'View', 'local-ai-anti-spam' ) );
		$actions['delete'] = self::action_button( 'delete', $id, __( 'Delete', 'local-ai-anti-spam' ) );
		return $out . $this->row_actions( $actions );
	}

	/**
	 * A submit button inside the table's POST form (no state change on GET).
	 *
	 * @param string $op    'release' or 'delete'.
	 * @param int    $id    Row ID.
	 * @param string $label Button text.
	 * @return string
	 */
	public static function action_button( string $op, int $id, string $label ): string {
		return sprintf(
			'<button type="submit" class="button-link%s" name="laas_row_action" value="%s">%s</button>',
			'delete' === $op ? ' button-link-delete' : '',
			esc_attr( $op . ':' . $id ),
			esc_html( $label )
		);
	}

	/**
	 * First line of the message, truncated.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_message( $item ) {
		$line = (string) $item['subject_line'];
		if ( strlen( $line ) > 90 ) {
			$line = rtrim( (string) wp_html_excerpt( $line, 90 ) ) . '…';
		}
		return esc_html( $line );
	}

	/**
	 * Which form it came from.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_form( $item ) {
		return esc_html( QuarantinePage::form_label( (string) $item['source'], (string) $item['form_id'] ) );
	}

	/**
	 * Score with a five-segment bar; the number is always shown (design.md §5).
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_score( $item ) {
		return QuarantinePage::score_html( (float) $item['score'] );
	}

	/**
	 * Why it was held, in words.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_why( $item ) {
		$features = json_decode( (string) $item['top_features'], true );
		return esc_html( QuarantinePage::why_line( (string) $item['reason'], is_array( $features ) ? $features : array(), 3 ) );
	}

	/**
	 * Age.
	 *
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_received( $item ) {
		$time = strtotime( (string) $item['created_at'] . ' UTC' );
		/* translators: %s: time difference, e.g. "2 hours". */
		return sprintf( '<span title="%s">%s</span>', esc_attr( wp_date( 'j M Y, H:i', $time ) ), esc_html( sprintf( __( '%s ago', 'local-ai-anti-spam' ), human_time_diff( $time ) ) ) );
	}
}
