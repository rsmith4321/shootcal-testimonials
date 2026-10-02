<?php
/**
 * Uninstall handler.
 *
 * Removes plugin options only. Testimonials, their photos and their category terms are
 * authored content and are deliberately left in place, because deleting a plugin to try
 * something else should never destroy a client review library.
 *
 * To remove the content as well, define SCT_REMOVE_CONTENT as true in wp-config.php
 * before uninstalling. That is an explicit, opt-in, destructive step.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shootcal_testimonials_options' );

if ( defined( 'SCT_REMOVE_CONTENT' ) && true === constant( 'SCT_REMOVE_CONTENT' ) ) {
	$posts = get_posts(
		array(
			'post_type'      => 'sct_testimonial',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $posts as $post_id ) {
		// Force-delete so nothing is left in Trash holding references to media.
		wp_delete_post( (int) $post_id, true );
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'sct_category',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( (int) $term_id, 'sct_category' );
		}
	}
}
