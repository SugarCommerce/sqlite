<?php
/**
 * Schema and Migrator test suite.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Sqlite\Database;
use SugarCommerce\Sqlite\Migrator;
use SugarCommerce\Sqlite\Schema;

final class MigratorTest extends TestCase {

	private const SCHEMA = '
		CREATE TABLE orders ( id INTEGER PRIMARY KEY, email TEXT NOT NULL );
		CREATE INDEX idx_orders_email ON orders(email);
	';

	private function db(): Database {
		return Database::memory( self::SCHEMA );
	}

	/* ─── Schema introspection ──────────────────────────────────────── */

	public function test_tables_are_listed_excluding_internals(): void {
		$tables = $this->db()->schema()->tables();

		$this->assertContains( 'orders', $tables );

		foreach ( $tables as $table ) {
			$this->assertStringStartsNotWith( 'sqlite_', $table );
		}
	}

	public function test_table_and_column_existence(): void {
		$schema = $this->db()->schema();

		$this->assertTrue( $schema->has_table( 'orders' ) );
		$this->assertFalse( $schema->has_table( 'nope' ) );
		$this->assertTrue( $schema->has_column( 'orders', 'email' ) );
		$this->assertFalse( $schema->has_column( 'orders', 'metadata' ) );
		$this->assertFalse( $schema->has_column( 'nope', 'anything' ) );
	}

	public function test_columns_are_returned_in_order(): void {
		$this->assertSame( array( 'id', 'email' ), $this->db()->schema()->columns( 'orders' ) );
	}

	public function test_missing_tables_report_no_columns(): void {
		$this->assertSame( array(), $this->db()->schema()->columns( 'nope' ) );
	}

	public function test_indexes_are_listed(): void {
		$schema = $this->db()->schema();

		$this->assertContains( 'idx_orders_email', $schema->indexes( 'orders' ) );
		$this->assertTrue( $schema->has_index( 'idx_orders_email' ) );
		$this->assertFalse( $schema->has_index( 'idx_nope' ) );
	}

	/* ─── Idempotent column addition ────────────────────────────────── */

	public function test_add_column_is_idempotent(): void {
		$schema = $this->db()->schema();

		$this->assertTrue( $schema->add_column( 'orders', 'metadata', "TEXT NOT NULL DEFAULT '{}'" ) );
		$this->assertFalse( $schema->add_column( 'orders', 'metadata', "TEXT NOT NULL DEFAULT '{}'" ) );
		$this->assertTrue( $schema->has_column( 'orders', 'metadata' ) );
	}

	public function test_added_columns_are_immediately_visible(): void {
		$db     = $this->db();
		$schema = $db->schema();

		$schema->add_column( 'orders', 'total', 'INTEGER NOT NULL DEFAULT 0' );
		$db->insert( 'orders', array( 'email' => 'a@b.com', 'total' => 500 ) );

		$this->assertSame( 1, $db->count( 'orders', array( 'total' => 500 ) ) );
	}

	/* ─── Migrations ────────────────────────────────────────────────── */

	public function test_migrations_run_once(): void {
		$db    = $this->db();
		$runs  = 0;
		$build = function ( Database $db ) use ( &$runs ): Migrator {
			return ( new Migrator( $db ) )->add( 'add_metadata', function ( Database $db, Schema $schema ) use ( &$runs ): void {
				$runs++;
				$schema->add_column( 'orders', 'metadata', "TEXT NOT NULL DEFAULT '{}'" );
			} );
		};

		$this->assertSame( array( 'add_metadata' ), $build( $db )->run() );
		$this->assertSame( array(), $build( $db )->run() );
		$this->assertSame( 1, $runs );
	}

	public function test_migrations_run_in_registration_order(): void {
		$order = array();

		$applied = ( new Migrator( $this->db() ) )
			->add( 'first', static function () use ( &$order ): void {
				$order[] = 'first';
			} )
			->add( 'second', static function () use ( &$order ): void {
				$order[] = 'second';
			} )
			->run();

		$this->assertSame( array( 'first', 'second' ), $order );
		$this->assertSame( array( 'first', 'second' ), $applied );
	}

	public function test_a_failing_migration_rolls_back_and_is_not_recorded(): void {
		$db       = $this->db();
		$migrator = ( new Migrator( $db ) )->add( 'bad', static function ( Database $db, Schema $schema ): void {
			$schema->add_column( 'orders', 'half_done', 'INTEGER NOT NULL DEFAULT 0' );

			throw new \RuntimeException( 'boom' );
		} );

		try {
			$migrator->run();
			$this->fail( 'Expected the migration to throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'bad', $e->getMessage() );
		}

		$this->assertSame( array(), $migrator->applied() );
		$this->assertSame( array( 'bad' ), $migrator->pending() );
	}

	public function test_pending_reports_outstanding_migrations(): void {
		$db       = $this->db();
		$migrator = ( new Migrator( $db ) )
			->add( 'a', static fn() => null )
			->add( 'b', static fn() => null );

		$this->assertSame( array( 'a', 'b' ), $migrator->pending() );

		$migrator->run();

		$this->assertSame( array(), $migrator->pending() );
		$this->assertSame( array( 'a', 'b' ), $migrator->applied() );
	}

	public function test_duplicate_names_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Migrator( $this->db() ) )
			->add( 'same', static fn() => null )
			->add( 'same', static fn() => null );
	}

	public function test_an_empty_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Migrator( $this->db() ) )->add( '  ', static fn() => null );
	}

	public function test_baseline_marks_migrations_done_without_running_them(): void {
		$db  = $this->db();
		$ran = false;

		$migrator = ( new Migrator( $db ) )->add( 'already_applied', static function () use ( &$ran ): void {
			$ran = true;
		} );

		$migrator->baseline( 'already_applied' );

		$this->assertSame( array(), $migrator->run() );
		$this->assertFalse( $ran );
	}

	public function test_baseline_is_idempotent(): void {
		$migrator = new Migrator( $this->db() );

		$migrator->baseline( 'x' );
		$migrator->baseline( 'x' );

		$this->assertSame( array( 'x' ), $migrator->applied() );
	}

	public function test_the_tracking_table_name_is_configurable(): void {
		$db = $this->db();

		( new Migrator( $db, 'schema_versions' ) )->add( 'a', static fn() => null )->run();

		$this->assertTrue( $db->schema()->has_table( 'schema_versions' ) );
	}

	/**
	 * Two deploys running together: by the time this runner holds the lock,
	 * the other has applied the migration. It is skipped, not run again and
	 * failed on its record.
	 */
	public function test_a_migration_another_runner_applied_meanwhile_is_skipped(): void {
		$db  = $this->db();
		$ran = 0;

		$migrator = ( new Migrator( $db ) )
			->add( 'first', static function ( Database $db ): void {
				$db->insert( 'migrations', array( 'name' => 'second' ) );
			} )
			->add( 'second', static function () use ( &$ran ): void {
				++$ran;
			} );

		$this->assertSame( array( 'first' ), $migrator->run() );
		$this->assertSame( 0, $ran );
		$this->assertSame( array(), $migrator->pending() );
	}

	/**
	 * `_` is a LIKE wildcard: `sqlite_%` unescaped hides `sqlitebackup` too.
	 */
	public function test_a_table_whose_name_merely_starts_sqlite_is_listed(): void {
		$db = $this->db();
		$db->pdo()->exec( 'CREATE TABLE sqlitebackup ( id INTEGER PRIMARY KEY )' );

		$this->assertContains( 'sqlitebackup', $db->schema()->tables() );
	}

	public function test_an_unsafe_tracking_table_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Migrator( $this->db(), 'bad; DROP TABLE orders' );
	}
}
