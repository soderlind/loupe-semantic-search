<?php
namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Brain Monkey with in-memory options and transients.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/** @var array<string,mixed> */
	protected array $options = [];

	/** @var array<string,mixed> */
	protected array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = [];
		$this->transients = [];

		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn ( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
		Functions\when( 'current_time' )->alias( static fn ( $format ) => gmdate( $format ) );
		Functions\when( 'current_user_can' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Save settings over the defaults.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	protected function settings( array $settings ): void {
		$this->options['loupe_semantic_settings'] = $settings;
	}
}
