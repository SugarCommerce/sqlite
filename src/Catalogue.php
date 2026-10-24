<?php
/**
 * The settings an admin page offers, with their types and bounds.
 *
 * @package   SugarCommerce\Sqlite
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.9.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Sqlite;

/**
 * Class Catalogue
 *
 * Settings is the store; this is the form in front of it. Each setting the
 * application lets an operator change is defined once — its type, default,
 * bounds, label and help — and that one definition is what the page prints,
 * what a submission is checked against, what is saved, and what the code
 * reads back. An empty table is then a correctly configured install, and a
 * setting removed from the code stops existing rather than lingering as a
 * row nothing reads.
 *
 * The bounds are not decoration: they stop a typo in a box becoming "delete
 * every file after 0 days". They are applied on the way out as well as in,
 * so a row saved before a bound changed cannot hand the code a value it does
 * not expect.
 *
 * Types:
 *
 * - `int`  — a whole number; `min`, `max` (default 0 … PHP_INT_MAX), `unit`
 *            for messages ("days").
 * - `bool` — a checkbox; absent from a submitted form means off.
 * - `string` — one line, trimmed; `max_length` (default 255).
 * - `text` — several lines, trimmed; `max_length` (default 5000).
 *
 * A blank int, string or text field removes the setting, so its default
 * applies again.
 *
 * ```php
 * $catalogue = new Catalogue( $settings, array(
 *     'billing.grace_days' => array( 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 90, 'unit' => 'days',
 *                                     'label' => 'Billing grace', 'help' => '…' ),
 *     'site_name'          => array( 'type' => 'string', 'default' => 'Shop', 'label' => 'Site name' ),
 * ) );
 *
 * $problems = $catalogue->problems( $form );   // key => message; render them, or…
 * $changed  = $catalogue->save( $form );       // …keys whose value changed
 * $days     = $catalogue->get( 'billing.grace_days' );
 * ```
 *
 * @since 1.9.0
 */
final class Catalogue {

	/**
	 * The definitions, checked.
	 *
	 * @var array<string, array{type: string, default: int|bool|string, min: int, max: int, max_length: int, unit: string, label: string, help: string}>
	 */
	private readonly array $definitions;

	/**
	 * @since 1.9.0
	 *
	 * @param Settings                            $settings    The store.
	 * @param array<string, array<string, mixed>> $definitions Key => definition.
	 *
	 * @throws \InvalidArgumentException On a definition with no or an unknown
	 *                                   type, a default that is not of its
	 *                                   type or outside its bounds, or min above
	 *                                   max.
	 */
	public function __construct( private readonly Settings $settings, array $definitions ) {
		$checked = array();
		$int     = static fn ( mixed $value, int $otherwise ): int => is_int( $value ) ? $value : $otherwise;
		$text    = static fn ( mixed $value, string $otherwise ): string => is_string( $value ) ? $value : $otherwise;

		foreach ( $definitions as $key => $definition ) {
			$type = $text( $definition['type'] ?? null, '' );
			$min  = $int( $definition['min'] ?? null, 0 );
			$max  = $int( $definition['max'] ?? null, PHP_INT_MAX );
			$def  = $definition['default'] ?? null;

			$fits = match ( $type ) {
				'int'            => is_int( $def ) && $def >= $min && $def <= $max && $min <= $max,
				'bool'           => is_bool( $def ),
				'string', 'text' => is_string( $def ),
				default          => false,
			};

			if ( '' === $key || ! $fits || ! ( is_int( $def ) || is_bool( $def ) || is_string( $def ) ) ) {
				throw new \InvalidArgumentException( 'Setting ' . json_encode( $key ) . ' needs a type of int, bool, string or text, and a default of that type within its bounds.' );
			}

			$checked[ $key ] = array(
				'type'       => $type,
				'default'    => $def,
				'min'        => $min,
				'max'        => $max,
				'max_length' => $int( $definition['max_length'] ?? null, 'text' === $type ? 5000 : 255 ),
				'unit'       => $text( $definition['unit'] ?? null, '' ),
				'label'      => $text( $definition['label'] ?? null, $key ),
				'help'       => $text( $definition['help'] ?? null, '' ),
			);
		}

		$this->definitions = $checked;
	}

