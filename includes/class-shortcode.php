<?php
/**
 * Shortcode and rendering.
 *
 * Testimonials render as uniform floating cards: a fixed-aspect media area, a quote
 * clamped to a set number of lines, an attribution pinned to the bottom, and a source
 * credit line. Clicking a card opens a native dialog with the complete review.
 *
 * Native <dialog> is used rather than a lightbox library. It gives focus management,
 * Escape to close, a backdrop and an inert background for free, and adds no dependency.
 *
 * Page weight. The complete quote appears in the HTML exactly once, inside the card, and
 * the dialog is populated from it on first open. The photo is likewise referenced once and
 * cloned into the dialog rather than emitted twice. Without JavaScript the quote is not
 * clamped at all, so the full text is already on the page and nothing is lost.
 *
 * No request is made by any interaction. View more reveals already-rendered cards and the
 * dialogs read from markup already present, so a visitor click cannot trigger a database
 * or provider query. The optional query-string category filter keeps that property: it
 * narrows the one page query the shortcode was already going to run, and never reaches
 * out to a review provider.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders [shootcal_testimonials].
 */
class Shortcode {

	/**
	 * Ceiling on items rendered by one shortcode instance.
	 *
	 * This is a page-weight guard, not a design constraint. The ShootCal Websites block
	 * caps at nine because its quotes are authored inline in the page document; WordPress
	 * stores testimonials as posts, so a higher ceiling is appropriate here. Recorded in
	 * AGENTS.md as an accepted platform variation rather than hidden.
	 */
	public const CEILING = 60;

	/**
	 * Default total rendered when View more is on. Kept well below the ceiling so a long
	 * library cannot silently balloon the HTML.
	 */
	public const DEFAULT_TOTAL = 60;

	/**
	 * Lines of quote shown on a card before it clamps.
	 */
	public const CLAMP_LINES = 5;

	/**
	 * Query-string parameter that may override the category attribute.
	 *
	 * Only read when a shortcode instance opts in with allow_query="on".
	 */
	public const QUERY_VAR = 'sct_category';

