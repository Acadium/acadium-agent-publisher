<?php
/**
 * Storage for OAuth clients, authorization codes, grants and tokens.
 *
 * One table, one row per item. Secrets (codes, tokens, client secrets) are
 * stored only as SHA-256 hashes; a database leak does not reveal usable
 * tokens.
 *
 *   type     token_hash                 meaning
 *   client   sha256(client_id)          registered client (data: name, redirect_uris, secret hash)
 *   code     sha256(code)               authorization code, 10 minutes, single use
 *   grant    sha256(grant_id)           an approved connection (client + agent user)
 *   access   sha256(access token)       1 hour
 *   refresh  sha256(refresh token)      30 days, rotated on every use
 *
 * @package Acadium_Agent_Publisher
 */

defined( 'ABSPATH' ) || exit;

// Direct queries on the plugin's own table; nothing here is cacheable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

final class Agent_Publisher_OAuth_Store {

	const DB_VERSION = 1;

	const CODE_TTL    = 600;
	const ACCESS_TTL  = HOUR_IN_SECONDS;
	const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/** Most clients kept; oldest unused ones are removed beyond this. */
	const MAX_CLIENTS = 200;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agent_publisher_oauth';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(16) NOT NULL,
				token_hash char(64) NOT NULL,
				client_id varchar(64) NOT NULL DEFAULT '',
				grant_id varchar(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				data longtext NULL,
				created datetime NOT NULL,
				expires datetime NULL,
				last_used datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY type_grant (type, grant_id),
				KEY client (client_id)
			) $charset;"
		);
		update_option( 'agent_publisher_oauth_db', self::DB_VERSION );
	}

	public static function maybe_install() {
		if ( (int) get_option( 'agent_publisher_oauth_db' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function uninstall() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		delete_option( 'agent_publisher_oauth_db' );
	}

	/* ------------------------------------------------------------------ */
	/* Primitives                                                          */
	/* ------------------------------------------------------------------ */

	public static function random( $bytes = 32 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	public static function hash( $secret ) {
		return hash( 'sha256', (string) $secret );
	}

	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function expires( $ttl ) {
		return gmdate( 'Y-m-d H:i:s', time() + $ttl );
	}

	private static function insert( $type, $secret, $fields = array(), $ttl = null ) {
		global $wpdb;
		$row = array(
			'type'       => $type,
			'token_hash' => self::hash( $secret ),
			'client_id'  => $fields['client_id'] ?? '',
			'grant_id'   => $fields['grant_id'] ?? '',
			'user_id'    => (int) ( $fields['user_id'] ?? 0 ),
			'data'       => isset( $fields['data'] ) ? wp_json_encode( $fields['data'] ) : null,
			'created'    => self::now(),
			'expires'    => $ttl ? self::expires( $ttl ) : null,
		);
		return (bool) $wpdb->insert( self::table(), $row );
	}

	/** A live (unexpired) row of $type for $secret, or null. */
	private static function find( $type, $secret ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE token_hash = %s AND type = %s AND ( expires IS NULL OR expires > %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				self::hash( $secret ),
				$type,
				self::now()
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['data'] = $row['data'] ? json_decode( $row['data'], true ) : array();
		return $row;
	}

	private static function delete_hash( $secret ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'token_hash' => self::hash( $secret ) ) );
	}

	private static function touch( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'last_used' => self::now() ), array( 'id' => (int) $id ) );
	}

	/** Remove expired codes and tokens (cheap; run opportunistically). */
	public static function purge_expired() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires IS NOT NULL AND expires < %s", self::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	}

	/* ------------------------------------------------------------------ */
	/* Clients (RFC 7591)                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{client_id:string,client_secret:?string}
	 */
	public static function create_client( $name, array $redirect_uris, $auth_method ) {
		self::prune_clients();
		$client_id = self::random( 16 );
		$secret    = 'none' === $auth_method ? null : self::random( 32 );
		self::insert( 'client', $client_id, array(
			'client_id' => $client_id,
			'data'      => array(
				'name'          => $name,
				'redirect_uris' => array_values( $redirect_uris ),
				'auth_method'   => $auth_method,
				'secret_hash'   => $secret ? self::hash( $secret ) : null,
			),
		) );
		return array( 'client_id' => $client_id, 'client_secret' => $secret );
	}

	public static function get_client( $client_id ) {
		return $client_id ? self::find( 'client', $client_id ) : null;
	}

	public static function client_secret_ok( array $client, $secret ) {
		$expected = $client['data']['secret_hash'] ?? null;
		if ( ! $expected ) {
			return true; // Public client (token_endpoint_auth_method "none").
		}
		return is_string( $secret ) && hash_equals( $expected, self::hash( $secret ) );
	}

	/** Keep at most MAX_CLIENTS; drop the oldest clients that have no grant. */
	private static function prune_clients() {
		global $wpdb;
		$table = self::table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE type = 'client'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		if ( $count < self::MAX_CLIENTS ) {
			return;
		}
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			"SELECT c.id FROM $table c LEFT JOIN $table g ON g.type = 'grant' AND g.client_id = c.client_id
			 WHERE c.type = 'client' AND g.id IS NULL ORDER BY c.created ASC LIMIT " . (int) ( $count - self::MAX_CLIENTS + 1 )
		);
		foreach ( $ids as $id ) {
			$wpdb->delete( $table, array( 'id' => (int) $id ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Authorization codes                                                 */
	/* ------------------------------------------------------------------ */

	public static function create_code( $client_id, $user_id, array $data ) {
		$code = self::random( 32 );
		self::insert( 'code', $code, array( 'client_id' => $client_id, 'user_id' => $user_id, 'data' => $data ), self::CODE_TTL );
		return $code;
	}

	/** Look up and delete a code in one step (single use). */
	public static function consume_code( $code ) {
		$row = self::find( 'code', $code );
		if ( $row && self::delete_hash( $code ) ) {
			return $row;
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Grants and tokens                                                   */
	/* ------------------------------------------------------------------ */

	public static function create_grant( $client_id, $user_id, $resource, $approved_by ) {
		$grant_id = self::random( 16 );
		self::insert( 'grant', $grant_id, array(
			'client_id' => $client_id,
			'grant_id'  => $grant_id,
			'user_id'   => $user_id,
			'data'      => array( 'resource' => $resource, 'approved_by' => (int) $approved_by ),
		) );
		return $grant_id;
	}

	/**
	 * @return array{access_token:string,refresh_token:string,expires_in:int}
	 */
	public static function issue_tokens( $grant_id, $client_id, $user_id ) {
		self::purge_expired();
		$access  = self::random( 32 );
		$refresh = self::random( 32 );
		$fields  = array( 'client_id' => $client_id, 'grant_id' => $grant_id, 'user_id' => $user_id );
		self::insert( 'access', $access, $fields, self::ACCESS_TTL );
		self::insert( 'refresh', $refresh, $fields, self::REFRESH_TTL );
		self::touch_grant( $grant_id );
		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_in'    => self::ACCESS_TTL,
		);
	}

	/** Look up and delete a refresh token (rotation: each is usable once). */
	public static function consume_refresh( $refresh ) {
		$row = self::find( 'refresh', $refresh );
		if ( $row && self::delete_hash( $refresh ) ) {
			return $row;
		}
		return null;
	}

	/** User ID for a valid access token, or 0. */
	public static function user_for_access_token( $token ) {
		$row = self::find( 'access', $token );
		if ( ! $row || ! self::grant_exists( $row['grant_id'] ) ) {
			return 0;
		}
		self::touch( $row['id'] );
		return (int) $row['user_id'];
	}

	public static function grant_exists( $grant_id ) {
		return (bool) self::find( 'grant', $grant_id );
	}

	private static function touch_grant( $grant_id ) {
		$row = self::find( 'grant', $grant_id );
		if ( $row ) {
			self::touch( $row['id'] );
		}
	}

	/** Revoke a single token (RFC 7009). Revoking a refresh token ends its grant. */
	public static function revoke_token( $token ) {
		foreach ( array( 'refresh', 'access' ) as $type ) {
			$row = self::find( $type, $token );
			if ( $row ) {
				if ( 'refresh' === $type ) {
					self::revoke_grant( $row['grant_id'] );
				} else {
					self::delete_hash( $token );
				}
				return;
			}
		}
	}

	/** Remove a grant and every code/token issued under it. */
	public static function revoke_grant( $grant_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'grant_id' => $grant_id ) );
	}

	/** Remove every grant and token of a user (e.g. when the user is deleted). */
	public static function revoke_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ) );
	}

	/** Approved connections, newest first, for the settings page. */
	public static function grants() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT g.grant_id, g.user_id, g.created, g.last_used, c.data AS client_data
			 FROM $table g LEFT JOIN $table c ON c.type = 'client' AND c.client_id = g.client_id
			 WHERE g.type = 'grant' ORDER BY g.created DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$client             = $row['client_data'] ? json_decode( $row['client_data'], true ) : array();
			$row['client_name'] = $client['name'] ?? '';
			$row['redirect']    = $client['redirect_uris'][0] ?? '';
			unset( $row['client_data'] );
		}
		return $rows;
	}
}
