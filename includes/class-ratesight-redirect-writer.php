<?php
/**
 * Where POST /redirect stores a rule, and how DELETE /redirect removes it (3.15.5).
 *
 * Before 3.15.5 the handler wrote into the first redirect system it found (Redirection plugin, Rank
 * Math, Yoast Premium, else the plugin's own map) and had two bugs with the Redirection plugin:
 * Red_Item::get_for_url() returns an ARRAY of items (including every regex rule), so calling
 * ->update() or ->delete() on it threw, the write silently fell back to the native map while the
 * answer still said "redirection", and a delete never removed the Redirection item. A failed
 * Red_Item::create() (it returns a WP_Error, it does not throw) was also reported as applied.
 *
 * Since 3.15.5:
 *   - `method: "native"` on the request stores the rule in the plugin's own map whatever other
 *     redirect system is active. The native map is served on template_redirect and yields to a
 *     published page, so it works the same on every site. The Studiosight dashboard asks for it so
 *     it can verify and roll back the same way everywhere.
 *   - the Redirection path only touches items whose source is exactly this path (never a regex rule),
 *     checks WP_Error, uses an enabled WordPress group, and reports the method that really stored it.
 *   - DELETE removes the native rule and every exact-match Redirection item for the path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ratesight_Redirect_Writer {

	const NATIVE_OPTION = 'ratesight_rs_redirects';

	/** The redirect system the site would use by default (first match wins). */
	public static function detect_method(): string {
		if ( class_exists( 'Red_Item' ) || function_exists( 'red_get_table_name' ) ) return 'redirection';
		if ( class_exists( 'RankMath\\Redirections\\Redirections' ) ) return 'rankmath';
		if ( class_exists( 'WPSEO_Redirect' ) ) return 'yoast_premium';
		return 'native';
	}

	/** The method a request asks for: 'native' forces the plugin's own map, anything else is the default. */
	public static function method_for_request( $requested ): string {
		return ( is_string( $requested ) && strtolower( trim( $requested ) ) === 'native' ) ? 'native' : self::detect_method();
	}

	/** /slug/ form used as the native map key. */
	public static function native_key( string $from ): string {
		return '/' . trim( $from, '/' ) . '/';
	}

	/** The path forms a stored source may take, for exact matching. */
	public static function path_forms( string $path ): array {
		$bare = trim( $path, '/' );
		return array_values( array_unique( array( '/' . $bare . '/', '/' . $bare, $bare . '/', $bare ) ) );
	}

	/**
	 * Store the rule. Returns [ applied, method actually used, error|null ].
	 *
	 * @return array{0:bool,1:string,2:?string}
	 */
	public static function write( string $from, string $to, int $code, string $method ): array {
		switch ( $method ) {
			case 'redirection':
				$error = self::write_redirection( $from, $to, $code );
				if ( $error === null ) return array( true, 'redirection', null );
				// Could not store it in Redirection: the native map still works on this site.
				self::write_native( $from, $to, $code );
				return array( true, 'native', 'redirection: ' . $error );
			case 'rankmath':
			case 'yoast_premium':
				// Unchanged legacy writers live in the webhook handler; native is the fallback there too.
				return array( false, $method, 'not handled here' );
			case 'native':
			default:
				self::write_native( $from, $to, $code );
				return array( true, 'native', null );
		}
	}

	public static function write_native( string $from, string $to, int $code ): void {
		$redirects = get_option( self::NATIVE_OPTION, array() );
		if ( ! is_array( $redirects ) ) $redirects = array();
		$redirects[ self::native_key( $from ) ] = array(
			'redirect_to' => $to,
			'code'        => $code,
			'set_by_api'  => true,
			'created_at'  => current_time( 'mysql' ),
		);
		update_option( self::NATIVE_OPTION, $redirects, false );
	}

	/** Remove the native rule for a path. Returns whether one was removed. */
	public static function delete_native( string $path ): bool {
		$redirects = get_option( self::NATIVE_OPTION, array() );
		if ( ! is_array( $redirects ) ) return false;
		$removed = false;
		foreach ( self::path_forms( $path ) as $try ) {
			if ( isset( $redirects[ $try ] ) ) {
				unset( $redirects[ $try ] );
				$removed = true;
			}
		}
		if ( $removed ) update_option( self::NATIVE_OPTION, $redirects, false );
		return $removed;
	}

	/**
	 * Redirection items whose source is exactly this path (no regex rules).
	 *
	 * @return array<int,object>
	 */
	public static function redirection_items( string $path ): array {
		if ( ! class_exists( 'Red_Item' ) ) return array();
		$found = \Red_Item::get_for_url( '/' . trim( $path, '/' ) . '/' );
		$more  = \Red_Item::get_for_url( '/' . trim( $path, '/' ) );
		$items = array_merge( is_array( $found ) ? $found : array(), is_array( $more ) ? $more : array() );
		$forms = self::path_forms( $path );
		$out   = array();
		$seen  = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_url' ) ) continue;
			if ( method_exists( $item, 'is_regex' ) && $item->is_regex() ) continue;
			if ( ! in_array( (string) $item->get_url(), $forms, true ) ) continue;
			$id = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : spl_object_id( $item );
			if ( isset( $seen[ $id ] ) ) continue;
			$seen[ $id ] = true;
			$out[]       = $item;
		}
		return $out;
	}

	/** First enabled Redirection group for WordPress redirects, else 1. */
	public static function redirection_group_id(): int {
		if ( class_exists( 'Red_Group' ) && method_exists( 'Red_Group', 'get_all' ) ) {
			try {
				foreach ( (array) \Red_Group::get_all() as $group ) {
					$g = is_array( $group ) ? $group : (array) $group;
					$enabled = ! isset( $g['enabled'] ) || $g['enabled'] === true || $g['enabled'] === 1 || $g['enabled'] === '1';
					$module  = isset( $g['module_id'] ) ? (int) $g['module_id'] : 1;
					if ( $enabled && $module === 1 && isset( $g['id'] ) ) return (int) $g['id'];
				}
			} catch ( \Throwable $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		return 1;
	}

	/** Store in the Redirection plugin. Returns null on success, else why not. */
	public static function write_redirection( string $from, string $to, int $code ): ?string {
		try {
			$items = self::redirection_items( $from );
			if ( $items ) {
				$result = $items[0]->update( array(
					'url'         => $from,
					'action_type' => 'url',
					'action_data' => array( 'url' => $to ),
					'action_code' => $code,
					'match_type'  => 'url',
					'group_id'    => method_exists( $items[0], 'get_group_id' ) ? (int) $items[0]->get_group_id() : self::redirection_group_id(),
				) );
			} else {
				$result = \Red_Item::create( array(
					'url'         => $from,
					'action_type' => 'url',
					'action_data' => array( 'url' => $to ),
					'action_code' => $code,
					'match_type'  => 'url',
					'group_id'    => self::redirection_group_id(),
				) );
			}
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
				return method_exists( $result, 'get_error_message' ) ? (string) $result->get_error_message() : 'WP_Error';
			}
			if ( $result === false || $result === null ) return 'no result';
			return null;
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * Remove the rule for a path everywhere this plugin can: native map and exact-match Redirection
	 * items. Returns [ native removed, Redirection items removed ].
	 *
	 * @return array{0:bool,1:int}
	 */
	public static function delete( string $path ): array {
		$native = self::delete_native( $path );
		$count  = 0;
		try {
			foreach ( self::redirection_items( $path ) as $item ) {
				$item->delete();
				$count++;
			}
		} catch ( \Throwable $e ) {} // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		return array( $native, $count );
	}
}
