<?php
/**
 * Plugin settings.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the `loupe_semantic_settings` option.
 */
class Settings {

	public const OPTION = 'loupe_semantic_settings';

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'provider'        => '',
			'model'           => '',
			'dimensions'      => 0,
			'hybrid_frontend' => true,
			'hybrid_rest'     => true,
			'abilities'       => true,
			'weight_keyword'  => 1.0,
			'weight_semantic' => 1.0,
			'min_similarity'  => 0.35,
			'rate_limit'      => 30,
			'daily_cap'       => 5000,
		];
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Whether an embedding model has been chosen.
	 */
	public static function has_model(): bool {
		$all = self::all();
		return '' !== $all['provider'] && '' !== $all['model'];
	}

	/**
	 * The embedding target: provider, model and dimensions (null for the model default).
	 *
	 * @return array{provider:string,model:string,dimensions:int|null}
	 */
	public static function target(): array {
		$all = self::all();
		return [
			'provider'   => (string) $all['provider'],
			'model'      => (string) $all['model'],
			'dimensions' => (int) $all['dimensions'] > 0 ? (int) $all['dimensions'] : null,
		];
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * The model arrives as one `provider::model` value from the picker.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::defaults();
		$out      = self::all();

		if ( array_key_exists( 'target', $input ) ) {
			$parts           = explode( '::', (string) $input['target'], 2 );
			$out['provider'] = 2 === count( $parts ) ? sanitize_key( $parts[0] ) : '';
			$out['model']    = 2 === count( $parts ) ? sanitize_text_field( $parts[1] ) : '';
		} elseif ( array_key_exists( 'provider', $input ) && array_key_exists( 'model', $input ) ) {
			// Already-sanitized input: add_option() re-runs this callback on the first save.
			$out['provider'] = sanitize_key( (string) $input['provider'] );
			$out['model']    = sanitize_text_field( (string) $input['model'] );
		}
		if ( array_key_exists( 'dimensions', $input ) ) {
			$out['dimensions'] = max( 0, min( 8192, (int) $input['dimensions'] ) );
		}
		foreach ( [ 'hybrid_frontend', 'hybrid_rest', 'abilities' ] as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}
		foreach ( [ 'weight_keyword', 'weight_semantic' ] as $weight ) {
			if ( array_key_exists( $weight, $input ) ) {
				$out[ $weight ] = max( 0.0, min( 10.0, (float) $input[ $weight ] ) );
			}
		}
		if ( array_key_exists( 'min_similarity', $input ) ) {
			$out['min_similarity'] = max( 0.0, min( 1.0, (float) $input['min_similarity'] ) );
		}
		if ( array_key_exists( 'rate_limit', $input ) ) {
			$out['rate_limit'] = max( 0, (int) $input['rate_limit'] );
		}
		if ( array_key_exists( 'daily_cap', $input ) ) {
			$out['daily_cap'] = max( 0, (int) $input['daily_cap'] );
		}

		return array_intersect_key( $out, $defaults );
	}
}
