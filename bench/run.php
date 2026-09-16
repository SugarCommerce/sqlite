<?php
declare( strict_types=1 );
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../../bench-lib.php';

use SugarCommerce\Sqlite\Database;

$db = Database::memory( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0 );' );

for ( $i = 0; $i < 1000; $i++ ) {
	$db->insert( 't', array( 'name' => 'row' . $i, 'n' => $i ) );
}

echo "sqlite (in-memory)\n";
bench( 'row by id', 50000, static fn() => $db->row( 'SELECT * FROM t WHERE id = :id', array( 'id' => 500 ) ) );
bench( 'value COUNT(*)', 50000, static fn() => $db->value( 'SELECT COUNT(*) FROM t' ) );
bench( 'all (100 rows)', 5000, static fn() => $db->all( 'SELECT * FROM t LIMIT 100' ) );
bench( 'insert', 20000, static fn() => $db->insert( 't', array( 'name' => 'x', 'n' => 1 ) ) );
bench( 'transaction (2 inserts)', 5000, static fn() => $db->transaction( static function ( Database $db ): void {
	$db->insert( 't', array( 'name' => 'a', 'n' => 1 ) );
	$db->insert( 't', array( 'name' => 'b', 'n' => 2 ) );
} ) );
bench_footer();
