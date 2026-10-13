<?php
namespace ReadinessIndex\Tests\Support;

/**
 * Enough of JSON Schema to hold a fixture to the server's OpenAPI components: type,
 * enum, required, properties, additionalProperties false, items, anyOf and $ref.
 *
 * Strict on purpose. The runtime models ignore a field they do not know, so that an
 * additive server change cannot break a site that has not updated; this test is where
 * such a change is noticed, because the fixtures are regenerated with the server.
 */
final class SchemaValidator {

	/**
	 * The OpenAPI document.
	 *
	 * @var array<string,mixed>
	 */
	private $doc;

	/**
	 * Builds a validator over the contract document.
	 *
	 * @param array<string,mixed> $doc Decoded openapi.json.
	 */
	public function __construct( array $doc ) {
		$this->doc = $doc;
	}

	/**
	 * Validates a value against a named component.
	 *
	 * @param mixed  $value     Decoded value.
	 * @param string $component Component name, such as SiteResponse.
	 * @return array<int,string> Problems; empty when valid.
	 */
	public function validate( $value, string $component ): array {
		return $this->check( $value, $this->doc['components']['schemas'][ $component ], '$' );
	}

	/**
	 * Checks one value against one schema node.
	 *
	 * @param mixed               $value  Value.
	 * @param array<string,mixed> $schema Schema node.
	 * @param string              $path   Path for messages.
	 * @return array<int,string>
	 */
	private function check( $value, array $schema, string $path ): array {
		if ( isset( $schema['$ref'] ) ) {
			$name = substr( $schema['$ref'], strlen( '#/components/schemas/' ) );
			return $this->check( $value, $this->doc['components']['schemas'][ $name ], $path );
		}
		if ( isset( $schema['anyOf'] ) ) {
			foreach ( $schema['anyOf'] as $option ) {
				if ( array() === $this->check( $value, $option, $path ) ) {
					return array();
				}
			}
			return array( $path . ' matches no anyOf option' );
		}
		$problems = array();
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$problems[] = $path . ' is not in the enum';
		}
		if ( isset( $schema['type'] ) && ! $this->is_type( $value, $schema['type'] ) ) {
			return array( $path . ' is not ' . $schema['type'] );
		}
		if ( 'object' === ( $schema['type'] ?? null ) ) {
			foreach ( $schema['required'] ?? array() as $name ) {
				if ( ! array_key_exists( $name, $value ) ) {
					$problems[] = $path . '.' . $name . ' is required';
				}
			}
			foreach ( $value as $name => $child ) {
				if ( isset( $schema['properties'][ $name ] ) ) {
					$problems = array_merge( $problems, $this->check( $child, $schema['properties'][ $name ], $path . '.' . $name ) );
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					$problems[] = $path . '.' . $name . ' is not in the contract';
				}
			}
		}
		if ( 'array' === ( $schema['type'] ?? null ) && isset( $schema['items'] ) ) {
			foreach ( $value as $i => $child ) {
				$problems = array_merge( $problems, $this->check( $child, $schema['items'], $path . '.' . $i ) );
			}
		}
		return $problems;
	}

	/**
	 * Whether a value has a JSON type.
	 *
	 * @param mixed  $value Value.
	 * @param string $type  JSON type name.
	 */
	private function is_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'object':
				return is_array( $value ) && ( array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
			case 'array':
				return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'boolean':
				return is_bool( $value );
			case 'null':
				return null === $value;
			default:
				return true;
		}
	}
}
