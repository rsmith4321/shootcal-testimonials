<?php
/**
 * Uninstall handler.
 *
 * Removes plugin options only. Testimonials, their photos and their category terms are
 * authored content and are deliberately left in place, because deleting a plugin to try
 * something else should never destroy a client review library.
 *
 * To remove the content as well, define SHOOTCAL_TESTIMONIALS_REMOVE_CONTENT as true in wp-config.php
 * before uninstalling. That is an explicit, opt-in, destructive step.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shootcal_testimonials_options' );
delete_option( 'shootcal_testimonials_storage_version' );
delete_option( 'shootcal_testimonials_render_cache_version' );

if ( defined( 'SHOOTCAL_TESTIMONIALS_REMOVE_CONTENT' ) && true === constant( 'SHOOTCAL_TESTIMONIALS_REMOVE_CONTENT' ) ) {
	$shootcal_testimonials_posts = get_posts(
		array(
			'post_type'      => 'shootcal_testimonial',
			'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $shootcal_testimonials_posts as $shootcal_testimonials_post_id ) {
		// Force-delete so nothing is left in Trash holding references to media.
		wp_delete_post( (int) $shootcal_testimonials_post_id, true );
	}

	if ( ! taxonomy_exists( 'shootcal_testimonials_category' ) ) { register_taxonomy( 'shootcal_testimonials_category', 'shootcal_testimonial' ); }
	$shootcal_testimonials_terms = get_terms(
		array(
			'taxonomy'   => 'shootcal_testimonials_category',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( ! is_wp_error( $shootcal_testimonials_terms ) ) {
		foreach ( $shootcal_testimonials_terms as $shootcal_testimonials_term_id ) {
			wp_delete_term( (int) $shootcal_testimonials_term_id, 'shootcal_testimonials_category' );
		}
	}
}
