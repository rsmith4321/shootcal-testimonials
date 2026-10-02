<?php
/**
 * Server-side rendering for the shootcal/testimonials block.
 *
 * Included by WordPress inside the block's render callback, with $attributes, $content
 * and $block in scope. Everything this file echoes becomes the block's front-end output.
 *
 * The block is dynamic and this template delegates to the same Shortcode renderer the
 * [shootcal_testimonials] shortcode uses, so the two surfaces cannot drift apart. There is
 * no second copy of the query, the card markup or the dialog markup here, and no build
 * step: block.json points at this file directly.
 *
 * No front-end style handle is declared in block.json on purpose. Rendering the shortcode
 * fires sct_rendered, which Assets already listens for, so the stylesheet is enqueued once
 * by that path whether the testimonials came from a shortcode or from this block. Adding a
 * second handle for the same file would print the CSS twice on a page using both.
 *
 * @package ShootCalTestimonials
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ShootCalTestimonials\Block' ) ) {
	return;
}

$sct_attributes = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block delegates to Shortcode, which escapes every field.
echo \ShootCalTestimonials\Block::render_block( $sct_attributes );
