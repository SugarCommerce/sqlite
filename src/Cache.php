<?php
/**
 * A key-value cache in a SQLite table.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

/**
 * Class Cache
 *
 * For values fetched from somewhere slow or rate-limited to render a page a
 * stranger asked for — without it, a link doing the rounds is an upstream API
 * call per visitor.
 *
 * Expired entries are kept until purge(), on purpose. remember() falls back
 * to one when the source cannot be reached: last week's answer is usually
 * better than none, and "none" is what visitors would otherwise see every
 * time the source had a bad minute. That is also why a failed fetch is never
 * cached — "could not ask" is not the same answer as "empty".
 *
 * The table:
 *
 *   CREATE TABLE cache (
 *       cache_key  TEXT    PRIMARY KEY,
 *       value      TEXT    NOT NULL,
 *       expires_at INTEGER NOT NULL
 *   );
 *
 * @since 1.1.0
 */
final readonly class Cache {

	/**
	 * The table this class expects, for a schema file or a migration.
	 *
	 * @since 1.1.0
	 */
	public const SCHEMA = 'CREATE TABLE IF NOT EXISTS cache (
	cache_key  TEXT    PRIMARY KEY,
	value      TEXT    NOT NULL,
	expires_at INTEGER NOT NULL
)';

	/**
	 * @since 1.1.0
	 *
	 * @param Database $db    Connection.
	 * @param string   $table Table name, when it is not `cache`.
	 */
	public function __construct(
		private Database $db,
		private string $table = 'cache',
	) {}

	/**
	 * A fresh value, or null.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $key Key.
	 * @param int|null $now Current time. For tests.
	 *
	 * @return string|null
	 */
	public function get( string $key, ?int $now = null ): ?string {
		return self::text( $this->db->value(
			"SELECT value FROM {$this->name()} WHERE cache_key = :k AND expires_at > :now",
			array( 'k' => $key, 'now' => $now ?? time() )
		) );
	}

	/**
	 * A value whatever its age, or null when there has never been one.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key Key.
	 *
	 * @return string|null
	 */
	public function stale( string $key ): ?string {
		return self::text( $this->db->value( "SELECT value FROM {$this->name()} WHERE cache_key = :k", array( 'k' => $key ) ) );
	}

	/**
	 * Store a value for a while.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $key   Key.
	 * @param string   $value Value.
	 * @param int      $ttl   Seconds it stays fresh.
	 * @param int|null $now   Current time. For tests.
	 *
	 * @return void
	 */
	public function put( string $key, string $value, int $ttl, ?int $now = null ): void {
		$this->db->query(
			"INSERT INTO {$this->name()} (cache_key, value, expires_at) VALUES (:k, :v, :e)
			 ON CONFLICT (cache_key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at",
			array( 'k' => $key, 'v' => $value, 'e' => ( $now ?? time() ) + $ttl )
		);
	}

	/**
	 * Read through: the fresh value, or fetch and store a new one, or fall
	 * back to the stale one when the fetch has nothing.
	 *
	 * @since 1.1.0
	 *
	 * @param string                  $key   Key.
	 * @param int                     $ttl   Seconds a fetched value stays fresh.
	 * @param \Closure(): string|null $fetch Fetches the value. Null means the
	 *                                       source could not be asked, and
	 *                                       is not stored.
	 * @param int|null                $now   Current time. For tests.
	 *
	 * @return string|null Null only when the fetch failed and nothing was
	 *                     ever stored.
	 */
	public function remember( string $key, int $ttl, \Closure $fetch, ?int $now = null ): ?string {
		$fresh = $this->get( $key, $now );

		if ( null !== $fresh ) {
			return $fresh;
		}

		$value = $fetch();

		if ( null === $value ) {
			return $this->stale( $key );
		}

		$this->put( $key, $value, $ttl, $now );

		return $value;
	}

	/**
	 * Drop a value, so the next read fetches.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key Key.
	 *
	 * @return void
	 */
	public function forget( string $key ): void {
		$this->db->delete( $this->table, array( 'cache_key' => $key ) );
	}

	/**
	 * Remove expired entries.
	 *
	 * @since 1.1.0
	 *
	 * @param int|null $now Current time. For tests.
	 * @param bool     $dry Count them instead.
	 *
	 * @return int Entries removed, or that would have been.
	 */
	public function purge( ?int $now = null, bool $dry = false ): int {
		$params = array( 'now' => $now ?? time() );

		return $dry
			? (int) $this->db->value( "SELECT COUNT(*) FROM {$this->name()} WHERE expires_at < :now", $params )
			: $this->db->query( "DELETE FROM {$this->name()} WHERE expires_at < :now", $params )->rowCount();
	}

	/**
	 * The table name, quoted.
	 *
	 * @return string
	 */
	private function name(): string {
		return Database::identifier( $this->table );
	}

	/**
	 * A stored value as a string, or null.
	 *
	 * @param mixed $value What the query returned.
	 *
	 * @return string|null
	 */
	private static function text( mixed $value ): ?string {
		return null === $value ? null : (string) $value;
	}
}
