<?php
/**
 * A configured SQLite connection with safe binding and transactions.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

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
	 * The clause that makes {@see like()}'s escapes count. Goes after every
	 * `LIKE` it is used with: `title LIKE :q ` . Database::ESCAPE.
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	public const string ESCAPE = "ESCAPE '\\'";

	/**
	 * SQLite result codes meaning "try again": SQLITE_BUSY, SQLITE_LOCKED.
	 *
	 * @var list<int>
	 */
	private const array RETRYABLE = array( 5, 6 );

	/**
	 * Valid `wal_checkpoint` modes.
	 *
	 * @var list<string>
	 */
	private const array CHECKPOINT_MODES = array( 'PASSIVE', 'FULL', 'RESTART', 'TRUNCATE' );

	/**
	 * Decoders applied to named columns of every row read, by column name.
	 *
	 * @var array<string, \Closure(mixed): mixed>
	 */
	private array $decoders = array();

	/**
	 * Wrap a connection that open() has configured.
	 *
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
		return self::connect( $path, $config ?? Config::production(), array() );
	}

	/**
	 * Make a database file readable by its owner only.
	 *
	 * The file is every customer's data, and its -wal and -shm siblings hold
	 * the most recent of it; nobody else on a shared box should be able to
	 * read any of them. Sets 0600 on the file and whichever siblings exist,
	 * and 0700 on its directory — unless that is shared: a database path
	 * under /tmp must not lock everyone else out of /tmp, so a sticky
	 * directory, or /, is left alone. Run it after creating or migrating
	 * the file, and on every deploy, so anything made by hand is brought
	 * into line.
	 *
	 * @since 1.8.0
	 *
	 * @param string $path The database file.
	 *
	 * @return list<string> The files and directory it could not change, empty
	 *                      when all went well.
	 */
	public static function secure_files( string $path ): array {
		$failed = array();
		$dir    = dirname( $path );
		$perms  = @fileperms( $dir );

		if ( false !== $perms && 0 === ( $perms & 01000 ) && '/' !== $dir && ! @chmod( $dir, 0700 ) ) {
			$failed[] = $dir;
		}

		foreach ( array( '', '-wal', '-shm' ) as $suffix ) {
			if ( is_file( $path . $suffix ) && ! @chmod( $path . $suffix, 0600 ) ) {
				$failed[] = $path . $suffix;
			}
		}

		return $failed;
	}

	/**
	 * Open a database that has to be there already.
	 *
	 * `open()` on a path that is wrong — a typo in .env, a deploy that
	 * skipped the upload — makes a new empty file and carries on, and the
	 * first anyone hears of it is "no such table" on the first query, or a
	 * site that runs with no data. This refuses instead: a missing file, an
	 * empty one, or (given $table) one without that table each throw, with
	 * the path in the message. The file is opened read-write but never
	 * created, so nothing is left behind by a failed attempt.
	 *
	 *   $db = Database::existing( $path, Config::durable(), 'settings' );
	 *
	 * @since 1.6.0
	 *
	 * @param string      $path   Filesystem path.
	 * @param Config|null $config Pragmas. Null uses {@see Config::production()}.
	 * @param string      $table  A table the database must have, or '' for
	 *                            no check.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException When the file is missing, empty, cannot be
	 *                           opened, or lacks $table.
	 */
	public static function existing( string $path, ?Config $config = null, string $table = '' ): self {
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( 'No SQLite database at "' . $path . '".' );
		}

		if ( 0 === filesize( $path ) ) {
			throw new \RuntimeException( 'The SQLite database at "' . $path . '" is empty (zero bytes).' );
		}

		$db = self::connect( $path, $config ?? Config::production(), array( \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READWRITE ) );

		if ( '' !== $table && ! $db->schema()->has_table( $table ) ) {
			throw new \RuntimeException( 'The SQLite database at "' . $path . '" has no "' . $table . '" table.' );
		}

		return $db;
	}

	/**
	 * Connect, set the attributes every query here relies on, apply pragmas.
	 *
	 * @since 1.6.0
	 *
	 * @param string           $path    Filesystem path.
	 * @param Config           $config  Pragmas.
	 * @param array<int, int>  $options PDO constructor options.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException When the file cannot be opened.
	 */
	private static function connect( string $path, Config $config, array $options ): self {
		try {
			$pdo = new \PDO( 'sqlite:' . $path, null, null, $options );
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
	 * Run every statement in a SQL file, such as a schema.
	 *
	 * Through `PDO::exec()`, which runs a whole script; a prepared statement
	 * runs only the first statement in it and ignores the rest, silently.
	 *
	 * @since 1.3.0
	 *
	 * @param string $path Path to the file.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the file cannot be read — an empty
	 *                           schema would otherwise "succeed".
	 */
	public function exec_file( string $path ): void {
		$sql = is_readable( $path ) ? file_get_contents( $path ) : false;

		if ( false === $sql ) {
			throw new \RuntimeException( 'Cannot read SQL file: ' . $path );
		}

		$this->pdo->exec( $sql );
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
	 * @param string                   $sql    SQL with named or positional placeholders.
	 * @param array<int|string, mixed> $params Values, bound by PHP type: by
	 *                                         name, or by position from 0.
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
	 * @param string                   $sql    Query.
	 * @param array<int|string, mixed> $params Values.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $sql, array $params = array() ): array {
		$rows = $this->query( $sql, $params )->fetchAll();

		// @phpstan-ignore return.type, argument.type (FETCH_ASSOC, set in open(), makes every row an array keyed by column name.)
		return $this->decoders ? array_map( $this->decoded( ... ), $rows ) : $rows;
	}

	/**
	 * The first matching row.
	 *
	 * @since 1.0.0
	 *
	 * @param string                   $sql    Query.
	 * @param array<int|string, mixed> $params Values.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row( string $sql, array $params = array() ): ?array {
		$row = $this->query( $sql, $params )->fetch();

		// @phpstan-ignore argument.type (FETCH_ASSOC, set in open(), makes a row an array keyed by column name.)
		return is_array( $row ) ? $this->decoded( $row ) : null;
	}

	/**
	 * Decode a column wherever it is read.
	 *
	 * For a value stored in a form the application never wants to handle —
	 * sealed, compressed — so it is turned back in one place rather than at
	 * every query that happens to select it. Applied by name to rows from
	 * row() and all(), including a column aliased to that name. value() and
	 * column() return bare values with no name to go by, and are not
	 * decoded: select those through row() when it matters.
	 *
	 * Writes are not encoded. What goes in is the caller's decision, made
	 * once where the row is written.
	 *
	 * @since 1.2.0
	 *
	 * @param string                  $column  Column name, as it appears in
	 *                                         the result.
	 * @param \Closure(mixed): mixed $decoder Turns a stored value back.
	 *
	 * @return self
	 */
	public function decode( string $column, \Closure $decoder ): self {
		$this->decoders[ $column ] = $decoder;

		return $this;
	}

	/**
	 * A row with its decoded columns decoded.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $row Fetched row.
	 *
	 * @return array<string, mixed>
	 */
	private function decoded( array $row ): array {
		foreach ( $this->decoders as $column => $decoder ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = $decoder( $row[ $column ] );
			}
		}

		return $row;
	}

	/**
	 * The first column of the first row.
	 *
	 * @since 1.0.0
	 *
	 * @param string                   $sql     Query.
	 * @param array<int|string, mixed> $params  Values.
	 * @param mixed                    $default Returned when there is no row.
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
	 * @param string                   $sql    Query.
	 * @param array<int|string, mixed> $params Values.
	 *
	 * @return list<mixed>
	 */
	public function column( string $sql, array $params = array() ): array {
		return array_values( $this->query( $sql, $params )->fetchAll( \PDO::FETCH_COLUMN ) );
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
		$this->query( self::insert_sql( $table, $data ), $data );

		return (int) $this->pdo->lastInsertId();
	}

	/**
	 * Insert a row unless one with the same unique key is already there.
	 *
	 * For a row whose unique index *is* the rule — one notice per buyer,
	 * one payment per intent — so two requests a millisecond apart cannot
	 * both write it. The alternative people reach for, check-then-insert,
	 * has a window between the two that both requests pass through.
	 *
	 * `ON CONFLICT DO NOTHING`, not `INSERT OR IGNORE`: the second also
	 * skips a row that breaks NOT NULL or CHECK, silently, so a bug that
	 * writes a null where none belongs looks exactly like a duplicate.
	 * This skips only a uniqueness conflict, and anything else still throws.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Column => value.
	 *
	 * @return bool True when the row was written, false when it was there.
	 *
	 * @throws \InvalidArgumentException On an empty row or unsafe identifier.
	 */
	public function insert_or_ignore( string $table, array $data ): bool {
		return $this->query( self::insert_sql( $table, $data ) . ' ON CONFLICT DO NOTHING', $data )->rowCount() > 0;
	}

	/**
	 * Insert a row, or update the one already holding its unique key.
	 *
	 * One statement, so there is no moment between "is it there?" and the
	 * write for another request to change the answer.
	 *
	 * The conflict target is usually column names, which are validated and
	 * quoted like any identifier. An index on an expression — the usual way
	 * to make a nullable column part of a unique key, `ifnull( col, '' )` —
	 * can only be named by repeating the expression, so a string is taken
	 * as SQL, as it is: write it in code, never from input.
	 *
	 * What an update changes, in `$update`:
	 *
	 *   - null — every inserted column outside the target takes the new
	 *     value. Needs the target as column names.
	 *   - `'column'` (a list entry) — takes the new value.
	 *   - `'column' => $value` — takes this value instead, bound like any
	 *     other: resetting a counter or clearing an error on reopening.
	 *
	 * @since 1.3.0
	 *
	 * @param string                        $table    Table name.
	 * @param array<string, mixed>          $data     Column => value to insert.
	 * @param string[]|string               $conflict Columns of the unique
	 *                                                key, or the key's SQL
	 *                                                when it has an
	 *                                                expression in it.
	 * @param array<int|string, mixed>|null $update   What an update changes.
	 *
	 * @return int Rows written: 1 whether inserted or updated.
	 *
	 * @throws \InvalidArgumentException On an empty row, an unsafe
	 *                                   identifier, nothing to update, or
	 *                                   an update value whose placeholder
	 *                                   would collide with a column's.
	 */
	public function upsert( string $table, array $data, array|string $conflict, ?array $update = null ): int {
		if ( null === $update ) {
			if ( ! is_array( $conflict ) ) {
				throw new \InvalidArgumentException( 'Name the columns to update when the conflict target is an expression.' );
			}

			$update = array_values( array_diff( array_keys( $data ), $conflict ) );
		}

		if ( array() === $update ) {
			throw new \InvalidArgumentException( 'An upsert with nothing to update is insert_or_ignore().' );
		}

		$target = is_array( $conflict ) ? implode( ', ', array_map( self::identifier( ... ), $conflict ) ) : $conflict;
		$sets   = array();
		$params = $data;

		foreach ( $update as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( ! is_string( $value ) ) {
					throw new \InvalidArgumentException( 'A column to update is named by a string, got ' . get_debug_type( $value ) . '.' );
				}

				$sets[] = self::identifier( $value ) . ' = excluded.' . self::identifier( $value );
				continue;
			}

			// A column really called `upsert_total` already owns that
			// placeholder; sharing it would write one value into both.
			if ( array_key_exists( 'upsert_' . $key, $params ) ) {
				throw new \InvalidArgumentException( 'The update value for "' . $key . '" would share a placeholder with the column "upsert_' . $key . '".' );
			}

			$sets[]                     = self::identifier( $key ) . ' = :upsert_' . $key;
			$params[ 'upsert_' . $key ] = $value;
		}

		return $this->query(
			self::insert_sql( $table, $data ) . ' ON CONFLICT (' . $target . ') DO UPDATE SET ' . implode( ', ', $sets ),
			$params
		)->rowCount();
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
	 * Delete or update the rows a condition matches — or only count them.
	 *
	 * For housekeeping that offers a dry run. Written as two branches at
	 * every call site, the dry run is the branch nobody tests, and sooner
	 * or later one step forgets it and a "dry" run deletes for real. Here
	 * the two share the table and the condition, so they cannot disagree
	 * about which rows they mean.
	 *
	 * The condition and the SET clause are SQL, interpolated as they are:
	 * write them in code, never from input. Values go in `$params`.
	 *
	 *   $db->sweep( 'sessions', 'expires_at < :now', [ 'now' => time() ], dry: $dry );
	 *   $db->sweep( 'logs', 'date_created < :before', [ 'before' => $cutoff ], 'ip = NULL' );
	 *
	 * @since 1.3.0
	 *
	 * @param string               $table  Table name.
	 * @param string               $where  Condition, with named placeholders.
	 * @param array<string, mixed> $params Values.
	 * @param string|null          $set    SET clause for an UPDATE, or null to
	 *                                     DELETE.
	 * @param bool                 $dry    Count instead of changing.
	 *
	 * @return int Rows changed, or that would have been.
	 *
	 * @throws \InvalidArgumentException On an unsafe table name or an empty
	 *                                   condition.
	 */
	public function sweep( string $table, string $where, array $params = array(), ?string $set = null, bool $dry = false ): int {
		if ( '' === trim( $where ) ) {
			throw new \InvalidArgumentException( 'Refusing to sweep every row — pass a condition.' );
		}

		$table = self::identifier( $table );

		if ( $dry ) {
			return self::whole( $this->value( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params, 0 ) );
		}

		$sql = null === $set
			? "DELETE FROM {$table} WHERE {$where}"
			: "UPDATE {$table} SET {$set} WHERE {$where}";

		return $this->query( $sql, $params )->rowCount();
	}

	/* ─── Lists ─────────────────────────────────────────────────────── */

	/**
	 * Placeholders and parameters for an `IN ( … )` list of any values.
	 *
	 * PDO cannot bind a list to one placeholder, so every application ends
	 * up with a loop that builds `:p0, :p1, …` — and a handful of copies of
	 * it, one of which forgets array_values() and numbers from a key that
	 * is not zero. One here.
	 *
	 *   [ $in, $params ] = Database::in( 'plan', $plans );
	 *   $db->value( "SELECT COUNT(*) FROM accounts WHERE plan IN ( {$in} )", $params );
	 *
	 * An empty list gives `IN ()`, which SQLite accepts and matches nothing
	 * (and `NOT IN ()` everything) — the right answer, rather than a syntax
	 * error or a sentinel value that might one day exist.
	 *
	 * @since 1.3.0
	 *
	 * @param string            $name   Placeholder prefix, unique within the
	 *                                  statement.
	 * @param array<int, mixed> $values Values, bound by PHP type.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The placeholders,
	 *         comma-separated, and their parameters.
	 *
	 * @throws \InvalidArgumentException On a prefix that is not a bare name.
	 */
	public static function in( string $name, array $values ): array {
		self::identifier( $name );

		$params = array();

		foreach ( array_values( $values ) as $index => $value ) {
			$params[ $name . $index ] = $value;
		}

		return array(
			implode( ', ', array_map( static fn( string $key ): string => ':' . $key, array_keys( $params ) ) ),
			$params,
		);
	}

	/**
	 * A value as JSON for a TEXT column.
	 *
	 * One set of flags for everything an application stores: slashes and
	 * non-ASCII kept as they are (readable in the database, and shorter),
	 * 1.0 kept a float, and a string with broken UTF-8 stored with U+FFFD in
	 * place of the bad bytes rather than json_encode() returning false — a
	 * false that, bound into an INSERT, becomes an empty string with no
	 * error. Null stays NULL.
	 *
	 *   $db->insert( 'audit', [ 'details' => Database::to_json( $details, null_if_empty: true ) ] );
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $value         Anything json_encode() accepts.
	 * @param bool  $null_if_empty Store an empty array or '' as NULL.
	 *
	 * @return string|null
	 *
	 * @throws \JsonException On a value that cannot be JSON at all (INF,
	 *                        NAN, nesting past 512).
	 */
	public static function to_json( mixed $value, bool $null_if_empty = false ): ?string {
		if ( null === $value || ( $null_if_empty && ( array() === $value || '' === $value ) ) ) {
			return null;
		}

		return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
	}

	/**
	 * A JSON TEXT column back as an array.
	 *
	 * NULL, '', JSON that does not parse, and JSON that is not an object or
	 * a list all come back as an empty array — the column is read to be
	 * iterated, and a stray scalar or a corrupt row should not take the page
	 * down with it. Usable as a {@see decode()} decoder:
	 *
	 *   $db->decode( 'file_paths', Database::from_json( ... ) );
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $stored The column's value.
	 *
	 * @return array<mixed>
	 */
	public static function from_json( mixed $stored ): array {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		try {
			$value = json_decode( $stored, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return array();
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * A search term as a `LIKE` pattern that matches it literally.
	 *
	 * A term bound straight into `'%' . $q . '%'` is a pattern, not text:
	 * a `%` or `_` someone types becomes a wildcard, so searching `50%` or
	 * `a_b` finds the wrong rows, and `%` alone finds every row. Each `\`,
	 * `%` and `_` is escaped here; the `LIKE` needs {@see ESCAPE} after it
	 * for the escapes to count.
	 *
	 *   $db->all( 'SELECT * FROM products WHERE title LIKE :q ' . Database::ESCAPE, [ 'q' => Database::like( $q ) ] );
	 *
	 * @since 1.5.0
	 *
	 * @param string $term     Text to look for, exactly as typed.
	 * @param string $position Where it may appear: 'contains', 'starts' or 'ends'.
	 *
	 * @return string The pattern to bind.
	 *
	 * @throws \InvalidArgumentException On an unknown position.
	 */
	public static function like( string $term, string $position = 'contains' ): string {
		$escaped = strtr( $term, array( '\\' => '\\\\', '%' => '\\%', '_' => '\\_' ) );

		return match ( $position ) {
			'contains' => '%' . $escaped . '%',
			'starts'   => $escaped . '%',
			'ends'     => '%' . $escaped,
			default    => throw new \InvalidArgumentException( 'A LIKE position is contains, starts or ends, not ' . json_encode( $position ) . '.' ),
		};
	}

	/**
	 * A list of row ids for an `IN ( … )`, written into the SQL.
	 *
	 * For ids that came out of an earlier query, where a placeholder per id
	 * buys nothing and a long list runs into SQLite's parameter limit. Each
	 * is cast to an integer here, which is what makes interpolating them
	 * safe — whatever the caller passes, nothing but digits reaches SQL.
	 * Duplicates are dropped; an empty list gives `IN ()`, which matches
	 * nothing.
	 *
	 *   $db->all( 'SELECT * FROM items WHERE order_id IN ( ' . Database::ids( $orders ) . ' )' );
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, int|string> $ids Row ids.
	 *
	 * @return string Comma-separated integers, or '' for none.
	 */
	public static function ids( array $ids ): string {
		return implode( ',', array_values( array_unique( array_map( 'intval', $ids ) ) ) );
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
			return self::whole( $this->value( $sql, array(), 0 ) );
		}

		[ $clause, $params ] = self::where( $where );

		return self::whole( $this->value( $sql . ' WHERE ' . $clause, $params, 0 ) );
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
		if ( $this->in_transaction() ) {
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
				if ( $this->in_transaction() ) {
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
		if ( $this->in_transaction() ) {
			return $work( $this );
		}

		$this->pdo->exec( 'BEGIN DEFERRED' );

		try {
			$result = $work( $this );
			$this->pdo->exec( 'COMMIT' );

			return $result;
		} catch ( \Throwable $e ) {
			if ( $this->in_transaction() ) {
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
	 * Rebuild the database file without its free pages, giving back to the
	 * disk the space deleted rows left behind.
	 *
	 * SQLite reuses free pages but never shrinks the file by itself, so after
	 * a large delete — pruning old logs — the file stays its old size. This
	 * rewrites the whole file and holds the write lock while it does: run it
	 * off-peak after a big delete, not routinely. SQLite can't vacuum inside
	 * a transaction.
	 *
	 * @since 1.10.0
	 *
	 * @return int Bytes the file shrank by; 0 when nothing was free.
	 *
	 * @throws \LogicException Inside a transaction.
	 */
	public function vacuum(): int {
		if ( $this->pdo->inTransaction() ) {
			throw new \LogicException( 'VACUUM cannot run inside a transaction.' );
		}

		$size   = fn (): int => self::whole( $this->value( 'PRAGMA page_count' ) ) * self::whole( $this->value( 'PRAGMA page_size' ) );
		$before = $size();
		$this->pdo->exec( 'VACUUM' );

		return max( 0, $before - $size() );
	}

	/**
	 * Run SQLite's integrity check.
	 *
	 * @since 1.0.0
	 *
	 * @return true|string True when sound, otherwise the reported problem.
	 */
	public function integrity_check(): true|string {
		$result = $this->value( 'PRAGMA integrity_check' );

		if ( 'ok' === $result ) {
			return true;
		}

		return is_string( $result ) ? $result : 'integrity_check returned nothing';
	}

	/**
	 * Write a consistent copy of the database to a new file.
	 *
	 * `VACUUM INTO` rather than copying the file: it is SQLite's own
	 * snapshot, safe against a live WAL database, where `cp` can catch the
	 * file and its `-wal` at different moments. The copy is also compacted.
	 *
	 * The file is created here first, empty and readable by its owner only,
	 * which SQLite then writes into. Left to SQLite it would take the
	 * process umask — usually world-readable — and a copy of the database
	 * is the database. Creating it exclusively also means a file, or a
	 * symlink planted at the path, is refused rather than written through.
	 *
	 * @since 1.1.0
	 *
	 * @param string $path Where the copy goes. Must not exist yet.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the path already exists — SQLite would
	 *                           refuse anyway, less clearly — or cannot be
	 *                           created.
	 */
	public function snapshot( string $path ): void {
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new \RuntimeException( 'A snapshot will not overwrite ' . $path );
		}

		$handle = @fopen( $path, 'x' );

		if ( false === $handle ) {
			throw new \RuntimeException( 'Cannot create a snapshot at ' . $path );
		}

		fclose( $handle );

		try {
			if ( ! chmod( $path, 0600 ) ) {
				throw new \RuntimeException( 'Cannot make the snapshot at ' . $path . ' owner-only.' );
			}

			// Bound, not interpolated: it is still a string reaching SQL.
			$this->query( 'VACUUM INTO :path', array( 'path' => $path ) );
		} catch ( \Throwable $e ) {
			@unlink( $path );

			throw $e;
		}
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
	 * @param \PDOStatement            $stmt   Prepared statement.
	 * @param array<int|string, mixed> $params Placeholder => value; an int key
	 *                                         is a 0-based position.
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
	 * go into SQL. Matched to the very end of the string — `$` alone would
	 * also accept a name with a newline after it.
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
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $name ) ) {
			throw new \InvalidArgumentException( 'Unsafe SQL identifier: ' . $name );
		}

		return '"' . $name . '"';
	}

	/**
	 * Whether an exception is a unique index refusing a duplicate.
	 *
	 * For the insert-and-catch pattern, where "already there" is an answer
	 * and anything else is a failure. Matching the message for "UNIQUE" is
	 * the usual way, and it also matches a table or column that happens to
	 * have the word in its name. This reads SQLite's result code first.
	 *
	 * @since 1.3.0
	 *
	 * @param \Throwable $e Caught exception.
	 *
	 * @return bool
	 */
	public static function is_unique_violation( \Throwable $e ): bool {
		if ( ! $e instanceof \PDOException ) {
			return false;
		}

		// SQLITE_CONSTRAINT, then which constraint: a primary key on a rowid
		// table reports itself as UNIQUE too.
		$message = $e->errorInfo[2] ?? null;

		return 19 === ( $e->errorInfo[1] ?? null )
			&& is_string( $message )
			&& str_starts_with( $message, 'UNIQUE constraint failed' );
	}

	/**
	 * The INSERT statement for a row, with a named placeholder per column.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Column => value.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException On an empty row or unsafe identifier.
	 */
	private static function insert_sql( string $table, array $data ): string {
		if ( array() === $data ) {
			throw new \InvalidArgumentException( 'Cannot insert an empty row.' );
		}

		$columns = array_keys( $data );

		return 'INSERT INTO ' . self::identifier( $table )
			. ' (' . implode( ', ', array_map( self::identifier( ... ), $columns ) ) . ')'
			. ' VALUES (' . implode( ', ', array_map( static fn( string $c ): string => ':' . $c, $columns ) ) . ')';
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
	 * Whether a transaction is open on the connection right now.
	 *
	 * Asked afresh every time: a failed COMMIT may or may not have ended the
	 * transaction, so the answer from before it means nothing.
	 *
	 * @since 1.4.0
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private function in_transaction(): bool {
		return $this->pdo->inTransaction();
	}

	/**
	 * A count read back from SQLite, as an int.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $value What the query returned.
	 *
	 * @return int Zero for anything that is not a number.
	 */
	private static function whole( mixed $value ): int {
		return match ( true ) {
			is_int( $value )     => $value,
			is_numeric( $value ) => (int) $value,
			default              => 0,
		};
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
