# SQLite

SQLite for web applications. Correct pragmas, type-aware binding, immediate write transactions with retry, schema introspection and idempotent migrations. Zero dependencies, no ORM.

SQLite is an excellent production database for a single-server application. Its defaults are not, because they're tuned for an embedded database on a phone.

## Features

- ⚡ **WAL by default** — readers and the writer proceed concurrently, instead of blocking each other.
- 🔢 **Typed binding** — fixes the silent-wrong-answer class of bug PDO's string-everything default causes.
- 🔒 **Immediate write transactions** — avoids the `SQLITE_BUSY` that `busy_timeout` famously does *not* retry.
- 🔁 **Automatic retry** — exponential backoff with jitter on genuine lock contention.
- 🧱 **Idempotent migrations** — named, recorded, each in its own transaction.
- 🔍 **Schema introspection** — `has_table()`, `has_column()`, `add_column()`.
- 🛡️ **Identifier validation** — table and column names are validated, never escaped-and-hoped.
- 🔂 **Duplicates as answers** — `insert_or_ignore()`, `upsert()` (columns or expression targets) and `is_unique_violation()`.
- 📋 **Lists** — `in()` and `ids()` for `IN ( … )`, empty lists included.
- 🧹 **Dry-run housekeeping** — `sweep()` deletes, updates or only counts, from one condition.
- 💾 **Rotating backups** — dated snapshots, pruned by name.
- 🚫 **No ORM** — helpers for the boring 90%, raw SQL for everything else.

## Requirements

PHP 8.5+, `ext-pdo_sqlite`

## Installation

```bash
composer require sugarcommerce/sqlite
```

## Usage

```php
use SugarCommerce\Sqlite\Database;

$db = Database::open( '/var/www/data/app.db' );

$rows  = $db->all( 'SELECT * FROM orders WHERE total >= :min', [ 'min' => 500 ] );
$order = $db->row( 'SELECT * FROM orders WHERE id = :id', [ 'id' => 42 ] );
$count = $db->value( 'SELECT COUNT(*) FROM orders' );

$id = $db->insert( 'orders', [ 'email' => 'a@b.com', 'total' => 999 ] );
$db->update( 'orders', [ 'status' => 'paid' ], [ 'id' => $id ] );
$db->delete( 'orders', [ 'id' => $id ] );
```

`update()` and `delete()` refuse an empty `WHERE` rather than rewriting the table.

## The two bugs this exists to prevent

### Binding integers as strings

`PDOStatement::execute( $array )` binds every value as a string. Against a plain column that's usually survivable — the column's INTEGER affinity coerces it back. Against an **expression** there's no affinity to apply, and SQLite orders every integer below every string:

```php
// Matches every row, whatever the totals are:
$stmt->execute( [ ':n' => 5 ] );   // '5' as TEXT
// SELECT ... GROUP BY id HAVING SUM(stock) <= :n
```

No error, just a wrong answer — and it only appears once someone wraps a column in `SUM()`, `COALESCE()` or `CAST()`. `query()` binds by PHP type, so it can't happen.

### Deferred write transactions

PDO's `beginTransaction()` issues a plain `BEGIN`, which is *deferred*: the transaction takes a read lock and only tries to upgrade on the first write. If another connection committed in between, SQLite returns `SQLITE_BUSY_SNAPSHOT` — and **deliberately does not invoke the busy handler**, because waiting can't refresh a stale snapshot. Your `busy_timeout` never fires. The write throws under concurrency and behaves perfectly in testing.

`transaction()` uses `BEGIN IMMEDIATE`, taking the write lock up front, which turns that into ordinary contention — which *is* retried:

```php
$db->transaction( function ( Database $db ) {
    $id = $db->insert( 'orders', [ 'email' => 'a@b.com' ] );
    $db->insert( 'order_items', [ 'order_id' => $id, 'sku' => 'X' ] );
} );
```

Rolls back and rethrows on any exception. Nested calls join the outer transaction — SQLite has no nested transactions, and committing an inner one would end the outer early.

For read-only work wanting a consistent snapshot, `read_transaction()` stays deferred.

## Configuration

```php
Database::open( $path );                      // Config::production()
Database::open( $path, Config::durable() );   // synchronous = FULL
Database::memory( $schema );                  // in-memory, for tests
```

`Config::production()` sets:

| Pragma | Value | Why |
|---|---|---|
| `journal_mode` | `WAL` | Readers don't block the writer |
| `synchronous` | `NORMAL` | fsync per checkpoint, not per commit |
| `busy_timeout` | `5000` | Wait for locks rather than failing |
| `foreign_keys` | `ON` | Off by default in SQLite |
| `cache_size` | 8MB | Per connection |
| `mmap_size` | 256MB | Helps read-heavy workloads |
| `temp_store` | `MEMORY` | Sort/join scratch out of the filesystem |

