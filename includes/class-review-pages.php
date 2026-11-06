<?php
/**
 * Further pages of reviews as their own addresses for search engines.
 *
 * A long list prints up to its page size and links to the next page. Search engines can only
 * read those later reviews if the later page isn't marked as a copy of the first, so each real
 * page names itself as canonical. A category filter, a page past the end and page one all keep
 * the page's normal address. Nothing here changes what a visitor sees.
 *
 * The plugin this replaced used ?ttpage=N. Those addresses go to the page itself.
 *
 * @package ShootCalTestimonials
 */

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical addresses for review pages, and the old paging addresses.
 */
final class Review_Pages {

	public const PAGE_VAR = 'shootcal_testimonials_review_page';

	public const OLD_PAGE_VAR = 'ttpage';

	/**
	 * Hook registration. Rank Math and The SEO Framework print their own canonical, core prints
	 * the rest.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'redirect_old_address' ), 1 );
		add_filter( 'get_canonical_url', array( $this, 'core_canonical' ), 20, 2 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical' ), 20 );
		add_filter( 'the_seo_framework_meta_render_data', array( $this, 'seo_framework_tags' ), 20 );
	}

	/**
	 * Send an old ?ttpage=N address on a page with reviews to that page, keeping anything else
	 * in the address.
	 */
	public function redirect_old_address(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public address.
		if ( ! isset( $_GET[ self::OLD_PAGE_VAR ] ) || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || array() === self::lists( $post ) ) {
			return;
		}
		$keep = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public address.
		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			if ( self::OLD_PAGE_VAR !== $key && is_string( $value ) ) {
				$keep[ $key ] = rawurlencode( $value );
			}
		}
		wp_safe_redirect( add_query_arg( $keep, get_permalink( $post ) ), 301, 'ShootCal Testimonials' );
		exit;
	}

	/**
	 * The canonical WordPress prints when no SEO plugin takes over.
	 *
	 * @param string|false $url  Canonical address.
	 * @param \WP_Post     $post The post.
	 * @return string|false
	 */
	public function core_canonical( $url, $post ) {
		return $post instanceof \WP_Post && get_queried_object_id() === $post->ID ? $this->canonical( $url ) : $url;
	}

	/**
	 * The canonical for this request.
	 *
	 * @param mixed $url Canonical address so far.
	 * @return mixed
	 */
	public function canonical( $url ) {
		$own = $this->own_address();
		return '' === $own ? $url : $own;
	}

	/**
	 * The SEO Framework's tags, just before they print.
	 *
	 * @param array<string,mixed> $tags Tags to render.
	 * @return array<string,mixed>
	 */
	public function seo_framework_tags( $tags ) {
		$own = $this->own_address();
		if ( '' !== $own && is_array( $tags ) && isset( $tags['canonical']['attributes']['href'] ) ) {
			$tags['canonical']['attributes']['href'] = $own;
		}
		return $tags;
	}

	/**
	 * This request's own address if it is a real later page of reviews, otherwise empty.
	 */
	private function own_address(): string {
		static $own = null;
		if ( null !== $own ) {
			return $own;
		}
		$own = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only public address.
		if ( ! is_singular() || ! isset( $_GET[ self::PAGE_VAR ] ) || ! is_string( $_GET[ self::PAGE_VAR ] ) || isset( $_GET[ Shortcode::QUERY_VAR ] ) ) {
			return $own;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET[ self::PAGE_VAR ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$page = ctype_digit( $raw ) ? (int) $raw : 0;
		$post = get_queried_object();
		if ( $page < 2 || $page > 100 || ! $post instanceof \WP_Post ) {
			return $own;
		}
		$shortcode = new Shortcode();
		foreach ( self::lists( $post ) as $atts ) {
			if ( $shortcode->has_page( $atts, $page ) ) {
				$own = add_query_arg( self::PAGE_VAR, $page, get_permalink( $post ) );
				break;
			}
		}
		return $own;
	}

	/**
	 * The review lists saved in a post, as shortcode attributes.
	 *
	 * @param \WP_Post $post The post.
	 * @return array<int,array<string,mixed>>
	 */
	private static function lists( \WP_Post $post ): array {
		$lists = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$lists ): void {
			foreach ( $blocks as $block ) {
				if ( 'shootcal/testimonials' === ( $block['blockName'] ?? '' ) ) {
					$lists[] = Block::shortcode_atts( is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array() );
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $post->post_content ) );
		if ( has_shortcode( $post->post_content, 'shootcal_testimonials' ) && preg_match_all( '/' . get_shortcode_regex( array( 'shootcal_testimonials' ) ) . '/', $post->post_content, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $match ) {
				$atts    = shortcode_parse_atts( $match[3] );
				$lists[] = is_array( $atts ) ? $atts : array();
			}
		}
		return $lists;
	}
}
