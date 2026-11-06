<?php
/**
 * Local check of which addresses count as a real later page of reviews. It needs more than 60
 * published reviews, such as qa/pagination.php's seed.
 *
 * Usage: wp eval-file qa/review-pages.php
 */
use ShootCalTestimonials\Shortcode;

if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
	throw new RuntimeException( 'Local only.' );
}

$shortcode = new Shortcode();
$found     = (int) wp_count_posts( 'shootcal_testimonial' )->publish;
$show      = array( 'count' => '21', 'total' => '60', 'more' => 'show' );
$last      = (int) ceil( $found / 60 );

$checks = array(
	'a list with View more has page 1'        => $shortcode->has_page( $show, 1 ),
	'its last page counts'                    => $shortcode->has_page( $show, $last ),
	'a page past the end does not'            => ! $shortcode->has_page( $show, $last + 1 ),
	'a list without View more has no pages'   => ! $shortcode->has_page( array( 'count' => '21', 'more' => 'hide' ), 2 ),
	'an empty category has no pages'          => ! $shortcode->has_page( array_merge( $show, array( 'category' => 'no-such-category-qa' ) ), 1 ),
);
foreach ( $checks as $label => $ok ) {
	if ( ! $ok ) {
		throw new RuntimeException( 'Failed: ' . $label );
	}
	echo 'ok ' . $label . "\n";
}
