<?php
/**
 * Cache test suite.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Sqlite\{Cache, Database};

final class CacheTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function cache(): Cache {
		return new Cache( Database::memory( Cache::SCHEMA ) );
	}

	public function test_a_value_is_fresh_until_its_time_is_up(): void {
		$cache = $this->cache();

		$cache->put( 'k', 'v', 60, self::NOW );

		$this->assertSame( 'v', $cache->get( 'k', self::NOW + 59 ) );
		$this->assertNull( $cache->get( 'k', self::NOW + 60 ) );
		$this->assertSame( 'v', $cache->stale( 'k' ), 'Expired is not gone.' );
	}

	public function test_putting_again_replaces(): void {
		$cache = $this->cache();

		$cache->put( 'k', 'one', 60, self::NOW );
		$cache->put( 'k', 'two', 60, self::NOW );

		$this->assertSame( 'two', $cache->get( 'k', self::NOW ) );
	}

	public function test_a_missing_key_is_null(): void {
		$this->assertNull( $this->cache()->get( 'nope' ) );
		$this->assertNull( $this->cache()->stale( 'nope' ) );
	}

	public function test_remember_fetches_once_while_fresh(): void {
		$cache   = $this->cache();
		$fetches = 0;
		$fetch   = function () use ( &$fetches ): string {
			return 'fetched ' . ++$fetches;
		};

		$this->assertSame( 'fetched 1', $cache->remember( 'k', 60, $fetch, self::NOW ) );
		$this->assertSame( 'fetched 1', $cache->remember( 'k', 60, $fetch, self::NOW + 30 ) );
		$this->assertSame( 'fetched 2', $cache->remember( 'k', 60, $fetch, self::NOW + 61 ) );
	}

	/**
	 * A source that could not be asked is not a source with nothing in it.
	 */
	public function test_a_failed_fetch_falls_back_to_the_stale_value_and_is_not_stored(): void {
		$cache = $this->cache();

		$cache->put( 'k', 'last week', 60, self::NOW );

		$this->assertSame( 'last week', $cache->remember( 'k', 60, fn (): ?string => null, self::NOW + 3600 ) );
		$this->assertNull( $cache->get( 'k', self::NOW + 3600 ), 'The failure did not refresh it.' );
	}

	public function test_a_failed_fetch_with_nothing_stored_is_null(): void {
		$this->assertNull( $this->cache()->remember( 'k', 60, fn (): ?string => null ) );
	}

	public function test_forget_makes_the_next_read_fetch(): void {
		$cache = $this->cache();

		$cache->put( 'k', 'old', 60, self::NOW );
		$cache->forget( 'k' );

		$this->assertSame( 'new', $cache->remember( 'k', 60, fn (): string => 'new', self::NOW ) );
	}

	public function test_purge_removes_only_the_expired(): void {
		$cache = $this->cache();

		$cache->put( 'old', 'x', 10, self::NOW - 100 );
		$cache->put( 'new', 'y', 10, self::NOW );

		$this->assertSame( 1, $cache->purge( self::NOW, dry: true ) );
		$this->assertSame( 'x', $cache->stale( 'old' ), 'A dry run changes nothing.' );
		$this->assertSame( 1, $cache->purge( self::NOW ) );
		$this->assertNull( $cache->stale( 'old' ) );
		$this->assertSame( 'y', $cache->stale( 'new' ) );
	}

	public function test_another_table_can_be_used(): void {
		$db    = Database::memory( str_replace( 'cache (', 'page_cache (', Cache::SCHEMA ) );
		$cache = new Cache( $db, 'page_cache' );

		$cache->put( 'k', 'v', 60 );

		$this->assertSame( 'v', $cache->get( 'k' ) );
		$this->assertSame( 1, $db->count( 'page_cache' ) );
	}

	public function test_an_unsafe_table_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Cache( Database::memory(), 'cache; DROP TABLE x' ) )->get( 'k' );
	}

	/* ─── Add: first caller wins (1.3.0) ────────────────────────────── */

	/**
	 * A replay guard: the second request with the same signature loses.
	 */
	public function test_only_the_first_add_stores(): void {
		$cache = $this->cache();

		$this->assertTrue( $cache->add( 'sig', '1', 300, self::NOW ) );
		$this->assertFalse( $cache->add( 'sig', '2', 300, self::NOW + 10 ) );
		$this->assertSame( '1', $cache->get( 'sig', self::NOW + 10 ) );
	}

	public function test_an_add_does_not_take_over_a_value_put_there(): void {
		$cache = $this->cache();

		$cache->put( 'k', 'v', 60, self::NOW );

		$this->assertFalse( $cache->add( 'k', 'w', 60, self::NOW ) );
	}

	/**
	 * Once a key's time is up it can be taken again, without waiting for
	 * housekeeping to run.
	 */
	public function test_an_expired_entry_does_not_block_an_add(): void {
		$cache = $this->cache();

		$this->assertTrue( $cache->add( 'k', 'old', 60, self::NOW ) );

		$this->assertFalse( $cache->add( 'k', 'new', 60, self::NOW + 60 ), 'At its expiry it still counts, as it does for purge().' );
		$this->assertTrue( $cache->add( 'k', 'new', 60, self::NOW + 61 ) );
		$this->assertSame( 'new', $cache->get( 'k', self::NOW + 61 ) );
	}

	/* ─── Expire: stale, not gone (1.3.0) ───────────────────────────── */

	/**
	 * forget() during an upstream outage leaves nothing to fall back on.
	 */
	public function test_an_expired_value_is_fetched_again_and_kept_as_a_fallback(): void {
		$cache = $this->cache();

		$cache->put( 'k', 'last', 3600, self::NOW );

		$this->assertTrue( $cache->expire( 'k', self::NOW ) );
		$this->assertNull( $cache->get( 'k', self::NOW ) );
		$this->assertSame( 'last', $cache->remember( 'k', 60, fn (): ?string => null, self::NOW ) );
		$this->assertSame( 'fresh', $cache->remember( 'k', 60, fn (): string => 'fresh', self::NOW ) );
	}

	public function test_expiring_a_missing_key_says_so(): void {
		$this->assertFalse( $this->cache()->expire( 'nope', self::NOW ) );
	}

	/**
	 * Expiring something already expired does not give it longer to live.
	 */
	public function test_expiring_never_extends(): void {
		$cache = $this->cache();

		$cache->put( 'k', 'v', 10, self::NOW - 100 );
		$cache->expire( 'k', self::NOW );

		$this->assertSame( 1, $cache->purge( self::NOW ) );
	}
}