	/**
	 * Ceiling on slugs accepted from the query string.
	 *
	 * Bounds the tax_query a visitor can influence, so a long comma list cannot be used
	 * to build an expensive query.
	 */
	public const QUERY_TERMS_MAX = 12;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_shortcode' ) );
	}

	/**
	 * Register the shortcode.
	 */
	public function add_shortcode(): void {
		add_shortcode( 'shootcal_testimonials', array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * Accepted attributes: category, allow_query, count, total, columns, more, orderby,
	 * order, heading, eyebrow, intro and lines. Unknown keys are dropped by
	 * shortcode_atts(), which is what lets the block renderer hand over its own attribute
	 * set without the two surfaces drifting apart.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'category'    => '',
				'allow_query' => 'off',
				'filter'      => 'hide',
				'count'       => (string) Config::get( 'default_count' ),
				'total'       => '',
				'columns'     => (string) Config::get( 'default_columns' ),
				'more'        => (string) Config::get( 'default_more' ),
				'orderby'     => 'date',
				'order'       => 'DESC',
				'heading'     => '',
				'eyebrow'     => '',
				'intro'       => '',
				'lines'       => (string) self::CLAMP_LINES,
			),
			$atts,
			'shootcal_testimonials'
		);

		$columns     = Config::normalize_columns( $atts['columns'] );
		$count       = max( 1, min( self::CEILING, (int) $atts['count'] ) );
		$more        = in_array( $atts['more'], array( 'show', 'hide' ), true ) ? $atts['more'] : 'hide';
		$lines       = max( 2, min( 12, (int) $atts['lines'] ) );
		$allow_query = in_array( $atts['allow_query'], array( 'on', 'off' ), true ) ? $atts['allow_query'] : 'off';

		// With View more on, fetch more than the initial count so there is something to
		// reveal. Without it, the initial count is the whole query and no card is hidden.
		if ( 'show' === $more ) {
			$requested = '' !== trim( (string) $atts['total'] ) ? (int) $atts['total'] : self::DEFAULT_TOTAL;
			$total     = min( self::CEILING, max( $count, $requested ) );
		} else {
			$total = $count;
		}

		$filter = 'show' === $atts['filter'];
		if ( $filter ) { $allow_query = 'on'; }
		$category = $this->resolve_category( (string) $atts['category'], $allow_query );
		$posts    = $this->query( $category, $total, (string) $atts['orderby'], (string) $atts['order'] );

		if ( array() === $posts ) {
			return '';
		}

		$instance = wp_unique_id( 'sct-list-' );
		$rendered_posts = array();
		$items   = '';
		$dialogs = '';
		$index   = 0;

		foreach ( $posts as $post ) {
			$rendered = $this->render_item( $post, $index >= $count, $lines, $instance );
			if ( '' === $rendered['card'] ) {
				continue;
			}
			++$index;
			$rendered_posts[] = $post;
			$items   .= $rendered['card'];
			$dialogs .= $rendered['dialog'];
		}

		// Dialogs are collected outside the grid so they never become grid items, and
		// outside each card so an ancestor transform or overflow cannot trap a modal.
		$button = '';

		if ( 'show' === $more && count( $rendered_posts ) > $count ) {
			$button = sprintf(
				'<div class="sct-testimonials__more"><button type="button" class="sct-more" data-sct-more>%s</button></div>',
				esc_html__( 'View more', 'shootcal-testimonials' )
			);
		}

		/**
		 * Fires with the testimonials a shortcode actually rendered.
		 *
		 * Lets the schema and asset loaders act on real page output instead of
		 * re-querying and risking markup for items that are not on the page.
		 *
		 * @param \WP_Post[] $posts    Rendered testimonials.
		 * @param bool       $has_more Whether View more is enabled for this instance.
		 */
		if ( array() === $rendered_posts ) {
			return '';
		}
		do_action( 'sct_rendered', $rendered_posts, '' !== $button );

		return sprintf(
			'<section id="%8$s" class="sct-section sct-testimonials" style="--sct-lines:%1$d" data-sct-columns="%2$d" data-sct-initial="%3$d">%4$s<div class="sct-testimonials__grid sct-testimonials__grid--%2$d">%5$s</div>%6$s%7$s</section>',
			$lines,
			$columns,
			$count,
			$this->render_heading( $atts ) . ( $filter ? $this->render_filter( $category, $instance ) : '' ),
			$items,
			$button,
			$dialogs,
			esc_attr( $instance )
		);
	}

	/** Category links use ordinary same-site navigation, including without JavaScript. */
	private function render_filter( string $category, string $instance ): string {
		$terms = get_terms( array( 'taxonomy' => TAXONOMY, 'hide_empty' => true ) );
		if ( ! is_array( $terms ) || count( $terms ) < 2 ) { return ''; }
		$url = get_permalink( get_queried_object_id() );
		if ( ! is_string( $url ) || '' === $url ) { return ''; }
		$choices = array( '' => __( 'All reviews', 'shootcal-testimonials' ) );
		foreach ( $terms as $term ) { $choices[ $term->slug ] = $term->name; }
		$selected = $choices[ $category ] ?? __( 'Selected categories', 'shootcal-testimonials' );
		$label_id = $instance . '-category-label';
		$out = '<div class="sct-filter"><span id="' . esc_attr( $label_id ) . '">' . esc_html__( 'Review category', 'shootcal-testimonials' ) . '</span><details class="sct-filter__dropdown"><summary aria-describedby="' . esc_attr( $label_id ) . '">' . esc_html( $selected ) . '</summary><nav class="sct-filter__options" aria-label="' . esc_attr__( 'Review categories', 'shootcal-testimonials' ) . '">';
		foreach ( $choices as $slug => $name ) {
			$href = add_query_arg( self::QUERY_VAR, $slug, $url ) . '#' . $instance;
			$out .= '<a href="' . esc_url( $href ) . '"' . ( $category === $slug ? ' aria-current="page"' : '' ) . '>' . esc_html( $name ) . '</a>';
		}
		return $out . '</nav></details></div>';
	}

	/**
	 * Resolve the category filter for this instance.
	 *
	 * With allow_query="on" a `sct_category` query-string parameter replaces the category
	 * attribute, which lets one page serve two URLs with different filters without a
	 * second shortcode or a second page.
	 *
	 * Narrowing only. The query it feeds already pins post_type to the testimonial type
	 * and post_status to publish, and the taxonomy is a constant rather than input, so the
	 * worst a crafted URL can do is select a different set of published testimonials.
	 * Every part is passed through sanitize_title(), duplicates and blanks are dropped,
	 * the list is capped, and any slug that is not a real term is discarded so a bogus
	 * value falls back to the attribute instead of rendering an empty section.
	 *
	 * This costs one cached taxonomy read and only when the parameter is actually present.
	 * It never triggers an outbound request.
	 *
	 * Deployment note: a page using allow_query="on" must not be served from a page cache
	 * keyed on the path alone, or every visitor sees whichever variant was cached first.
	 *
	 * @param string $attribute   Category attribute, comma separated slugs.
	 * @param string $allow_query 'on' or 'off'.
	 */
	private function resolve_category( string $attribute, string $allow_query ): string {
		if ( 'on' !== $allow_query ) {
			return $attribute;
		}

		// Read-only public filter. No state changes, so no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		if ( '' === trim( $raw ) ) {
			return $attribute;
		}

		$requested = array();

		foreach ( explode( ',', $raw ) as $part ) {
			$slug = sanitize_title( trim( $part ) );

			if ( '' !== $slug && ! in_array( $slug, $requested, true ) ) {
				$requested[] = $slug;
			}
		}

		if ( array() === $requested ) {
			return $attribute;
		}

		$requested = array_slice( $requested, 0, self::QUERY_TERMS_MAX );

		$known = get_terms(
			array(
				'taxonomy'   => TAXONOMY,
				'fields'     => 'slugs',
				'hide_empty' => false,
			)
		);

		$valid = is_array( $known ) ? array_values( array_intersect( $requested, $known ) ) : array();

		return array() === $valid ? $attribute : implode( ',', $valid );
	}

	/**
	 * Query published testimonials.
	 *
	 * @param string $category Category slug, empty for all.
	 * @param int    $count    Maximum items.
	 * @param string $orderby  date or rating.
	 * @param string $order    ASC or DESC.
	 * @return \WP_Post[]
	 */
	private function query( string $category, int $count, string $orderby, string $order ): array {
		$order = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'      => POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $count,
			'order'          => $order,
			'no_found_rows'  => true,
		);

		if ( 'rating' === $orderby ) {
			$args['meta_query'] = array( 'relation' => 'OR',
				'rated' => array( 'key' => META_PREFIX . 'rating', 'compare' => 'EXISTS', 'type' => 'NUMERIC' ),
				array( 'key' => META_PREFIX . 'rating', 'compare' => 'NOT EXISTS' ),
			);
			$args['orderby']  = array(
				'rated' => $order,
				'date'           => 'DESC',
			);
		} else {
			$args['orderby'] = 'date';
		}

		if ( '' !== $category ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => TAXONOMY,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', explode( ',', $category ) ),
				),
			);
		}

		$query = new \WP_Query( $args );

		return is_array( $query->posts ) ? $query->posts : array();
	}

	/**
	 * Render the optional section heading.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 */
	private function render_heading( array $atts ): string {
		$heading = trim( (string) $atts['heading'] );

		if ( '' === $heading ) {
			return '';
		}

		$eyebrow = trim( (string) $atts['eyebrow'] );
		$intro   = trim( (string) $atts['intro'] );
		$out     = '<div class="sct-testimonials__header">';

		if ( '' !== $eyebrow ) {
			$out .= '<p class="sct-testimonials__eyebrow">' . esc_html( $eyebrow ) . '</p>';
		}

		$out .= '<h2 class="sct-testimonials__heading">' . esc_html( $heading ) . '</h2>';

		if ( '' !== $intro ) {
			$out .= '<p class="sct-testimonials__intro">' . esc_html( $intro ) . '</p>';
		}

		return $out . '</div>';
	}

	/**
	 * Render one testimonial card and its dialog.
	 *
	 * @param \WP_Post $post   Testimonial post.
	 * @param bool     $hidden Whether the card starts collapsed behind View more.
	 * @param int      $lines  Quote lines to clamp to.
	 * @return array{card:string,dialog:string}
	 */
	private function render_item( \WP_Post $post, bool $hidden, int $lines, string $instance ): array {
		$quote  = $this->quote_text( $post );
		$name   = get_the_title( $post );
		$rating = Meta::normalize_rating( get_post_meta( $post->ID, META_PREFIX . 'rating', true ) );
		$source = Meta::normalize_source( get_post_meta( $post->ID, META_PREFIX . 'source', true ) );

		if ( '' === trim( $quote ) || '' === trim( $name ) ) {
			return array(
				'card'   => '',
				'dialog' => '',
			);
		}

		$classes     = array( 'sct-testimonial' );
		$date        = mysql2date( get_option( 'date_format', 'F j, Y' ), $post->post_date );
		$media       = $this->render_media( $post, $name, $classes );
		$stars       = ( $rating > 0 && Config::get( 'show_rating', true ) ) ? $this->render_rating( $rating ) : '';
		$source_line = $this->render_source( $post, $source );
		$dialog_id   = $instance . '-dialog-' . $post->ID;

		if ( $hidden ) {
			$classes[] = 'sct-testimonial--hidden';
		}

		$attribution = '<figcaption class="sct-testimonial__by"><span class="sct-testimonial__name">' . esc_html( $name ) . '</span>';

		if ( Config::get( 'show_date', true ) && '' !== $date ) {
			$attribution .= '<span class="sct-testimonial__date">' . esc_html( $date ) . '</span>';
		}

		$category_html = '';
		if ( Config::get( 'show_category', false ) ) {
			$terms = get_the_terms( $post, TAXONOMY );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$icon = Category_Icons::icon( $term->term_id );
					$category_html .= '<span class="sct-category">' . ( '' !== $icon ? '<span class="sct-category__icon">' . $icon . '</span>' : '' ) . '<span>' . esc_html( $term->name ) . '</span></span>';
				}
				$attribution .= '<span class="sct-testimonial__categories">' . $category_html . '</span>';
			}
		}
		$attribution .= '</figcaption>';

		$card = sprintf(
			'<figure class="%1$s" data-sct-card>%2$s<div class="sct-testimonial__body">%3$s<blockquote class="sct-testimonial__quote" data-sct-quote>%4$s</blockquote>%5$s%6$s</div><button type="button" class="sct-testimonial__open" data-sct-open aria-haspopup="dialog" aria-controls="%7$s">%8$s</button></figure>',
			esc_attr( implode( ' ', $classes ) ),
			$media,
			$stars,
			esc_html( $quote ),
			$attribution,
			'' === $source_line && Config::get( 'show_source', true ) ? '<p class="sct-source sct-source--empty" aria-hidden="true"><span class="sct-source__label"></span></p>' : $source_line,
			esc_attr( $dialog_id ),
			esc_html__( 'Read full review', 'shootcal-testimonials' )
		);

		$dialog = $this->render_dialog( $dialog_id, $name, $date, $rating, $source_line, $category_html );

		return array(
			'card'   => $card,
			'dialog' => $dialog,
		);
	}

	/**
	 * Render the card media area.
	 *
	 * A fixed aspect ratio is reserved whether or not a photo exists, because cards only
	 * line up across the grid when every media block has the same height. Where there is
	 * no photo the reviewer's initials sit on a soft gradient instead.
	 *
	 * @param \WP_Post $post    Testimonial post.
	 * @param string   $name    Reviewer display name.
	 * @param string[] $classes Class list, appended to in place.
	 */
	private function render_media( \WP_Post $post, string $name, array &$classes ): string {
		if ( Config::get( 'show_photo', true ) && has_post_thumbnail( $post ) ) {
			$classes[] = 'sct-testimonial--photo';

			// skip-lazy is the cross-plugin marker that tells lazy-loaders to leave an
			// image alone; the dialog clones this node, so it must keep a real src.
			$thumb = (string) get_the_post_thumbnail( $post, 'medium_large', array( 'loading' => 'lazy' ) );
			$thumb = preg_replace( '/ class="([^"]*)"/', ' class="$1 skip-lazy"', $thumb, 1 );

			return '<div class="sct-testimonial__media" data-sct-media>'
				. $thumb
				. '</div>';
		}

		$classes[] = 'sct-testimonial--monogram';

		return sprintf(
			'<div class="sct-testimonial__media sct-testimonial__media--monogram" aria-hidden="true"><span>%s</span></div>',
			esc_html( $this->initials( $name ) )
		);
	}

	/**
	 * Up to two initials from a display name.
	 *
	 * @param string $name Reviewer name.
	 */
	private function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) ) ?: array();
		$out   = '';

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			$out .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 );

			if ( strlen( $out ) >= 2 ) {
				break;
			}
		}

		return '' !== $out ? strtoupper( $out ) : '?';
	}

	/**
	 * The dialog shell.
	 *
	 * The quote and the photo are deliberately absent from the server output. Both are
	 * copied in from the card on first open, which keeps the complete review text in the
	 * HTML exactly once instead of twice. The heading, rating, byline and source line are
	 * rendered here so the dialog has a correct accessible name before script runs.
	 *
	 * @param string $id          Element id referenced by the opener.
	 * @param string $name        Reviewer display name.
	 * @param string $date        Formatted review date.
	 * @param int    $rating      0 to 5.
	 * @param string $source_line Pre-rendered source attribution.
	 */
	private function render_dialog( string $id, string $name, string $date, int $rating, string $source_line, string $category_html ): string {
		$stars = ( $rating > 0 && Config::get( 'show_rating', true ) ) ? $this->render_rating( $rating ) : '';

		$by = '<div class="sct-dialog__by">';

		if ( Config::get( 'show_date', true ) && '' !== $date ) {
			$by .= '<span class="sct-dialog__date">' . esc_html( $date ) . '</span>';
		}

		if ( '' !== $category_html ) { $by .= '<span class="sct-dialog__categories">' . $category_html . '</span>'; }
		$by .= '</div>';

		return sprintf(
			'<dialog class="sct-dialog" id="%1$s" data-sct-dialog aria-labelledby="%1$s-title"><div class="sct-dialog__inner" tabindex="-1"><button type="button" class="sct-dialog__close" data-sct-close aria-label="%2$s">&#215;</button><div class="sct-dialog__media" data-sct-dialog-media></div><div class="sct-dialog__content"><h3 class="sct-dialog__heading" id="%1$s-title">%3$s</h3>%5$s%4$s<blockquote class="sct-dialog__quote" data-sct-dialog-quote></blockquote>%6$s</div></div></dialog>',
			esc_attr( $id ),
			esc_attr__( 'Close', 'shootcal-testimonials' ),
			sprintf(
				'<span class="sct-dialog__heading-label">%1$s</span><span class="sct-dialog__heading-name">%2$s</span>',
				esc_html__( 'Review', 'shootcal-testimonials' ),
				esc_html( $name )
			),
			$stars,
			$by,
			$source_line
		);
	}

	/**
	 * Accessible star rating.
	 *
	 * @param int $rating 1 to 5.
	 */
	private function render_rating( int $rating ): string {
		return sprintf(
			'<span class="sct-rating" role="img" aria-label="%1$s">%2$s</span>',
			esc_attr( sprintf( /* translators: %d: star rating. */ _n( '%d star out of 5', '%d stars out of 5', $rating, 'shootcal-testimonials' ), $rating ) ),
			str_repeat( '<span class="sct-star" aria-hidden="true">&#9733;</span>', $rating )
		);
	}

	/**
	 * Source attribution line.
	 *
	 * Google-sourced testimonials get the "Originally posted on Google" label and every
	 * other platform gets a plain text credit, so the plugin never renders a third-party
	 * brand it holds no usage guidance for. No badge is drawn beside any of them:
	 * Google's brand rules require the official, unaltered G and forbid a custom one, and
	 * the bundled gradient G is downloaded unmodified from Google's official brand resource.
	 *
	 * Stars are rendered in the card body, deliberately separate from this line, because
	 * Google's brand rules forbid placing stars beside the Google name or logo.
	 *
	 * @param \WP_Post $post   Testimonial post.
	 * @param string   $source Normalized source key.
	 */
	private function render_source( \WP_Post $post, string $source ): string {
		if ( ! Config::get( 'show_source', true ) || 'direct' === $source ) {
			return '';
		}

		$url = (string) get_post_meta( $post->ID, META_PREFIX . 'source_url', true );
		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( 'google' === $source ) {
			$label = __( 'Originally posted on Google', 'shootcal-testimonials' );
		} else {
			$label = sprintf(
				/* translators: %s: platform name. */
				__( 'Originally posted on %s', 'shootcal-testimonials' ),
				Meta::source_labels()[ $source ] ?? __( 'another website', 'shootcal-testimonials' )
			);
		}

		$label_html = '<span class="sct-source__label">' .
			( 'google' === $source ? '<img class="sct-source__mark" src="' . esc_url( PLUGIN_URL . 'assets/google-g.svg' ) . '" width="18" height="18" alt="" loading="lazy" />' : '' ) . esc_html( $label ) . '</span>';

		if ( '' !== $url ) {
			$label_html = sprintf(
				'<a class="sct-source__link" href="%1$s" rel="nofollow noopener external" target="_blank">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( $url ),
				$label_html,
				esc_html__( '(opens in a new tab)', 'shootcal-testimonials' )
			);
		}

		return sprintf(
			'<p class="sct-source sct-source--%1$s">%2$s</p>',
			esc_attr( $source ),
			$label_html
		);
	}

	/**
	 * The quote text.
	 *
	 * Stored in post_content, unlike the legacy plugin which kept display text in meta and
	 * left post_content empty. The importer maps that across.
	 *
	 * Reviewer wording, punctuation and paragraph breaks are preserved exactly. Markup is
	 * stripped but the text is never rewritten, and em dashes in a client quote are left
	 * alone because the no-em-dash preference applies to copy we author.
	 *
	 * @param \WP_Post $post Testimonial post.
	 */
	private function quote_text( \WP_Post $post ): string {
		$text = trim( (string) $post->post_content );

		if ( '' === $text ) {
			return '';
		}

		// Strip tags only. wp_strip_all_tags() with $remove_breaks collapses every run of
		// whitespace to a single space, which would flatten the paragraph breaks the
		// normalization below and the card's white-space: pre-line both depend on.
		$text = preg_replace( '/<br\s*\/?\s*>|<\/(?:p|div|li|h[1-6])\s*>/i', "\n", $text ) ?? $text;
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[ \t]+\n/', "\n", $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
