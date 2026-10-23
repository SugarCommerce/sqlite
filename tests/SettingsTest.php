<?php
/**
 * Settings tests.
 *
 * @package SugarCommerce\Sqlite\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Sqlite\Database;
use SugarCommerce\Sqlite\Settings;

/**
 * Class SettingsTest
 */
final class SettingsTest extends TestCase {

	private const string SCHEMA = '
		CREATE TABLE settings (
			key        TEXT PRIMARY KEY,
			value      TEXT NOT NULL,
			updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
		);
	';

	private Database $db;

	private Settings $settings;

	protected function setUp(): void {
		$this->db       = Database::memory( self::SCHEMA );
		$this->settings = new Settings( $this->db );
	}

	public function test_absent_or_blank_falls_back_to_the_default(): void {
		$this->settings->set( 'blank', '' );

		$this->assertSame( 'Shop', $this->settings->string( 'missing', 'Shop' ) );
		$this->assertSame( 'Shop', $this->settings->string( 'blank', 'Shop' ) );
		$this->assertSame( 20, $this->settings->int( 'missing', 20 ) );
		$this->assertSame( 20, $this->settings->int( 'blank', 20 ) );
		$this->assertTrue( $this->settings->bool( 'blank', true ) );
		$this->assertFalse( $this->settings->has( 'blank' ) );
	}

	public function test_values_round_trip(): void {
		$this->settings->set( 'site_name', 'Shop & Co' );
		$this->settings->set( 'limit', '35' );
		$this->settings->set( 'open', 'yes' );

		$this->assertSame( 'Shop & Co', $this->settings->string( 'site_name' ) );
		$this->assertSame( 35, $this->settings->int( 'limit', 20 ) );
		$this->assertTrue( $this->settings->bool( 'open', false ) );
		$this->assertTrue( $this->settings->has( 'site_name' ) );
	}

	public function test_an_int_nobody_can_read_as_a_number_is_the_default(): void {
		foreach ( array( '12abc', 'abc', '1.5', '99999999999999999999', ' ' ) as $stored ) {
			$this->settings->set( 'n', $stored );
			$this->assertSame( 7, $this->settings->int( 'n', 7 ), var_export( $stored, true ) );
		}

		$this->settings->set( 'n', ' -3 ' );
		$this->assertSame( -3, $this->settings->int( 'n', 7 ) );
	}

	public function test_bool_reads_the_usual_spellings_only(): void {
		foreach ( array( '1' => true, 'TRUE' => true, 'on' => true, '0' => false, 'No' => false, 'off' => false ) as $stored => $expected ) {
			$this->settings->set( 'b', (string) $stored );
			$this->assertSame( $expected, $this->settings->bool( 'b', ! $expected ), (string) $stored );
		}

		$this->settings->set( 'b', 'maybe' );
		$this->assertTrue( $this->settings->bool( 'b', true ) );
	}

	public function test_set_updates_in_place_and_stamps_the_time(): void {
		$this->settings->set( 'k', 'one' );
		$this->settings->set( 'k', 'two' );

		$all = $this->settings->all();

		$this->assertSame( array( 'k' ), array_keys( $all ) );
		$this->assertSame( 'two', $all['k']['value'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $all['k']['updated_at'] );
	}

	public function test_forget_restores_the_default(): void {
		$this->settings->set( 'k', 'custom' );
		$this->settings->forget( 'k' );

		$this->assertSame( 'default', $this->settings->string( 'k', 'default' ) );
		$this->assertSame( array(), $this->settings->all() );
	}

	public function test_reads_are_cached_until_refresh(): void {
		$this->settings->set( 'k', 'one' );
		$this->assertSame( 'one', $this->settings->string( 'k' ) );

		// Another process changes it underneath.
		$this->db->query( "UPDATE settings SET value = 'two' WHERE key = 'k'" );
		$this->assertSame( 'one', $this->settings->string( 'k' ), 'still the copy read at first use' );

		$this->settings->refresh();
		$this->assertSame( 'two', $this->settings->string( 'k' ) );
	}

	public function test_a_rolled_back_set_is_not_seen_after_refresh(): void {
		try {
			$this->db->transaction( function (): void {
				$this->settings->set( 'k', 'inside' );
				throw new \DomainException( 'refused' );
			} );
		} catch ( \DomainException ) {
			// expected
		}

		$this->assertSame( 'default', $this->settings->string( 'k', 'default' ) );
	}

	public function test_a_missing_table_reads_as_empty(): void {
		$settings = new Settings( Database::memory( 'CREATE TABLE other ( id INTEGER );' ) );

		$this->assertSame( 'd', $settings->string( 'k', 'd' ) );
		$this->assertSame( array(), $settings->all() );
	}

	public function test_another_table_name_works_and_a_bad_one_is_refused(): void {
		$db       = Database::memory( str_replace( 'TABLE settings', 'TABLE app_settings', self::SCHEMA ) );
		$settings = new Settings( $db, 'app_settings' );
		$settings->set( 'k', 'v' );

		$this->assertSame( 'v', $settings->string( 'k' ) );

		$this->expectException( \InvalidArgumentException::class );
		new Settings( $db, 'settings; DROP TABLE x' );
	}

	/* ─── 1.8.0 ─────────────────────────────────────────────────────── */

	public function test_forget_says_whether_there_was_anything(): void {
		$this->settings->set( 'k', 'v' );

		$this->assertTrue( $this->settings->forget( 'k' ) );
		$this->assertFalse( $this->settings->forget( 'k' ) );
	}

	public function test_advance_only_ever_raises(): void {
		$this->assertTrue( $this->settings->advance( 'slot', 10 ), 'absent: stored' );
		$this->assertFalse( $this->settings->advance( 'slot', 9 ), 'lower: refused' );
		$this->assertFalse( $this->settings->advance( 'slot', 10 ), 'equal: refused (a replayed code)' );
		$this->assertTrue( $this->settings->advance( 'slot', 11 ) );
		$this->assertSame( 11, $this->settings->int( 'slot', 0 ), 'and the cached copy follows' );
		$this->assertTrue( $this->settings->advance( 'neg', -5 ) );
		$this->assertTrue( $this->settings->advance( 'neg', -4 ) );
	}

	public function test_another_updated_column_holding_unix_time(): void {
		$db       = Database::memory( 'CREATE TABLE options ( key TEXT PRIMARY KEY, value TEXT NOT NULL, date_updated INTEGER NOT NULL DEFAULT 0 );' );
		$settings = new Settings( $db, 'options', 'date_updated', unix_time: true );
		$before   = time();

		$settings->set( 'site_name', 'Shop' );
		$settings->advance( 'epoch', 3 );

		$this->assertSame( 'Shop', $settings->string( 'site_name' ) );
		$this->assertSame( 3, $settings->int( 'epoch', 0 ) );
		$this->assertGreaterThanOrEqual( $before, (int) $db->value( "SELECT date_updated FROM options WHERE key = 'site_name'" ) );
		$this->assertSame( 'integer', $db->value( "SELECT typeof( date_updated ) FROM options WHERE key = 'epoch'" ) );
		$this->assertMatchesRegularExpression( '/^\d{10}$/', $settings->all()['site_name']['updated_at'] );
	}

	public function test_a_bad_column_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Settings( $this->db, 'settings', 'updated_at; DROP TABLE x' );
	}
}
