<?php
/**
 * Rebuilds the text of a matched chunk.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * The ai plugin stores vectors, not text. The passage is rebuilt by chunking the
 * post's current text the same way. When the post changed since it was embedded
 * (hash mismatch) the chunk boundaries may differ, so a plain excerpt is returned
 * and marked stale.
 */
class Passage_Builder {

	/**
	 * Constructor.
	 *
	 * @param Ai_Adapter $ai AI plugin adapter.
	 */
	public function __construct( private Ai_Adapter $ai ) {}

	/**
	 * Build the passage for a vector match.
	 *
	 * @param int      $post_id     Post ID.
	 * @param int      $chunk_index Matched chunk.
	 * @param string   $hash        Content hash stored with the vector.
	 * @param int|null $dimensions  Target dimensions.
	 * @return array{text:string,stale:bool}
	 */
	public function build( int $post_id, int $chunk_index, string $hash, ?int $dimensions ): array {
		$text = $this->ai->post_text( $post_id );
		if ( '' !== $text && hash_equals( $this->ai->hash_text( $text, $dimensions ), $hash ) ) {
			$chunks = $this->ai->chunks( $text );
			if ( isset( $chunks[ $chunk_index ] ) ) {
				return [ 'text' => $chunks[ $chunk_index ], 'stale' => false ];
			}
		}

		return [ 'text' => self::excerpt( $text ), 'stale' => true ];
	}

	/**
	 * A plain-text excerpt of about the size of a chunk.
	 *
	 * @param string $text Source text.
	 */
	public static function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		/**
		 * Filters the length, in characters, of a stale passage excerpt.
		 *
		 * @param int $length Default 1000.
		 */
		$length = max( 50, (int) apply_filters( 'loupe_semantic_stale_excerpt_length', 1000 ) );
		return mb_strlen( $text ) > $length ? rtrim( mb_substr( $text, 0, $length ) ) . '…' : $text;
	}
}
