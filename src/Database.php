<?php
/**
 * A configured SQLite connection with safe binding and transactions.
 *
 * @package   SugarMill\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Sqlite;

/**
 * Class Database
 *
 * A thin layer over PDO that fixes the three things people get wrong
 * with SQLite in a web application.
 *
 * **Binding is typed.** PDO binds everything as a string unless told
 * otherwise, and SQLite sorts every integer below every string. So
 * `WHERE stock <= :n` with `:n` bound as the string `'5'` matches every
 * row in the table, silently. {@see self::bind()} binds by PHP type.
 *
 * **Write transactions are immediate.** PDO issues a plain `BEGIN`,
 * which is deferred: the transaction takes a read lock and only tries to
 * upgrade when the first write happens. If another connection committed
 * in between, SQLite returns `SQLITE_BUSY_SNAPSHOT` — and does not
 * invoke the busy handler, because waiting cannot help a stale snapshot.
 * `busy_timeout` does not save you. {@see self::transaction()} uses
 * `BEGIN IMMEDIATE` and retries genuine lock contention.
 *
 * **Pragmas are set.** See {@see Config}.
 *
 * Typical use:
 *
 *   $db = Database::open( '/var/www/data/app.db' );
 *
 *   $rows = $db->all( 'SELECT * FROM orders WHERE total >= :min', [ 'min' => 500 ] );
 *
 *   $db->transaction( function ( Database $db ) {
 *       $id = $db->insert( 'orders', [ 'email' => 'a@b.com', 'total' => 999 ] );
 *       $db->insert( 'order_items', [ 'order_id' => $id, 'sku' => 'X' ] );
 *   } );
 *
 * @since 1.0.0
 */
final class Database {

	/**
	 * SQLite result codes meaning "try again".
	 */
	private const RETRYABLE = array( 5, 6 );   // SQLITE_BUSY, SQLITE_LOCKED

	/**
	 * Valid `wal_checkpoint` modes.
	 */
	private const CHECKPOINT_MODES = array( 'PASSIVE', 'FULL', 'RESTART', 'TRUNCATE' );

	/**
	 * @since 1.0.0
	 *
	 * @param \PDO   $pdo    Configured connection.
	 * @param Config $config Pragmas applied to it.
	 * @param string $path   Database path, or `:memory:`.
	 */
	private function __construct(
		private readonly \PDO $pdo,
		public readonly Config $config,
		public readonly string $path,
	) {}

	/* ─── Opening ───────────────────────────────────────────────────── */

	/**
	 * Open a database file, applying pragmas.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $path   Filesystem path. WAL requires local disk —
	 *                            it corrupts over NFS and most network mounts.
	 * @param Config|null $config Pragmas. Null uses {@see Config::production()}.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException When the file cannot be opened.
	 */
	public static function open( string $path, ?Config $config = null ): self {
		$config ??= Config::production();

		try {
			$pdo = new \PDO( 'sqlite:' . $path );
		} catch ( \PDOException $e ) {
			throw new \RuntimeException( 'Could not open SQLite database at "' . $path . '": ' . $e->getMessage(), 0, $e );
		}

		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
		$pdo->setAttribute( \PDO::ATTR_EMULATE_PREPARES, false );

		foreach ( $config->pragmas() as $pragma ) {
			$pdo->exec( $pragma );
		}

		return new self( $pdo, $config, $path );
	}

	/**
	 * Open a throwaway in-memory database.
	 *
	 * @since 1.0.0
	 *
	 * @param string $schema Optional SQL to execute immediately, e.g. the
	 *                       contents of a schema file.
	 *
	 * @return self
	 */
	public static function memory( string $schema = '' ): self {
		$db = self::open( ':memory:', Config::testing() );

		if ( '' !== $schema ) {
			$db->pdo()->exec( $schema );
		}

		return $db;
	}

	/**
	 * The underlying PDO handle, for anything this class does not wrap.
	 *
	 * @since 1.0.0
	 *
	 * @return \PDO
	 */
	public function pdo(): \PDO {
		return $this->pdo;
	}

	/* ─── Querying ──────────────────────────────────────────────────── */