**On `synchronous = NORMAL`:** under WAL this is the standard production setting, but it's a real trade. An OS crash or power cut can lose the last few *committed* transactions. An application crash cannot, and the database never corrupts either way. Use `Config::durable()` when losing a committed write is unacceptable and you have no external source of truth to replay from.

**WAL needs local disk.** It corrupts over NFS and most network filesystems.

## Migrations

```php
use SugarCommerce\Sqlite\{Migrator, Database, Schema};

$migrator = new Migrator( $db );

$migrator->add( 'add_orders_metadata', function ( Database $db, Schema $schema ) {
    $schema->add_column( 'orders', 'metadata', "TEXT NOT NULL DEFAULT '{}'" );
} );

$applied = $migrator->run();   // ['add_orders_metadata']
```

Each runs once, inside its own immediate transaction. A failure rolls back and leaves the migration unrecorded, so you fix it and run again.

Write the bodies defensively with `Schema` anyway. A tracking table only knows what *this* runner did — not about a column someone added by hand, or a database restored from a mid-sequence backup.

Adopting this on a database that's already current:

```php
$migrator->baseline( 'add_orders_metadata', 'add_customers_country' );
```

## Schema introspection

```php
$schema = $db->schema();

$schema->tables();
$schema->has_table( 'orders' );
$schema->columns( 'orders' );
$schema->has_column( 'orders', 'metadata' );
$schema->has_index( 'idx_orders_email' );
$schema->add_column( 'orders', 'metadata', "TEXT NOT NULL DEFAULT '{}'" );  // false if present
```

## Decoding columns

```php
$db->decode( 'email', fn ( mixed $v ): mixed => $sealer->reveal( $v ) );
```

Every row from `row()` and `all()` has that column decoded, whichever query selected it — for values stored sealed or compressed, so turning them back is one line at boot rather than a line at every query. `value()` and `column()` return bare values and are not decoded.

## Cache

A key-value cache in a table, for values fetched from somewhere slow or rate-limited:

```php
use SugarCommerce\Sqlite\Cache;

$db->pdo()->exec( Cache::SCHEMA );   // or put it in your schema / a migration

$cache = new Cache( $db );

$json = $cache->remember( 'catalogue:acct_1', 300, function (): ?string {
    $products = $api->products();              // null when the API is down
    return null === $products ? null : json_encode( $products );
} );

$cache->forget( 'catalogue:acct_1' );          // after the source changed
$cache->purge();                               // from cron
```

`remember()` never stores a failed fetch, and falls back to the expired value when the source cannot be reached — last week's answer beats an empty page. Expired rows stay until `purge()` for that reason.

`add()` is the other half: it stores only when nothing is there, and says whether it did — one insert that the key's uniqueness decides, so two requests racing with the same signature or nonce cannot both win. An entry `purge()` would remove no longer counts. `expire()` makes a value stale without losing it, so the next `remember()` fetches again and still has it to fall back on; `forget()` during an upstream outage leaves nothing.

```php
if ( ! $cache->add( 'nonce:' . $nonce, '1', 300 ) ) {
    return 'replayed';
}

$cache->expire( 'catalogue:acct_1' );          // refresh, but keep a fallback
```

## Writing what is already there

```php
// One notice per buyer, ever: false when the unique key already holds one.
$queued = $db->insert_or_ignore( 'notices', [ 'dedupe_key' => "version:{$file}:{$buyer}", 'body' => $body ] );

// Insert, or update the row holding the key — in one statement.
$db->upsert( 'options', [ 'key' => 'theme', 'value' => 'dark' ], [ 'key' ] );

// Choose what an update changes: list entries take the new value,
// 'column' => value pairs are set (bound) instead.
$db->upsert( 'members', $row, [ 'grant_id', 'user_id' ], [ 'status', 'attempts' => 0, 'last_error' => null ] );

// A unique index on an expression is named by repeating it — SQL, as is.
$db->upsert( 'policies', $row, "product, ifnull( price, '' )", [ 'seats' ] );

// Insert-and-catch, where the duplicate needs its own answer.
try {
    $db->insert( 'claims', $row );
} catch ( \PDOException $e ) {
    if ( ! Database::is_unique_violation( $e ) ) {
        throw $e;               // a busy database is not "already claimed"
    }
}
```

