<?php
/**
 * Read-only verification of the ShootCal Testimonials trial.
 *
 * Produces one receipt covering the server-side checks that the trial must satisfy:
 * plugin active and at the right version, imported record count, complete and valid
 * sct_source and sct_source_lookup on every record, the untouched legacy post count,
 * block registration, shortcode availability, and that two different category filters
 * really do render two different card sets.
 *
 * Writes nothing. Run with WP-CLI:
 *   wp eval-file tools/verify-trial.php <trial-page-slug>
 *
 * A positional argument is used rather than a --flag because WP-CLI rejects unknown
 * assoc parameters before eval-file ever sees them.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress.\n" );
	exit( 1 );
}

$cli_args  = is_array( $args ?? null ) ? $args : array();
$trial_slug = '';

foreach ( $cli_args as $arg ) {
	if ( is_string( $arg ) && '' !== $arg && '-' !== $arg[0] ) {
		$trial_slug = $arg;
		break;
	}
}

global $wpdb;

$out = array( 'generated_at' => gmdate( 'c' ), 'site' => home_url() );

$plugin_file = 'shootcal-testimonials/shootcal-testimonials.php';

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$all_plugins = get_plugins();
$plugin_data = $all_plugins[ $plugin_file ] ?? null;

$out['plugin'] = array(
	'installed' => null !== $plugin_data,
	'active'    => is_plugin_active( $plugin_file ),
	'version'   => (string) ( $plugin_data['Version'] ?? '' ),
	'name'      => (string) ( $plugin_data['Name'] ?? '' ),
);

/* Legacy plugin must still be installed and active: the trial sits alongside it. */
$legacy_active = array();

foreach ( $all_plugins as $file => $data ) {
	if ( false !== stripos( (string) ( $data['Name'] ?? '' ), 'testimonial' ) ) {
		$legacy_active[ (string) $file ] = array(
			'name'    => (string) ( $data['Name'] ?? '' ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'active'  => is_plugin_active( (string) $file ),
		);
	}
}

$out['testimonial_plugins'] = $legacy_active;

/* ------------------------------------------------------------ post counts */

$sct_counts = (array) wp_count_posts( 'sct_testimonial' );
$tt_counts  = (array) wp_count_posts( 'ttshowcase' );

$out['counts'] = array(
	'sct_testimonial_by_status' => array_filter( $sct_counts, static fn( $v ): bool => (int) $v > 0 ),
	'sct_testimonial_any'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'sct_testimonial'" ),
	'sct_testimonial_trash'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'sct_testimonial' AND post_status = 'trash'" ),
	'ttshowcase_published'      => (int) ( $tt_counts['publish'] ?? 0 ),
	'ttshowcase_any'            => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ttshowcase'" ),
);

/* ------------------------------------------------- provenance completeness */

$sct_ids = array_map( 'intval', (array) $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sct_testimonial' ORDER BY ID ASC"
) );

$valid_lookups = array( 'matched', 'not-found', 'blocked' );
$valid_sources = class_exists( '\ShootCalTestimonials\Meta' )
	? \ShootCalTestimonials\Meta::SOURCES
	: array( 'google', 'zola', 'weddingwire', 'theknot', 'yelp', 'facebook', 'direct', 'other' );

$source_table = array();
$lookup_table = array();
$pair_table   = array();
$empty_source = array();
$empty_lookup = array();
$bad_lookup   = array();
$bad_source   = array();
$empty_note   = array();

foreach ( $sct_ids as $id ) {
	$src    = trim( (string) get_post_meta( $id, 'sct_source', true ) );
	$lookup = trim( (string) get_post_meta( $id, 'sct_source_lookup', true ) );
	$note   = trim( (string) get_post_meta( $id, 'sct_source_note', true ) );

	if ( '' === $src ) {
		$empty_source[] = $id;
	} elseif ( ! in_array( $src, $valid_sources, true ) ) {
		$bad_source[] = $id . '=' . $src;
	} else {
		$source_table[ $src ] = ( $source_table[ $src ] ?? 0 ) + 1;
	}

	if ( '' === $lookup ) {
		$empty_lookup[] = $id;
	} elseif ( ! in_array( $lookup, $valid_lookups, true ) ) {
		$bad_lookup[] = $id . '=' . $lookup;
	} else {
		$lookup_table[ $lookup ] = ( $lookup_table[ $lookup ] ?? 0 ) + 1;
	}

	if ( '' === $note ) {
		$empty_note[] = $id;
	}

	$pair            = ( '' !== $src ? $src : 'EMPTY' ) . ' / ' . ( '' !== $lookup ? $lookup : 'EMPTY' );
	$pair_table[ $pair ] = ( $pair_table[ $pair ] ?? 0 ) + 1;
}

