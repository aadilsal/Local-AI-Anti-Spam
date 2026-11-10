<?php
/**
 * Rules screen: always-allow and always-block lists (design.md §3.6, F9).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Admin;

use LocalAIAntiSpam\Plugin;

/**
 * Lists, adds and removes rules.
 */
final class RulesPage {

	const SLUG  = 'laas-rules';
	const NONCE = 'laas_rules';

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
	 * URL of this screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * A small POST form that adds one rule (used after "Not spam").
	 *
	 * @param string $kind   Kind.
	 * @param string $value  Value.
	 * @param string $action allow or block.
	 * @param string $label  Button text.
	 * @return string
	 */
	public static function add_button( string $kind, string $value, string $action, string $label ): string {
		return sprintf(
			'<form method="post" action="%1$s" style="display:inline">%2$s<input type="hidden" name="laas_rule_op" value="add"><input type="hidden" name="kind" value="%3$s"><input type="hidden" name="value" value="%4$s"><input type="hidden" name="rule_action" value="%5$s"><button type="submit" class="button button-small">%6$s</button></form>',
			esc_url( self::url() ),
			wp_nonce_field( self::NONCE, '_wpnonce', true, false ),
			esc_attr( $kind ),
			esc_attr( $value ),
			esc_attr( $action ),
			esc_html( $label )
		);
	}

	/**
	 * `load-` hook: handle add and remove, then redirect.
	 *
	 * @return void
	 */
	public function load(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to manage rules.', 'local-ai-anti-spam' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$op     = isset( $_POST['laas_rule_op'] ) ? sanitize_key( wp_unslash( $_POST['laas_rule_op'] ) ) : '';
		$result = 'none';
		if ( 'add' === $op ) {
			$kind   = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
			$value  = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
			$action = isset( $_POST['rule_action'] ) ? sanitize_key( wp_unslash( $_POST['rule_action'] ) ) : '';
			$result = null !== $this->plugin->rules()->add( $kind, $value, $action, get_current_user_id() ) ? 'added' : 'invalid';
		} elseif ( 'delete' === $op && isset( $_POST['rule_id'] ) ) {
			$result = $this->plugin->rules()->delete( absint( $_POST['rule_id'] ) ) ? 'removed' : 'none';
		}
		wp_safe_redirect( add_query_arg( 'laas_rule', $result, self::url() ) );
		exit;
	}

	/**
	 * Page output.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Admin::CAP ) ) {
			return;
		}
		$rules  = $this->plugin->rules()->listing();
		$kinds  = array(
			'email'  => __( 'Email', 'local-ai-anti-spam' ),
			'domain' => __( 'Domain', 'local-ai-anti-spam' ),
			'ip'     => __( 'IP address', 'local-ai-anti-spam' ),
			'phrase' => __( 'Phrase', 'local-ai-anti-spam' ),
		);
		$result = isset( $_GET['laas_rule'] ) ? sanitize_key( wp_unslash( $_GET['laas_rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rules', 'local-ai-anti-spam' ); ?></h1>
			<?php if ( 'added' === $result ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Rule saved. It applies to new messages from now on.', 'local-ai-anti-spam' ); ?></p></div>
			<?php elseif ( 'invalid' === $result ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( "That value doesn't look right for its type. Emails need an @, domains look like example.com, phrases need at least 3 characters.", 'local-ai-anti-spam' ); ?></p></div>
			<?php elseif ( 'removed' === $result ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Rule removed.', 'local-ai-anti-spam' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Rules are checked before the model. If a message matches both lists, "Always allow" wins.', 'local-ai-anti-spam' ); ?></p>

			<h2><?php esc_html_e( 'Add a rule', 'local-ai-anti-spam' ); ?></h2>
			<form method="post" action="<?php echo esc_url( self::url() ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="laas_rule_op" value="add">
				<label class="screen-reader-text" for="laas-rule-action"><?php esc_html_e( 'List', 'local-ai-anti-spam' ); ?></label>
				<select id="laas-rule-action" name="rule_action">
					<option value="block"><?php esc_html_e( 'Always block', 'local-ai-anti-spam' ); ?></option>
					<option value="allow"><?php esc_html_e( 'Always allow', 'local-ai-anti-spam' ); ?></option>
				</select>
				<label class="screen-reader-text" for="laas-rule-kind"><?php esc_html_e( 'Type', 'local-ai-anti-spam' ); ?></label>
				<select id="laas-rule-kind" name="kind">
					<?php foreach ( $kinds as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="laas-rule-value"><?php esc_html_e( 'Value', 'local-ai-anti-spam' ); ?></label>
				<input type="text" id="laas-rule-value" name="value" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. seo-pros.example', 'local-ai-anti-spam' ); ?>" required>
				<?php submit_button( __( 'Add rule', 'local-ai-anti-spam' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php
			foreach ( array(
				'allow' => __( 'Always allow', 'local-ai-anti-spam' ),
				'block' => __( 'Always block', 'local-ai-anti-spam' ),
			) as $action => $heading ) :
				?>
				<h2><?php echo esc_html( $heading ); ?></h2>
				<?php
				$list = array_filter(
					$rules,
					static function ( $rule ) use ( $action ) {
						return $action === $rule['action'];
					}
				);
				?>
				<?php if ( array() === $list ) : ?>
					<p class="description"><?php esc_html_e( 'No rules yet.', 'local-ai-anti-spam' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:60em">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Type', 'local-ai-anti-spam' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Value', 'local-ai-anti-spam' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Added', 'local-ai-anti-spam' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Matches', 'local-ai-anti-spam' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'local-ai-anti-spam' ); ?></span></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $list as $rule ) : ?>
							<tr>
								<td><?php echo esc_html( $kinds[ $rule['kind'] ] ?? $rule['kind'] ); ?></td>
								<td><code><?php echo esc_html( (string) $rule['value'] ); ?></code></td>
								<td><?php echo esc_html( wp_date( 'j M Y', (int) strtotime( (string) $rule['created_at'] . ' UTC' ) ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $rule['hits'] ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( self::url() ); ?>">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="laas_rule_op" value="delete">
										<input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule['id'] ); ?>">
										<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Remove', 'local-ai-anti-spam' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
