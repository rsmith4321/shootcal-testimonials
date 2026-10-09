<?php
/**
 * Frontend assets.
 *
 * The stylesheet is enqueued only on requests that actually render the shortcode,
 * and the script whenever review dialogs are in use. PhotoSwipe CSS is list-only and
 * its JavaScript module loads only when a review is opened.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and conditionally enqueues assets.
 */
class Assets {

	/**
	 * Whether a shortcode rendered during this request.
	 */
	private bool $used = false;

	/**
	 * Whether any rendered shortcode asked for View more.
	 */
	private bool $needs_script = false;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'protect_form_cache' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_known_content' ) );
		add_action( 'shootcal_testimonials_rendered', array( $this, 'note_usage' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'enqueue' ), 1 );
	}

	/** Submission nonces and one-time notices must not be shared through page caches. */
	public function protect_form_cache(): void {
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && $this->content_has_form( $post->post_content ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress cache-control contract.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			nocache_headers();
		}
	}

	/** Follow only referenced synced blocks that can actually contain the form. */
	private function content_has_form( string $content, array $seen = array() ): bool {
		if ( has_shortcode( $content, Form::SHORTCODE ) ) { return true; }
		if ( ! has_block( 'core/block', $content ) ) { return false; }
		foreach ( parse_blocks( $content ) as $block ) {
			if ( $this->block_has_form( $block, $seen ) ) { return true; }
		}
		return false;
	}

	/** @param array<string,mixed> $block Parsed block tree node. */
	private function block_has_form( array $block, array $seen ): bool {
		if ( 'core/block' === ( $block['blockName'] ?? '' ) ) {
			$ref = (int) ( $block['attrs']['ref'] ?? 0 );
			if ( $ref > 0 && ! isset( $seen[ $ref ] ) ) {
				$seen[ $ref ] = true;
				$pattern = get_post( $ref );
				if ( $pattern instanceof \WP_Post && 'wp_block' === $pattern->post_type && $this->content_has_form( $pattern->post_content, $seen ) ) { return true; }
			}
		}
		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			if ( $this->block_has_form( $inner, $seen ) ) { return true; }
		}
		return false;
	}

	/** Enqueue before the head only when the current authored content needs these assets. */
	public function enqueue_known_content(): void {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) { return; }
		$content = $post->post_content;
		if ( has_shortcode( $content, 'shootcal_testimonials' ) || has_shortcode( $content, Form::SHORTCODE ) || has_block( Block::NAME, $content ) ) {
			$this->register_assets();
			wp_enqueue_style( SLUG );
			if ( has_shortcode( $content, 'shootcal_testimonials' ) || has_block( Block::NAME, $content ) || preg_match( '/mode=[\"\']dialog[\"\']/', $content ) ) {
				wp_enqueue_script( SLUG );
			}
			if ( has_shortcode( $content, 'shootcal_testimonials' ) || has_block( Block::NAME, $content ) ) { wp_enqueue_style( SLUG . '-photoswipe' ); }
		}
	}

	/**
	 * Register handles without enqueuing them.
	 */
	public function register_assets(): void {
		wp_register_style(
			SLUG,
			PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			self::version_for( 'assets/css/frontend.css' )
		);
		wp_register_style( SLUG . '-photoswipe', PLUGIN_URL . 'assets/photoswipe/photoswipe.css', array(), self::version_for( 'assets/photoswipe/photoswipe.css' ) );

		if ( ! wp_script_is( SLUG, 'registered' ) ) {
			wp_register_script(
				SLUG,
				PLUGIN_URL . 'assets/js/frontend.js',
				array(),
				self::version_for( 'assets/js/frontend.js' ),
				true
			);
			wp_localize_script(
				SLUG,
				'shootcalTestimonialsFrontend',
				array(
					/* translators: %shown% is the visible review count; %total% is the total rendered count. */
					'shownSingular' => __( '%shown% of %total% testimonial shown', 'shootcal-testimonials' ),
					/* translators: %shown% is the visible review count; %total% is the total rendered count. */
					'shownPlural'   => __( '%shown% of %total% testimonials shown', 'shootcal-testimonials' ),
					'photoSwipeUrl' => PLUGIN_URL . 'assets/photoswipe/photoswipe.esm.js?ver=' . rawurlencode( self::version_for( 'assets/photoswipe/photoswipe.esm.js' ) ),
					'moreReviews'   => __( 'More reviews are available', 'shootcal-testimonials' ),
					'continueReviews' => __( 'Continue to the next reviews', 'shootcal-testimonials' ),
					'loadingReviews' => __( 'Loading more reviews…', 'shootcal-testimonials' ),
					'loadFailed' => __( 'More reviews could not be loaded. Use Next reviews to continue.', 'shootcal-testimonials' ),
				)
			);
		}
	}

	/**
	 * Cache-busting version for one asset file.
	 *
	 * The host serves static files with a thirty day max-age and no revalidation, and
	 * the ?ver= query only changes when VERSION changes, so a fix shipped inside one
	 * release would stay invisible to returning visitors for a month. Appending the
	 * file's own modification time makes the query change exactly when the file does.
	 *
	 * @param string $relative Path below the plugin directory.
	 */
	public static function version_for( string $relative ): string {
		$file  = PLUGIN_DIR . $relative;
		$mtime = is_file( $file ) ? filemtime( $file ) : false;

		return VERSION . ( false === $mtime ? '' : '.' . $mtime );
	}

	/**
	 * Record that output happened.
	 *
	 * @param \WP_Post[] $posts    Rendered testimonials.
	 * @param bool       $has_more Whether View more is enabled.
	 */
	public function note_usage( array $posts, bool $has_more = false ): void {
		if ( array() !== $posts ) { $this->register_assets(); }
		if ( array() !== $posts ) {
			$this->used = true;
		}

		if ( array() !== $posts ) {
			$this->needs_script = true;
		}
	}

	/**
	 * Enqueue only what this request needs.
	 */
	public function enqueue(): void {
		if ( ! $this->used ) {
			return;
		}

		wp_enqueue_style( SLUG );
		wp_enqueue_style( SLUG . '-photoswipe' );

		if ( $this->needs_script ) {
			wp_enqueue_script( SLUG );
		}
	}
}
