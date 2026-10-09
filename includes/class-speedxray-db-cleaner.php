<?php
/**
 * Step 4: database cleanup.
 *
 * @package SpeedXRay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Counts and removes database clutter. Uses WordPress's own delete functions where they
 * exist so related meta, terms and caches are cleaned too; works in batches to avoid timeouts.
 *
 * Direct queries are intentional here: these are one-off maintenance counts/deletes on core
 * tables with no WordPress API equivalent, and caching their results would defeat the purpose.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class SpeedXRay_DB_Cleaner {

	const BATCH = 500;

	public static function items() {
		return array(
			'revisions'          => array( __( 'Post revisions', 'speed-x-ray' ), __( 'Old copies saved on every edit. The current version of every post is not touched.', 'speed-x-ray' ) ),
			'auto_drafts'        => array( __( 'Auto-drafts older than 7 days', 'speed-x-ray' ), __( 'Empty drafts WordPress creates when the editor is opened.', 'speed-x-ray' ) ),
			'trash_posts'        => array( __( 'Posts and pages in trash', 'speed-x-ray' ), __( 'Items you already moved to trash. Deleted permanently.', 'speed-x-ray' ) ),
			'spam_comments'      => array( __( 'Spam comments', 'speed-x-ray' ), __( 'Comments already marked as spam.', 'speed-x-ray' ) ),
			'trash_comments'     => array( __( 'Trashed comments', 'speed-x-ray' ), __( 'Comments already moved to trash.', 'speed-x-ray' ) ),
			'expired_transients' => array( __( 'Expired transients', 'speed-x-ray' ), __( 'Temporary cached values whose expiry time has passed.', 'speed-x-ray' ) ),
			'orphan_postmeta'    => array( __( 'Orphaned post meta', 'speed-x-ray' ), __( 'Meta rows whose post no longer exists.', 'speed-x-ray' ) ),
		);
	}

	public static function counts() {
		$counts = array();
		foreach ( array( 'revisions', 'auto_drafts', 'trash_posts', 'spam_comments', 'trash_comments', 'expired_transients', 'orphan_postmeta' ) as $item ) {
			$counts[ $item ] = self::count( $item );
		}
		return $counts;
	}

	public static function count( $item ) {
		global $wpdb;
		switch ( $item ) {
			case 'revisions':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
			case 'auto_drafts':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified_gmt < %s", self::week_ago() ) );
			case 'trash_posts':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
			case 'spam_comments':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
			case 'trash_comments':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
			case 'expired_transients':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d",
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
						time()
					)
				);
			case 'orphan_postmeta':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
		}
		return 0;
	}

	/**
	 * Cleans one batch.
	 *
	 * @param string $item One of the keys from items().
	 * @return int How many rows were removed.
	 */
	public static function clean( $item ) {
		global $wpdb;
		$done = 0;
		switch ( $item ) {
			case 'revisions':
				foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT %d", self::BATCH ) ) as $id ) {
					$done += wp_delete_post_revision( (int) $id ) ? 1 : 0;
				}
				break;
			case 'auto_drafts':
				foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified_gmt < %s LIMIT %d", self::week_ago(), self::BATCH ) ) as $id ) {
					$done += wp_delete_post( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'trash_posts':
				foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT %d", self::BATCH ) ) as $id ) {
					$done += wp_delete_post( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'spam_comments':
			case 'trash_comments':
				$status = 'spam_comments' === $item ? 'spam' : 'trash';
				foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d", $status, self::BATCH ) ) as $id ) {
					$done += wp_delete_comment( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'expired_transients':
				$before = self::count( 'expired_transients' );
				delete_expired_transients( true );
				$done = max( 0, $before - self::count( 'expired_transients' ) );
				break;
			case 'orphan_postmeta':
				$ids = array_map( 'intval', $wpdb->get_col( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL LIMIT 5000" ) );
				if ( $ids ) {
					$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are built from the ID count above.
					$done = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($placeholders)", $ids ) );
				}
				break;
		}
		return $done;
	}

	private static function week_ago() {
		return gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
	}
}
