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
 * Progressive enhancement: without JavaScript the quote is not clamped, the full text
 * is on the page, and the open control stays hidden. Nothing depends on a request, so
 * View more and the dialogs work offline and cannot trigger a database or provider query
 * from a visitor click.
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
	 * This is a page-weight guard, not a design constraint. The ShootCal Websites
	 * block caps at nine because its quotes are authored inline in the page document;
	 * WordPress stores testimonials as posts, so a higher ceiling is appropriate here.
	 * The difference is a storage consequence and is recorded as an accepted platform
	 * variation rather than hidden.
	 */
	public const CEILING = 60;

	/**
	 * Lines of quote shown on a card before it clamps.
	 */
	public const CLAMP_LINES = 5;

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
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'count'    => (string) Config::get( 'default_count' ),
				'columns'  => (string) Config::get( 'default_columns' ),
				'more'     => (string) Config::get( 'default_more' ),
				'orderby'  => 'date',
				'order'    => 'DESC',
				'heading'  => '',
				'eyebrow'  => '',
				'intro'    => '',
				'lines'    => (string) self::CLAMP_LINES,
			),
			$atts,
			'shootcal_testimonials'
		);

		$columns = Config::normalize_columns( $atts['columns'] );
		$count   = max( 1, min( self::CEILING, (int) $atts['count'] ) );
		$more    = in_array( $atts['more'], array( 'show', 'hide' ), true ) ? $atts['more'] : 'hide';
		$lines   = max( 2, min( 12, (int) $atts['lines'] ) );

		$posts = $this->query( (string) $atts['category'], $count, (string) $atts['orderby'], (string) $atts['order'] );

		if ( array() === $posts ) {
			return '';
		}

		$items = '';
		$index = 0;

		foreach ( $posts as $post ) {
			++$index;
			$items .= $this->render_item( $post, $index > $count && 'show' === $more, $lines );
		}

		$button = '';

		if ( 'show' === $more && count( $posts ) > $count ) {
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
		do_action( 'sct_rendered', $posts, 'show' === $more );

		return sprintf(
			'<section class="sct-section sct-testimonials" data-sct-columns="%1$d" data-sct-initial="%2$d" data-sct-lines="%3$d">%4$s<div class="sct-testimonials__grid sct-testimonials__grid--%1$d">%5$s</div>%6$s</section>',
			$columns,
			$count,
			$lines,
			$this->render_heading( $atts ),
			$items,
			$button
		);
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
			$args['meta_key'] = META_PREFIX . 'rating';
			$args['orderby']  = array(
				'meta_value_num' => $order,
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
	 */
	private function render_item( \WP_Post $post, bool $hidden, int $lines ): string {
		$quote  = $this->quote_text( $post );
		$name   = get_the_title( $post );
		$rating = Meta::normalize_rating( get_post_meta( $post->ID, META_PREFIX . 'rating', true ) );
		$source = Meta::normalize_source( get_post_meta( $post->ID, META_PREFIX . 'source', true ) );

		if ( '' === trim( $quote ) || '' === trim( $name ) ) {
			return '';
		}

		$classes = array( 'sct-testimonial' );

		if ( $hidden ) {
			$classes[] = 'sct-testimonial--hidden';
		}

		$date    = mysql2date( 'F j, Y', $post->post_date );
		$media   = $this->render_media( $post, $name, $classes );
		$stars   = ( $rating > 0 && Config::get( 'show_rating', true ) ) ? $this->render_rating( $rating ) : '';
		$source_line = $this->render_source( $post, $source );
		$dialog_id   = 'sct-dialog-' . $post->ID;

		$attribution = '<figcaption class="sct-testimonial__by"><span class="sct-testimonial__name">' . esc_html( $name ) . '</span>';

		if ( Config::get( 'show_date', true ) && '' !== $date ) {
			$attribution .= '<span class="sct-testimonial__date">' . esc_html( $date ) . '</span>';
		}

		$attribution .= '</figcaption>';

		// The card quote is clamped by CSS only when JavaScript is present, so the
		// full text remains readable without it. The dialog always carries the
		// complete wording, unmodified.
		$card = sprintf(
			'<figure class="%1$s" data-sct-card>%2$s<div class="sct-testimonial__body">%3$s<blockquote class="sct-testimonial__quote" style="--sct-lines:%4$d">&#8220;%5$s&#8221;</blockquote>%6$s%7$s</div><button type="button" class="sct-testimonial__open" data-sct-open aria-haspopup="dialog" aria-controls="%8$s">%9$s</button></figure>',
			esc_attr( implode( ' ', $classes ) ),
			$media,
			$stars,
			$lines,
			esc_html( $quote ),
			$attribution,
			$source_line,
			esc_attr( $dialog_id ),
			esc_html__( 'Read full review', 'shootcal-testimonials' )
		);

		$dialog = $this->render_dialog( $dialog_id, $post, $name, $quote, $date, $rating, $source_line );

		return $card . $dialog;
	}

	/**
	 * Render the card media area.
	 *
	 * A fixed aspect ratio is reserved whether or not a photo exists, because cards
	 * only line up across the grid when every media block has the same height. Where
	 * there is no photo the reviewer's initials sit on a soft gradient instead.
	 *
	 * @param \WP_Post $post    Testimonial post.
	 * @param string   $name    Reviewer display name.
	 * @param string[] $classes Class list, appended to by reference semantics.
	 */
	private function render_media( \WP_Post $post, string $name, array &$classes ): string {
		if ( Config::get( 'show_photo', true ) && has_post_thumbnail( $post ) ) {
			$classes[] = 'sct-testimonial--photo';

			return '<div class="sct-testimonial__media">'
				. get_the_post_thumbnail( $post, 'medium_large', array( 'loading' => 'lazy' ) )
				. '</div>';
		}

		$initials = $this->initials( $name );
		$classes[] = 'sct-testimonial--monogram';

		return sprintf(
			'<div class="sct-testimonial__media sct-testimonial__media--monogram" data-initial="%1$s" aria-hidden="true"><span>%1$s</span></div>',
			esc_attr( $initials )
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
			$out .= function_exists( 'mb_strtoupper' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 );
			if ( strlen( $out ) >= 2 ) {
				break;
			}
		}

		return '' !== $out ? strtoupper( $out ) : '?';
	}

	/**
	 * The dialog carrying the complete review.
	 *
	 * @param string $id          Element id referenced by the opener.
	 * @param \WP_Post $post      Testimonial post.
	 * @param string $name        Reviewer display name.
	 * @param string $quote       Complete quote text.
	 * @param string $date        Formatted review date.
	 * @param int    $rating      0 to 5.
	 * @param string $source_line Pre-rendered source attribution.
	 */
	private function render_dialog( string $id, \WP_Post $post, string $name, string $quote, string $date, int $rating, string $source_line ): string {
		$photo = '';

		if ( Config::get( 'show_photo', true ) && has_post_thumbnail( $post ) ) {
			$photo = '<div class="sct-dialog__media">' . get_the_post_thumbnail( $post, 'large', array( 'loading' => 'lazy' ) ) . '</div>';
		}

		$stars = ( $rating > 0 && Config::get( 'show_rating', true ) ) ? $this->render_rating( $rating ) : '';

		$meta = '<div class="sct-dialog__by"><span class="sct-dialog__name">' . esc_html( $name ) . '</span>';

		if ( Config::get( 'show_date', true ) && '' !== $date ) {
			$meta .= '<span class="sct-dialog__date">' . esc_html( $date ) . '</span>';
		}

		$meta .= '</div>';

		return sprintf(
			'<dialog class="sct-dialog" id="%1$s" data-sct-dialog aria-labelledby="%1$s-title"><div class="sct-dialog__inner"><button type="button" class="sct-dialog__close" data-sct-close aria-label="%2$s">&#215;</button>%3$s<div class="sct-dialog__content"><h3 class="sct-dialog__heading" id="%1$s-title">%4$s</h3>%5$s<blockquote class="sct-dialog__quote">&#8220;%6$s&#8221;</blockquote>%7$s%8$s</div></div></dialog>',
			esc_attr( $id ),
			esc_attr__( 'Close', 'shootcal-testimonials' ),
			$photo,
			esc_html( sprintf( /* translators: %s: reviewer name. */ __( '%s review', 'shootcal-testimonials' ), $name ) ),
			$stars,
			esc_html( $quote ),
			$meta,
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
	 * Only Google-sourced testimonials get the Google mark and the "Originally posted
	 * on Google" label. Other platforms get a plain text credit, so the plugin never
	 * renders a third-party brand it holds no usage guidance for.
	 *
	 * Stars are rendered in the card body, deliberately separate from this line,
	 * because Google's brand rules forbid placing stars beside the Google name or logo.
	 *
	 * @param \WP_Post $post   Testimonial post.
	 * @param string   $source Normalized source key.
	 */
	private function render_source( \WP_Post $post, string $source ): string {
		if ( ! Config::get( 'show_source', true ) || 'direct' === $source ) {
			return '';
		}

		$url = (string) get_post_meta( $post->ID, META_PREFIX . 'source_url', true );
		$url = filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';

		if ( 'google' === $source ) {
			$label = __( 'Originally posted on Google', 'shootcal-testimonials' );
			$mark  = '<span class="sct-source__mark" aria-hidden="true">' . $this->google_mark() . '</span>';
		} else {
			$label = sprintf(
				/* translators: %s: platform name. */
				__( 'Originally posted on %s', 'shootcal-testimonials' ),
				ucfirst( $source )
			);
			$mark  = '';
		}

		$link = '';

		if ( '' !== $url ) {
			$link = sprintf(
				'<a class="sct-source__link" href="%1$s" rel="nofollow noopener external" target="_blank">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( $url ),
				esc_html__( 'View original review', 'shootcal-testimonials' ),
				esc_html__( '(opens in a new tab)', 'shootcal-testimonials' )
			);
		}

		return sprintf(
			'<p class="sct-source sct-source--%1$s">%2$s<span class="sct-source__label">%3$s</span>%4$s</p>',
			esc_attr( $source ),
			$mark,
			esc_html( $label ),
			$link
		);
	}

	/**
	 * Inline Google G mark.
	 *
	 * Placeholder geometry only. Before this ships to other users the official asset
	 * must be swapped in from Google's brand resource centre, used unaltered, with
	 * correct clear space and no custom badge built around it.
	 */
	private function google_mark(): string {
		return '<svg viewBox="0 0 48 48" width="14" height="14" focusable="false" role="presentation"><circle cx="24" cy="24" r="20" fill="currentColor"/></svg>';
	}

	/**
	 * The quote text.
	 *
	 * Stored in post_content, unlike the legacy plugin which kept display text in meta
	 * and left post_content empty. The importer maps that across.
	 *
	 * Reviewer wording, punctuation and paragraph breaks are preserved exactly. Markup
	 * is stripped but the text is never rewritten, and em dashes in a client quote are
	 * left alone because the no-em-dash preference applies to copy we author.
	 *
	 * @param \WP_Post $post Testimonial post.
	 */
	private function quote_text( \WP_Post $post ): string {
		$text = trim( (string) $post->post_content );

		if ( '' === $text ) {
			return '';
		}

		$text = wp_strip_all_tags( $text, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[ \t]+\n/', "\n", $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
