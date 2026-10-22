<?php
/**
 * Connection pragmas.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

/**
 * Class Config
 *
 * SQLite's defaults are tuned for an embedded database on a phone, not
 * for a web application taking concurrent requests. Left alone you get
 * rollback-journal mode, where a single writer blocks every reader, and
 * an fsync on every commit.
 *
 * {@see self::production()} is the configuration you actually want.
 *
 * @since 1.0.0
 */
final readonly class Config {

	/**
	 * Choose the pragmas; every default is the production one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $journal_mode    `WAL` unless you have a reason. WAL
	 *                                lets readers and the writer proceed
	 *                                concurrently; the default `DELETE`
	 *                                does not. Requires local disk — WAL
	 *                                over NFS corrupts.
	 * @param string $synchronous     `NORMAL` under WAL is the standard
	 *                                production setting: fsync at
	 *                                checkpoints rather than every commit.
	 *                                An OS crash or power cut can lose the
	 *                                last few committed transactions; an
	 *                                application crash cannot, and the
	 *                                database never corrupts either way.
	 *                                Use `FULL` when losing a committed
	 *                                write is unacceptable and you have no
	 *                                external source of truth to replay from.
	 * @param int    $busy_timeout_ms How long a blocked connection waits
	 *                                for a lock before giving up. Note this
	 *                                does NOT cover write-after-read
	 *                                conflicts — see
	 *                                {@see Database::transaction()}.
	 * @param bool   $foreign_keys    Off by default in SQLite, for
	 *                                backwards compatibility reasons that
	 *                                stopped mattering years ago.
	 * @param int    $cache_size_kb   Page cache per connection. Expressed
	 *                                in KB here and passed to SQLite as a
	 *                                negative value, which is how it
	 *                                distinguishes KB from page count.
	 * @param int    $mmap_size       Bytes to memory-map. Helps read-heavy
	 *                                workloads; 0 disables.
	 * @param string $temp_store      `MEMORY` keeps sort and join scratch
	 *                                space out of the filesystem.
	 */
	public function __construct(
		public string $journal_mode = 'WAL',
		public string $synchronous = 'NORMAL',
		public int $busy_timeout_ms = 5000,
		public bool $foreign_keys = true,
		public int $cache_size_kb = 8000,
		public int $mmap_size = 268435456,
		public string $temp_store = 'MEMORY',
	) {}

	/**
	 * Settings for a web application on local disk.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function production(): self {
		return new self();
	}

	/**
	 * Settings that trade throughput for never losing a committed write.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function durable(): self {
		return new self( synchronous: 'FULL' );
	}

	/**
	 * Settings for tests and in-memory databases.
	 *
	 * WAL is meaningless without a file on disk, and durability is
	 * irrelevant when the database dies with the process.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function testing(): self {
		return new self(
			journal_mode: 'MEMORY',
			synchronous: 'OFF',
			busy_timeout_ms: 1000,
			mmap_size: 0,
		);
	}

	/**
	 * The pragma statements this configuration implies, in order.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function pragmas(): array {
		$pragmas = array(
			'PRAGMA journal_mode = ' . self::word( $this->journal_mode ),
			'PRAGMA synchronous = ' . self::word( $this->synchronous ),
			'PRAGMA busy_timeout = ' . max( 0, $this->busy_timeout_ms ),
			'PRAGMA foreign_keys = ' . ( $this->foreign_keys ? 'ON' : 'OFF' ),
			'PRAGMA cache_size = -' . max( 0, $this->cache_size_kb ),
			'PRAGMA temp_store = ' . self::word( $this->temp_store ),
		);

		if ( $this->mmap_size > 0 ) {
			$pragmas[] = 'PRAGMA mmap_size = ' . $this->mmap_size;
		}

		return $pragmas;
	}

	/**
	 * Constrain a pragma value to a bare word.
	 *
	 * Pragma values cannot be bound as parameters, so they are
	 * interpolated. Anything outside `[A-Za-z_]` is refused rather than
	 * escaped — every legitimate value here is a keyword.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Candidate.
	 *
	 * @return string Uppercased keyword.
	 *
	 * @throws \InvalidArgumentException When it is not a bare word.
	 */
	private static function word( string $value ): string {
		if ( 1 !== preg_match( '/^[A-Za-z_]+$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Pragma value must be a bare keyword, got: ' . $value );
		}

		return strtoupper( $value );
	}
}
