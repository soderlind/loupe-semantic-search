<?php
/**
 * Plugin bootstrap.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

use Soderlind\Plugin\LoupeSearch\WP_Loupe_Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the services together.
 */
final class Plugin {

	public const TARGET_CHANGE_OPTION = 'loupe_semantic_target_change';

	private static ?self $instance = null;

	private Ai_Adapter $ai;
	private Rate_Limiter $limiter;
	private ?Retriever $retriever  = null;
	private ?Hybrid_Search $hybrid = null;

	/**
	 * The shared instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->ai      = new Ai_Adapter();
		$this->limiter = new Rate_Limiter();
	}

	/**
	 * Register hooks. Runs on `plugins_loaded`.
	 */
	public function boot(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );

		if ( is_admin() ) {
			( new Admin\Settings_Page( $this->ai, $this->limiter ) )->register();
			add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		}

		if ( ! $this->is_ready() ) {
			return;
		}

		add_action( 'add_option_' . Settings::OPTION, [ $this, 'on_settings_added' ], 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, [ $this, 'on_settings_updated' ], 10, 2 );
		add_action( 'init', [ $this, 'register_consumer' ], 15 );
		add_action( 'init', [ $this, 'apply_target_change' ], 30 );

		( new Indexability( $this->ai ) )->register();
		$this->hybrid()->register();
		if ( Settings::get( 'abilities' ) && Settings::has_model() ) {
			( new Abilities( $this->retriever(), $this->hybrid() ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'loupe-semantic', CLI\Command::class );
		}
	}

	/**
	 * Whether Loupe Search 1.5+ and the ai plugin's embedding support are loaded.
	 */
	public function is_ready(): bool {
		return $this->has_loupe() && $this->ai->is_available();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'loupe-semantic-search', false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}

	/**
	 * AI plugin adapter.
	 */
	public function ai(): Ai_Adapter {
		return $this->ai;
	}

	/**
	 * Rate limiter.
	 */
	public function limiter(): Rate_Limiter {
		return $this->limiter;
	}

	/**
	 * Semantic retriever.
	 */
	public function retriever(): Retriever {
		if ( null === $this->retriever ) {
			$default = new Ai_Repository_Vector_Store( $this->ai );
			/**
			 * Filters the vector store used for semantic search.
			 *
			 * @param Vector_Store $store Default: a full scan of the ai plugin's embeddings table.
			 */
			$store = apply_filters( 'loupe_semantic_vector_store', $default );
			if ( ! $store instanceof Vector_Store ) {
				$store = $default;
			}
			$this->retriever = new Retriever(
				new Query_Embedder( $this->ai, $this->limiter ),
				$store,
				new Passage_Builder( $this->ai )
			);
		}
		return $this->retriever;
	}

	/**
	 * Hybrid ranking.
	 */
	public function hybrid(): Hybrid_Search {
		return $this->hybrid ??= new Hybrid_Search( $this->retriever() );
	}

	/**
	 * Post types to embed: Loupe Search's indexed post types that exist.
	 *
	 * @return list<string>
	 */
	public function post_types(): array {
		return array_values( array_filter( WP_Loupe_Utils::get_indexed_post_types(), 'post_type_exists' ) );
	}

	/**
	 * Register the embedding consumer (`init` 15: after post types, before the ai plugin's sync at 20).
	 */
	public function register_consumer(): void {
		if ( Settings::has_model() ) {
			$this->ai->register_consumer( Settings::target(), $this->post_types() );
		}
	}

	/**
	 * First save of the settings.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 */
	public function on_settings_added( $option, $value ): void {
		$this->on_settings_updated( Settings::defaults(), $value );
	}

	/**
	 * Remember a model change; it is applied on the next request, once the consumer
	 * is registered with the new target.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 */
	public function on_settings_updated( $old, $new ): void {
		$old = wp_parse_args( is_array( $old ) ? $old : [], Settings::defaults() );
		$new = wp_parse_args( is_array( $new ) ? $new : [], Settings::defaults() );
		$key = static fn ( array $s ): string => $s['provider'] . '|' . $s['model'] . '|' . (int) $s['dimensions'];
		if ( $key( $old ) === $key( $new ) || '' === $new['provider'] || '' === $new['model'] ) {
			return;
		}
		update_option( self::TARGET_CHANGE_OPTION, [ 'provider' => (string) $old['provider'], 'model' => (string) $old['model'] ], false );
	}

	/**
	 * Start the backfill for a new model and prune the old model's vectors (`init` 30).
	 */
	public function apply_target_change(): void {
		$change = get_option( self::TARGET_CHANGE_OPTION );
		if ( ! is_array( $change ) || ! $this->ai->is_registered() ) {
			return;
		}
		delete_option( self::TARGET_CHANGE_OPTION );
		$this->ai->start_backfill();

		$target = Settings::target();
		$old_p  = (string) ( $change['provider'] ?? '' );
		$old_m  = (string) ( $change['model'] ?? '' );
		// A dimensions-only change keeps the model; the ai plugin re-embeds on hash mismatch.
		if ( '' !== $old_p && '' !== $old_m && ( $old_p !== $target['provider'] || $old_m !== $target['model'] ) && ! $this->ai->other_consumer_uses( $old_p, $old_m ) ) {
			$this->ai->prune( $old_p, $old_m );
		}
	}

	/**
	 * Setup notices for administrators.
	 */
	public function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = '';
		if ( ! $this->has_loupe() ) {
			$message = __( 'Loupe Semantic Search needs Loupe Search 1.5.0 or later.', 'loupe-semantic-search' );
		} elseif ( ! $this->ai->is_available() ) {
			$message = sprintf(
				/* translators: %s: Connectors screen URL. */
				__( 'Loupe Semantic Search needs the AI plugin (1.5.0 or later) and a provider that offers embeddings. <a href="%s">Connect a provider</a>.', 'loupe-semantic-search' ),
				esc_url( admin_url( 'options-connectors.php' ) )
			);
		} elseif ( ! Settings::has_model() ) {
			$screen = get_current_screen();
			if ( $screen && in_array( $screen->id, [ 'plugins', 'dashboard' ], true ) ) {
				$message = sprintf(
					/* translators: %s: settings page URL. */
					__( 'Loupe Semantic Search is inactive until you <a href="%s">choose an embedding model</a>.', 'loupe-semantic-search' ),
					esc_url( Admin\Settings_Page::url() )
				);
			}
		}

		if ( '' !== $message ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', wp_kses( $message, [ 'a' => [ 'href' => [] ] ] ) );
		}
	}

	/**
	 * Whether the Loupe Search 1.5+ add-on API is available.
	 */
	private function has_loupe(): bool {
		return function_exists( 'loupe_search_is_post_indexable' ) && class_exists( WP_Loupe_Utils::class );
	}
}
