<?php
/**
 * Operator-tunable settings in a SQLite table.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.7.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

/**
 * Class Settings
 *
 * The values an operator changes from an admin page rather than over SSH —
 * a rate limit, a site name, a retention period. Secrets and bootstrap
 * configuration stay in the environment; these are the rest.
 *
 * The code holds every default, and a setting that is absent or blank
 * falls back to it. So a fresh install with an empty table behaves exactly
 * as documented, and clearing a field in the admin form restores the
 * default instead of storing an empty value that means "zero" to one reader
 * and "unset" to another.
 *
 * Read once per instance: the first read loads the whole table, which is a
 * handful of rows; set() and forget() drop the copy. A database without the
 * table reads as empty, so a page can render before its migration has run.
 *
 * The table:
 *
 *   CREATE TABLE settings (
 *       key        TEXT PRIMARY KEY,
 *       value      TEXT NOT NULL,
 *       updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   );
 *
 * ```php
 * $settings = new Settings( $db );
 *
 * $limit = $settings->int( 'lookups_per_hour', 20 );
 * $name  = $settings->string( 'site_name', 'Shop' );
 * $settings->set( 'site_name', 'Shop & Co' );
 * ```
 *
 * @since 1.7.0
 */
final class Settings {

	/**
	 * Every row, key => value, once read.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $values = null;

	/**
	 * The quoted table name.
	 */
	private readonly string $table;

	/**
	 * The quoted column that records when a row last changed.
	 */
	private readonly string $updated;

	/**
	 * What the updated column is set to: SQL for the current time.
	 */
	private readonly string $now;

	/**
	 * @since 1.7.0
	 * @since 1.8.0 $updated_column and $unix_time.
	 *
	 * @param Database $db             Where the table is.
	 * @param string   $table          Its name.
	 * @param string   $updated_column The column recording when a row changed.
	 * @param bool     $unix_time      Store that as Unix seconds (an INTEGER
	 *                                 column) rather than 'Y-m-d H:i:s' UTC.
	 *
	 * @throws \InvalidArgumentException On a table or column name that is not
	 *                                   a bare identifier.
	 */
	public function __construct( private readonly Database $db, string $table = 'settings', string $updated_column = 'updated_at', bool $unix_time = false ) {
		$this->table   = Database::identifier( $table );
		$this->updated = Database::identifier( $updated_column );
		$this->now     = $unix_time ? "CAST( strftime( '%s', 'now' ) AS INTEGER )" : "datetime( 'now' )";
	}

	/**
	 * A setting as text, or the default when it is absent or blank.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key     Setting name.
	 * @param string $default What the code uses when nobody has set it.
	 *
	 * @return string
	 */
	public function string( string $key, string $default = '' ): string {
		$value = $this->values()[ $key ] ?? '';

		return '' === $value ? $default : $value;
	}

	/**
	 * A setting as an integer, or the default when it is absent, blank or
	 * not a whole number — a value nobody can read as a number is not one,
	 * rather than (int)'s silent 0 or first digits.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key     Setting name.
	 * @param int    $default What the code uses when nobody has set it.
	 *
	 * @return int
	 */
	public function int( string $key, int $default ): int {
		$value = trim( $this->values()[ $key ] ?? '' );

		if ( 1 !== preg_match( '/^[+-]?\d{1,19}$/D', $value ) ) {
			return $default;
		}

		$int = filter_var( $value, FILTER_VALIDATE_INT );

		return false === $int ? $default : $int;
	}

	/**
	 * A setting as a yes/no: `1`, `true`, `yes`, `on` or `0`, `false`, `no`,
	 * `off` (any case); the default for absent, blank or anything else.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key     Setting name.
	 * @param bool   $default What the code uses when nobody has set it.
	 *
	 * @return bool
	 */
	public function bool( string $key, bool $default ): bool {
		return match ( strtolower( trim( $this->values()[ $key ] ?? '' ) ) ) {
			'1', 'true', 'yes', 'on'  => true,
			'0', 'false', 'no', 'off' => false,
			default                   => $default,
		};
	}

	/**
	 * Whether a setting has a stored, non-blank value.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return bool
	 */
	public function has( string $key ): bool {
		return '' !== ( $this->values()[ $key ] ?? '' );
	}

