<?php
/**
 * Frontend assets.
 *
 * The stylesheet is enqueued only on requests that actually render the shortcode,
 * and the script only when View more is in use. No render-blocking output on pages
 * that have no testimonials.
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
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'sct_rendered', array( $this, 'note_usage' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'enqueue' ), 1 );
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

		wp_register_script(
			SLUG,
			PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			self::version_for( 'assets/js/frontend.js' ),
			true
		);
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
		if ( array() !== $posts ) {
			$this->used = true;
		}

		if ( $has_more ) {
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

		if ( $this->needs_script ) {
			wp_enqueue_script( SLUG );
		}
	}
}
