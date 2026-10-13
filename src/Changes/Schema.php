<?php
/**
 * The changes table.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Changes;

/**
 * `{prefix}readiness_index_changes`, created and upgraded with `dbDelta` and versioned in one
 * non-autoloaded option, so an upgrade is a comparison in the admin and never a query on the
 * front end.
 */
final class Schema {

	const VERSION        = 1;
	const VERSION_OPTION = 'readiness_index_db_version';

	/**
	 * The table's name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'readiness_index_changes';
	}

	/**
	 * Creates or upgrades the table when the stored version is behind. Cheap to call.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Creates the table now.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		// dbDelta is particular: each column on its own line, two spaces after PRIMARY KEY.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fixer varchar(40) NOT NULL,
  checks varchar(255) NOT NULL DEFAULT '',
  action varchar(8) NOT NULL DEFAULT 'apply',
  status varchar(12) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_gmt datetime NOT NULL,
  prior_json longtext NULL,
  new_json longtext NULL,
  extra_json longtext NULL,
  verified tinyint(1) NULL,
  undoes bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY fixer_status (fixer,status)
) {$charset};";
		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Drops the table and its version, for an uninstall.
	 */
	public static function drop(): void {
		global $wpdb;
		$table = esc_sql( self::table() );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table, named by this class and never by input.
		delete_option( self::VERSION_OPTION );
	}
}
