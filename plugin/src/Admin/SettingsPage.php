<?php
/**
 * Settings: General and Diagnostics tabs (design.md §3.7). Saved through the Settings API, which
 * checks the nonce and `manage_options`.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Admin;

use LocalAIAntiSpam\Classifier\Submission;
use LocalAIAntiSpam\Plugin;
use LocalAIAntiSpam\Support\Log;
use LocalAIAntiSpam\Support\Settings;

/**
 * The settings screen.
 */
final class SettingsPage {

	const GROUP = 'laas_settings_group';

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
	 * Register the option with its sanitiser.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::DEFAULTS,
			)
		);
	}

	/**
	 * Page output.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) && 'diagnostics' === $_GET['tab'] ? 'diagnostics' : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$base = admin_url( 'admin.php?page=' . Admin::SETTINGS_SLUG );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Anti-Spam Settings', 'local-ai-anti-spam' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base ); ?>" class="nav-tab<?php echo 'general' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'local-ai-anti-spam' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'diagnostics', $base ) ); ?>" class="nav-tab<?php echo 'diagnostics' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Diagnostics', 'local-ai-anti-spam' ); ?></a>
			</nav>
			<?php 'diagnostics' === $tab ? $this->render_diagnostics() : $this->render_general(); ?>
		</div>
		<?php
	}

	/**
	 * General tab.
	 *
	 * @return void
	 */
	private function render_general(): void {
		$settings = Settings::all();
		$name     = Settings::OPTION;
		$labels   = array(
			'relaxed'  => array( __( 'Relaxed', 'local-ai-anti-spam' ), __( 'Holds only very obvious spam. Fewest real messages held by mistake.', 'local-ai-anti-spam' ) ),
			'balanced' => array( __( 'Balanced', 'local-ai-anti-spam' ), __( 'Recommended.', 'local-ai-anti-spam' ) ),
			'strict'   => array( __( 'Strict', 'local-ai-anti-spam' ), __( 'Holds more spam, but real enquiries are a little more likely to be held. Check the quarantine regularly.', 'local-ai-anti-spam' ) ),
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Protect', 'local-ai-anti-spam' ); ?></th>
					<td><fieldset>
						<?php foreach ( array_keys( $this->plugin->integrations() ) as $id ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $name . '[protect_known][]' ); ?>" value="<?php echo esc_attr( $id ); ?>">
							<label><input type="checkbox" name="<?php echo esc_attr( $name . '[protect][' . $id . ']' ); ?>" value="1" <?php checked( Settings::protects( $id ) ); ?>> <?php echo esc_html( Admin::integration_name( $id ) ); ?></label><br>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sensitivity', 'local-ai-anti-spam' ); ?></th>
					<td><fieldset>
						<?php foreach ( $labels as $value => $label ) : ?>
							<label><input type="radio" name="<?php echo esc_attr( $name . '[strength]' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['strength'], $value ); ?>> <strong><?php echo esc_html( $label[0] ); ?></strong>: <?php echo esc_html( $label[1] ); ?></label><br>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><label for="laas-subject-tag"><?php esc_html_e( 'Possible-spam tag', 'local-ai-anti-spam' ); ?></label></th>
					<td>
						<input type="text" id="laas-subject-tag" class="regular-text" name="<?php echo esc_attr( $name . '[subject_tag]' ); ?>" value="<?php echo esc_attr( (string) $settings['subject_tag'] ); ?>">
						<p class="description"><?php esc_html_e( 'Added to the subject of form emails the model was unsure about. They are still delivered. Leave empty to turn this off.', 'local-ai-anti-spam' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="laas-retention"><?php esc_html_e( 'Keep held messages for', 'local-ai-anti-spam' ); ?></label></th>
					<td><input type="number" id="laas-retention" class="small-text" min="1" max="365" name="<?php echo esc_attr( $name . '[retention_days]' ); ?>" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>"> <?php esc_html_e( 'days', 'local-ai-anti-spam' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email digest', 'local-ai-anti-spam' ); ?></th>
					<td><fieldset>
						<?php
						$digests = array(
							'off'    => __( 'Off', 'local-ai-anti-spam' ),
							'daily'  => __( 'Daily', 'local-ai-anti-spam' ),
							'weekly' => __( 'Weekly', 'local-ai-anti-spam' ),
						);
						foreach ( $digests as $value => $label ) :
							?>
							<label><input type="radio" name="<?php echo esc_attr( $name . '[digest]' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['digest'], $value ); ?>> <?php echo esc_html( $label ); ?></label>&nbsp;&nbsp;
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'A short list of newly held messages, lowest score first. Sent only when something new was held.', 'local-ai-anti-spam' ); ?></p>
						<label for="laas-digest-to"><?php esc_html_e( 'Send to', 'local-ai-anti-spam' ); ?></label>
						<input type="email" id="laas-digest-to" class="regular-text" name="<?php echo esc_attr( $name . '[digest_to]' ); ?>" value="<?php echo esc_attr( (string) $settings['digest_to'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
					</fieldset></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Privacy', 'local-ai-anti-spam' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name . '[store_ip]' ); ?>" value="1" <?php checked( (bool) $settings['store_ip'] ); ?>> <?php esc_html_e( 'Store sender IP addresses with held messages', 'local-ai-anti-spam' ); ?></label>
						<p class="description"><?php esc_html_e( 'IP addresses help you spot repeat senders. Nothing is ever sent off this site.', 'local-ai-anti-spam' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Diagnostics tab: model, self-test and error log.
	 *
	 * @return void
	 */
	private function render_diagnostics(): void {
		$rows = array();
		try {
			$header   = $this->plugin->load_model()->header();
			$rows     = array(
				__( 'Model', 'local-ai-anti-spam' )      => (string) $header['model_version'],
				__( 'Trained', 'local-ai-anti-spam' )    => (string) ( $header['trained_at'] ?? '' ),
				__( 'Thresholds', 'local-ai-anti-spam' ) => sprintf( 'clean < %.4f, spam ≥ %.4f (offset %+.2f)', (float) $header['t_low'], (float) $header['t_high'], Plugin::t_high_offset() ),
				__( 'Model file', 'local-ai-anti-spam' ) => size_format( (int) filesize( LAAS_DIR . 'model/model.bin' ) ),
				__( 'Plugin version', 'local-ai-anti-spam' ) => LAAS_VERSION,
			);
			$model_ok = true;
		} catch ( \Throwable $error ) {
			$model_ok = false;
		}
		?>
		<h2><?php esc_html_e( 'Model', 'local-ai-anti-spam' ); ?></h2>
		<?php if ( ! $model_ok ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( "The spam model couldn't be loaded, so messages are being delivered without checking. Reinstall the plugin or contact support.", 'local-ai-anti-spam' ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped" style="max-width:50em"><tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Self-test', 'local-ai-anti-spam' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Three built-in samples, classified now. Nothing is stored.', 'local-ai-anti-spam' ); ?></p>
		<table class="widefat striped" style="max-width:50em"><tbody>
			<?php foreach ( self::self_test( $this->plugin ) as $result ) : ?>
				<tr><td><?php echo esc_html( $result['sample'] ); ?></td><td><?php echo esc_html( $result['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( $result['score'], 2 ) ); ?></td><td><?php echo esc_html( $result['ok'] ? __( 'as expected', 'local-ai-anti-spam' ) : __( 'unexpected', 'local-ai-anti-spam' ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Error log', 'local-ai-anti-spam' ); ?></h2>
		<?php $entries = array_slice( Log::entries(), -20 ); ?>
		<?php if ( array() === $entries ) : ?>
			<p><?php esc_html_e( 'No errors. Every message has been checked normally.', 'local-ai-anti-spam' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'When a check fails, the message is delivered unchecked. The log never contains message content.', 'local-ai-anti-spam' ); ?></p>
			<table class="widefat striped" style="max-width:50em"><tbody>
				<?php foreach ( array_reverse( $entries ) as $entry ) : ?>
					<tr><td><?php echo esc_html( wp_date( 'j M Y, H:i', (int) ( $entry['time'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( (string) ( $entry['where'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $entry['error'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $entry['at'] ?? '' ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Classify three fixed samples.
	 *
	 * @param Plugin $plugin The plugin.
	 * @return array<int, array{sample: string, label: string, score: float, ok: bool}>
	 */
	public static function self_test( Plugin $plugin ): array {
		$samples = array(
			array( __( 'Booking enquiry', 'local-ai-anti-spam' ), 'Hi, could I book a table for four people this Friday at 7pm? Many thanks, Sarah', false ),
			array( __( 'Quote request', 'local-ai-anti-spam' ), 'Hello, could you give me a quote for replacing the boiler in a three-bedroom house? I am free most mornings.', false ),
			array( __( 'SEO pitch', 'local-ai-anti-spam' ), 'I noticed your website is not ranking on the first page of Google. Our SEO agency can get you backlinks and more traffic. Reply for a free audit: https://seo-boost.example/offer', true ),
		);
		$out     = array();
		foreach ( $samples as $sample ) {
			$verdict = $plugin->pipeline()->classify( new Submission( 'self-test', $sample[1], 'test@example.com' ) );
			$out[]   = array(
				'sample' => $sample[0],
				'label'  => $verdict->label,
				'score'  => $verdict->score,
				'ok'     => $sample[2] === $verdict->is_spam() && 'error' !== $verdict->reason,
			);
		}
		return $out;
	}
}
