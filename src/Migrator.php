<?php
/**
 * Named, once-only schema migrations.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

/**
 * Class Migrator
 *
 * Migrations are registered by name, recorded once applied, and skipped
 * thereafter. Each runs inside its own immediate transaction, so a
 * failure leaves the schema exactly as it was and the migration
 * unrecorded — fix it and run again.
 *
 * Write migration bodies defensively anyway, using {@see Schema}. A
 * tracking table only knows what *this* runner did; it cannot know about
 * a column someone added by hand, or a database restored from a backup
 * taken mid-sequence.
 *
 *   $migrator = new Migrator( $db );
 *
 *   $migrator->add( 'add_orders_metadata', function ( Database $db, Schema $schema ) {
 *       $schema->add_column( 'orders', 'metadata', "TEXT NOT NULL DEFAULT '{}'" );
 *   } );
 *
 *   $applied = $migrator->run();   // ['add_orders_metadata']
 *
 * @since 1.0.0
 */
final class Migrator {

	/**
	 * Registered migrations, in registration order.
	 *
	 * @var array<string, callable(Database, Schema): void>
	 */
	private array $migrations = array();

	/**
	 * Use a connection, creating the record of applied migrations if it is
	 * not there yet.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $db    Connection to migrate.
	 * @param string   $table Where applied migrations are recorded.
	 *
	 * @throws \InvalidArgumentException When the table name is not a bare
	 *                                   identifier.
	 */
	public function __construct(
		private readonly Database $db,
		private readonly string $table = 'migrations',
	) {
		$this->db->pdo()->exec(
			'CREATE TABLE IF NOT EXISTS ' . Database::identifier( $this->table ) . ' ('
			. 'name TEXT PRIMARY KEY NOT NULL, '
			. 'applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
			. ')'
		);
	}

	/**
	 * Register a migration.
	 *
	 * Order of registration is order of execution, so put new migrations
	 * at the end.
	 *
	 * @since 1.0.0
	 *
	 * @param string                           $name Stable identifier.
	 *                                               Renaming one makes it
	 *                                               run again.
	 * @param callable(Database, Schema): void $work Migration body.
	 *
	 * @return self For chaining.
	 *
	 * @throws \InvalidArgumentException On a duplicate or empty name.
	 */
	public function add( string $name, callable $work ): self {
		if ( '' === trim( $name ) ) {
			throw new \InvalidArgumentException( 'A migration needs a name.' );
		}

		if ( isset( $this->migrations[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Duplicate migration name: ' . $name );
		}

		$this->migrations[ $name ] = $work;

		return $this;
	}

	/**
	 * Apply everything outstanding.
	 *
	 * Whether a migration has run is asked again inside its transaction,
	 * once the write lock is held. Two deploys starting together both see
	 * it outstanding; the second waits for the lock, then finds it recorded
	 * and skips it, rather than running the body a second time and failing
	 * on the record.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Names applied, in the order they ran — by this
	 *                      call, not by another runner it raced.
	 *
	 * @throws \RuntimeException When a migration fails, naming it.
	 */
	public function run(): array {
		$applied = array();
		$schema  = $this->db->schema();
		$done    = $this->applied();

		foreach ( $this->migrations as $name => $work ) {
			if ( in_array( $name, $done, true ) ) {
				continue;
			}

			try {
				$ran = $this->db->transaction( function ( Database $db ) use ( $name, $work, $schema ): bool {
					if ( $db->exists( $this->table, array( 'name' => $name ) ) ) {
						return false;
					}

					$work( $db, $schema );
					$db->insert( $this->table, array( 'name' => $name ) );

					return true;
				} );
			} catch ( \Throwable $e ) {
				throw new \RuntimeException(
					'Migration "' . $name . '" failed and was rolled back: ' . $e->getMessage(),
					0,
					$e
				);
			}

			$schema->forget();

			if ( true === $ran ) {
				$applied[] = $name;
			}
		}

		return $applied;
	}

	/**
	 * Names already recorded as applied.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function applied(): array {
		return array_values(
			array_filter(
				$this->db->column( 'SELECT name FROM ' . Database::identifier( $this->table ) ),
				is_string( ... )
			)
		);
	}

	/**
	 * Registered names not yet applied.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function pending(): array {
		return array_values( array_diff( array_keys( $this->migrations ), $this->applied() ) );
	}

	/**
	 * Record a migration as applied without running it.
	 *
	 * For adopting this runner on a database whose schema is already
	 * current — baseline the existing migrations so they are not
	 * re-applied to a schema that already has them.
	 *
	 * @since 1.0.0
	 *
	 * @param string ...$names Migration names to mark done.
	 *
	 * @return void
	 */
	public function baseline( string ...$names ): void {
		foreach ( $names as $name ) {
			$this->db->query(
				'INSERT OR IGNORE INTO ' . Database::identifier( $this->table ) . ' (name) VALUES (:name)',
				array( 'name' => $name )
			);
		}
	}
}
