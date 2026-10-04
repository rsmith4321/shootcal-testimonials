<?php
/** Disposable localhost fixture for the 60-review continuation boundary. */
use ShootCalTestimonials\Shortcode;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
	throw new RuntimeException( 'Local WP-CLI only.' );
}

$mode = $args[0] ?? 'check';
$existing = get_posts( array( 'post_type' => 'sct_testimonial', 'post_status' => 'any', 'meta_key' => '_sct_pagination_qa', 'meta_value' => '1', 'posts_per_page' => -1, 'fields' => 'ids' ) );
if ( 'cleanup' === $mode ) {
	foreach ( $existing as $id ) { wp_delete_post( (int) $id, true ); }
	echo 'Removed ' . count( $existing ) . " local fixtures\n";
	return;
}

if ( 'seed' === $mode && count( $existing ) < 65 ) {
	$term = get_term_by( 'slug', 'wedding-photography', 'sct_category' );
	for ( $i = count( $existing ); $i < 65; ++$i ) {
		$id = wp_insert_post( array( 'post_type' => 'sct_testimonial', 'post_status' => 'publish', 'post_title' => sprintf( 'Pagination QA %03d', $i ), 'post_content' => sprintf( 'Complete synthetic review %03d.', $i ), 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 12:00:00' ) + $i * 60 ) ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		update_post_meta( $id, '_sct_pagination_qa', '1' );
		if ( $term ) { wp_set_object_terms( $id, array( $term->term_id ), 'sct_category' ); }
	}
	$existing = get_posts( array( 'post_type' => 'sct_testimonial', 'post_status' => 'any', 'meta_key' => '_sct_pagination_qa', 'meta_value' => '1', 'posts_per_page' => -1, 'fields' => 'ids' ) );
}

$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => 33 ) );
$shortcode = new Shortcode();
$options = array( 'filter' => 'show', 'count' => '21', 'total' => '60', 'more' => 'show' );
$_GET['sct_category'] = 'wedding-photography';
$_GET['sct_review_page'] = '1';
$first = $shortcode->render( $options );
$_GET['sct_review_page'] = '2';
$second = $shortcode->render( $options );
preg_match_all( '/data-sct-post="(\d+)"/', $first, $ids1 );
preg_match_all( '/data-sct-post="(\d+)"/', $second, $ids2 );
if ( count( $ids1[1] ) !== 60 || count( $ids2[1] ) < 5 || array_intersect( $ids1[1], $ids2[1] ) ) { throw new RuntimeException( 'Page boundary lost or duplicated reviews.' ); }
if ( ! str_contains( $first, 'sct_review_page=2' ) || ! str_contains( $first, 'sct_category=wedding-photography' ) || ! str_contains( $first, 'data-sct-next' ) ) { throw new RuntimeException( 'Category-preserving next link missing.' ); }
if ( ! str_contains( $first, 'Complete synthetic review' ) || ! str_contains( $second, 'Previous reviews' ) ) { throw new RuntimeException( 'No-script quote/page-link path missing.' ); }
echo 'PASS 60-card boundary, category continuation, unique pages, no-script content; fixtures=' . count( $existing ) . "\n";