	/**
	 * A setting's value, of its type, within its bounds.
	 *
	 * @since 1.9.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return int|bool|string
	 *
	 * @throws \InvalidArgumentException On a key that is not defined —
	 *                                   a typo, not a missing row.
	 */
	public function get( string $key ): int|bool|string {
		$definition = $this->definition( $key );
		$default    = $definition['default'];

		return match ( $definition['type'] ) {
			'int'   => min( $definition['max'], max( $definition['min'], $this->settings->int( $key, is_int( $default ) ? $default : 0 ) ) ),
			'bool'  => $this->settings->bool( $key, true === $default ),
			default => $this->settings->string( $key, is_string( $default ) ? $default : '' ),
		};
	}

	/**
	 * A whole-number setting, within its bounds.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return int
	 *
	 * @throws \InvalidArgumentException On a key that is not defined, or not
	 *                                   an `int`.
	 */
	public function int( string $key ): int {
		$value = $this->typed( $key, 'int' );

		return is_int( $value ) ? $value : 0;
	}

	/**
	 * A `string` or `text` setting.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException On a key that is not defined, or not
	 *                                   a `string` or `text`.
	 */
	public function string( string $key ): string {
		$value = $this->typed( $key, 'string', 'text' );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * A checkbox setting.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return bool
	 *
	 * @throws \InvalidArgumentException On a key that is not defined, or not
	 *                                   a `bool`.
	 */
	public function bool( string $key ): bool {
		return true === $this->typed( $key, 'bool' );
	}

	/**
	 * Whether a key is one of the defined settings — what a command line or
	 * an API checks before letting a caller change it, so values kept in the
	 * same table for the code's own use can't be.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->definitions[ $key ] );
	}

	/**
	 * Every setting, for the page: value, default and what to print.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, array{value: int|bool|string, default: int|bool|string, type: string, label: string, help: string, unit: string, min: int|null, max: int|null, max_length: int|null, field: string}>
	 */
	public function all(): array {
		$out = array();

		foreach ( $this->definitions as $key => $definition ) {
			$number = 'int' === $definition['type'];
			$words  = in_array( $definition['type'], array( 'string', 'text' ), true );

			$out[ $key ] = array(
				'value'      => $this->get( $key ),
				'default'    => $definition['default'],
				'type'       => $definition['type'],
				'label'      => $definition['label'],
				'help'       => $definition['help'],
				'unit'       => $definition['unit'],
				'min'        => $number ? $definition['min'] : null,
				'max'        => $number ? $definition['max'] : null,
				'max_length' => $words ? $definition['max_length'] : null,
				'field'      => self::field( $key ),
			);
		}

		return $out;
	}

	/**
	 * What is wrong with a submission, setting by setting.
	 *
	 * Keyed by setting name, as submitted under field()'s names or the keys
	 * themselves. A setting absent from the submission is not checked; a
	 * blank one is fine (its default applies).
	 *
	 * @since 1.9.0
	 *
	 * @param array<array-key, mixed> $submitted Form values.
	 *
	 * @return array<string, string> Key => message; empty when it can be saved.
	 */
	public function problems( array $submitted ): array {
		$problems = array();

		foreach ( $this->definitions as $key => $definition ) {
			$raw = $this->submitted( $submitted, $key );

			if ( null === $raw || 'bool' === $definition['type'] ) {
				continue;
			}

			$value = is_scalar( $raw ) ? trim( (string) $raw ) : null;

			if ( null === $value ) {
				$problems[ $key ] = 'Not a value this setting can hold.';
			} elseif ( '' === $value ) {
				continue;
			} elseif ( 'int' === $definition['type'] ) {
				$unit = '' !== $definition['unit'] ? ' ' . $definition['unit'] : '';

				if ( 1 !== preg_match( '/^-?\d{1,18}$/D', $value ) ) {
					$problems[ $key ] = 'Enter a whole number' . ( '' !== $unit ? ' of' . $unit : '' ) . '.';
				} elseif ( (int) $value < $definition['min'] || (int) $value > $definition['max'] ) {
					$problems[ $key ] = sprintf( 'Must be between %d and %d%s.', $definition['min'], $definition['max'], $unit );
				}
			} elseif ( mb_strlen( $value ) > $definition['max_length'] ) {
				$problems[ $key ] = sprintf( 'At most %d characters.', $definition['max_length'] );
			}
		}

		return $problems;
	}

	/**
	 * Save a submission, and say which settings changed.
	 *
	 * Check problems() first; this does not refuse, it clamps a number into
	 * bounds and cuts text to length, so a value it is handed is always one
	 * the code can use. A blank field removes the setting so its default
	 * applies; an unticked checkbox (absent) is off. A value equal to what is
	 * already in effect — a default written back — is not a change and
	 * writes nothing.
	 *
	 * @since 1.9.0
	 *
	 * @param array<array-key, mixed> $submitted Form values, from a form that
	 *                                           was submitted (for checkboxes).
	 *
	 * @return list<string> The keys whose value changed.
	 */
	public function save( array $submitted ): array {
		$changed = array();

		foreach ( $this->definitions as $key => $definition ) {
			$raw = $this->submitted( $submitted, $key );

			if ( ( null !== $raw || 'bool' === $definition['type'] ) && $this->set( $key, $raw ) ) {
				$changed[] = $key;
			}
		}

		return $changed;
	}

	/**
	 * Save one setting, by the same rules as save(), and say whether its
	 * value changed — for a command line or an API that changes one thing.
	 *
	 * Check problems() first; this clamps and cuts rather than refusing. A
	 * blank value, or null, removes the setting so its default applies; a
	 * checkbox is on for any value but blank, null, 0, false, off and no.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value The new value, as a form would submit it.
	 *
	 * @return bool True when the value in effect changed.
	 *
	 * @throws \InvalidArgumentException On a key that is not defined.
	 */
	public function set( string $key, mixed $value ): bool {
		$definition = $this->definition( $key );
		$before     = $this->get( $key );
		$text       = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( 'bool' === $definition['type'] ) {
			$on = ! in_array( strtolower( $text ), array( '', '0', 'false', 'off', 'no' ), true );

			if ( $on !== $before ) {
				$this->settings->set( $key, $on ? '1' : '0' );
			}
		} elseif ( '' === $text ) {
			$this->settings->forget( $key );
		} else {
			$target = 'int' === $definition['type']
				? min( $definition['max'], max( $definition['min'], false === ( $number = filter_var( $text, FILTER_VALIDATE_INT ) ) ? ( is_int( $definition['default'] ) ? $definition['default'] : 0 ) : $number ) )
				: mb_substr( 'string' === $definition['type'] ? (string) preg_replace( '/\s+/u', ' ', $text ) : $text, 0, $definition['max_length'] );

			if ( $target !== $before ) {
				$this->settings->set( $key, (string) $target );
			}
		}

		return $this->get( $key ) !== $before;
	}

	/**
	 * The form field name for a key: dots become `__`, because PHP turns a
	 * dot in a submitted name into an underscore.
	 *
	 * @since 1.9.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return string
	 */
	public static function field( string $key ): string {
		return str_replace( '.', '__', $key );
	}

	/**
	 * A definition, or the refusal for an unknown key.
	 *
	 * @since 1.9.0
	 *
	 * @param string $key Setting name.
	 *
	 * @return array{type: string, default: int|bool|string, min: int, max: int, max_length: int, unit: string, label: string, help: string}
	 */
	private function definition( string $key ): array {
		return $this->definitions[ $key ] ?? throw new \InvalidArgumentException( 'No such setting: ' . $key );
	}

	/**
	 * A setting's value, refusing a key of another type: reading a number
	 * as text is a mistake to find now, not a quiet zero later.
	 *
	 * @since 1.11.0
	 *
	 * @param string $key      Setting name.
	 * @param string ...$types The types the caller can take.
	 *
	 * @return int|bool|string
	 */
	private function typed( string $key, string ...$types ): int|bool|string {
		$type = $this->definition( $key )['type'];

		if ( ! in_array( $type, $types, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Setting %s is a %s, not a %s.', $key, $type, implode( ' or ', $types ) ) );
		}

		return $this->get( $key );
	}

	/**
	 * A setting's submitted value, under its field name or its key; null
	 * when it was not submitted.
	 *
	 * @since 1.9.0
	 *
	 * @param array<array-key, mixed> $submitted Form values.
	 * @param string                  $key       Setting name.
	 *
	 * @return mixed
	 */
	private function submitted( array $submitted, string $key ): mixed {
		return $submitted[ self::field( $key ) ] ?? $submitted[ $key ] ?? null;
	}
}
