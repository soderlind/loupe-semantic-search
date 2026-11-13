<?php
/**
 * WP-CLI command.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch\CLI;

use Soderlind\Plugin\LoupeSemanticSearch\Plugin;
use Soderlind\Plugin\LoupeSemanticSearch\Settings;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and test Loupe Semantic Search.
 *
 * ## EXAMPLES
 *
 *     $ wp loupe-semantic status
 *     $ wp loupe-semantic query "how do I reset my password" --limit=3
 *     $ wp loupe-semantic backfill --reset
 */
class Command {

	/**
	 * Show the settings, the embedding model and index coverage.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$status = Plugin::instance()->ai()->status();

		if ( 'table' !== $format ) {
			WP_CLI::print_value( [ 'settings' => Settings::all(), 'sync' => $status ], [ 'format' => $format ] );
			return;
		}

		$target = Settings::target();
		WP_CLI::log( sprintf( 'Model:       %s', Settings::has_model() ? $target['provider'] . ' / ' . $target['model'] . ( $target['dimensions'] ? " ({$target['dimensions']} dims)" : '' ) : '(none)' ) );
		WP_CLI::log( sprintf( 'Hybrid:      front-end %s, REST %s; abilities %s', self::on( 'hybrid_frontend' ), self::on( 'hybrid_rest' ), self::on( 'abilities' ) ) );
		WP_CLI::log( sprintf( 'Used today:  %d query embeddings (cap %d)', Plugin::instance()->limiter()->used_today(), (int) Settings::get( 'daily_cap' ) ) );

		if ( null === $status ) {
			WP_CLI::warning( 'The embedding consumer is not registered. Choose a model in Settings → Loupe Search → Semantic search.' );
			return;
		}

		WP_CLI::log( sprintf( 'Backfill:    %s', $status['backfill']['status'] ?? 'not started' ) );
		WP_CLI::log( sprintf( 'Queue:       %d pending, %d failed', (int) $status['queue']['pending'], (int) $status['queue']['failed'] ) );
		if ( $status['provider_error'] ) {
			WP_CLI::warning( 'Provider error: ' . $status['provider_error'] );
		}

		$rows = [];
		foreach ( (array) ( $status['coverage']['post'] ?? [] ) as $subtype => $row ) {
			$rows[] = [ 'post_type' => $subtype, 'embedded' => (int) $row['indexed'], 'indexable' => (int) $row['indexable'] ];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'post_type', 'embedded', 'indexable' ] );
	}

	/**
	 * Retrieve the passages closest to a query.
	 *
	 * ## OPTIONS
	 *
	 * <text>
	 * : The query.
	 *
	 * [--limit=<n>]
	 * : Number of passages.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Default: all public indexed post types.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function query( $args, $assoc_args ): void {
		$types = isset( $assoc_args['post_type'] )
			? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['post_type'] ) ) )
			: \Soderlind\Plugin\LoupeSearch\WP_Loupe_Utils::get_public_indexed_post_types();

		$result = Plugin::instance()->retriever()->passages( (string) $args[0], max( 1, (int) ( $assoc_args['limit'] ?? 5 ) ), array_values( $types ), true );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = $result['passages'];
		if ( 'table' === $format ) {
			$rows = array_map(
				static function ( array $p ): array {
					$p['text'] = mb_strimwidth( (string) preg_replace( '/\s+/u', ' ', $p['text'] ), 0, 80, '…' );
					return $p;
				},
				$rows
			);
		}
		WP_CLI\Utils\format_items( $format, $rows, [ 'post_id', 'score', 'title', 'chunk_index', 'stale', 'text' ] );
	}

	/**
	 * Start embedding all content for the selected model.
	 *
	 * Unchanged posts already embedded are skipped. Progress runs in the background;
	 * use `wp ai embeddings sync run` to process the queue now.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Forget progress and start from the beginning.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function backfill( $args, $assoc_args ): void {
		$ai = Plugin::instance()->ai();
		if ( ! $ai->is_registered() ) {
			WP_CLI::error( 'The embedding consumer is not registered. Choose a model in Settings → Loupe Search → Semantic search.' );
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'reset', false ) ) {
			$ai->reset_backfill();
		}
		if ( ! $ai->start_backfill() ) {
			WP_CLI::error( 'Could not start the backfill.' );
		}
		WP_CLI::success( 'Backfill started.' );
	}

	/**
	 * "on" or "off" for a setting.
	 *
	 * @param string $key Setting key.
	 */
	private static function on( string $key ): string {
		return Settings::get( $key ) ? 'on' : 'off';
	}
}
