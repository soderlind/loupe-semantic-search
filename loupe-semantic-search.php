<?php
/**
 * Loupe Semantic Search
 *
 * @package   Soderlind\Plugin\LoupeSemanticSearch
 * @author    Per Soderlind
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Loupe Semantic Search
 * Plugin URI:        https://github.com/soderlind/loupe-semantic-search
 * Description:       Adds semantic retrieval and hybrid (keyword + meaning) search to Loupe Search, using embeddings from a provider connected under Settings → Connectors.
 * Version:           0.1.2
 * Author:            Per Soderlind
 * Author URI:        https://soderlind.no
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       loupe-semantic-search
 * Domain Path:       /languages
 * Requires at least: 7.0.3
 * Requires PHP:      8.3
 * Requires Plugins:  loupe-search, ai
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.2';
const FILE    = __FILE__;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// After Loupe Search (plugins_loaded, 10), so its classes are available.
add_action( 'plugins_loaded', static fn () => Plugin::instance()->boot(), 20 );
