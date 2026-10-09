<?php
/**
 * Gutenberg block registration.
 *
 * One dynamic block, shootcal/testimonials, that exposes the testimonial list and its
 * category filter to the block editor.
 *
 * There is deliberately no build step. No package.json, no bundler, no node_modules. The
 * editor script is plain ES5 using wp.element.createElement, registered from PHP, and
 * block.json points at both the script handle and the PHP render template directly.
 *
 * The block is dynamic: render.php delegates to the same Shortcode renderer the shortcode
 * uses, so the editor preview, the block output and the shortcode output are all the same
 * code path and cannot drift apart. save() returns null, which is also what makes the void
 * comment form <!-- wp:shootcal/testimonials /--> valid with no stored markup to compare
 * against.
 *
 * Nothing here issues a request from the front end. The category terms the editor control
 * lists are fetched by the block editor's own REST layer while an editor is signed in; the
 * rendered block carries no query of its own beyond the page render it already performs.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block, its editor script and its render bridge.
 */
class Block {

	/**
	 * Block name, matching the name field in block.json.
	 */
	public const NAME = 'shootcal/testimonials';

	/**
	 * Handle for the editor script, referenced from block.json as editorScript.
	 */
	public const EDITOR_HANDLE = 'shootcal-testimonials-block-editor';

	/**
	 * Global the editor script reads its site defaults from.
	 *
	 * block.json defaults have to be static JSON, so the three values that come from the
	 * settings page are handed to the editor here instead. That keeps the block controls
	 * showing the site's real defaults rather than a guess, and keeps an untouched
	 * attribute out of the saved markup so the shortcode's own Config-driven default still
	 * applies on the front end.
	 */
	public const EDITOR_OBJECT = 'shootcalTestimonialsBlockEditor';

	/**
	 * Block attribute name to shortcode attribute name.
	 *
	 * camelCase on the JavaScript side because that is the block editor convention, snake
	 * case on the PHP side because that is what Shortcode::render() reads.
	 *
	 * @var array<string,string>
	 */
	private const ATTRIBUTE_MAP = array(
		'category'   => 'category',
		'allowQuery' => 'allow_query',
		'filter'     => 'filter',
		'count'      => 'count',
		'total'      => 'total',
		'columns'    => 'columns',
		'more'       => 'more',
		'orderby'    => 'orderby',
		'order'      => 'order',
		'heading'    => 'heading',
		'eyebrow'    => 'eyebrow',
		'intro'      => 'intro',
		'lines'      => 'lines',
	);

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_editor_script' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Absolute path to the block directory holding block.json.
	 */
	public static function block_dir(): string {
		return PLUGIN_DIR . 'blocks/sct-testimonials';
	}

	/**
	 * Register the block from its metadata.
	 *
	 * Passing the directory rather than an argument array means block.json stays the single
	 * source of truth for the name, attributes, supports, editor script and render
	 * template.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type( self::block_dir() );
	}

	/**
	 * Register the editor script and its site defaults.
	 *
	 * Registered rather than enqueued here. block.json names the handle as its editorScript,
	 * so the block editor loads it on its own, and enqueue_editor_assets() below is the
	 * explicit belt-and-braces pass that keeps it out of every other context.
	 */
	public function register_editor_script(): void {
		wp_register_script(
			self::EDITOR_HANDLE,
			PLUGIN_URL . 'assets/js/block-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-server-side-render', 'wp-i18n' ),
			Assets::version_for( 'assets/js/block-editor.js' ),
			true
		);

		wp_localize_script(
			self::EDITOR_HANDLE,
			self::EDITOR_OBJECT,
			array(
				'defaults' => array(
					'count'   => max( 1, min( Shortcode::CEILING, (int) Config::get( 'default_count' ) ) ),
					'columns' => Config::normalize_columns( Config::get( 'default_columns' ) ),
					'more'    => self::default_more(),
					'total'   => Shortcode::DEFAULT_TOTAL,
					'lines'   => Shortcode::CLAMP_LINES,
				),
				'ceiling'   => Shortcode::CEILING,
				// The quote clamp range Shortcode::render() enforces, restated here so the
				// editor control cannot offer a value the renderer would silently reject.
				'clamp'     => array(
					'min' => 2,
					'max' => 12,
				),
			)
		);
	}

	/**
	 * Load the editor script in the block editor only.
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_script( self::EDITOR_HANDLE );
	}

	/**
	 * The configured View more default, coerced to a value the renderer accepts.
	 */
	private static function default_more(): string {
		$more = Config::get( 'default_more' );

		return in_array( $more, array( 'show', 'hide' ), true ) ? (string) $more : 'hide';
	}

	/**
	 * Translate block attributes into shortcode attributes.
	 *
	 * Anything unset, null or empty is left out entirely, so Shortcode::render() applies
	 * its own defaults for it. That is what lets the block inherit the site's configured
	 * column count, item count and View more setting instead of overriding them with a
	 * blank value baked into block.json.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return array<string,string> Shortcode attributes.
	 */
	public static function shortcode_atts( array $attributes ): array {
		$atts = array();

		foreach ( self::ATTRIBUTE_MAP as $attribute => $key ) {
			if ( ! array_key_exists( $attribute, $attributes ) ) {
				continue;
			}

			$value = $attributes[ $attribute ];

			if ( null === $value || ! is_scalar( $value ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$value = $value ? 'on' : 'off';
			}

			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$atts[ $key ] = $value;
		}

		return $atts;
	}

	/**
	 * Render the block.
	 *
	 * Called from blocks/sct-testimonials/render.php. Delegates to the shortcode renderer
	 * so there is exactly one implementation of the testimonial list.
	 *
	 * Block supports such as alignment, spacing, colour and anchor are applied here through
	 * get_block_wrapper_attributes(), which is the only path WordPress offers a dynamic
	 * block for them. The wrapper is added only when there is something to apply, so a
	 * plain block renders the section on its own with no extra element.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_block( array $attributes ): string {
		$html = ( new Shortcode() )->render( self::shortcode_atts( $attributes ) );

		if ( '' === $html ) {
			// Nothing on the front end. The editor shows its own placeholder for an empty
			// render, supplied by the editor script rather than injected here, so a
			// headless consumer reading rendered content never receives placeholder text.
			return '';
		}

		$wrapper = get_block_wrapper_attributes();

		if ( '' === $wrapper ) {
			return $html;
		}

		return '<div ' . $wrapper . '>' . $html . '</div>';
	}
}
