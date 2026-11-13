<?php
/**
 * Vector store contract.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the posts whose chunks are most similar to a query vector.
 *
 * Replace the default store with the `loupe_semantic_vector_store` filter.
 */
interface Vector_Store {

	/**
	 * Nearest posts, best chunk per post, highest similarity first.
	 *
	 * @param list<float>                                             $query_vector   Query embedding.
	 * @param array{provider:string,model:string,dimensions:int|null} $target         Embedding target.
	 * @param int                                                     $k              Maximum posts.
	 * @param list<string>                                            $post_types     Allowed post types; empty for all.
	 * @param float                                                   $min_similarity Drop chunks scoring below this.
	 * @return list<array{post_id:int,post_type:string,chunk_index:int,score:float,hash:string}>
	 */
	public function search( array $query_vector, array $target, int $k, array $post_types, float $min_similarity ): array;
}