	/**
	 * Prepare, bind and execute.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $sql    SQL with named or positional placeholders.
	 * @param array<string, mixed> $params Values, bound by PHP type.
	 *
	 * @return \PDOStatement
	 */
	public function query( string $sql, array $params = array() ): \PDOStatement {
		$stmt = $this->pdo->prepare( $sql );
		self::bind( $stmt, $params );
		$stmt->execute();

		return $stmt;
	}

	/**
	 * All matching rows.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $sql    Query.
	 * @param array<string, mixed> $params Values.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $sql, array $params = array() ): array {
		return $this->query( $sql, $params )->fetchAll();
	}

	/**
	 * The first matching row.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $sql    Query.
	 * @param array<string, mixed> $params Values.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row( string $sql, array $params = array() ): ?array {
		$row = $this->query( $sql, $params )->fetch();

		return false === $row ? null : $row;
	}

	/**
	 * The first column of the first row.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $sql     Query.
	 * @param array<string, mixed> $params  Values.
	 * @param mixed                $default Returned when there is no row.
	 *
	 * @return mixed
	 */
	public function value( string $sql, array $params = array(), mixed $default = null ): mixed {
		$value = $this->query( $sql, $params )->fetchColumn();

		return false === $value ? $default : $value;
	}

	/**
	 * The first column of every row.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $sql    Query.
	 * @param array<string, mixed> $params Values.
	 *
	 * @return array<int, mixed>
	 */
	public function column( string $sql, array $params = array() ): array {
		return $this->query( $sql, $params )->fetchAll( \PDO::FETCH_COLUMN );
	}

	/* ─── Writing ───────────────────────────────────────────────────── */

	/**
	 * Insert a row.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Column => value.
	 *
	 * @return int Row id.
	 *
	 * @throws \InvalidArgumentException On an empty row or unsafe identifier.
	 */
	public function insert( string $table, array $data ): int {
		if ( array() === $data ) {
			throw new \InvalidArgumentException( 'Cannot insert an empty row.' );
		}

		$columns      = array_keys( $data );
		$placeholders = array_map( static fn( string $c ): string => ':' . $c, $columns );

		$this->query(
			'INSERT INTO ' . self::identifier( $table )
			. ' (' . implode( ', ', array_map( self::identifier( ... ), $columns ) ) . ')'
			. ' VALUES (' . implode( ', ', $placeholders ) . ')',
			$data
		);

		return (int) $this->pdo->lastInsertId();
	}

	/**
	 * Update rows matching a set of equality conditions.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Column => new value.
	 * @param array<string, mixed> $where Column => required value.
	 *
	 * @return int Rows affected.
	 *
	 * @throws \InvalidArgumentException On empty data, or an empty WHERE —
	 *                                   which would rewrite the whole table.
	 */
	public function update( string $table, array $data, array $where ): int {
		if ( array() === $data ) {
			throw new \InvalidArgumentException( 'Cannot update with no columns.' );
		}

		if ( array() === $where ) {
			throw new \InvalidArgumentException( 'Refusing to update every row — pass a WHERE condition.' );
		}

		$sets   = array();
		$params = array();

		foreach ( $data as $column => $value ) {
			$sets[]                    = self::identifier( $column ) . ' = :set_' . $column;
			$params[ 'set_' . $column ] = $value;
		}

		[ $clause, $where_params ] = self::where( $where );

		$stmt = $this->query(
			'UPDATE ' . self::identifier( $table ) . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . $clause,
			$params + $where_params
		);

		return $stmt->rowCount();
	}

	/**
	 * Delete rows matching a set of equality conditions.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Column => required value.
	 *
	 * @return int Rows affected.
	 *
	 * @throws \InvalidArgumentException On an empty WHERE.
	 */
	public function delete( string $table, array $where ): int {
		if ( array() === $where ) {
			throw new \InvalidArgumentException( 'Refusing to delete every row — pass a WHERE condition.' );
		}

		[ $clause, $params ] = self::where( $where );

		return $this->query( 'DELETE FROM ' . self::identifier( $table ) . ' WHERE ' . $clause, $params )->rowCount();
	}