ksort( $source_table );
ksort( $lookup_table );
ksort( $pair_table );

$out['provenance'] = array(
	'records_checked'       => count( $sct_ids ),
	'source_table'          => $source_table,
	'lookup_table'          => $lookup_table,
	'platform_lookup_table' => $pair_table,
	'empty_source_ids'      => $empty_source,
	'empty_lookup_ids'      => $empty_lookup,
	'invalid_source'        => $bad_source,
	'invalid_lookup'        => $bad_lookup,
	'empty_note_ids'        => $empty_note,
	'complete'              => array() === $empty_source && array() === $empty_lookup && array() === $bad_lookup && array() === $bad_source,
);

/* The two Google-sourced records, called out by name. */
$google_rows = array();

foreach ( $sct_ids as $id ) {
	if ( 'google' === trim( (string) get_post_meta( $id, 'sct_source', true ) ) ) {
		$google_rows[] = array(
			'id'            => $id,
			'title'         => (string) get_post_field( 'post_title', $id ),
			'legacy_id'     => (string) get_post_meta( $id, 'sct_legacy_id', true ),
			'lookup'        => (string) get_post_meta( $id, 'sct_source_lookup', true ),
			'review_id'     => substr( (string) get_post_meta( $id, 'sct_source_review_id', true ), 0, 24 ) . '...',
			'profile_url'   => (string) get_post_meta( $id, 'sct_reviewer_profile_url', true ),
			'rating'        => (string) get_post_meta( $id, 'sct_rating', true ),
		);
	}
}

$out['google_sourced_records'] = $google_rows;

/* --------------------------------------------------------------- taxonomy */

$terms = get_terms( array( 'taxonomy' => 'sct_category', 'hide_empty' => false ) );
$term_rows = array();

if ( ! is_wp_error( $terms ) ) {
	foreach ( $terms as $term ) {
		$term_rows[] = array(
			'slug'  => (string) $term->slug,
			'name'  => (string) $term->name,
			'count' => (int) $term->count,
		);
	}
}

usort( $term_rows, static fn( $a, $b ): int => $b['count'] <=> $a['count'] );

$out['sct_category_terms'] = $term_rows;

/* ------------------------------------------------------- block and forms */

$registry      = \WP_Block_Type_Registry::get_instance();
$block         = $registry->is_registered( 'shootcal/testimonials' ) ? $registry->get_registered( 'shootcal/testimonials' ) : null;
$block_attrs   = null !== $block && is_array( $block->attributes ) ? array_keys( $block->attributes ) : array();

$out['block'] = array(
	'registered'        => null !== $block,
	'name'              => null !== $block ? (string) $block->name : '',
	'has_category_attr' => in_array( 'category', $block_attrs, true ),
	'is_dynamic'        => null !== $block && is_callable( $block->render_callback ),
	'attributes'        => $block_attrs,
	'editor_script'     => null !== $block ? (string) ( is_string( $block->editor_script ) ? $block->editor_script : '' ) : '',
);

$out['shortcodes'] = array(
	'shootcal_testimonials'     => shortcode_exists( 'shootcal_testimonials' ),
	'shootcal_testimonial_form' => shortcode_exists( 'shootcal_testimonial_form' ),
);

/* --------------------------------------- two category filters render differently */

$render_probe = array();
$probe_slugs  = array();

foreach ( $term_rows as $term ) {
	if ( (int) $term['count'] > 0 ) {
		$probe_slugs[] = (string) $term['slug'];
	}

	if ( count( $probe_slugs ) >= 2 ) {
		break;
	}
}

