<?php
/**
 * Everything the plugin has changed, newest first.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Changes;

/**
 * The repository over the changes table. A row is one apply or one undo, with the prior and
 * new stored choices verbatim as JSON, who did it and when (GMT). Nothing sensitive is stored:
 * a user id and the owner's own choices, never a key, a token or what a scanned site said.
 */
final class Log {

	const APPLIED    = 'applied';
	const UNDONE     = 'undone';
	const FAILED     = 'failed';
	const SUPERSEDED = 'superseded';

	const APPLY = 'apply';
	const UNDO  = 'undo';

	/**
	 * Writes a row and returns its id.
	 *
	 * @param array<string,mixed> $row fixer, checks, action, status, user_id, prior, new, extra, verified, undoes.
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
			Schema::table(),
			array(
				'fixer'       => (string) $row['fixer'],
				'checks'      => implode( ',', (array) ( $row['checks'] ?? array() ) ),
				'action'      => (string) ( $row['action'] ?? self::APPLY ),
				'status'      => (string) $row['status'],
				'user_id'     => (int) ( $row['user_id'] ?? 0 ),
				'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'prior_json'  => self::encode( $row['prior'] ?? null ),
				'new_json'    => self::encode( $row['new'] ?? null ),
				'extra_json'  => self::encode( $row['extra'] ?? null ),
				'verified'    => isset( $row['verified'] ) ? ( $row['verified'] ? 1 : 0 ) : null,
				'undoes'      => isset( $row['undoes'] ) ? (int) $row['undoes'] : null,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates columns of one row.
	 *
	 * @param int                 $id     Row id.
	 * @param array<string,mixed> $fields `status` and `verified`: the only columns that change after a row is written.
	 */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$set = array();
		if ( isset( $fields['status'] ) ) {
			$set['status'] = (string) $fields['status'];
		}
		if ( array_key_exists( 'verified', $fields ) ) {
			$set['verified'] = null === $fields['verified'] ? null : ( $fields['verified'] ? 1 : 0 );
		}
		if ( array() !== $set ) {
			$wpdb->update( Schema::table(), $set, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		}
	}

	/**
	 * One row, or null.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$table = esc_sql( Schema::table() );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	/**
	 * Rows, newest first.
	 *
	 * @param int $limit  How many.
	 * @param int $offset How many to skip.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = esc_sql( Schema::table() );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		return array_map( array( self::class, 'decode_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The applied row of a fixer that Undo would act on, or null when the fixer is off.
	 *
	 * @param string $fixer Fixer id.
	 * @return array<string,mixed>|null
	 */
	public static function latest_applied( string $fixer ): ?array {
		global $wpdb;
		$table = esc_sql( Schema::table() );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE fixer = %s AND action = %s AND status = %s ORDER BY id DESC LIMIT 1", $fixer, self::APPLY, self::APPLIED ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	/**
	 * How many rows there are.
	 */
	public static function count(): int {
		global $wpdb;
		$table = esc_sql( Schema::table() );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
	}

	/**
	 * Marks every applied row of a fixer as replaced by a newer one, so its Undo is refused.
	 *
	 * @param string $fixer Fixer id.
	 */
	public static function supersede_applied( string $fixer ): void {
		global $wpdb;
		$table = esc_sql( Schema::table() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE fixer = %s AND action = %s AND status = %s", self::SUPERSEDED, $fixer, self::APPLY, self::APPLIED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table.
	}

	/**
	 * The JSON stored for a value: verbatim, with nothing escaped that JSON does not need to.
	 *
	 * @param mixed $value A decoded value.
	 */
	private static function encode( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		// Stored choices are objects. An empty one is `{}`, as the vectors write it, and not the
		// `[]` PHP would give an empty array, so what is stored is what was recorded.
		return array() === $value ? '{}' : (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * A stored row with its JSON columns decoded.
	 *
	 * @param array<string,mixed> $row A database row.
	 * @return array<string,mixed>
	 */
	private static function decode_row( array $row ): array {
		$decode = static function ( $json ) {
			return null === $json || '' === $json ? null : json_decode( (string) $json, true );
		};
		return array(
			'id'          => (int) $row['id'],
			'fixer'       => (string) $row['fixer'],
			'checks'      => '' === (string) $row['checks'] ? array() : explode( ',', (string) $row['checks'] ),
			'action'      => (string) $row['action'],
			'status'      => (string) $row['status'],
			'user_id'     => (int) $row['user_id'],
			'created_gmt' => (string) $row['created_gmt'],
			'prior'       => $decode( $row['prior_json'] ),
			'new'         => $decode( $row['new_json'] ),
			'extra'       => $decode( $row['extra_json'] ),
			'verified'    => null === $row['verified'] ? null : (bool) (int) $row['verified'],
			'undoes'      => null === $row['undoes'] ? null : (int) $row['undoes'],
		);
	}
}
