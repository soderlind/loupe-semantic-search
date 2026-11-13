<?php
/**
 * Reciprocal rank fusion.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Combines two rankings without comparing their scores.
 */
final class Fusion {

	public const K = 60;

	/**
	 * Fuse a keyword and a semantic ranking.
	 *
	 * score = w_keyword / (k + keyword rank) + w_semantic / (k + semantic rank), ranks from 1.
	 * Ties keep keyword order, then semantic order.
	 *
	 * @param list<int> $keyword_ids  Post IDs, best first.
	 * @param list<int> $semantic_ids Post IDs, best first.
	 * @param float     $w_keyword    Keyword weight.
	 * @param float     $w_semantic   Semantic weight.
	 * @param int       $k            Rank constant.
	 * @return array<int,float> Fused scores keyed by post ID, best first.
	 */
	public static function rrf( array $keyword_ids, array $semantic_ids, float $w_keyword = 1.0, float $w_semantic = 1.0, int $k = self::K ): array {
		$scores = [];
		$order  = [];
		foreach ( [ [ $keyword_ids, $w_keyword ], [ $semantic_ids, $w_semantic ] ] as [ $ids, $weight ] ) {
			$rank = 0;
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				++$rank;
				$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + $weight / ( $k + $rank );
				$order[ $id ] ??= count( $order );
			}
			$seen = [];
		}

		uksort(
			$scores,
			static fn ( int $a, int $b ): int => [ $scores[ $b ], $order[ $a ] ] <=> [ $scores[ $a ], $order[ $b ] ]
		);
		return $scores;
	}
}
