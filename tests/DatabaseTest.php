<?php
/**
 * Database test suite.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Sqlite\Config;
use SugarCommerce\Sqlite\Database;

final class DatabaseTest extends TestCase {

	private const SCHEMA = '
		CREATE TABLE products (
			id     INTEGER PRIMARY KEY AUTOINCREMENT,
			title  TEXT NOT NULL,
			stock  INTEGER NOT NULL DEFAULT 0,
			price  INTEGER NOT NULL DEFAULT 0,
			active INTEGER NOT NULL DEFAULT 1,
			note   TEXT
		);
	';

	private function db(): Database {
		return Database::memory( self::SCHEMA );
	}

	private function seeded(): Database {
		$db = $this->db();

		foreach ( array( 1, 3, 5, 8, 20, 100 ) as $stock ) {
			$db->insert( 'products', array( 'title' => 'p' . $stock, 'stock' => $stock ) );
		}

		return $db;
	}

	/* ─── Typed binding — the affinity bug ──────────────────────────── */

	/**
	 * `PDOStatement::execute( $array )` binds every value as a string.
	 *
	 * Against a plain column that is usually survivable, because the
	 * column's INTEGER affinity coerces the text back to a number before
	 * comparing. Against an *expression* there is no affinity to apply,
	 * and SQLite's type ordering puts every integer below every string —
	 * so `SUM(stock) <= '5'` is true for every row, whatever the total.
	 *
	 * That is a silent wrong answer, not an error, and it is the exact
	 * shape of bug this class exists to prevent. Demonstrated here
	 * against raw PDO so the test proves the problem is real.
	 */
	public function test_raw_pdo_string_binding_corrupts_expression_comparisons(): void {
		$db   = $this->seeded();
		$stmt = $db->pdo()->prepare(
			'SELECT COUNT(*) FROM ( SELECT SUM(stock) AS total FROM products GROUP BY id HAVING total <= :n )'
		);

		$stmt->execute( array( ':n' => 5 ) );   // bound as TEXT

		$this->assertSame( 6, (int) $stmt->fetchColumn(), 'Precondition: untyped binding matches every row.' );
	}

	public function test_typed_binding_fixes_expression_comparisons(): void {
		$this->assertSame(
			3,
			(int) $this->seeded()->value(
				'SELECT COUNT(*) FROM ( SELECT SUM(stock) AS total FROM products GROUP BY id HAVING total <= :n )',
				array( 'n' => 5 )
			)
		);
	}

	public function test_column_affinity_masks_the_bug_on_plain_columns(): void {
		// Documents why this is so easy to miss: the same mistake against
		// a plain column gives the right answer, so it only surfaces once
		// someone wraps the column in SUM(), COALESCE() or CAST().
		$db   = $this->seeded();
		$stmt = $db->pdo()->prepare( 'SELECT COUNT(*) FROM products WHERE stock <= :n' );
		$stmt->execute( array( ':n' => 5 ) );

		$this->assertSame( 3, (int) $stmt->fetchColumn() );
	}

	public function test_booleans_bind_as_integers(): void {
		$db = $this->db();
		$db->insert( 'products', array( 'title' => 'on', 'active' => true ) );
		$db->insert( 'products', array( 'title' => 'off', 'active' => false ) );

		$this->assertSame( 1, $db->count( 'products', array( 'active' => true ) ) );
		$this->assertSame( 1, $db->count( 'products', array( 'active' => false ) ) );
	}

	public function test_nulls_bind_as_null(): void {
		$db = $this->db();
		$db->insert( 'products', array( 'title' => 'x', 'note' => null ) );

		$this->assertNull( $db->row( 'SELECT note FROM products' )['note'] );
	}

	public function test_unbindable_values_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->db()->query( 'SELECT :v', array( 'v' => array( 1, 2 ) ) );
	}

	public function test_placeholders_work_with_or_without_a_leading_colon(): void {
		$db = $this->seeded();

		$this->assertSame(
			$db->value( 'SELECT COUNT(*) FROM products WHERE stock <= :n', array( 'n' => 5 ) ),
			$db->value( 'SELECT COUNT(*) FROM products WHERE stock <= :n', array( ':n' => 5 ) )
		);
	}

	/* ─── Reads ─────────────────────────────────────────────────────── */

	public function test_read_helpers(): void {
		$db = $this->seeded();

		$this->assertCount( 6, $db->all( 'SELECT * FROM products' ) );
		$this->assertSame( 'p1', $db->row( 'SELECT * FROM products ORDER BY id' )['title'] );
		$this->assertSame( 6, (int) $db->value( 'SELECT COUNT(*) FROM products' ) );
		$this->assertCount( 6, $db->column( 'SELECT title FROM products' ) );
	}

	public function test_missing_rows_return_null_and_defaults(): void {
		$db = $this->db();

		$this->assertNull( $db->row( 'SELECT * FROM products' ) );
		$this->assertSame( 'none', $db->value( 'SELECT title FROM products', array(), 'none' ) );
		$this->assertSame( array(), $db->all( 'SELECT * FROM products' ) );
	}

	/* ─── Writes ────────────────────────────────────────────────────── */

	public function test_insert_returns_the_row_id(): void {
		$db = $this->db();

		$this->assertSame( 1, $db->insert( 'products', array( 'title' => 'a' ) ) );
		$this->assertSame( 2, $db->insert( 'products', array( 'title' => 'b' ) ) );
	}

	public function test_update_affects_only_matching_rows(): void {
		$db = $this->seeded();

		$this->assertSame( 1, $db->update( 'products', array( 'title' => 'renamed' ), array( 'stock' => 20 ) ) );
		$this->assertSame( 1, $db->count( 'products', array( 'title' => 'renamed' ) ) );
	}

	public function test_delete_affects_only_matching_rows(): void {
		$db = $this->seeded();

		$this->assertSame( 1, $db->delete( 'products', array( 'stock' => 100 ) ) );
		$this->assertSame( 5, $db->count( 'products' ) );
	}

	public function test_count_and_exists(): void {
		$db = $this->seeded();

		$this->assertSame( 6, $db->count( 'products' ) );
		$this->assertSame( 1, $db->count( 'products', array( 'stock' => 8 ) ) );
		$this->assertTrue( $db->exists( 'products', array( 'stock' => 8 ) ) );
		$this->assertFalse( $db->exists( 'products', array( 'stock' => 9999 ) ) );
	}

	public function test_a_null_condition_becomes_is_null(): void {
		$db = $this->db();
		$db->insert( 'products', array( 'title' => 'a', 'note' => null ) );
		$db->insert( 'products', array( 'title' => 'b', 'note' => 'x' ) );

		$this->assertSame( 1, $db->count( 'products', array( 'note' => null ) ) );
	}

	/* ─── Guardrails ────────────────────────────────────────────────── */

	public function test_update_without_a_where_clause_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->seeded()->update( 'products', array( 'title' => 'x' ), array() );
	}

	public function test_delete_without_a_where_clause_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->seeded()->delete( 'products', array() );
	}

	public function test_an_empty_insert_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->db()->insert( 'products', array() );
	}

	#[DataProvider( 'unsafe_identifiers' )]
	public function test_unsafe_identifiers_are_refused( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		Database::identifier( $name );
	}

	/** @return array<string, array{0: string}> */
	public static function unsafe_identifiers(): array {
		return array(
			'injection'  => array( 'products; DROP TABLE products' ),
			'quote'      => array( 'pro"ducts' ),
			'backtick'   => array( 'pro`ducts' ),
			'space'      => array( 'my table' ),
			'empty'      => array( '' ),
			'leading digit' => array( '1table' ),
			'hyphen'     => array( 'my-table' ),
			'trailing newline' => array( "products\n" ),
		);
	}

	public function test_injection_through_a_table_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->db()->count( 'products; DROP TABLE products' );
	}

	public function test_valid_identifiers_are_quoted(): void {
		$this->assertSame( '"products"', Database::identifier( 'products' ) );
		$this->assertSame( '"_private"', Database::identifier( '_private' ) );
	}

	/* ─── Transactions ──────────────────────────────────────────────── */

	public function test_a_transaction_commits_on_success(): void {
		$db = $this->db();

		$result = $db->transaction( static function ( Database $db ): string {
			$db->insert( 'products', array( 'title' => 'a' ) );
			$db->insert( 'products', array( 'title' => 'b' ) );

			return 'done';
		} );

		$this->assertSame( 'done', $result );
		$this->assertSame( 2, $db->count( 'products' ) );
	}

	public function test_a_transaction_rolls_back_on_failure(): void {
		$db = $this->db();

		try {
			$db->transaction( static function ( Database $db ): void {
				$db->insert( 'products', array( 'title' => 'a' ) );

				throw new \RuntimeException( 'boom' );
			} );
		} catch ( \RuntimeException ) {
			// expected
		}

		$this->assertSame( 0, $db->count( 'products' ) );
		$this->assertFalse( $db->pdo()->inTransaction() );
	}

	public function test_the_original_exception_propagates(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'boom' );

		$this->db()->transaction( static function (): void {
			throw new \RuntimeException( 'boom' );
		} );
	}

	/**
	 * A nested call must join the outer transaction, not start a second
	 * one — SQLite has no nested transactions, and committing the inner
	 * would end the outer early.
	 */
	public function test_nested_transactions_join_the_outer_one(): void {
		$db = $this->db();

		try {
			$db->transaction( static function ( Database $db ): void {
				$db->insert( 'products', array( 'title' => 'outer' ) );

				$db->transaction( static function ( Database $db ): void {
					$db->insert( 'products', array( 'title' => 'inner' ) );
				} );

				throw new \RuntimeException( 'roll it all back' );
			} );
		} catch ( \RuntimeException ) {
			// expected
		}

		$this->assertSame( 0, $db->count( 'products' ), 'The inner insert must roll back with the outer.' );
	}

	public function test_a_read_transaction_returns_its_value(): void {
		$db = $this->seeded();

		$this->assertSame( 6, $db->read_transaction( static fn( Database $db ): int => $db->count( 'products' ) ) );
	}

	public function test_a_read_transaction_rolls_back_and_rethrows(): void {
		$db = $this->db();

		try {
			$db->read_transaction( static function (): void {
				throw new \RuntimeException( 'nope' );
			} );
		} catch ( \RuntimeException ) {
			// expected
		}

		$this->assertFalse( $db->pdo()->inTransaction() );
	}

	/* ─── Configuration ─────────────────────────────────────────────── */

	public function test_pragmas_are_applied_to_a_file_database(): void {
		$path = tempnam( sys_get_temp_dir(), 'sqlite-test-' );

		try {
			$db = Database::open( $path );

			$this->assertSame( 'wal', strtolower( (string) $db->value( 'PRAGMA journal_mode' ) ) );
			$this->assertSame( 1, (int) $db->value( 'PRAGMA foreign_keys' ) );
			$this->assertSame( 5000, (int) $db->value( 'PRAGMA busy_timeout' ) );
			$this->assertSame( 1, (int) $db->value( 'PRAGMA synchronous' ), 'NORMAL is 1' );
		} finally {
			foreach ( array( $path, $path . '-wal', $path . '-shm' ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		}
	}

	public function test_durable_config_uses_full_synchronous(): void {
		$this->assertContains( 'PRAGMA synchronous = FULL', Config::durable()->pragmas() );
	}

	public function test_pragma_values_must_be_bare_keywords(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new Config( journal_mode: 'WAL; DROP TABLE x' ) )->pragmas();
	}

	/**
	 * `$` without `D` also matches before a final newline.
	 */
	public function test_a_pragma_value_with_a_trailing_newline_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new Config( journal_mode: "WAL\n" ) )->pragmas();
	}

	public function test_opening_an_unwritable_path_throws(): void {
		$this->expectException( \RuntimeException::class );
		Database::open( '/definitely/not/a/directory/db.sqlite' );
	}

	/* ─── Maintenance ───────────────────────────────────────────────── */

	public function test_a_snapshot_is_a_readable_copy(): void {
		$path = sys_get_temp_dir() . '/snapshot-' . bin2hex( random_bytes( 4 ) ) . '.db';

		$this->seeded()->snapshot( $path );

		try {
			$this->assertSame( 6, Database::open( $path )->count( 'products' ) );
		} finally {
			@unlink( $path );
		}
	}

	/**
	 * A copy of the database is the database: it must not take the umask.
	 */
	public function test_a_snapshot_is_owner_only(): void {
		$path = sys_get_temp_dir() . '/snapshot-' . bin2hex( random_bytes( 4 ) ) . '.db';
		$mask = umask( 0022 );

		try {
			$this->seeded()->snapshot( $path );
			clearstatcache();

			$this->assertSame( 0600, fileperms( $path ) & 0777 );
			$this->assertSame( 6, Database::open( $path )->count( 'products' ) );
		} finally {
			umask( $mask );
			@unlink( $path );
		}
	}

	/**
	 * A symlink planted where the snapshot goes must not be written through.
	 */
	public function test_a_snapshot_will_not_follow_a_symlink(): void {
		$dir    = sys_get_temp_dir() . '/snapshot-link-' . bin2hex( random_bytes( 4 ) );
		$target = $dir . '-elsewhere.db';
		$link   = $dir . '.db';

		symlink( $target, $link );

		try {
			$this->seeded()->snapshot( $link );
			$this->fail( 'A snapshot was written through a symlink.' );
		} catch ( \RuntimeException ) {
			$this->assertFileDoesNotExist( $target );
		} finally {
			@unlink( $link );
			@unlink( $target );
		}
	}

	public function test_a_snapshot_will_not_overwrite(): void {
		$path = (string) tempnam( sys_get_temp_dir(), 'snap' );

		try {
			$this->expectException( \RuntimeException::class );

			$this->seeded()->snapshot( $path );
		} finally {
			@unlink( $path );
		}
	}

	/* ─── Column decoders (1.2.0) ──────────────────────────────────── */

	public function test_a_decoded_column_is_decoded_in_every_row(): void {
		$db = $this->seeded()->decode( 'title', static fn ( mixed $v ): string => strtoupper( (string) $v ) );

		$this->assertSame( 'P1', $db->row( 'SELECT title FROM products WHERE stock = 1' )['title'] );
		$this->assertSame( array( 'P1', 'P3' ), array_column( $db->all( 'SELECT title FROM products WHERE stock < 5 ORDER BY stock' ), 'title' ) );
	}

	public function test_an_alias_to_the_name_is_decoded_too(): void {
		$db = $this->seeded()->decode( 'title', static fn ( mixed $v ): string => '[' . $v . ']' );

		$this->assertSame( '[p1]', $db->row( 'SELECT note AS x, title FROM products WHERE stock = 1' )['title'] );
		$this->assertSame( 'p1', $db->row( 'SELECT title AS name FROM products WHERE stock = 1' )['name'], 'Other names are left alone.' );
	}

	public function test_without_decoders_rows_are_untouched(): void {
		$this->assertSame( 'p1', $this->seeded()->row( 'SELECT title FROM products WHERE stock = 1' )['title'] );
	}

	public function test_integrity_check_passes_on_a_healthy_database(): void {
		$this->assertTrue( $this->seeded()->integrity_check() );
	}

	public function test_optimize_runs_without_error(): void {
		$this->seeded()->optimize();

		$this->assertTrue( true );
	}

	public function test_an_unknown_checkpoint_mode_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->db()->checkpoint( 'SOMETHING' );
	}

	/* ─── Insert or ignore (1.3.0) ──────────────────────────────────── */

	private const UNIQUE_SCHEMA = '
		CREATE TABLE notices (
			id         INTEGER PRIMARY KEY AUTOINCREMENT,
			dedupe_key TEXT NOT NULL UNIQUE,
			body       TEXT NOT NULL,
			sent       INTEGER NOT NULL DEFAULT 0
		);
		CREATE TABLE policies (
			id       INTEGER PRIMARY KEY AUTOINCREMENT,
			product  TEXT NOT NULL,
			price    TEXT,
			seats    INTEGER NOT NULL,
			attempts INTEGER NOT NULL DEFAULT 0,
			error    TEXT
		);
		CREATE UNIQUE INDEX policies_key ON policies ( product, ifnull( price, \'\' ) );
	';

	public function test_the_first_insert_wins_and_the_second_is_skipped(): void {
		$db = Database::memory( self::UNIQUE_SCHEMA );

		$this->assertTrue( $db->insert_or_ignore( 'notices', array( 'dedupe_key' => 'k', 'body' => 'first' ) ) );
		$this->assertFalse( $db->insert_or_ignore( 'notices', array( 'dedupe_key' => 'k', 'body' => 'second' ) ) );
		$this->assertSame( 'first', $db->value( 'SELECT body FROM notices' ) );
	}

	/**
	 * INSERT OR IGNORE would skip this too, and a null where none belongs
	 * would look exactly like a duplicate.
	 */
	public function test_ignoring_a_duplicate_does_not_ignore_a_broken_row(): void {
		$db = Database::memory( self::UNIQUE_SCHEMA );

		$this->expectException( \PDOException::class );

		$db->insert_or_ignore( 'notices', array( 'dedupe_key' => 'k', 'body' => null ) );
	}

	/* ─── Upsert (1.3.0) ────────────────────────────────────────────── */

	public function test_an_upsert_inserts_then_updates_the_same_row(): void {
		$db = Database::memory( self::UNIQUE_SCHEMA );

		$this->assertSame( 1, $db->upsert( 'notices', array( 'dedupe_key' => 'k', 'body' => 'one' ), array( 'dedupe_key' ) ) );
		$this->assertSame( 1, $db->upsert( 'notices', array( 'dedupe_key' => 'k', 'body' => 'two' ), array( 'dedupe_key' ) ) );

		$this->assertSame( array( array( 'id' => 1, 'body' => 'two' ) ), $db->all( 'SELECT id, body FROM notices' ) );
	}

	public function test_an_upsert_can_set_values_of_its_own_on_update(): void {
		$db = Database::memory( self::UNIQUE_SCHEMA );

		$db->insert( 'notices', array( 'dedupe_key' => 'k', 'body' => 'one', 'sent' => 1 ) );
		$db->upsert( 'notices', array( 'dedupe_key' => 'k', 'body' => 'two' ), array( 'dedupe_key' ), array( 'body', 'sent' => 0 ) );

		$this->assertSame( array( 'body' => 'two', 'sent' => 0 ), $db->row( 'SELECT body, sent FROM notices' ) );
	}

	/**
	 * A nullable column in a unique key needs an index on an expression,
	 * and the conflict target has to repeat it.
	 */
	public function test_an_upsert_takes_an_expression_as_its_target(): void {
		$db     = Database::memory( self::UNIQUE_SCHEMA );
		$target = "product, ifnull( price, '' )";

		$db->upsert( 'policies', array( 'product' => 'p', 'price' => null, 'seats' => 1 ), $target, array( 'seats' ) );
		$db->upsert( 'policies', array( 'product' => 'p', 'price' => null, 'seats' => 5, 'attempts' => 3 ), $target, array( 'seats', 'error' => null ) );
		$db->upsert( 'policies', array( 'product' => 'p', 'price' => 'price_1', 'seats' => 2 ), $target, array( 'seats' ) );

		$this->assertSame(
			array( array( 'price' => null, 'seats' => 5, 'attempts' => 0 ), array( 'price' => 'price_1', 'seats' => 2, 'attempts' => 0 ) ),
			$db->all( 'SELECT price, seats, attempts FROM policies ORDER BY id' )
		);
	}

	public function test_an_expression_target_must_name_what_to_update(): void {
		$this->expectException( \InvalidArgumentException::class );

		Database::memory( self::UNIQUE_SCHEMA )->upsert( 'policies', array( 'product' => 'p', 'seats' => 1 ), "product, ifnull( price, '' )" );
	}

	public function test_an_upsert_with_nothing_to_update_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Database::memory( self::UNIQUE_SCHEMA )->upsert( 'notices', array( 'dedupe_key' => 'k' ), array( 'dedupe_key' ) );
	}

	/**
	 * A column called `upsert_seats` owns the `:upsert_seats` placeholder,
	 * so an update value for `seats` cannot quietly share it.
	 */
	public function test_an_upsert_value_cannot_share_a_columns_placeholder(): void {
		$db = Database::memory( 'CREATE TABLE t ( k TEXT PRIMARY KEY, seats INTEGER, upsert_seats INTEGER )' );

		try {
			$db->upsert( 't', array( 'k' => 'a', 'seats' => 1, 'upsert_seats' => 7 ), array( 'k' ), array( 'seats' => 0 ) );
			$this->fail( 'The update value was bound into the column.' );
		} catch ( \InvalidArgumentException ) {
			$this->assertSame( 0, $db->count( 't' ) );
		}
	}

	public function test_an_upsert_validates_its_column_names(): void {
		$this->expectException( \InvalidArgumentException::class );

		Database::memory( self::UNIQUE_SCHEMA )->upsert( 'notices', array( 'dedupe_key' => 'k', 'body' => 'x' ), array( 'dedupe_key' ), array( 'body = 1; --' ) );
	}

	/* ─── Unique violations (1.3.0) ─────────────────────────────────── */

	public function test_a_duplicate_is_recognised(): void {
		$db = Database::memory( self::UNIQUE_SCHEMA );
		$db->insert( 'notices', array( 'dedupe_key' => 'k', 'body' => 'x' ) );

		try {
			$db->insert( 'notices', array( 'dedupe_key' => 'k', 'body' => 'y' ) );
			$this->fail( 'Expected the unique index to refuse it.' );
		} catch ( \PDOException $e ) {
			$this->assertTrue( Database::is_unique_violation( $e ) );
		}
	}

	public function test_a_duplicate_primary_key_is_recognised(): void {
		$db = Database::memory( self::UNIQUE_SCHEMA );
		$db->insert( 'notices', array( 'id' => 7, 'dedupe_key' => 'a', 'body' => 'x' ) );

		try {
			$db->insert( 'notices', array( 'id' => 7, 'dedupe_key' => 'b', 'body' => 'y' ) );
			$this->fail( 'Expected the primary key to refuse it.' );
		} catch ( \PDOException $e ) {
			$this->assertTrue( Database::is_unique_violation( $e ) );
		}
	}

	/**
	 * A NOT NULL failure is a constraint too, and is not "already there".
	 */
	public function test_other_failures_are_not_duplicates(): void {
		$db = Database::memory( self::UNIQUE_SCHEMA );

		try {
			$db->insert( 'notices', array( 'dedupe_key' => 'k', 'body' => null ) );
			$this->fail( 'Expected NOT NULL to refuse it.' );
		} catch ( \PDOException $e ) {
			$this->assertFalse( Database::is_unique_violation( $e ) );
		}

		$this->assertFalse( Database::is_unique_violation( new \RuntimeException( 'UNIQUE constraint failed: x' ) ) );
	}

	/* ─── Lists (1.3.0) ─────────────────────────────────────────────── */

	public function test_a_list_gets_a_placeholder_per_value(): void {
		[ $in, $params ] = Database::in( 'p', array( 3 => 'a', 7 => 'b' ) );

		$this->assertSame( ':p0, :p1', $in );
		$this->assertSame( array( 'p0' => 'a', 'p1' => 'b' ), $params );
	}

	public function test_a_list_binds_by_type(): void {
		[ $in, $params ] = Database::in( 's', array( 3, 5 ) );

		$this->assertSame( 2, (int) $this->seeded()->value( "SELECT COUNT(*) FROM products WHERE stock IN ( {$in} )", $params ) );
	}

	/**
	 * SQLite accepts an empty list: nothing is in it, and everything is
	 * not.
	 */
	public function test_an_empty_list_matches_nothing(): void {
		[ $in, $params ] = Database::in( 'p', array() );

		$this->assertSame( 0, (int) $this->seeded()->value( "SELECT COUNT(*) FROM products WHERE title IN ( {$in} )", $params ) );
		$this->assertSame( 6, (int) $this->seeded()->value( "SELECT COUNT(*) FROM products WHERE title NOT IN ( {$in} )", $params ) );
		$this->assertSame( 0, (int) $this->seeded()->value( 'SELECT COUNT(*) FROM products WHERE id IN ( ' . Database::ids( array() ) . ' )' ) );
	}

	public function test_a_placeholder_prefix_must_be_a_name(): void {
		$this->expectException( \InvalidArgumentException::class );

		Database::in( 'p) OR (1', array( 1 ) );
	}

	/**
	 * Whatever is passed, only digits reach the SQL.
	 */
	public function test_ids_are_integers_and_nothing_else(): void {
		$this->assertSame( '1,2,30', Database::ids( array( 1, '2', '30', 2 ) ) );
		$this->assertSame( '1,5', Database::ids( array( '1) OR (1=1', 5 ) ) );
		$this->assertSame( '0', Database::ids( array( 'x' ) ) );
		$this->assertSame( 2, (int) $this->seeded()->value( 'SELECT COUNT(*) FROM products WHERE id IN ( ' . Database::ids( array( 1, 2 ) ) . ' )' ) );
	}

	/* ─── LIKE patterns (1.5.0) ─────────────────────────────────────── */

	public function test_like_escapes_the_wildcards_and_the_escape(): void {
		$this->assertSame( '%50\%%', Database::like( '50%' ) );
		$this->assertSame( '%a\_b%', Database::like( 'a_b' ) );
		$this->assertSame( '%c:\\\\x%', Database::like( 'c:\\x' ) );
		$this->assertSame( 'ab%', Database::like( 'ab', 'starts' ) );
		$this->assertSame( '%ab', Database::like( 'ab', 'ends' ) );
		$this->assertSame( '%%', Database::like( '' ) );
	}

	public function test_a_typed_wildcard_matches_only_itself(): void {
		$db = $this->db();

		foreach ( array( '50% off', '50 off', 'a_b', 'axb', 'back\\slash', 'backslash', 'Plain' ) as $title ) {
			$db->insert( 'products', array( 'title' => $title ) );
		}

		$find = static fn( string $q, string $position = 'contains' ): array => array_column(
			$db->all( 'SELECT title FROM products WHERE title LIKE :q ' . Database::ESCAPE . ' ORDER BY id', array( 'q' => Database::like( $q, $position ) ) ),
			'title'
		);

		$this->assertSame( array( '50% off' ), $find( '%' ) );
		$this->assertSame( array( '50% off' ), $find( '50%' ) );
		$this->assertSame( array( 'a_b' ), $find( '_' ) );
		$this->assertSame( array( 'a_b' ), $find( 'a_b' ) );
		$this->assertSame( array( 'back\\slash' ), $find( '\\' ) );
		$this->assertSame( array( 'Plain' ), $find( 'plain' ), 'still case-insensitive for ASCII' );
		$this->assertSame( array( '50% off', '50 off' ), $find( '50', 'starts' ) );
		$this->assertSame( array( '50% off', '50 off' ), $find( 'off', 'ends' ) );
		$this->assertCount( 7, $find( '' ) );
	}

	public function test_an_unknown_like_position_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Database::like( 'x', 'middle' );
	}

	/* ─── Sweep (1.3.0) ─────────────────────────────────────────────── */

	public function test_a_dry_sweep_counts_and_changes_nothing(): void {
		$db = $this->seeded();

		$this->assertSame( 3, $db->sweep( 'products', 'stock < :n', array( 'n' => 6 ), dry: true ) );
		$this->assertSame( 6, $db->count( 'products' ) );
	}

	public function test_a_sweep_deletes_what_its_dry_run_counted(): void {
		$db = $this->seeded();

		$this->assertSame( 3, $db->sweep( 'products', 'stock < :n', array( 'n' => 6 ) ) );
		$this->assertSame( 3, $db->count( 'products' ) );
	}

	public function test_a_sweep_can_update_instead(): void {
		$db = $this->seeded();

		$this->assertSame( 2, $db->sweep( 'products', 'stock >= :n', array( 'n' => 20 ), 'active = 0' ) );
		$this->assertSame( 2, $db->count( 'products', array( 'active' => 0 ) ) );
	}

	public function test_a_sweep_needs_a_condition(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->seeded()->sweep( 'products', ' ' );
	}

	/* ─── SQL files (1.3.0) ─────────────────────────────────────────── */

	public function test_every_statement_in_a_file_runs(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'schema' );
		file_put_contents( $file, self::UNIQUE_SCHEMA );

		$db = Database::memory();
		$db->exec_file( $file );
		unlink( $file );

		$this->assertTrue( $db->schema()->has_table( 'notices' ) );
		$this->assertTrue( $db->schema()->has_table( 'policies' ) );
	}

	public function test_a_missing_file_is_an_error_not_an_empty_schema(): void {
		$this->expectException( \RuntimeException::class );

		Database::memory()->exec_file( sys_get_temp_dir() . '/no-such-schema-' . bin2hex( random_bytes( 4 ) ) . '.sql' );
	}
}