	/**
	 * Count rows.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Column => required value.
	 *
	 * @return int
	 */
	public function count( string $table, array $where = array() ): int {
		$sql = 'SELECT COUNT(*) FROM ' . self::identifier( $table );

		if ( array() === $where ) {
			return (int) $this->value( $sql, array(), 0 );
		}

		[ $clause, $params ] = self::where( $where );

		return (int) $this->value( $sql . ' WHERE ' . $clause, $params, 0 );
	}

	/**
	 * Whether any row matches.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Column => required value.
	 *
	 * @return bool
	 */
	public function exists( string $table, array $where ): bool {
		return $this->count( $table, $where ) > 0;
	}

	/* ─── Transactions ──────────────────────────────────────────────── */

	/**
	 * Run a closure inside a write transaction.
	 *
	 * Uses `BEGIN IMMEDIATE`, which takes the write lock up front. This
	 * matters: a deferred transaction that reads and then writes can fail
	 * with `SQLITE_BUSY_SNAPSHOT`, and SQLite deliberately does not
	 * invoke the busy handler for it — so `busy_timeout` never retries,
	 * and the write throws under concurrency while behaving perfectly in
	 * testing. Taking the lock at the start converts that into ordinary
	 * contention, which *is* retried.
	 *
	 * Rolls back and rethrows on any exception. Nested calls join the
	 * outer transaction rather than starting a second one.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(self): mixed $work    Receives this database.
	 * @param int                   $retries Attempts when the database is
	 *                                       locked by another connection.
	 *
	 * @return mixed Whatever the closure returned.
	 *
	 * @throws \Throwable Anything the closure throws, after rollback.
	 */
	public function transaction( callable $work, int $retries = 3 ): mixed {
		// Already inside one — just run, so the outer commit is atomic.
		if ( $this->pdo->inTransaction() ) {
			return $work( $this );
		}

		$attempt = 0;

		while ( true ) {
			try {
				$this->pdo->exec( 'BEGIN IMMEDIATE' );
			} catch ( \PDOException $e ) {
				if ( self::is_retryable( $e ) && $attempt < $retries ) {
					$attempt++;
					usleep( self::backoff_micros( $attempt ) );
					continue;
				}

				throw $e;
			}

			try {
				$result = $work( $this );
				$this->pdo->exec( 'COMMIT' );

				return $result;
			} catch ( \Throwable $e ) {
				if ( $this->pdo->inTransaction() ) {
					$this->pdo->exec( 'ROLLBACK' );
				}

				if ( $e instanceof \PDOException && self::is_retryable( $e ) && $attempt < $retries ) {
					$attempt++;
					usleep( self::backoff_micros( $attempt ) );
					continue;
				}

				throw $e;
			}
		}
	}

	/**
	 * Run a closure inside a read-only transaction.
	 *
	 * Gives every statement inside a consistent snapshot. Deferred, since
	 * nothing here writes.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(self): mixed $work Receives this database.
	 *
	 * @return mixed
	 *
	 * @throws \Throwable Anything the closure throws.
	 */
	public function read_transaction( callable $work ): mixed {
		if ( $this->pdo->inTransaction() ) {
			return $work( $this );
		}

		$this->pdo->exec( 'BEGIN DEFERRED' );

		try {
			$result = $work( $this );
			$this->pdo->exec( 'COMMIT' );

			return $result;
		} catch ( \Throwable $e ) {
			if ( $this->pdo->inTransaction() ) {
				$this->pdo->exec( 'ROLLBACK' );
			}

			throw $e;
		}
	}

	/* ─── Maintenance ───────────────────────────────────────────────── */

