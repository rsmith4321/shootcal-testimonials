<?php
/**
 * Post type and taxonomy registration.
 *
 * Testimonials are stored natively: the quote lives in post_content, the reviewer
 * display name in post_title, the review date in post_date, and the photo as the
 * featured image. That keeps the block editor, revisions, search and the REST API
 * working without custom code.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the testimonial post type and its category taxonomy.
 */
class Post_Type {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
	}

	/**
	 * Register the testimonial post type.
	 */
	public function register_post_type(): void {
		register_post_type(
			POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Testimonials', 'shootcal-testimonials' ),
					'singular_name' => __( 'Testimonial', 'shootcal-testimonials' ),
					'add_new'       => __( 'Add New', 'shootcal-testimonials' ),
					'add_new_item'  => __( 'Add New Testimonial', 'shootcal-testimonials' ),
					'edit_item'     => __( 'Edit Testimonial', 'shootcal-testimonials' ),
					'view_item'     => __( 'View Testimonial', 'shootcal-testimonials' ),
					'search_items'  => __( 'Search Testimonials', 'shootcal-testimonials' ),
					'not_found'     => __( 'No testimonials found.', 'shootcal-testimonials' ),
				),
				'description'  => __( 'Client testimonials and reviews.', 'shootcal-testimonials' ),
				'public'       => true,
				// Individual testimonials have no public single URL by default, matching the
				// shortcode-rendered model the site already uses. Flip this on if a permalink
				// archive is ever wanted.
				'publicly_queryable' => Config::get( 'public_single_urls', false ),
				'show_in_rest'   => true,
				'show_ui'        => true,
				'show_in_menu'   => true,
				'menu_icon'      => 'dashicons-format-quote',
				'menu_position'  => 26,
				'supports'       => array( 'title', 'editor', 'thumbnail', 'revisions', 'excerpt', 'custom-fields' ),
				'has_archive'    => false,
				'rewrite'        => false,
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Register the hierarchical category taxonomy.
	 *
	 * Mirrors the session-type grouping the site already uses, but as a native
	 * WordPress taxonomy so categories behave the way editors expect.
	 */
	public function register_taxonomy(): void {
		register_taxonomy(
			TAXONOMY,
			POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Testimonial Categories', 'shootcal-testimonials' ),
					'singular_name' => __( 'Testimonial Category', 'shootcal-testimonials' ),
					'search_items'  => __( 'Search Categories', 'shootcal-testimonials' ),
					'all_items'     => __( 'All Categories', 'shootcal-testimonials' ),
					'edit_item'     => __( 'Edit Category', 'shootcal-testimonials' ),
					'add_new_item'  => __( 'Add New Category', 'shootcal-testimonials' ),
				),
				'hierarchical' => true,
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'rewrite'      => false,
			)
		);
	}
}
