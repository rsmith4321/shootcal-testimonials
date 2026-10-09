<?php
/**
 * Review structured data.
 *
 * Off by default, and that default is a policy decision rather than an oversight.
 *
 * Google's review-snippet documentation treats reviews of your own business hosted
 * on your own site as self-serving, which makes them ineligible for the star review
 * feature. It also prohibits aggregating reviews or ratings from other websites, and
 * warns that violating those guidelines can draw a manual action. A testimonial page
 * that republishes a business's own Google reviews meets both conditions, so emitting
 * Review or AggregateRating markup there is a risk with no rich-result upside.
 *
 * The legitimate case is a directory-style site that reviews other businesses using
 * ratings collected directly from its own users. Google lists LocalBusiness and
 * Organization as supported "only for sites that capture reviews about other"
 * businesses or organizations. That is what this switch is for.
 *
 * When enabled, markup is emitted only for testimonials actually rendered on the
 * current page, never for the whole library.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Emits JSON-LD for rendered testimonials when the site opts in.
 */
class Schema {

	/**
	 * Posts rendered by a shortcode on this request.
	 *
	 * @var \WP_Post[]
	 */
	private array $rendered = array();

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'shootcal_testimonials_rendered', array( $this, 'collect' ), 10, 1 );
		add_action( 'wp_footer', array( $this, 'output' ) );
	}

	/**
	 * Record what a shortcode actually rendered.
	 *
	 * @param \WP_Post[] $posts Rendered testimonials.
	 */
	public function collect( array $posts ): void {
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$this->rendered[ $post->ID ] = $post;
			}
		}
	}

	/**
	 * Print the JSON-LD block.
	 */
	public function output(): void {
		if ( ! Config::get( 'schema_enabled', false ) || array() === $this->rendered ) {
			return;
		}

		$name = trim( (string) Config::get( 'business_name', '' ) );

		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}

		$entity_type = in_array( Config::get( 'schema_entity' ), array( 'LocalBusiness', 'Organization' ), true )
			? (string) Config::get( 'schema_entity' )
			: 'LocalBusiness';

		$reviews = array();
		$ratings = array();

		foreach ( $this->rendered as $post ) {
			if ( 'direct' !== Meta::normalize_source( get_post_meta( $post->ID, META_PREFIX . 'source', true ) ) ) {
				continue;
			}
			$rating = Meta::normalize_rating( get_post_meta( $post->ID, META_PREFIX . 'rating', true ) );
			$quote  = trim( wp_strip_all_tags( (string) $post->post_content, true ) );

			if ( '' === $quote ) {
				continue;
			}

			$review = array(
				'@type'         => 'Review',
				'itemReviewed'  => array(
					'@type' => $entity_type,
					'name'  => $name,
				),
				'reviewBody'    => $quote,
				'name'          => get_the_title( $post ),
				'datePublished' => mysql2date( 'Y-m-d', $post->post_date ),
				'author'        => array(
					'@type' => 'Person',
					'name'  => get_the_title( $post ),
				),
			);

			if ( $rating > 0 ) {
				$review['reviewRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'bestRating'  => 5,
					'worstRating' => 1,
				);
				$ratings[]          = $rating;
			}

			$reviews[] = $review;
		}

		if ( array() === $reviews ) {
			return;
		}

		$graph = $reviews;

		// Only ratings captured here contribute to the average. A review without a
		// score still counts as a review, so keep ratingCount and reviewCount distinct.
		$as_of = (string) Config::get( 'rating_as_of', '' );
		if ( array() !== $ratings && Config::valid_as_of_date( $as_of ) ) {
			$graph[] = array(
				'@type'          => $entity_type,
				'name'           => $name,
				'aggregateRating' => array(
					'@type'       => 'AggregateRating',
					'ratingValue' => round( array_sum( $ratings ) / count( $ratings ), 1 ),
					'ratingCount' => count( $ratings ),
					'reviewCount' => count( $reviews ),
					'bestRating'  => 5,
					'worstRating' => 1,
				),
			);
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		printf(
			"\n<script type=\"application/ld+json\" class=\"sct-schema\">%s</script>\n",
			/* JSON_HEX_TAG encodes < and > as \u003C and \u003E. Without it a stored value
			   containing </script>, which any user with edit_posts can place into a
			   testimonial from the post editor, would break out of this tag. The two
			   UNESCAPED flags only affect slashes and unicode, so the payload stays
			   readable. */
			wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
		);
	}
}
