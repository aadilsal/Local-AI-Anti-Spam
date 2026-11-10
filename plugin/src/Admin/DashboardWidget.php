<?php
/**
 * Dashboard widget: last 30 days (design.md §3.8, F13). Leads with "was a real customer lost?":
 * held messages near the threshold get a nudge to look.
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Admin;

use LocalAIAntiSpam\Plugin;
use LocalAIAntiSpam\Stats\Stats;

/**
 * Renders the widget.
 */
final class DashboardWidget {

	const DAYS = 30;
	const NEAR = 0.02; // "Near the line": held with a score below t_high + this.

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
	 * `wp_dashboard_setup`.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( current_user_can( Admin::CAP ) ) {
			wp_add_dashboard_widget( 'laas_dashboard', __( 'Spam protection: last 30 days', 'local-ai-anti-spam' ), array( $this, 'render' ) );
		}
	}

	/**
	 * Widget body.
	 *
	 * @return void
	 */
	public function render(): void {
		$stats  = Stats::last_days( self::DAYS );
		$totals = $stats['totals'];
		$held   = array_values( $stats['held_per_day'] );
		$line   = null;
		try {
			$line = min( 0.999, (float) $this->plugin->load_model()->header()['t_high'] + Plugin::t_high_offset() + self::NEAR );
		} catch ( \Throwable $error ) {
			$line = null;
		}
		$near  = null !== $line ? $this->plugin->repository()->count_held_below( $line ) : 0;
		$queue = add_query_arg(
			array(
				'orderby' => 'score',
				'order'   => 'asc',
			),
			admin_url( 'admin.php?page=' . Admin::SLUG )
		);
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: messages checked, 2: messages held, 3: messages released. */
					__( '%1$s checked · %2$s held · %3$s released by you', 'local-ai-anti-spam' ),
					number_format_i18n( $totals['checked'] ),
					number_format_i18n( $totals['spam'] ),
					number_format_i18n( $totals['released'] )
				)
			);
			?>
		</p>
		<?php echo self::sparkline( $held ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from integers, labels escaped inside. ?>
		<p>
			<?php if ( $near > 0 && null !== $line ) : ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of messages, 2: score. */
						_n( '%1$d held message scored under %2$s. Worth a quick look.', '%1$d held messages scored under %2$s. Worth a quick look.', $near, 'local-ai-anti-spam' ),
						$near,
						number_format_i18n( $line, 2 )
					)
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Nothing near the line. Looks clean.', 'local-ai-anti-spam' ); ?>
			<?php endif; ?>
		</p>
		<p><a class="button" href="<?php echo esc_url( $queue ); ?>"><?php esc_html_e( 'Review quarantine', 'local-ai-anti-spam' ); ?></a></p>
		<?php
	}

	/**
	 * Inline SVG of held messages per day, with a text alternative (design.md §5).
	 *
	 * @param int[] $values One value per day, oldest first.
	 * @return string
	 */
	public static function sparkline( array $values ): string {
		$max    = max( 1, max( $values ? $values : array( 0 ) ) );
		$width  = 3 * count( $values );
		$points = array();
		foreach ( array_values( $values ) as $i => $value ) {
			$points[] = ( 3 * $i + 1 ) . ',' . ( 30 - round( 28 * $value / $max ) );
		}
		$label = sprintf(
			/* translators: 1: smallest daily count, 2: largest daily count. */
			__( 'Held per day, last 30 days: min %1$d, max %2$d', 'local-ai-anti-spam' ),
			$values ? min( $values ) : 0,
			$values ? max( $values ) : 0
		);
		return sprintf(
			'<svg role="img" aria-label="%1$s" width="%2$d" height="32" viewBox="0 0 %2$d 32" style="max-width:100%%"><title>%1$s</title><polyline fill="none" stroke="currentColor" stroke-width="1.5" points="%3$s"/></svg>',
			esc_attr( $label ),
			max( 3, $width ),
			esc_attr( implode( ' ', $points ) )
		);
	}
}