`insert_or_ignore()` is `ON CONFLICT DO NOTHING`, not `INSERT OR IGNORE`. The second also skips a row that breaks NOT NULL or CHECK, so a bug writing a null looks exactly like a duplicate; this skips only a uniqueness conflict. `is_unique_violation()` reads SQLite's result code rather than looking for "UNIQUE" in a message that can contain a table's name.

## Lists

PDO cannot bind a list to one placeholder, so every application grows a loop that builds `:p0, :p1, …`, and then several copies of it.

```php
[ $in, $params ] = Database::in( 'plan', $plans );
$db->value( "SELECT COUNT(*) FROM accounts WHERE plan NOT IN ( {$in} )", $params );

// Ids from an earlier query: cast to integers, so only digits reach SQL.
$db->all( 'SELECT * FROM items WHERE order_id IN ( ' . Database::ids( $orders ) . ' )' );
```

An empty list gives `IN ()`, which SQLite accepts: nothing is in it, and everything is not in it. No sentinel `0` that might one day be a real id.

## Opening a database that must exist

`open()` on a wrong path makes a new empty file and carries on. `existing()` refuses a missing file, an empty one, or one without the table you name — and never creates anything.

```php
$db = Database::existing( $path, Config::durable(), 'settings' );
```

## JSON columns

```php
Database::to_json( $files );                          // '["a/b.zip","café"]' — never false
Database::to_json( $details, null_if_empty: true );   // NULL for [] or ''
Database::from_json( $row['files'] );                 // always an array; [] for NULL or junk
$db->decode( 'files', Database::from_json( ... ) );   // or decode it on every read
```

`to_json()` keeps slashes and non-ASCII as written, keeps `1.0` a float, and stores broken UTF-8 with U+FFFD instead of returning false — which, bound into an INSERT, would have stored an empty string with no error.

## Searching

A term bound as `'%' . $q . '%'` is a pattern, not text: a `%` or `_` someone types is a wildcard, so `%` alone finds every row. `like()` escapes them; `ESCAPE` makes the escapes count.

```php
$db->all( 'SELECT * FROM products WHERE title LIKE :q ' . Database::ESCAPE, [ 'q' => Database::like( $q ) ] );

Database::like( $q, 'starts' );   // 'q%'
Database::like( $q, 'ends' );     // '%q'
```

## Housekeeping

```php
$removed = $db->sweep( 'sessions', 'expires_at < :now', [ 'now' => time() ], dry: $dry );
$blanked = $db->sweep( 'logs', 'date_created < :before', [ 'before' => $cutoff ], set: 'ip = NULL', dry: $dry );
```

Delete or update what a condition matches, or with `dry` only count it. Written as two branches at every call site, the dry run is the branch nobody tests, and one day a "dry" run deletes for real; here both share the condition. The condition and SET clause are SQL from your code, never from input.

## Maintenance

```php
$db->checkpoint();          // fold the -wal file back in, before a backup
$db->optimize();            // PRAGMA optimize — cheap, run periodically
$db->integrity_check();     // true, or the problem found
$db->snapshot( $path );     // VACUUM INTO: a consistent copy of a live database
$db->exec_file( 'schema.sql' );   // every statement in a file; throws if unreadable
```

For backups, [Litestream](https://litestream.io) streams the WAL to S3-compatible storage continuously. That's the standard answer for SQLite in production, and it beats anything you'd script.

Without it, `Backups` keeps dated snapshots in a directory and prunes them:

```php
use SugarCommerce\Sqlite\Backups;

$backups = new Backups( $db, '/var/backups/app', 'app', keep: 14 );

$path = $backups->take();     // app-20261006-030000.db, via snapshot()
$backups->prune();            // all but the newest 14
$backups->newest();           // when the last one was written, for a health check
```

Pruned by name, which is the time the copy was taken, not by modification time — touching an old copy to look at it does not make a newer one the one that goes. The directory is created owner-only, and every snapshot file is created `0600` whatever the umask, refusing a file or symlink already at the path: a copy of the database is the database. Only names `take()` could have written count as copies, so a second prefix such as `app-staging` in the same directory is never pruned by `app`. Getting a copy off the box is yours: `take()` returns the path.

## Testing

```bash
composer install
composer test
```

129 tests. Both bugs above are covered by tests that first **demonstrate the failure against raw PDO**, then show it fixed — so the suite proves the problem is real, not theoretical. Also covered: insert-or-ignore that still refuses a broken row, upserts on columns and on expressions, unique-violation detection, empty and hostile lists, typed wildcards in searches, databases that must already exist, JSON columns, dry-run sweeps, the cache's first-caller-wins `add()`, and backup rotation by name.

## License

GPL-2.0-or-later
