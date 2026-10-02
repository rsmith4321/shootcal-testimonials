<?php
/**
 * Post meta registration.
 *
 * The privacy split is deliberate and structural. Keys registered here with
 * show_in_rest are reachable from the REST API and therefore from any mobile or
 * headless consumer. Reviewer email addresses and private owner notes are never
 * registered at all, so they cannot leak through the API even by accident.
 *
 * The public submission form follows the same rule: it writes the submitter's email to
 * `_sct_submitter_email`, a leading-underscore key that appears nowhere in fields() and
 * is therefore unreachable through the REST API. Do not register it.
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
	 * The only three states a source lookup may be recorded as.
	 *
	 * 'matched' means the original review was found on the named platform and its
	 * identity reconciled. 'not-found' means the platform was reachable and holds no
	 * matching review, including the case where the testimonial never came from a
	 * platform at all. 'blocked' means the platform refused to answer, so the question
	 * is still open and must not be reported as settled.
	 *
	 * There is deliberately no fourth state and no 'unknown'. An empty value means the
	 * lookup has not been researched yet, and normalize_lookup() preserves that instead
	 * of guessing a plausible answer.
	 *
	 * @var string[]
	 */
	public const LOOKUPS = array(
		'matched',
		'not-found',
		'blocked',
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

			/*
			 * Source reconciliation state.
			 *
			 * Recording that a testimonial exists on a platform is not the same as
			 * recording that it was checked. These two keys keep the difference visible:
			 * `sct_source_lookup` holds one of Meta::LOOKUPS, and `sct_source_note` holds
			 * the human reason for that value, for example "WeddingWire returns HTTP 403
			 * to anonymous requests". An empty lookup means nobody has researched it yet,
			 * which is a real state and is never filled in by a guess.
			 */
			META_PREFIX . 'source_lookup'         => array(
				'type'          => 'string',
				'description'   => __( 'Whether the original review was found on the named platform: matched, not-found or blocked. Empty means not yet researched.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'source_note'           => array(
				'type'          => 'string',
				'description'   => __( 'Why the source lookup reached its recorded value.', 'shootcal-testimonials' ),
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
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),

			/*
			 * Best-of provenance.
			 *
			 * A published testimonial is one record chosen from several reviews the same
			 * client may have left on different platforms, often on the same day with
			 * different wording. Recording the rejected alternates keeps that decision
			 * recoverable instead of leaving it as tribal knowledge, and stops Google
			 * being treated as the default source when a longer review exists elsewhere.
			 *
			 * `sct_alternates` is a JSON string of objects shaped
			 * {platform, url, date, note}. It is stored as a string rather than an array
			 * so the REST surface stays a single scalar and no serialization ambiguity is
			 * introduced for headless consumers.
			 */
			META_PREFIX . 'alternates'            => array(
				'type'          => 'string',
				'description'   => __( 'JSON list of reviews by the same author on other platforms that were not chosen.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			),
			META_PREFIX . 'selection_reason'      => array(
				'type'          => 'string',
				'description'   => __( 'Why this review was chosen over the alternates.', 'shootcal-testimonials' ),
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
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
	 * Sanitize a source lookup value against the known list.
	 *
	 * Unlike normalize_source() this never falls back to a plausible answer. Anything
	 * outside LOOKUPS, including an empty string, comes back empty, because empty is the
	 * honest representation of "not yet researched". Coercing it to 'matched' or
	 * 'not-found' would report a conclusion nobody reached.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function normalize_lookup( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return in_array( $value, self::LOOKUPS, true ) ? $value : '';
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