	/**
	 * Every stored setting with when it last changed, for an admin page.
	 * Read fresh, not from the cached copy. `updated_at` is the updated
	 * column's value, whatever the column is called.
	 *
	 * @since 1.7.0
	 *
	 * @return array<string, array{value: string, updated_at: string}>
	 */
	public function all(): array {
		$all = array();

		foreach ( $this->rows( 'SELECT key, value, ' . $this->updated . ' AS updated_at FROM ' . $this->table ) as $row ) {
			$all[ self::text( $row['key'] ?? '' ) ] = array(
				'value'      => self::text( $row['value'] ?? '' ),
				'updated_at' => self::text( $row['updated_at'] ?? '' ),
			);
		}

		return $all;
	}

	/**
	 * Store a value. A blank one is stored as given — use forget() to fall
	 * back to the default.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key   Setting name.
	 * @param string $value New value.
	 *
	 * @return void
	 */
	public function set( string $key, string $value ): void {
		$this->db->query(
			'INSERT INTO ' . $this->table . ' ( key, value, ' . $this->updated . ' ) VALUES ( :key, :value, ' . $this->now . ' )
			 ON CONFLICT ( key ) DO UPDATE SET value = excluded.value, ' . $this->updated . ' = excluded.' . $this->updated,
			array(
				'key'   => $key,
				'value' => $value,
			)
		);

		$this->values = null;
	}

	/**
	 * Remove a setting, so its default applies again.
	 *
	 * @since 1.7.0
	 * @since 1.8.0 Returns whether a row was removed.
	 *
	 * @param string $key Setting name.
	 *
	 * @return bool
	 */
	public function forget( string $key ): bool {
		$removed = $this->db->query( 'DELETE FROM ' . $this->table . ' WHERE key = :key', array( 'key' => $key ) )->rowCount() > 0;

		$this->values = null;

		return $removed;
	}

	/**
	 * Raise a counter, never lower it.
	 *
	 * For a value that must only go forward even when two requests race —
	 * the last one-time-code slot used, a sessions epoch: stored when the
	 * setting is absent or holds a smaller integer, in one statement, so a
	 * slower request can never write an older value over a newer one. True
	 * when this call stored it.
	 *
	 * @since 1.8.0
	 *
	 * @param string $key   Setting name.
	 * @param int    $value The new value.
	 *
	 * @return bool
	 */
	public function advance( string $key, int $value ): bool {
		$stored = 1 === $this->db->query(
			'INSERT INTO ' . $this->table . ' ( key, value, ' . $this->updated . ' ) VALUES ( :key, :value, ' . $this->now . ' )
			 ON CONFLICT ( key ) DO UPDATE SET value = excluded.value, ' . $this->updated . ' = excluded.' . $this->updated . '
			 WHERE CAST( ' . $this->table . '.value AS INTEGER ) < CAST( excluded.value AS INTEGER )',
			array(
				'key'   => $key,
				'value' => (string) $value,
			)
		)->rowCount();

		$this->values = null;

		return $stored;
	}

	/**
	 * Drop the cached copy, so the next read sees what another process (or
	 * a rolled-back transaction) left.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function refresh(): void {
		$this->values = null;
	}

	/**
	 * Every value, loaded on first use.
	 *
	 * @since 1.7.0
	 *
	 * @return array<string, string>
	 */
	private function values(): array {
		if ( null === $this->values ) {
			$this->values = array();

			foreach ( $this->rows( 'SELECT key, value FROM ' . $this->table ) as $row ) {
				$this->values[ self::text( $row['key'] ?? '' ) ] = self::text( $row['value'] ?? '' );
			}
		}

		return $this->values;
	}

	/**
	 * Rows of a query, or none when the table does not exist yet.
	 *
	 * @since 1.7.0
	 *
	 * @param string $sql Query.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows( string $sql ): array {
		try {
			return array_values( $this->db->all( $sql ) );
		} catch ( \PDOException $e ) {
			if ( str_contains( $e->getMessage(), 'no such table' ) ) {
				return array();
			}

			throw $e;
		}
	}

	/**
	 * A column value as text: '' for NULL or anything that is not a scalar.
	 *
	 * @since 1.7.0
	 *
	 * @param mixed $value Column value.
	 *
	 * @return string
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
