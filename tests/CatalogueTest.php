<?php
/**
 * Catalogue tests.
 *
 * @package SugarCommerce\Sqlite\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Sqlite\{Catalogue, Database, Settings};

/**
 * Class CatalogueTest
 */
final class CatalogueTest extends TestCase {

	private Database $db;

	private Catalogue $catalogue;

	protected function setUp(): void {
		$this->db        = Database::memory( 'CREATE TABLE settings ( key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP );' );
		$this->catalogue = new Catalogue( new Settings( $this->db ), array(
			'billing.grace_days' => array( 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 90, 'unit' => 'days', 'label' => 'Billing grace', 'help' => 'Days after a failed payment.' ),
			'site_name'          => array( 'type' => 'string', 'default' => 'Shop', 'max_length' => 20 ),
			'footer'             => array( 'type' => 'text', 'default' => '' ),
			'maintenance'        => array( 'type' => 'bool', 'default' => false ),
			'emails'             => array( 'type' => 'bool', 'default' => true ),
		) );
	}

	private function rows(): int {
		return (int) $this->db->value( 'SELECT COUNT(*) FROM settings' );
	}

	public function test_an_empty_table_reads_every_default(): void {
		$this->assertSame( 10, $this->catalogue->get( 'billing.grace_days' ) );
		$this->assertSame( 'Shop', $this->catalogue->get( 'site_name' ) );
		$this->assertFalse( $this->catalogue->get( 'maintenance' ) );
		$this->assertTrue( $this->catalogue->get( 'emails' ) );
	}

	public function test_problems_are_reported_per_setting(): void {
		$problems = $this->catalogue->problems( array(
			'billing__grace_days' => '91',
			'site_name'           => str_repeat( 'x', 21 ),
		) );

		$this->assertSame( 'Must be between 0 and 90 days.', $problems['billing.grace_days'] );
		$this->assertSame( 'At most 20 characters.', $problems['site_name'] );
		$this->assertSame( 'Enter a whole number of days.', $this->catalogue->problems( array( 'billing.grace_days' => '7d' ) )['billing.grace_days'] );
		$this->assertSame( array(), $this->catalogue->problems( array( 'billing.grace_days' => '', 'site_name' => ' ' ) ), 'blank means default' );
		$this->assertSame( array(), $this->catalogue->problems( array() ), 'absent is not checked' );
	}

	public function test_save_reports_only_what_changed_and_writes_only_that(): void {
		$form = array( 'billing__grace_days' => '10', 'site_name' => 'Shop', 'footer' => '', 'emails' => 'on' );

		$this->assertSame( array(), $this->catalogue->save( $form ), 'defaults written back are no change' );
		$this->assertSame( 0, $this->rows() );

		$this->assertSame( array( 'billing.grace_days', 'site_name' ), $this->catalogue->save( array( 'billing__grace_days' => '30', 'site_name' => "  Shop &\n Co ", 'emails' => '1' ) ) );
		$this->assertSame( 30, $this->catalogue->get( 'billing.grace_days' ) );
		$this->assertSame( 'Shop & Co', $this->catalogue->get( 'site_name' ), 'one line, trimmed' );
	}

	public function test_an_unticked_checkbox_is_off_and_a_blank_field_restores_the_default(): void {
		$this->catalogue->save( array( 'billing.grace_days' => '30', 'emails' => '1' ) );

		$this->assertSame( array( 'billing.grace_days', 'emails' ), $this->catalogue->save( array( 'billing.grace_days' => '' ) ) );
		$this->assertSame( 10, $this->catalogue->get( 'billing.grace_days' ) );
		$this->assertFalse( $this->catalogue->get( 'emails' ) );
	}

	public function test_bounds_apply_on_the_way_in_and_out(): void {
		$this->catalogue->save( array( 'billing.grace_days' => '500', 'emails' => '1' ) );
		$this->assertSame( 90, $this->catalogue->get( 'billing.grace_days' ) );

		$this->db->query( "UPDATE settings SET value = '-4' WHERE key = 'billing.grace_days'" );
		$catalogue = new Catalogue( new Settings( $this->db ), array( 'billing.grace_days' => array( 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 90 ) ) );
		$this->assertSame( 0, $catalogue->get( 'billing.grace_days' ), 'a row from before the bound changed' );
	}

	public function test_all_describes_the_form(): void {
		$all = $this->catalogue->all();

		$this->assertSame( 'billing__grace_days', $all['billing.grace_days']['field'] );
		$this->assertSame( array( 0, 90, 'days', null ), array( $all['billing.grace_days']['min'], $all['billing.grace_days']['max'], $all['billing.grace_days']['unit'], $all['billing.grace_days']['max_length'] ) );
		$this->assertSame( 20, $all['site_name']['max_length'] );
		$this->assertSame( 'bool', $all['maintenance']['type'] );
	}

	public function test_an_unknown_key_is_a_mistake(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->catalogue->get( 'billing.grace' );
	}

	public function test_a_broken_definition_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Catalogue( new Settings( $this->db ), array( 'x' => array( 'type' => 'int', 'default' => 100, 'max' => 90 ) ) );
	}
}
