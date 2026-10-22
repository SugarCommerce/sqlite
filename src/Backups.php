<?php
/**
 * Dated snapshots of a database, and how many of them to keep.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

/**
 * Class Backups
 *
 * A directory of snapshots taken with {@see Database::snapshot()}, named by
 * the time they were taken so that the name is the order, and pruned to the
 * newest few.
 *
 * Three things a hand-rolled version gets wrong:
 *
 * - **Copying the file.** `cp` of a live WAL database can catch the file
 *   and its `-wal` at different moments. A snapshot is SQLite's own
 *   consistent copy.
 * - **Pruning by modification time.** A copy restored or touched for a
 *   look jumps to the front and something newer is deleted. The name is
 *   the time it was taken, and it does not change.
 * - **A world-readable directory.** A copy of the database is the
 *   database. The directory is created owner-only.
 *
 * Getting a copy off the box is the application's business: take() returns
 * the path, and what happens to the file next is up to the caller.
 *
 *   $backups = new Backups( $db, '/var/backups/app', 'app', keep: 14 );
 *   $path    = $backups->take();
 *   $removed = $backups->prune();
 *
 * @since 1.3.0
 */
final readonly class Backups {

	/**
	 * Point at a directory of copies.
	 *
	 * @since 1.3.0
	 *
	 * @param Database $db     Connection to copy.
	 * @param string   $dir    Where copies go. Created when missing.
	 * @param string   $prefix Start of every copy's name, so the directory
	 *                         can hold other files.
	 * @param int      $keep   Copies kept by prune(), newest first.
	 *
	 * @throws \InvalidArgumentException On a prefix that could reach
	 *                                   outside the directory or match
	 *                                   other files, or keeping none.
	 */
	public function __construct(
		private Database $db,
		private string $dir,
		private string $prefix = 'backup',
		public int $keep = 14,
	) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $prefix ) ) {
			throw new \InvalidArgumentException( 'A backup prefix is letters, digits, _ and - only: ' . $prefix );
		}

		if ( $keep < 1 ) {
			throw new \InvalidArgumentException( 'Keep at least one backup.' );
		}
	}

	/**
	 * Take a copy now.
	 *
	 * @since 1.3.0
	 *
	 * @param int|null $now Current time. For tests.
	 *
	 * @return string The copy's path.
	 *
	 * @throws \RuntimeException When a copy with this second's name exists.
	 */
	public function take( ?int $now = null ): string {
		if ( ! is_dir( $this->dir ) ) {
			@mkdir( $this->dir, 0700, true );
		}

		$path = $this->dir . '/' . $this->prefix . '-' . gmdate( 'Ymd-His', $now ?? time() ) . '.db';

		$this->db->snapshot( $path );

		return $path;
	}

	/**
	 * Remove all but the newest copies.
	 *
	 * @since 1.3.0
	 *
	 * @return int Copies removed.
	 */
	public function prune(): int {
		$removed = 0;

		foreach ( array_slice( $this->copies(), $this->keep ) as $old ) {
			$removed += @unlink( $old ) ? 1 : 0;
		}

		return $removed;
	}

	/**
	 * When the newest copy was written, or null when there is none.
	 *
	 * For a health check: a backup job that has stopped does not fail, it
	 * just stops producing copies, and nothing else will say so.
	 *
	 * @since 1.3.0
	 *
	 * @return int|null Unix time.
	 */
	public function newest(): ?int {
		$copies = $this->copies();

		return $copies ? (int) filemtime( $copies[0] ) : null;
	}

	/**
	 * Every copy, newest first.
	 *
	 * The name sorts by time, so the name is the order.
	 *
	 * Only a name take() could have written counts: the prefix, a dash and
	 * the time. A looser match counts other files as copies — another
	 * Backups with the prefix `app-staging` sharing the directory with
	 * `app`, say — and prune() deletes them. The directory is read rather
	 * than globbed, so a `[` or `*` in its path is not a pattern.
	 *
	 * @since 1.3.0
	 *
	 * @return list<string> Paths.
	 */
	public function copies(): array {
		$names = is_dir( $this->dir ) ? scandir( $this->dir ) : false;
		$name  = '/^' . preg_quote( $this->prefix, '/' ) . '-[0-9]{8}-[0-9]{6}\.db$/D';
		$found = array();

		foreach ( $names ?: array() as $entry ) {
			if ( 1 === preg_match( $name, $entry ) && is_file( $this->dir . '/' . $entry ) ) {
				$found[] = $this->dir . '/' . $entry;
			}
		}

		rsort( $found );

		return $found;
	}
}
