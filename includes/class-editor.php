<?php
/** Testimonial metadata controls for the WordPress editor. @package ShootCalTestimonials */
declare( strict_types=1 );
namespace ShootCalTestimonials;
defined( 'ABSPATH' ) || exit;

class Editor {
	public function register(): void {
		add_action( 'add_meta_boxes_' . POST_TYPE, array( $this, 'add_box' ) );
		add_action( 'save_post_' . POST_TYPE, array( $this, 'save' ) );
	}
	public function add_box(): void {
		add_meta_box( 'sct-review-details', __( 'Review details', 'shootcal-testimonials' ), array( $this, 'render' ), POST_TYPE, 'normal' );
	}
	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'shootcal_testimonials_review_details', 'shootcal_testimonials_details_nonce' );
		echo '<p>' . esc_html__( 'Edit the reviewer name, quote, date, photo and categories with the standard WordPress controls. Source details below do not change the client’s wording.', 'shootcal-testimonials' ) . '</p>';
		$source = Meta::normalize_source( get_post_meta( $post->ID, 'shootcal_testimonials_source', true ) );
		echo '<p><label for="shootcal_testimonials_source"><strong>' . esc_html__( 'Originally posted on', 'shootcal-testimonials' ) . '</strong></label><br><select id="shootcal_testimonials_source" name="shootcal_testimonials_details[shootcal_testimonials_source]" aria-describedby="shootcal_testimonials_source_help">';
		foreach ( Meta::source_labels() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $source, $key, false ) . '>' . esc_html( 'other' === $key ? __( 'Other website', 'shootcal-testimonials' ) : $label ) . '</option>';
		}
		echo '</select><br><span id="shootcal_testimonials_source_help">' . esc_html__( 'Choose the site where this review originally appeared. Direct reviews have no external source label.', 'shootcal-testimonials' ) . '</span></p>';
		echo '<p><label for="shootcal_testimonials_source_url"><strong>' . esc_html__( 'Original review link (optional)', 'shootcal-testimonials' ) . '</strong></label><br><input type="url" class="widefat" id="shootcal_testimonials_source_url" name="shootcal_testimonials_details[shootcal_testimonials_source_url]" value="' . esc_attr( (string) get_post_meta( $post->ID, 'shootcal_testimonials_source_url', true ) ) . '" placeholder="https://" aria-describedby="shootcal_testimonials_source_url_help"><br><span id="shootcal_testimonials_source_url_help">' . esc_html__( 'Paste a public link to the review, reviewer profile or review listing. Without a link, the source is displayed as plain text.', 'shootcal-testimonials' ) . '</span></p>';
		echo '<p>' . esc_html__( 'Source attribution helps visitors verify a review. It does not grant permission to reuse content or guarantee Google search stars; review structured data is off by default.', 'shootcal-testimonials' ) . '</p>';
		foreach ( ( new Meta() )->fields() as $key => $args ) {
			if ( in_array( $key, array( 'shootcal_testimonials_source', 'shootcal_testimonials_source_url' ), true ) ) { continue; }
			$value = (string) get_post_meta( $post->ID, $key, true );
			echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $args['description'] ) . '</strong></label><br>';
			if ( in_array( $key, array( 'shootcal_testimonials_source', 'shootcal_testimonials_source_lookup', 'shootcal_testimonials_rating' ), true ) ) {
				$options = 'shootcal_testimonials_source' === $key ? Meta::SOURCES : ( 'shootcal_testimonials_rating' === $key ? array( '0', '1', '2', '3', '4', '5' ) : array_merge( array( '' ), Meta::LOOKUPS ) );
				echo '<select id="' . esc_attr( $key ) . '" name="shootcal_testimonials_details[' . esc_attr( $key ) . ']">';
				foreach ( $options as $option ) {
					echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( '' === $option ? __( 'Not researched', 'shootcal-testimonials' ) : (string) $option ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<textarea class="widefat" rows="2" id="' . esc_attr( $key ) . '" name="shootcal_testimonials_details[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
			}
			echo '</p>';
		}
		$email = (string) get_post_meta( $post->ID, Form::EMAIL_META_KEY, true );
		if ( '' !== $email ) {
			echo '<p><strong>' . esc_html__( 'Submitter email (private)', 'shootcal-testimonials' ) . '</strong><br>' . esc_html( $email ) . '</p>';
		}
	}
	public function save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$nonce = $_POST['shootcal_testimonials_details_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $nonce ) ), 'shootcal_testimonials_review_details' ) ) { return; }
		$values = $_POST['shootcal_testimonials_details'] ?? null;
		if ( ! is_array( $values ) ) { return; }
		foreach ( ( new Meta() )->fields() as $key => $args ) {
			if ( array_key_exists( $key, $values ) && is_scalar( $values[ $key ] ) ) {
				update_post_meta( $post_id, $key, wp_slash( Meta::sanitize_field( $key, wp_unslash( $values[ $key ] ) ) ) );
			}
		}
	}
}
