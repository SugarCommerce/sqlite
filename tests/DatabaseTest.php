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

	public function test_opening_an_unwritable_path_throws(): void {
		$this->expectException( \RuntimeException::class );
		Database::open( '/definitely/not/a/directory/db.sqlite' );
	}

	/* ─── Maintenance ───────────────────────────────────────────────── */

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
}
