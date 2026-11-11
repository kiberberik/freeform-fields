<?php
/**
 * Cached fingerprints of ACF's own posts.
 *
 * @package FreeformFields
 */

namespace FreeformFields\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The ACF fallback (groups and options pages read from ACF's posts while ACF is inactive) needs
 * to know when those posts change. Counting them on every request scans the posts table, so the
 * fingerprint is kept in one autoloaded option, cleared whenever WordPress changes such a post
 * and recomputed at least daily (for changes made with direct SQL).
 */
final class AcfStamp {

	public const OPTION = 'wpff_acf_stamps';

	/** Fingerprint kinds and the post types they cover. */
	private const KINDS = array(
		'groups' => array( 'acf-field-group', 'acf-field' ),
		'pages'  => array( 'acf-ui-options-page' ),
	);

	public static function register(): void {
		add_action( 'clean_post_cache', array( self::class, 'on_post_change' ), 10, 2 );
		add_action( 'activated_plugin', array( self::class, 'flush' ) );
		add_action( 'deactivated_plugin', array( self::class, 'flush' ) );
	}

	/**
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post.
	 */
	public static function on_post_change( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
		foreach ( self::KINDS as $types ) {
			if ( in_array( $type, $types, true ) ) {
				self::flush();
				return;
			}
		}
	}

	public static function flush(): void {
		if ( false !== get_option( self::OPTION ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Fingerprint of ACF's posts of one kind: '' when there are none.
	 *
	 * @param string $kind `groups` or `pages`.
	 */
	public static function get( string $kind ): string {
		$stamps = get_option( self::OPTION );
		$stamps = is_array( $stamps ) ? $stamps : array();
		if ( isset( $stamps[ $kind ]['hash'], $stamps[ $kind ]['time'] ) && (int) $stamps[ $kind ]['time'] > time() - DAY_IN_SECONDS ) {
			return (string) $stamps[ $kind ]['hash'];
		}

		global $wpdb;
		$types = self::KINDS[ $kind ] ?? array();
		if ( array() === $types ) {
			return '';
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of placeholders; cached in an option.
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MAX(post_modified_gmt) AS m FROM {$wpdb->posts} WHERE post_type IN ($in)", $types ) );
		$hash = $row && (int) $row->n ? md5( $row->n . '|' . $row->m ) : '';

		$stamps[ $kind ] = array(
			'hash' => $hash,
			'time' => time(),
		);
		update_option( self::OPTION, $stamps, true );
		return $hash;
	}
}
