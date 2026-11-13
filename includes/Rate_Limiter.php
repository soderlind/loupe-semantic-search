<?php
/**
 * Limits on query embedding calls.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

/**
 * A per-IP limit per minute and a site-wide daily cap on calls to the provider.
 *
 * Only uncached query embeddings count. Administrators and WP-CLI are exempt from
 * the per-IP limit, never from the daily cap. A limit of 0 means no limit.
 */
class Rate_Limiter {

	public const DAILY_OPTION = 'loupe_semantic_daily_usage';

	/**
	 * Reserve one provider call. Returns false when a limit is reached.
	 */
	public function acquire(): bool {
		$today = current_time( 'Y-m-d' );
		$usage = get_option( self::DAILY_OPTION, [] );
		$count = is_array( $usage ) && ( $usage['date'] ?? '' ) === $today ? (int) ( $usage['count'] ?? 0 ) : 0;

		/**
		 * Filters the site-wide daily cap on query embedding calls. 0 disables the cap.
		 *
		 * @param int $cap Daily cap from the settings.
		 */
		$cap = (int) apply_filters( 'loupe_semantic_daily_cap', (int) Settings::get( 'daily_cap' ) );
		if ( $cap > 0 && $count >= $cap ) {
			return false;
		}

		if ( ! $this->is_exempt() ) {
			/**
			 * Filters the per-IP limit on query embedding calls per minute. 0 disables the limit.
			 *
			 * @param int $limit Limit from the settings.
			 */
			$limit = (int) apply_filters( 'loupe_semantic_rate_limit', (int) Settings::get( 'rate_limit' ) );
			if ( $limit > 0 ) {
				$key  = 'loupe_semantic_rl_' . md5( $this->client_ip() ) . '_' . (int) floor( time() / MINUTE_IN_SECONDS );
				$hits = (int) get_transient( $key );
				if ( $hits >= $limit ) {
					return false;
				}
				set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
			}
		}

		update_option( self::DAILY_OPTION, [ 'date' => $today, 'count' => $count + 1 ], false );
		return true;
	}

	/**
	 * Calls made today.
	 */
	public function used_today(): int {
		$usage = get_option( self::DAILY_OPTION, [] );
		return is_array( $usage ) && ( $usage['date'] ?? '' ) === current_time( 'Y-m-d' ) ? (int) ( $usage['count'] ?? 0 ) : 0;
	}

	/**
	 * Whether the current request skips the per-IP limit.
	 */
	private function is_exempt(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'manage_options' );
	}

	/**
	 * The client IP used for the per-IP limit.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the client IP used for rate limiting, e.g. to read a trusted proxy header.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'loupe_semantic_client_ip', $ip );
	}
}