	/**
	 * Fold the write-ahead log back into the main database file.
	 *
	 * SQLite checkpoints automatically. Call this explicitly before a
	 * backup, or if a long-running reader has let the `-wal` file grow.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mode `PASSIVE`, `FULL`, `RESTART` or `TRUNCATE`.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException On an unknown mode.
	 */
	public function checkpoint( string $mode = 'TRUNCATE' ): void {
		if ( ! in_array( strtoupper( $mode ), self::CHECKPOINT_MODES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown checkpoint mode: ' . $mode );
		}

		$this->pdo->exec( 'PRAGMA wal_checkpoint(' . strtoupper( $mode ) . ')' );
	}

	/**
	 * Let SQLite refresh whatever statistics it thinks are stale.
	 *
	 * Cheap. Worth running periodically, or on graceful shutdown.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function optimize(): void {
		$this->pdo->exec( 'PRAGMA optimize' );
	}

	/**
	 * Run SQLite's integrity check.
	 *
	 * @since 1.0.0
	 *
	 * @return true|string True when sound, otherwise the reported problem.
	 */
	public function integrity_check(): true|string {
		$result = (string) $this->pdo->query( 'PRAGMA integrity_check' )->fetchColumn();

		return 'ok' === $result ? true : $result;
	}

	/**
	 * Schema introspection for this connection.
	 *
	 * @since 1.0.0
	 *
	 * @return Schema
	 */
	public function schema(): Schema {
		return new Schema( $this );
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * Bind values by PHP type.
	 *
	 * The whole point of this class. PDO defaults every binding to
	 * `PARAM_STR`, and SQLite's type affinity ranks all integers below
	 * all text — so an integer comparison bound as a string quietly
	 * matches rows it should not.
	 *
	 * @since 1.0.0
	 *
	 * @param \PDOStatement        $stmt   Prepared statement.
	 * @param array<string, mixed> $params Placeholder => value.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException On a value PDO cannot bind.
	 */
	public static function bind( \PDOStatement $stmt, array $params ): void {
		foreach ( $params as $key => $value ) {
			$placeholder = is_int( $key ) ? $key + 1 : ':' . ltrim( (string) $key, ':' );

			$type = match ( true ) {
				null === $value    => \PDO::PARAM_NULL,
				is_bool( $value )  => \PDO::PARAM_INT,
				is_int( $value )   => \PDO::PARAM_INT,
				is_float( $value ) => \PDO::PARAM_STR,
				is_string( $value ) => \PDO::PARAM_STR,
				default            => throw new \InvalidArgumentException(
					'Cannot bind a ' . get_debug_type( $value ) . ' for "' . $key . '".'
				),
			};

			$stmt->bindValue( $placeholder, is_bool( $value ) ? ( $value ? 1 : 0 ) : $value, $type );
		}
	}

	/**
	 * Quote an identifier, refusing anything that is not a bare name.
	 *
	 * Table and column names cannot be bound as parameters, so they are
	 * interpolated. Validating rather than escaping keeps the rule easy
	 * to reason about: if it is not `[A-Za-z_][A-Za-z0-9_]*`, it does not
	 * go into SQL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Table or column name.
	 *
	 * @return string Quoted identifier.
	 *
	 * @throws \InvalidArgumentException When the name is not a bare identifier.
	 */
	public static function identifier( string $name ): string {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
			throw new \InvalidArgumentException( 'Unsafe SQL identifier: ' . $name );
		}

		return '"' . $name . '"';
	}

	/**
	 * Build an equality WHERE clause and its parameters.
	 *
	 * A null value becomes `IS NULL`, since `= NULL` never matches.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $where Column => required value.
	 *
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	private static function where( array $where ): array {
		$clauses = array();
		$params  = array();

		foreach ( $where as $column => $value ) {
			if ( null === $value ) {
				$clauses[] = self::identifier( $column ) . ' IS NULL';
				continue;
			}

			$clauses[]                     = self::identifier( $column ) . ' = :where_' . $column;
			$params[ 'where_' . $column ] = $value;
		}

		return array( implode( ' AND ', $clauses ), $params );
	}

	/**
	 * Whether an exception represents transient lock contention.
	 *
	 * @since 1.0.0
	 *
	 * @param \PDOException $e Caught exception.
	 *
	 * @return bool
	 */
	private static function is_retryable( \PDOException $e ): bool {
		$code = $e->errorInfo[1] ?? null;

		if ( is_int( $code ) && in_array( $code, self::RETRYABLE, true ) ) {
			return true;
		}

		return str_contains( strtolower( $e->getMessage() ), 'database is locked' );
	}

	/**
	 * Backoff before a retry, in microseconds.
	 *
	 * Exponential with jitter, so several blocked workers do not all wake
	 * at the same instant and collide again.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attempt 1-based attempt number.
	 *
	 * @return int
	 */
	private static function backoff_micros( int $attempt ): int {
		$base = 1000 * ( 2 ** min( $attempt, 6 ) );

		return $base + random_int( 0, $base );
	}
}
