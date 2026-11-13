<?php
/**
 * Default vector store: a scan of the AI plugin's embeddings table.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Computes cosine similarity against every stored chunk of the target model.
 *
 * The table has no vector index, so this is a full scan in batches. It is fast
 * enough up to roughly 20,000 chunks.
 */
class Ai_Repository_Vector_Store implements Vector_Store {

	/**
	 * Constructor.
	 *
	 * @param Ai_Adapter $ai AI plugin adapter.
	 */
	public function __construct( private Ai_Adapter $ai ) {}

	/**
	 * {@inheritDoc}
	 */
	public function search( array $query_vector, array $target, int $k, array $post_types, float $min_similarity ): array {
		$query_norm = self::norm( $query_vector );
		if ( $k < 1 || 0.0 === $query_norm ) {
			return [];
		}

		$allowed = empty( $post_types ) ? null : array_flip( $post_types );
		$best    = [];

		foreach ( $this->ai->iterate_vectors( $target['provider'], $target['model'] ) as $row ) {
			if ( null !== $allowed && ! isset( $allowed[ $row['post_type'] ] ) ) {
				continue;
			}
			$score = self::cosine( $query_vector, $query_norm, $row['vector'] );
			if ( $score < $min_similarity ) {
				continue;
			}
			$id = $row['post_id'];
			if ( ! isset( $best[ $id ] ) || $score > $best[ $id ]['score'] ) {
				$best[ $id ] = [
					'post_id'     => $id,
					'post_type'   => $row['post_type'],
					'chunk_index' => $row['chunk_index'],
					'score'       => $score,
					'hash'        => $row['hash'],
				];
			}
		}

		usort( $best, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );
		return array_slice( $best, 0, $k );
	}

	/**
	 * Cosine similarity with a precomputed query norm. Returns 0 on a dimension mismatch.
	 *
	 * @param list<float> $query      Query vector.
	 * @param float       $query_norm Its norm.
	 * @param list<float> $vector     Stored vector.
	 */
	public static function cosine( array $query, float $query_norm, array $vector ): float {
		$count = count( $query );
		if ( count( $vector ) !== $count ) {
			return 0.0;
		}
		$dot  = 0.0;
		$norm = 0.0;
		for ( $i = 0; $i < $count; $i++ ) {
			$dot  += $query[ $i ] * $vector[ $i ];
			$norm += $vector[ $i ] * $vector[ $i ];
		}
		return $norm > 0.0 ? $dot / ( $query_norm * sqrt( $norm ) ) : 0.0;
	}

	/**
	 * Euclidean norm.
	 *
	 * @param list<float> $vector Vector.
	 */
	private static function norm( array $vector ): float {
		$sum = 0.0;
		foreach ( $vector as $value ) {
			$sum += $value * $value;
		}
		return sqrt( $sum );
	}
}
