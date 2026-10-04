<?php
/**
 * Self-registers with optimizer and page-cache plugins so they leave this plugin alone.
 *
 * Unused-CSS removers strip the stylesheet because card dialogs are cloned after their
 * crawl, delay/defer wrappers break the small script that enables those dialogs, and
 * lazy-loaders must not rewrite images the dialog clones from the card. Every filter
 * name and value shape below was verified against real source: Perfmatters 2.6.8 and
 * Nginx Helper 2.4.1 on the live site, Autoptimize and LiteSpeed Cache from their current
 * wordpress.org packages, WP Rocket from its public filter API as already used by the
 * ShootCal Social Feed compatibility class.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the plugin's assets and selectors to optimizer exclusion lists.
 */
class Compatibility {

	/** URL fragment matching every front-end asset shipped by this plugin. */
	private const ASSET_PATH = '/shootcal-testimonials/';

	/** Selector prefixes whose rules must survive unused-CSS removal. */
	private const SELECTORS = array(
		'.sct-section',
		'.sct-testimonials',
		'.sct-testimonial',
		'.sct-rating',
		'.sct-source',
		'.sct-dialog',
		'.sct-more',
		'.sct-pages',
		'.sct-review-slide',
		'.sct-review-continue',
		'.pswp',
		'.sct-form',
	);

	/** Class substrings for lazy-load exclusions that match parents by class name. */
	private const PARENT_CLASSES = array( 'sct-testimonial', 'sct-dialog' );

