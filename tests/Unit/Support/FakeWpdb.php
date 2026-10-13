<?php
/**
 * An in-memory stand-in for the one table the plugin has.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Support;

/**
 * Understands exactly the statements `Changes\Log` makes, and nothing else, so a change to
 * the log's SQL that this does not recognize fails the test that made it instead of passing
 * against a fake that accepts anything.
 */
final class FakeWpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Last inserted id.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Rows by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $rows = array();

	/**
	 * Charset clause.
	 */
	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	/**
	 * Insert a row.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data  Columns.
	 */
	public function insert( $table, $data ) {
		++$this->insert_id;
		$this->rows[ $this->insert_id ] = array( 'id' => $this->insert_id ) + $data + array(
			'verified' => null,
			'undoes'   => null,
		);
		return 1;
	}

	/**
	 * Update a row by id.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data  Columns.
	 * @param array<string,mixed> $where Where.
	 */
	public function update( $table, $data, $where ) {
		$id = (int) $where['id'];
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = $data + $this->rows[ $id ];
		return 1;
	}

	/**
	 * Substitutes placeholders, quoting strings.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $query, 1 );
		}
		return $query;
	}

	/**
	 * One row.
	 *
	 * @param string $query  Query.
	 * @param string $output Ignored.
	 */
	public function get_row( $query, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WordPress's signature.
		if ( preg_match( "/WHERE fixer = '([\w-]+)' AND action = 'apply' AND status = 'applied' ORDER BY id DESC LIMIT 1$/", $query, $m ) ) {
			foreach ( array_reverse( $this->rows ) as $row ) {
				if ( $row['fixer'] === $m[1] && 'apply' === $row['action'] && 'applied' === $row['status'] ) {
					return $row;
				}
			}
			return null;
		}
		if ( ! preg_match( '/WHERE id = (\d+)$/', $query, $m ) ) {
			throw new \LogicException( 'FakeWpdb does not understand: ' . $query ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $this->rows[ (int) $m[1] ] ?? null;
	}

	/**
	 * Several rows.
	 *
	 * @param string $query  Query.
	 * @param string $output Ignored.
	 */
	public function get_results( $query, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WordPress's signature.
		if ( ! preg_match( '/ORDER BY id DESC LIMIT (\d+) OFFSET (\d+)$/', $query, $m ) ) {
			throw new \LogicException( 'FakeWpdb does not understand: ' . $query ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$rows = array_reverse( array_values( $this->rows ) );
		return array_slice( $rows, (int) $m[2], (int) $m[1] );
	}

	/**
	 * A count.
	 *
	 * @param string $query Query.
	 */
	public function get_var( $query ) {
		if ( ! preg_match( '/SELECT COUNT\(\*\)/', $query ) ) {
			throw new \LogicException( 'FakeWpdb does not understand: ' . $query ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return count( $this->rows );
	}

	/**
	 * The two statements the log runs.
	 *
	 * @param string $query Query.
	 */
	public function query( $query ) {
		if ( preg_match( "/^UPDATE .* SET status = '(\w+)' WHERE fixer = '([\w-]+)' AND action = 'apply' AND status = 'applied'$/", $query, $m ) ) {
			foreach ( $this->rows as $id => $row ) {
				if ( $row['fixer'] === $m[2] && 'apply' === $row['action'] && 'applied' === $row['status'] ) {
					$this->rows[ $id ]['status'] = $m[1];
				}
			}
			return 1;
		}
		if ( 0 === strpos( $query, 'DROP TABLE' ) ) {
			$this->rows = array();
			return 1;
		}
		throw new \LogicException( 'FakeWpdb does not understand: ' . $query ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
