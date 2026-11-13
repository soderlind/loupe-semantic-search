<?php
/**
 * Settings → Loupe Search → Semantic search tab.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch\Admin;

use Soderlind\Plugin\LoupeSemanticSearch\Ai_Adapter;
use Soderlind\Plugin\LoupeSemanticSearch\Rate_Limiter;
use Soderlind\Plugin\LoupeSemanticSearch\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Model picker, hybrid options, limits and index status.
 */
class Settings_Page {

	public const SLUG   = 'loupe-semantic-search';
	public const ACTION = 'loupe_semantic_backfill';
	public const TAB    = 'semantic';

	/**
	 * URL of the Semantic search tab on the Loupe Search settings page.
	 *
	 * @param array $args Extra query arguments.
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => 'loupe-search', 'tab' => self::TAB ], $args ),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Constructor.
	 *
	 * @param Ai_Adapter   $ai      AI plugin adapter.
	 * @param Rate_Limiter $limiter Rate limiter.
	 */
	public function __construct( private Ai_Adapter $ai, private Rate_Limiter $limiter ) {}

	/**
	 * Hook into the admin.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_setting' ] );
		add_filter( 'loupe_search_settings_tabs', [ $this, 'add_tab' ] );
		add_action( 'admin_page_access_denied', [ $this, 'redirect_old_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_backfill' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( \Soderlind\Plugin\LoupeSemanticSearch\FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * Register the option.
	 */
	public function register_setting(): void {
		register_setting(
			self::SLUG,
			Settings::OPTION,
			[
				'type'              => 'object',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Add the Semantic search tab to Settings → Loupe Search.
	 *
	 * @param array $tabs Add-on tabs.
	 */
	public function add_tab( $tabs ): array {
		$tabs              = (array) $tabs;
		$tabs[ self::TAB ] = [
			'label'  => __( 'Semantic search', 'loupe-semantic-search' ),
			'render' => [ $this, 'render' ],
		];
		return $tabs;
	}

	/**
	 * Send bookmarks of the former Settings → Loupe Semantic Search page to the tab.
	 */
	public function redirect_old_page(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page && current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 */
	public function action_links( $links ): array {
		$url = self::url();
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'loupe-semantic-search' ) ) );
		return (array) $links;
	}

	/**
	 * Start, cancel or reset the backfill.
	 */
	public function handle_backfill(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'loupe-semantic-search' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$ok = match ( $op ) {
			'start'  => $this->ai->start_backfill(),
			'cancel' => $this->ai->cancel_backfill(),
			'reset'  => $this->ai->reset_backfill() && $this->ai->start_backfill(),
			default  => false,
		};

		wp_safe_redirect( self::url( [ 'backfill' => $ok ? $op : 'failed' ] ) );
		exit;
	}

	/**
	 * Render the tab body; Loupe Search prints the page wrap and title.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::all();
		$name     = Settings::OPTION;
		?>
		<div class="loupe-semantic-search-settings">
			<?php $this->render_backfill_notice(); ?>

			<?php if ( ! $this->ai->is_available() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: Connectors screen URL. */
							__( 'Semantic search needs the AI plugin (1.5.0 or later) and a provider that offers embeddings. <a href="%s">Connect a provider</a>.', 'loupe-semantic-search' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						esc_url( admin_url( 'options-connectors.php' ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::SLUG ); ?>

				<h2><?php esc_html_e( 'Embedding model', 'loupe-semantic-search' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lss-target"><?php esc_html_e( 'Model', 'loupe-semantic-search' ); ?></label></th>
						<td>
							<?php $this->render_model_select( $settings ); ?>
							<p class="description"><?php esc_html_e( 'Changing the model re-embeds all content and deletes the old model’s vectors when nothing else uses them.', 'loupe-semantic-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lss-dimensions"><?php esc_html_e( 'Dimensions', 'loupe-semantic-search' ); ?></label></th>
						<td>
							<input type="number" id="lss-dimensions" class="small-text" min="0" max="8192" name="<?php echo esc_attr( $name ); ?>[dimensions]" value="<?php echo esc_attr( (string) $settings['dimensions'] ); ?>" />
							<p class="description"><?php esc_html_e( '0 uses the model default. Only for models that support shortened embeddings.', 'loupe-semantic-search' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Hybrid search', 'loupe-semantic-search' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Use on', 'loupe-semantic-search' ); ?></th>
						<td><fieldset>
							<?php
							$this->checkbox( 'hybrid_frontend', __( 'Front-end search (and the loupe-search/search ability)', 'loupe-semantic-search' ), $settings );
							$this->checkbox( 'hybrid_rest', __( 'REST search endpoint (Loupe Search Block)', 'loupe-semantic-search' ), $settings );
							$this->checkbox( 'abilities', __( 'Abilities: loupe-semantic/retrieve and loupe-semantic/search', 'loupe-semantic-search' ), $settings );
							?>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Weights', 'loupe-semantic-search' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Keyword', 'loupe-semantic-search' ); ?>
								<input type="number" class="small-text" step="0.1" min="0" max="10" name="<?php echo esc_attr( $name ); ?>[weight_keyword]" value="<?php echo esc_attr( (string) $settings['weight_keyword'] ); ?>" /></label>
							&nbsp;
							<label><?php esc_html_e( 'Semantic', 'loupe-semantic-search' ); ?>
								<input type="number" class="small-text" step="0.1" min="0" max="10" name="<?php echo esc_attr( $name ); ?>[weight_semantic]" value="<?php echo esc_attr( (string) $settings['weight_semantic'] ); ?>" /></label>
							<p class="description"><?php esc_html_e( 'Relative weight of each ranking in reciprocal rank fusion.', 'loupe-semantic-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lss-min"><?php esc_html_e( 'Minimum similarity', 'loupe-semantic-search' ); ?></label></th>
						<td>
							<input type="number" id="lss-min" class="small-text" step="0.01" min="0" max="1" name="<?php echo esc_attr( $name ); ?>[min_similarity]" value="<?php echo esc_attr( (string) $settings['min_similarity'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Semantic matches below this cosine similarity are ignored.', 'loupe-semantic-search' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Limits', 'loupe-semantic-search' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lss-rate"><?php esc_html_e( 'Per visitor', 'loupe-semantic-search' ); ?></label></th>
						<td>
							<input type="number" id="lss-rate" class="small-text" min="0" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $settings['rate_limit'] ); ?>" />
							<?php esc_html_e( 'query embeddings per minute per IP address (0 = no limit)', 'loupe-semantic-search' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lss-cap"><?php esc_html_e( 'Daily cap', 'loupe-semantic-search' ); ?></label></th>
						<td>
							<input type="number" id="lss-cap" class="small-text" min="0" name="<?php echo esc_attr( $name ); ?>[daily_cap]" value="<?php echo esc_attr( (string) $settings['daily_cap'] ); ?>" />
							<?php
							printf(
								/* translators: %d: query embeddings used today. */
								esc_html__( 'query embeddings per day for the whole site (0 = no limit). Used today: %d.', 'loupe-semantic-search' ),
								(int) $this->limiter->used_today()
							);
							?>
							<p class="description"><?php esc_html_e( 'Cached queries do not count. When a limit is reached, search falls back to keyword results.', 'loupe-semantic-search' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_status(); ?>
		</div>
		<?php
	}

	/**
	 * The model picker.
	 *
	 * @param array $settings Current settings.
	 */
	private function render_model_select( array $settings ): void {
		$models  = $this->ai->embedding_models();
		$current = '' !== $settings['provider'] && '' !== $settings['model'] ? $settings['provider'] . '::' . $settings['model'] : '';

		if ( empty( $models ) && '' === $current ) {
			printf(
				'<p>%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: Connectors screen URL. */
						__( 'No connected provider offers embedding models. <a href="%s">Connect a provider</a>.', 'loupe-semantic-search' ),
						esc_url( admin_url( 'options-connectors.php' ) )
					),
					[ 'a' => [ 'href' => [] ] ]
				)
			);
			return;
		}

		$values = array_map( static fn ( $m ) => $m['provider'] . '::' . $m['model'], $models );
		echo '<select id="lss-target" name="' . esc_attr( Settings::OPTION ) . '[target]">';
		echo '<option value="">' . esc_html__( '— Select a model —', 'loupe-semantic-search' ) . '</option>';
		if ( '' !== $current && ! in_array( $current, $values, true ) ) {
			/* translators: %s: provider::model. */
			echo '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( sprintf( __( '%s (not available)', 'loupe-semantic-search' ), $current ) ) . '</option>';
		}
		foreach ( $models as $i => $model ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $values[ $i ] ),
				selected( $current, $values[ $i ], false ),
				esc_html( $model['provider_name'] . ' — ' . $model['model_name'] )
			);
		}
		echo '</select>';
	}

	/**
	 * A checkbox row.
	 *
	 * @param string $key      Setting key.
	 * @param string $label    Label.
	 * @param array  $settings Current settings.
	 */
	private function checkbox( string $key, string $label, array $settings ): void {
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label><br />',
			esc_attr( Settings::OPTION ),
			esc_attr( $key ),
			checked( ! empty( $settings[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Result notice after a backfill action.
	 */
	private function render_backfill_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$result = isset( $_GET['backfill'] ) ? sanitize_key( wp_unslash( $_GET['backfill'] ) ) : '';
		$text   = match ( $result ) {
			'start'  => __( 'Backfill started.', 'loupe-semantic-search' ),
			'cancel' => __( 'Backfill cancelled.', 'loupe-semantic-search' ),
			'reset'  => __( 'Backfill restarted from the beginning.', 'loupe-semantic-search' ),
			'failed' => __( 'The backfill action did not run. Is a model selected?', 'loupe-semantic-search' ),
			default  => '',
		};
		if ( '' !== $text ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'failed' === $result ? 'error' : 'success', esc_html( $text ) );
		}
	}

	/**
	 * Index status and backfill buttons.
	 */
	private function render_status(): void {
		$status = $this->ai->status();
		echo '<h2>' . esc_html__( 'Index status', 'loupe-semantic-search' ) . '</h2>';
		if ( null === $status ) {
			echo '<p>' . esc_html__( 'Choose a model and save to start embedding content.', 'loupe-semantic-search' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:40em"><thead><tr><th>' . esc_html__( 'Post type', 'loupe-semantic-search' ) . '</th><th>' . esc_html__( 'Embedded', 'loupe-semantic-search' ) . '</th><th>' . esc_html__( 'Indexable', 'loupe-semantic-search' ) . '</th></tr></thead><tbody>';
		foreach ( (array) ( $status['coverage']['post'] ?? [] ) as $subtype => $row ) {
			$object = get_post_type_object( $subtype );
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td></tr>',
				esc_html( $object ? $object->labels->name : $subtype ),
				(int) $row['indexed'],
				(int) $row['indexable']
			);
		}
		echo '</tbody></table>';

		$backfill = is_array( $status['backfill'] ) ? (string) ( $status['backfill']['status'] ?? '' ) : '';
		$lines    = [
			__( 'Backfill', 'loupe-semantic-search' ) => '' !== $backfill ? $backfill : __( 'not started', 'loupe-semantic-search' ),
			__( 'Queue', 'loupe-semantic-search' )    => sprintf(
				/* translators: 1: pending objects, 2: failed objects. */
				__( '%1$d pending, %2$d failed', 'loupe-semantic-search' ),
				(int) ( $status['queue']['pending'] ?? 0 ),
				(int) ( $status['queue']['failed'] ?? 0 )
			),
			__( 'Last run', 'loupe-semantic-search' ) => $status['last_run'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['last_run'] ) : __( 'never', 'loupe-semantic-search' ),
		];
		if ( $status['backoff'] ) {
			$lines[ __( 'Paused until', 'loupe-semantic-search' ) ] = wp_date( get_option( 'time_format' ), (int) $status['backoff'] );
		}
		if ( $status['provider_error'] ) {
			$lines[ __( 'Provider error', 'loupe-semantic-search' ) ] = (string) $status['provider_error'];
		}
		echo '<p>';
		foreach ( $lines as $label => $value ) {
			printf( '<strong>%s:</strong> %s<br />', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		if ( 'running' === $backfill ) {
			echo '<button type="submit" name="op" value="cancel" class="button">' . esc_html__( 'Cancel backfill', 'loupe-semantic-search' ) . '</button> ';
		} else {
			echo '<button type="submit" name="op" value="start" class="button button-primary">' . esc_html__( 'Start backfill', 'loupe-semantic-search' ) . '</button> ';
		}
		echo '<button type="submit" name="op" value="reset" class="button">' . esc_html__( 'Reset and re-run', 'loupe-semantic-search' ) . '</button>';
		echo '</form>';
	}
}
