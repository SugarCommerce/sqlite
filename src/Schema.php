<?php
/**
 * Schema introspection.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

/**
 * Class Schema
 *
 * Wraps the `PRAGMA table_info` / `sqlite_master` incantations that
 * migrations otherwise repeat by hand.
 *
 * Results are cached for the life of the object, since a migration run
 * asks the same questions repeatedly. Call {@see self::forget()} after
 * changing the schema through anything but this object.
 *
 * @since 1.0.0
 */
final class Schema {

	/**
	 * Memoised `PRAGMA table_info` results, keyed by table.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $cache = array();

	/**
	 * @since 1.0.0
	 *
	 * @param Database $db Connection to inspect.
	 */
	public function __construct( private readonly Database $db ) {}

	/**
	 * Every table name, excluding SQLite's internal ones.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function tables(): array {
		return array_map(
			'strval',
			$this->db->column(
				"SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
			)
		);
	}

	/**
	 * Whether a table exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 */
	public function has_table( string $table ): bool {
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
			array( 'name' => $table ),
			0
		) > 0;
	}

	/**
	 * Column names for a table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table Table name.
	 *
	 * @return string[] Empty when the table does not exist.
	 */
	public function columns( string $table ): array {
		return array_map( 'strval', array_column( $this->table_info( $table ), 'name' ) );
	}

	/**
	 * Whether a column exists.
	 *
	 * The check migrations need most — it is what makes an
	 * "add this column" migration safe to re-run.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 *
	 * @return bool
	 */
	public function has_column( string $table, string $column ): bool {
		return in_array( $column, $this->columns( $table ), true );
	}

	/**
	 * Full column definitions for a table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, array<string, mixed>> `PRAGMA table_info` rows.
	 */
	public function table_info( string $table ): array {
		if ( ! isset( $this->cache[ $table ] ) ) {
			if ( ! $this->has_table( $table ) ) {
				return array();
			}

			$this->cache[ $table ] = $this->db->all( 'PRAGMA table_info(' . Database::identifier( $table ) . ')' );
		}

		return $this->cache[ $table ];
	}

	/**
	 * Index names on a table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table Table name.
	 *
	 * @return string[]
	 */
	public function indexes( string $table ): array {
		return array_map(
			'strval',
			$this->db->column(
				"SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = :table AND name NOT LIKE 'sqlite_%'",
				array( 'table' => $table )
			)
		);
	}

	/**
	 * Whether an index exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Index name.
	 *
	 * @return bool
	 */
	public function has_index( string $name ): bool {
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :name",
			array( 'name' => $name ),
			0
		) > 0;
	}

	/**
	 * Add a column, unless it is already there.
	 *
	 * The building block of an idempotent migration.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table      Table name.
	 * @param string $column     Column name.
	 * @param string $definition SQL type and constraints, e.g.
	 *                           `TEXT NOT NULL DEFAULT ''`.
	 *
	 * @return bool True when the column was added, false when it existed.
	 */
	public function add_column( string $table, string $column, string $definition ): bool {
		if ( $this->has_column( $table, $column ) ) {
			return false;
		}

		$this->db->pdo()->exec(
			'ALTER TABLE ' . Database::identifier( $table )
			. ' ADD COLUMN ' . Database::identifier( $column ) . ' ' . $definition
		);

		$this->forget( $table );

		return true;
	}

	/**
	 * Drop memoised results.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $table Table to forget, or null for everything.
	 *
	 * @return void
	 */
	public function forget( ?string $table = null ): void {
		if ( null === $table ) {
			$this->cache = array();

			return;
		}

		unset( $this->cache[ $table ] );
	}
}
