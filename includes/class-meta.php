<?php
/**
 * Post meta registration.
 *
 * The privacy split is deliberate and structural. Keys registered here with
 * show_in_rest are reachable from the REST API and therefore from any mobile or
 * headless consumer. Reviewer email addresses and private owner notes are never
 * registered at all, so they cannot leak through the API even by accident.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Registers testimonial meta.
 */
class Meta {

	/**
	 * Source platforms a testimonial may have been collected from.
	 *
	 * Drives which attribution mark and label render. Only 'google' produces the
	 * Google G with "Originally posted on Google".
	 *
	 * @var string[]
	 */
	public const SOURCES = array(
		'google',
		'zola',
		'weddingwire',
		'theknot',
		'yelp',
		'facebook',
		'direct',
		'other',
	);

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	/**
	 * Register every public meta key.
	 */
	public function register_meta(): void {
		$fields = $this->fields();

		foreach ( $fields as $key => $args ) {
			register_post_meta( POST_TYPE, $key, $args );
		}
	}

	/**
	 * The registered field set.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array(
			META_PREFIX . 'rating'                => array(
				'type'          => 'integer',
				'description'   => __( 'Star rating, 1 to 5.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'review_title'          => array(
				'type'          => 'string',
				'description'   => __( 'Optional short headline for the review.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'source'                => array(
				'type'          => 'string',
				'description'   => __( 'Platform the review was collected from.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => 'direct',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'source_url'            => array(
				'type'          => 'string',
				'description'   => __( 'Public link to the original review.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'source_review_id'      => array(
				'type'          => 'string',
				'description'   => __( 'Provider review identifier, used for reconciliation.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'reviewer_profile_url'  => array(
				'type'          => 'string',
				'description'   => __( 'Public reviewer profile link.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'date_provenance'       => array(
				'type'          => 'string',
				'description'   => __( 'How the displayed date was established.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'consent_recorded'      => array(
				'type'          => 'string',
				'description'   => __( 'Date the reviewer permitted website reuse, if recorded.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'consent_note'          => array(
				'type'          => 'string',
				'description'   => __( 'Short note on how permission was given.', 'shootcal-testimonials' ),
				'single'        => true,
				'show_in_rest'  => true,
				'default'       => '',
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
		);
	}

	/**
	 * Sanitize a source value against the known list.
	 *
	 * Unknown values fall back to 'direct', which renders no third-party mark.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function normalize_source( $value ): string {
		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, self::SOURCES, true ) ? $value : 'direct';
	}

	/**
	 * Clamp a rating to the 0 to 5 range. Zero means unrated and renders no stars.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function normalize_rating( $value ): int {
		$rating = (int) $value;

		return max( 0, min( 5, $rating ) );
	}
}