foreach ( $probe_slugs as $slug ) {
	$html = (string) do_shortcode( '[shootcal_testimonials category="' . $slug . '" count="6"]' );

	preg_match_all( '/<figure[^>]*data-sct-card[^>]*>/', $html, $figures );
	preg_match_all( '/sct-dialog-(\d+)/', $html, $dialogs );

	$render_probe[ $slug ] = array(
		'cards'        => count( $figures[0] ),
		'dialog_ids'   => array_values( array_unique( $dialogs[1] ) ),
		'html_bytes'   => strlen( $html ),
	);
}

$two = array_values( $render_probe );

$out['category_filter_probe'] = array(
	'slugs'             => $probe_slugs,
	'results'           => $render_probe,
	'different_sets'    => 2 === count( $two )
		&& array() !== array_diff( $two[0]['dialog_ids'], $two[1]['dialog_ids'] )
		&& array() === array_intersect( $two[0]['dialog_ids'], $two[1]['dialog_ids'] ),
);

/* Query-string override used by the two trial URLs. */
$override_probe = array();

foreach ( $probe_slugs as $slug ) {
	$html = (string) do_shortcode( '[shootcal_testimonials allow_query="on" count="4"]' );
	unset( $html );

	$_GET['sct_category'] = $slug;
	$html2                = (string) do_shortcode( '[shootcal_testimonials allow_query="on" count="4"]' );
	unset( $_GET['sct_category'] );

	preg_match_all( '/sct-dialog-(\d+)/', $html2, $d2 );

	$override_probe[ $slug ] = array_values( array_unique( $d2[1] ) );
}

$ov = array_values( $override_probe );

$out['query_override_probe'] = array(
	'slugs'          => $probe_slugs,
	'dialog_ids'     => $override_probe,
	'different_sets' => 2 === count( $ov )
		&& array() !== $ov[0] && array() !== $ov[1]
		&& array() === array_intersect( $ov[0], $ov[1] ),
);

/* ---------------------------------------------------------- trial page */

if ( '' !== $trial_slug ) {
	$page = get_page_by_path( $trial_slug );

	if ( ! $page ) {
		$out['trial_page'] = array( 'slug' => $trial_slug, 'exists' => false );
	} else {
		$out['trial_page'] = array(
			'slug'      => $trial_slug,
			'exists'    => true,
			'id'        => (int) $page->ID,
			'title'     => (string) $page->post_title,
			'status'    => (string) $page->post_status,
			'permalink' => (string) get_permalink( $page->ID ),
			/* rank_math_robots is stored as an array on this site, so it is JSON encoded
			   rather than cast to a string, which would raise an Array to string warning. */
			'noindex'   => (string) wp_json_encode( get_post_meta( $page->ID, 'rank_math_robots', true ) ),
			'has_shortcode' => false !== strpos( (string) $page->post_content, 'shootcal_testimonials' ),
			'has_block'     => false !== strpos( (string) $page->post_content, 'wp:shootcal/testimonials' ),
			'has_form'      => false !== strpos( (string) $page->post_content, 'shootcal_testimonial_form' ),
			'content_sha256' => hash( 'sha256', (string) $page->post_content ),
		);
	}
}

/* ------------------------------------------------------------- verdicts */

$out['verdicts'] = array(
	'plugin_active'          => (bool) $out['plugin']['active'],
	'plugin_version_0_3_0'   => '0.3.0' === $out['plugin']['version'],
	'legacy_still_active'    => (bool) ( $out['counts']['ttshowcase_published'] > 0 ),
	'provenance_complete'    => (bool) $out['provenance']['complete'],
	'block_registered'       => (bool) $out['block']['registered'],
	'block_has_category'     => (bool) $out['block']['has_category_attr'],
	'category_filters_differ' => (bool) $out['category_filter_probe']['different_sets'],
	'query_override_differs' => (bool) $out['query_override_probe']['different_sets'],
	'form_shortcode_exists'  => (bool) $out['shortcodes']['shootcal_testimonial_form'],
);

fwrite( STDOUT, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
