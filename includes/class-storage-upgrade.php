<?php
/** Preserve native WordPress identities while replacing pre-review storage prefixes. */
declare( strict_types=1 );
namespace ShootCalTestimonials;
defined( 'ABSPATH' ) || exit;

class Storage_Upgrade {

	public const OPTION = 'shootcal_testimonials_storage_version';

	/** Old public bookmarks remain readable; all generated links use the new names. */
	public static function legacy_query_aliases(): void {
		foreach ( array( 'sct_category' => 'shootcal_testimonials_category', 'sct_review_page' => 'shootcal_testimonials_review_page', 'sct_form_open' => 'shootcal_testimonials_form_open' ) as $old => $new ) {
			if ( ! isset( $_GET[ $new ] ) && isset( $_GET[ $old ] ) ) {
				// Values remain untrusted and are validated by the receiving renderer.
				$_GET[ $new ] = $_GET[ $old ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Compatibility alias, not a mutation or trust boundary.
			}
		}
	}

	/** One serialized, atomic migration. No authored field, numeric ID or value changes. */
	public static function run(): void {
		if ( '1' === get_option( self::OPTION ) ) { return; }
		global $wpdb;
		$lock = 'shootcal_testimonials_' . substr( hash( 'sha256', DB_NAME . $wpdb->prefix ), 0, 32 );
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
		if ( 1 !== $locked ) { self::failure(); return; }
		$transaction = false;
		try {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'sct_testimonial' ) );
			$terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'sct_category' ) );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'Storage lookup failed.' ); }
			if ( $ids || $terms ) {
				// Do not attempt a partial, non-transactional migration on older installations.
				foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->term_taxonomy, $wpdb->termmeta ) as $table ) {
					$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
					if ( 'InnoDB' !== $engine ) { throw new \RuntimeException( 'Transactional storage required.' ); }
				}
				self::query( 'START TRANSACTION' ); $transaction = true;
				// A collision is not permission to overwrite an existing value.
				foreach ( array( array( $wpdb->postmeta, 'post_id', $wpdb->posts, 'ID', 'post_type', 'sct_testimonial' ), array( $wpdb->termmeta, 'term_id', $wpdb->term_taxonomy, 'term_id', 'taxonomy', 'sct_category' ) ) as $spec ) {
					[ $meta, $id, $parent, $parent_id, $field, $value ] = $spec;
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_id, m.%i AS object_id, m.meta_key FROM %i m INNER JOIN %i p ON p.%i = m.%i WHERE p.%i = %s AND (m.meta_key LIKE %s OR m.meta_key = %s)", $id, $meta, $parent, $parent_id, $id, $field, $value, $wpdb->esc_like( 'sct_' ) . '%', '_sct_submitter_email' ) );
					if ( $wpdb->last_error ) { throw new \RuntimeException( 'Metadata lookup failed.' ); }
					foreach ( $rows as $row ) {
						$key = '_sct_submitter_email' === $row->meta_key ? '_shootcal_testimonials_submitter_email' : 'shootcal_testimonials_' . substr( $row->meta_key, 4 );
						$conflict = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM %i WHERE %i = %d AND meta_key = %s LIMIT 1", $meta, $id, $row->object_id, $key ) );
						if ( $wpdb->last_error || $conflict ) { throw new \RuntimeException( 'Metadata collision.' ); }
					}
					foreach ( $rows as $row ) {
						$key = '_sct_submitter_email' === $row->meta_key ? '_shootcal_testimonials_submitter_email' : 'shootcal_testimonials_' . substr( $row->meta_key, 4 );
						self::query( $wpdb->prepare( "UPDATE %i SET meta_key = %s WHERE meta_id = %d AND meta_key = %s", $meta, $key, $row->meta_id, $row->meta_key ) );
					}
				}
				$collision = $wpdb->get_var( $wpdb->prepare( "SELECT old.term_id FROM {$wpdb->term_taxonomy} old INNER JOIN {$wpdb->term_taxonomy} fresh ON old.term_id = fresh.term_id WHERE old.taxonomy = %s AND fresh.taxonomy = %s LIMIT 1", 'sct_category', TAXONOMY ) );
				if ( $wpdb->last_error || $collision ) { throw new \RuntimeException( 'Taxonomy collision.' ); }
				self::query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s", POST_TYPE, 'sct_testimonial' ) );
				self::query( $wpdb->prepare( "UPDATE {$wpdb->term_taxonomy} SET taxonomy = %s WHERE taxonomy = %s", TAXONOMY, 'sct_category' ) );
				self::query( 'COMMIT' ); $transaction = false;
				foreach ( $ids as $id ) { clean_post_cache( (int) $id ); }
				foreach ( $terms as $id ) { wp_cache_delete( (int) $id, 'terms' ); wp_cache_delete( (int) $id, 'term_meta' ); }
				wp_cache_set_terms_last_changed();
				delete_option( 'sct_category_children' );
				delete_option( TAXONOMY . '_children' );
			}
			update_option( self::OPTION, '1', false );
		} catch ( \Throwable $error ) {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
			self::failure();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function query( string $sql ): void {
		if ( false === $GLOBALS['wpdb']->query( $sql ) ) { throw new \RuntimeException( 'Storage update failed.' ); }
	}

	private static function failure(): void {
		wp_die( esc_html__( 'ShootCal Testimonials could not safely upgrade its storage. Existing review data has been preserved. Please contact the site administrator.', 'shootcal-testimonials' ), '', array( 'response' => 503 ) );
	}
}
