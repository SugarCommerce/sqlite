<?php
/**
 * Results that must not be ignored.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Sqlite\Cache;

/**
 * Class MustCheckTest
 *
 * These answer "was it mine?", and code that ignores the answer behaves as
 * though it were yes — a replay guard that never looks at whether the
 * signature was already spent guards nothing. #[\NoDiscard] makes PHP warn
 * where a result is dropped; this keeps the attribute from being lost in a
 * refactor.
 */
final class MustCheckTest extends TestCase {

	/**
	 * @return array<string, array{class-string, string}>
	 */
	public static function methods(): array {
		return array(
			'Cache::add' => array( Cache::class, 'add' ),
		);
	}

	#[DataProvider( 'methods' )]
	public function test_the_result_must_be_used( string $class, string $method ): void {
		$attributes = ( new \ReflectionMethod( $class, $method ) )->getAttributes( \NoDiscard::class );

		$this->assertCount( 1, $attributes, $class . '::' . $method . ' lost #[\NoDiscard].' );
		$this->assertNotSame( '', $attributes[0]->newInstance()->message, 'Say why it matters.' );
	}
}
