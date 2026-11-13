<?php
/**
 * Uninstall Loupe Semantic Search.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$loupe_semantic_settings = get_option( 'loupe_semantic_settings' );

// Delete our vectors only when no other active consumer embeds with the same model.
if (
	is_array( $loupe_semantic_settings )
	&& ! empty( $loupe_semantic_settings['provider'] )
	&& ! empty( $loupe_semantic_settings['model'] )
	&& class_exists( '\WordPress\AI\Embeddings\Sync\Embedding_Sync' )
) {
	$loupe_semantic_registry = \WordPress\AI\Embeddings\Sync\Embedding_Sync::instance()->get_registry();
	$loupe_semantic_shared   = false;
	foreach ( $loupe_semantic_registry->get_consumer_ids() as $loupe_semantic_id ) {
		$loupe_semantic_consumer = (array) $loupe_semantic_registry->get_consumer( $loupe_semantic_id );
		if (
			'loupe-semantic-search' !== $loupe_semantic_id
			&& ( $loupe_semantic_consumer['provider'] ?? '' ) === $loupe_semantic_settings['provider']
			&& ( $loupe_semantic_consumer['model'] ?? '' ) === $loupe_semantic_settings['model']
		) {
			$loupe_semantic_shared = true;
			break;
		}
	}
	if ( ! $loupe_semantic_shared ) {
		\WordPress\AI\Embeddings\Sync\Embedding_Sync::prune_target( (string) $loupe_semantic_settings['provider'], (string) $loupe_semantic_settings['model'] );
	}
}

delete_option( 'loupe_semantic_settings' );
delete_option( 'loupe_semantic_daily_usage' );
delete_option( 'loupe_semantic_target_change' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_loupe_semantic_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_loupe_semantic_' ) . '%'
	)
);
