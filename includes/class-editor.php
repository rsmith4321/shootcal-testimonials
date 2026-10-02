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
		wp_nonce_field( 'sct_review_details', 'sct_details_nonce' );
		echo '<p>' . esc_html__( 'Edit the reviewer name, quote, date, photo and categories with the standard WordPress controls. Source details below do not change the client’s wording.', 'shootcal-testimonials' ) . '</p>';
		foreach ( ( new Meta() )->fields() as $key => $args ) {
			$value = (string) get_post_meta( $post->ID, $key, true );
			echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $args['description'] ) . '</strong></label><br>';
			if ( in_array( $key, array( 'sct_source', 'sct_source_lookup', 'sct_rating' ), true ) ) {
				$options = 'sct_source' === $key ? Meta::SOURCES : ( 'sct_rating' === $key ? array( '0', '1', '2', '3', '4', '5' ) : array_merge( array( '' ), Meta::LOOKUPS ) );
				echo '<select id="' . esc_attr( $key ) . '" name="sct_details[' . esc_attr( $key ) . ']">';
				foreach ( $options as $option ) {
					echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( '' === $option ? __( 'Not researched', 'shootcal-testimonials' ) : (string) $option ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<textarea class="widefat" rows="2" id="' . esc_attr( $key ) . '" name="sct_details[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
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
		$nonce = $_POST['sct_details_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $nonce ) ), 'sct_review_details' ) ) { return; }
		$values = $_POST['sct_details'] ?? null;
		if ( ! is_array( $values ) ) { return; }
		foreach ( ( new Meta() )->fields() as $key => $args ) {
			if ( array_key_exists( $key, $values ) && is_scalar( $values[ $key ] ) ) {
				update_post_meta( $post_id, $key, wp_slash( Meta::sanitize_field( $key, wp_unslash( $values[ $key ] ) ) ) );
			}
		}
	}
}