	/**
	 * Hook registration.
	 */
	public function register(): void {
		// Perfmatters: unused-CSS, delay/defer JS and lazy-load parent exclusions.
		add_filter( 'perfmatters_rucss_excluded_stylesheets', array( self::class, 'exclude_asset' ) );
		add_filter( 'perfmatters_rucss_excluded_selectors', array( self::class, 'exclude_selectors' ) );
		add_filter( 'perfmatters_delay_js_exclusions', array( self::class, 'exclude_asset' ) );
		add_filter( 'perfmatters_defer_js_exclusions', array( self::class, 'exclude_asset' ) );
		add_filter( 'perfmatters_lazyload_parent_exclusions', array( self::class, 'exclude_parent_selectors' ) );

		// WP Rocket: unused-CSS safelist plus CSS/JS exclusion and delay lists.
		add_filter( 'rocket_rucss_safelist', array( self::class, 'exclude_selectors' ) );
		add_filter( 'rocket_exclude_css', array( self::class, 'exclude_asset' ) );
		add_filter( 'rocket_exclude_js', array( self::class, 'exclude_asset' ) );
		add_filter( 'rocket_delay_js_exclusions', array( self::class, 'exclude_asset' ) );

		// Autoptimize: exclusion lists are comma-separated strings, not arrays.
		add_filter( 'autoptimize_filter_css_exclude', array( self::class, 'exclude_asset_csv' ) );
		add_filter( 'autoptimize_filter_js_exclude', array( self::class, 'exclude_asset_csv' ) );

		// LiteSpeed Cache: URL fragment arrays plus lazy-load class exclusions.
		add_filter( 'litespeed_optimize_css_excludes', array( self::class, 'exclude_asset' ) );
		add_filter( 'litespeed_optimize_js_excludes', array( self::class, 'exclude_asset' ) );
		add_filter( 'litespeed_media_lazy_img_cls_excludes', array( self::class, 'exclude_skip_lazy_class' ) );
		add_filter( 'litespeed_media_lazy_img_parent_cls_excludes', array( self::class, 'exclude_parent_classes' ) );

		// Page-cache freshness: nginx-helper purges the testimonial itself on save, but
		// not the pages rendering the library, so an approval would stay invisible.
		add_action( 'transition_post_status', array( $this, 'purge_rendering_pages' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'post_deleted' ), 10, 2 );
		add_action( 'edited_' . TAXONOMY, array( $this, 'schedule_purge' ) );
		add_action( 'delete_' . TAXONOMY, array( $this, 'schedule_purge' ) );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) { add_action( $hook, array( $this, 'term_icon_changed' ), 10, 4 ); }
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'set_object_terms', array( $this, 'terms_changed' ), 10, 6 );
		add_action( 'update_option_' . OPTION_KEY, array( $this, 'schedule_purge' ) );
		add_action( 'shutdown', array( $this, 'flush_pending' ) );
	}

	/**
	 * Append the asset path fragment to an array exclusion list.
	 *
	 * @param mixed $list Exclusion list from the optimizer.
	 * @return mixed
	 */
	public static function exclude_asset( $list ) {
		if ( ! is_array( $list ) ) {
			return $list;
		}

		if ( ! in_array( self::ASSET_PATH, $list, true ) ) {
			$list[] = self::ASSET_PATH;
		}

		return $list;
	}

	/**
	 * Append the asset path fragment to a comma-separated exclusion list.
	 *
	 * @param mixed $list Exclusion list from the optimizer.
	 * @return mixed
	 */
	public static function exclude_asset_csv( $list ) {
		if ( is_array( $list ) ) {
			return self::exclude_asset( $list );
		}

		if ( ! is_string( $list ) || false !== strpos( $list, self::ASSET_PATH ) ) {
			return $list;
		}

		return '' === trim( $list ) ? self::ASSET_PATH : $list . ',' . self::ASSET_PATH;
	}

	/**
	 * Append the plugin's selector prefixes to a safelist.
	 *
	 * @param mixed $selectors Safelist from the optimizer.
	 * @return mixed
	 */
	public static function exclude_selectors( $selectors ) {
		if ( ! is_array( $selectors ) ) {
			return $selectors;
		}

		foreach ( self::SELECTORS as $selector ) {
			if ( ! in_array( $selector, $selectors, true ) ) {
				$selectors[] = $selector;
			}
		}

		return $selectors;
	}

	/**
	 * Append parent selectors for Perfmatters lazy-load exclusions.
	 *
	 * @param mixed $selectors Selector list from Perfmatters.
	 * @return mixed
	 */
	public static function exclude_parent_selectors( $selectors ) {
		if ( ! is_array( $selectors ) ) {
			return $selectors;
		}

		foreach ( array( '.sct-testimonial', '.sct-dialog' ) as $selector ) {
			if ( ! in_array( $selector, $selectors, true ) ) {
				$selectors[] = $selector;
			}
		}

		return $selectors;
	}

	/**
	 * Append parent class names for LiteSpeed lazy-load exclusions.
	 *
	 * @param mixed $classes Class list from LiteSpeed.
	 * @return mixed
	 */
	public static function exclude_parent_classes( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		foreach ( self::PARENT_CLASSES as $class ) {
			if ( ! in_array( $class, $classes, true ) ) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	/**
	 * Keep the cross-plugin skip-lazy marker in LiteSpeed's image class exclusions.
	 *
	 * @param mixed $classes Class list from LiteSpeed.
	 * @return mixed
	 */
	public static function exclude_skip_lazy_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		if ( ! in_array( 'skip-lazy', $classes, true ) ) {
			$classes[] = 'skip-lazy';
		}

		return $classes;
	}

	/**
	 * Purge the cached pages that render the library when visibility changes.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       The testimonial being transitioned.
	 */
	public function purge_rendering_pages( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || 'sct_testimonial' !== $post->post_type ) {
			return;
		}

		if ( 'publish' === $new_status || 'publish' === $old_status ) { $this->schedule_purge(); }
	}

	public function post_deleted( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && POST_TYPE === $post->post_type && 'publish' === $post->post_status ) { $this->schedule_purge(); }
	}
	private bool $purge_pending = false;
	public function schedule_purge(): void { $this->purge_pending = true; }
	public function meta_changed( $meta_id, $post_id, $key, $value ): void {
		if ( POST_TYPE === get_post_type( $post_id ) && 'publish' === get_post_status( $post_id ) && ( 0 === strpos( (string) $key, META_PREFIX ) || '_thumbnail_id' === $key ) ) { $this->schedule_purge(); }
	}
	public function term_icon_changed( $meta_id, $term_id, $key, $value ): void {
		if ( ! in_array( $key, array( 'sct_icon', 'sct_icon_svg' ), true ) ) { return; }
		$term = get_term( (int) $term_id );
		if ( $term instanceof \WP_Term && TAXONOMY === $term->taxonomy ) { $this->schedule_purge(); }
	}
	public function terms_changed( $id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( TAXONOMY === $taxonomy && POST_TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) { $this->schedule_purge(); }
	}
	public function flush_pending(): void {
		if ( ! $this->purge_pending ) { return; }
		$this->purge_pending = false;

		foreach ( self::rendering_page_ids() as $id ) {
			$url = get_permalink( $id );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			if ( class_exists( '\FastCGI_Purger' ) ) {
				( new \FastCGI_Purger() )->purge_url( $url, false );
			}

			if ( function_exists( 'rocket_clean_post' ) ) { rocket_clean_post( $id ); }
			do_action( 'litespeed_purge_post', $id );
			self::clear_used_css( $id );
		}
	}

	/**
	 * Published posts whose content renders the library or the form.
	 *
	 * @return int[]
	 */
	private static function rendering_page_ids(): array {
		global $wpdb;

		$patterns = array(
			'%' . $wpdb->esc_like( '[shootcal_testimonials' ) . '%',
			'%' . $wpdb->esc_like( '[shootcal_testimonial_form' ) . '%',
			'%' . $wpdb->esc_like( 'wp:shootcal/testimonials' ) . '%',
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( 'page', 'post' ) AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )",
				$patterns[0],
				$patterns[1],
				$patterns[2]
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Clear Perfmatters used-CSS for one post, static or instance scoped.
	 *
	 * @param int $id Post ID.
	 */
	private static function clear_used_css( int $id ): void {
		$class = '\Perfmatters\CSS';

		if ( ! class_exists( $class ) || ! method_exists( $class, 'clear_post_used_css' ) ) {
			return;
		}

		$ref = new \ReflectionMethod( $class, 'clear_post_used_css' );

		if ( $ref->isStatic() ) {
			$class::clear_post_used_css( $id );
		} else {
			( new $class() )->clear_post_used_css( $id );
		}
	}
}
