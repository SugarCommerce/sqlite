<?php
/**
 * Backups test suite.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Sqlite\{Backups, Database};

final class BackupsTest extends TestCase {

	private const NOW = 1_800_000_000;

	private string $dir;

	private Database $db;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sqlite-backups-' . bin2hex( random_bytes( 4 ) );
		$this->db  = Database::open( $this->dir . '-source.db' );

		$this->db->pdo()->exec( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, v TEXT )' );
		$this->db->insert( 't', array( 'v' => 'kept' ) );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}

		@rmdir( $this->dir );

		foreach ( glob( $this->dir . '-source.db*' ) ?: array() as $file ) {
			unlink( $file );
		}
	}

	/* ─── Taking ────────────────────────────────────────────────────── */

	public function test_a_copy_is_a_readable_database_named_by_its_time(): void {
		$path = ( new Backups( $this->db, $this->dir, 'app' ) )->take( self::NOW );

		$this->assertSame( $this->dir . '/app-' . gmdate( 'Ymd-His', self::NOW ) . '.db', $path );
		$this->assertSame( 'kept', Database::open( $path )->value( 'SELECT v FROM t' ) );
	}

	/**
	 * A copy of the database is the database.
	 */
	public function test_the_directory_is_created_owner_only(): void {
		( new Backups( $this->db, $this->dir ) )->take( self::NOW );

		$this->assertSame( 0700, fileperms( $this->dir ) & 0777 );
	}

	/* ─── Pruning ───────────────────────────────────────────────────── */

	public function test_pruning_keeps_the_newest(): void {
		$backups = new Backups( $this->db, $this->dir, 'app', keep: 2 );

		foreach ( array( 0, 60, 120, 180 ) as $offset ) {
			$backups->take( self::NOW + $offset );
		}

		$this->assertSame( 2, $backups->prune() );
		$this->assertSame(
			array( $this->dir . '/app-' . gmdate( 'Ymd-His', self::NOW + 180 ) . '.db', $this->dir . '/app-' . gmdate( 'Ymd-His', self::NOW + 120 ) . '.db' ),
			$backups->copies()
		);
	}

	/**
	 * By name, not by modification time: touching an old copy to look at
	 * it does not make a newer one the one that goes.
	 */
	public function test_pruning_goes_by_name_not_by_modification_time(): void {
		$backups = new Backups( $this->db, $this->dir, 'app', keep: 1 );
		$old     = $backups->take( self::NOW );
		$new     = $backups->take( self::NOW + 60 );

		touch( $old, time() + 3600 );
		$backups->prune();

		$this->assertSame( array( $new ), $backups->copies() );
	}

	public function test_other_files_in_the_directory_are_left_alone(): void {
		$backups = new Backups( $this->db, $this->dir, 'app', keep: 1 );
		$backups->take( self::NOW );
		$backups->take( self::NOW + 60 );
		file_put_contents( $this->dir . '/other-20200101-000000.db', 'x' );

		$backups->prune();

		$this->assertFileExists( $this->dir . '/other-20200101-000000.db' );
	}

	/* ─── Newest ────────────────────────────────────────────────────── */

	public function test_with_no_copies_there_is_no_newest(): void {
		$this->assertNull( ( new Backups( $this->db, $this->dir ) )->newest() );
	}

	public function test_the_newest_copy_is_reported(): void {
		$backups = new Backups( $this->db, $this->dir );
		$backups->take( self::NOW );

		$this->assertEqualsWithDelta( time(), $backups->newest(), 5 );
	}

	/* ─── Refusals ──────────────────────────────────────────────────── */

	public function test_a_prefix_that_could_escape_the_directory_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Backups( $this->db, $this->dir, '../app' );
	}

	public function test_keeping_none_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Backups( $this->db, $this->dir, keep: 0 );
	}
}
