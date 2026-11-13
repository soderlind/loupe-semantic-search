<?php
declare( strict_types = 1 );

namespace Soderlind\Plugin\LoupeSemanticSearch\Tests;

use Brain\Monkey\Functions;
use Soderlind\Plugin\LoupeSemanticSearch\Settings;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
	}

	public function test_model_survives_the_second_sanitize_pass_on_first_save(): void {
		// update_option() on a missing option sanitizes, then add_option() sanitizes the result again.
		$first  = Settings::sanitize( [ 'target' => 'openai::text-embedding-3-small' ] );
		$second = Settings::sanitize( $first );

		$this->assertSame( 'openai', $second['provider'] );
		$this->assertSame( 'text-embedding-3-small', $second['model'] );
	}

	public function test_empty_target_clears_the_model(): void {
		$this->settings( [ 'provider' => 'openai', 'model' => 'text-embedding-3-small' ] );

		$out = Settings::sanitize( [ 'target' => '' ] );

		$this->assertSame( '', $out['provider'] );
		$this->assertSame( '', $out['model'] );
	}
}
