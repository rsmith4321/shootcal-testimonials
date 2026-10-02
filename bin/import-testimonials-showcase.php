<?php
/**
 * Import testimonials from Testimonials Showcase (ttshowcase) into ShootCal Testimonials.
 *
 * Run with WP-CLI. Arguments are positional, because WP-CLI rejects unknown --flags
 * before eval-file ever sees them:
 *
 *   wp eval-file bin/import-testimonials-showcase.php                        # dry run
 *   wp eval-file bin/import-testimonials-showcase.php apply                  # write
 *   wp eval-file bin/import-testimonials-showcase.php /path/receipts.json    # dry run with receipts
 *   wp eval-file bin/import-testimonials-showcase.php /path/receipts.json apply
 *
 * The receipts file is the source-lookup audit produced by tools/survey-legacy.php and the
 * platform reachability probes. It supplies per-record sct_source and sct_source_lookup
 * overrides plus the evidence behind them, so provenance is recorded from receipts rather
 * than inferred at import time.
 *
 * Design rules this follows:
 *
 * - Never modifies a ttshowcase post, its meta, its terms or its media. The legacy plugin
 *   stays installed and its data stays intact so the switch is reversible.
 * - Reuses the existing _thumbnail_id rather than re-uploading anything, so attachment
 *   identity and every derivative already on disk are preserved.
 * - Preserves post_date and post_date_gmt exactly. WordPress replaces a backdated
 *   post_date on publish unless edit_date is passed, so both writes restate the date.
 * - Idempotent. Each imported record stores its source post ID in sct_legacy_id and a run
 *   skips anything already imported, so it is safe to rerun.
 * - Reviewer email (_aditional_info_email) and private owner notes (_answer_info_notes)
 *   are deliberately not carried across. That is a privacy decision, not an omission.
 * - Editor and plugin cruft (_edit_lock, Jetpack cache, AMP meta, slide_template) is not
 *   carried across.
 * - Never guesses a source platform. Where the legacy record stores no third-party
 *   identifier the source is 'direct', and where a platform could not be read the lookup
 *   is 'blocked'. Neither is silently upgraded to something more confident.
 *
 * @package ShootCalTestimonials
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP-CLI eval-file tool scope and terminal report streams.
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Must run inside WordPress.\n" );
	exit( 1 );
}

/* ------------------------------------------------------------------ arguments */

$cli_args = is_array( $args ?? null ) ? $args : array();

$apply         = false;
$receipts_path = '';

foreach ( $cli_args as $arg ) {
	if ( ! is_string( $arg ) || '' === $arg ) {
		continue;
	}

	if ( in_array( $arg, array( 'apply', '--apply' ), true ) ) {
		$apply = true;
		continue;
	}

	if ( in_array( $arg, array( 'dry-run', 'dry' ), true ) ) {
		continue;
	}

	if ( '-' !== $arg[0] ) {
		$receipts_path = $arg;
	}
}

$source_type = 'ttshowcase';
$target_type = 'sct_testimonial';

if ( ! post_type_exists( $source_type ) ) {
	fwrite( STDERR, "Source post type '{$source_type}' is not registered. Is Testimonials Showcase active?\n" );
	exit( 1 );
}

if ( ! post_type_exists( $target_type ) ) {
	fwrite( STDERR, "Target post type '{$target_type}' is not registered. Is ShootCal Testimonials active?\n" );
	exit( 1 );
}

/* ------------------------------------------------------------- source lookup */

/**
 * Lookup outcomes are a closed set. An empty string means "not researched yet" and is
 * treated as a hard failure below, because the whole point of this field is that an
 * unverified record must never look verified.
 */
$valid_lookups = array( 'matched', 'not-found', 'blocked' );

$valid_sources = class_exists( '\ShootCalTestimonials\Meta' )
	? \ShootCalTestimonials\Meta::SOURCES
	: array( 'google', 'zola', 'weddingwire', 'theknot', 'yelp', 'facebook', 'direct', 'other' );

$overrides = array();
$receipts  = null;

if ( '' !== $receipts_path && is_readable( $receipts_path ) ) {
	$decoded = json_decode( (string) file_get_contents( $receipts_path ), true );

	if ( is_array( $decoded ) ) {
		$receipts = $decoded;

		if ( isset( $decoded['record_overrides'] ) && is_array( $decoded['record_overrides'] ) ) {
			$overrides = $decoded['record_overrides'];
		}
	}
}

if ( '' !== $receipts_path && ! is_array( $receipts ) ) {
	fwrite( STDERR, "Receipt file is missing or invalid; no writes performed.\n" ); exit( 1 );
}
// Validate every receipt before creating any term or review.
foreach ( $overrides as $legacy_key => $override ) {
	if ( ! is_array( $override ) || ! ctype_digit( (string) $legacy_key ) ||
		( isset( $override['source'] ) && ! in_array( $override['source'], $valid_sources, true ) ) ||
		( isset( $override['lookup'] ) && ! in_array( $override['lookup'], $valid_lookups, true ) ) ) {
		WP_CLI::error( 'Receipt has an invalid record override; no writes performed.' );
	}
}
/* Meta carried across, legacy key to new key. */
$meta_map = array(
	'_aditional_info_rating'         => 'sct_rating',
	'_aditional_info_review_title'   => 'sct_review_title',
	'_rsp_google_review_id'          => 'sct_source_review_id',
	'_rsp_google_reviewer_profile'   => 'sct_reviewer_profile_url',
	'_rsp_review_date_provenance'    => 'sct_date_provenance',
);

/**
 * Legacy keys retained under a legacy_ prefix so nothing is lost, but which the current
 * renderer does not use. Testimonials Showcase has no field for the public URL of the
 * review itself, so _aditional_info_url is a client or business link and must not be
 * mistaken for a review source URL.
 */
$legacy_keep = array(
	'_aditional_info_url'        => 'sct_legacy_url',
	'_aditional_info_custom_url' => 'sct_legacy_custom_url',
	'_answer_info_answer'        => 'sct_legacy_response',
);

/** Never carried across. Listed explicitly so the decision is visible in the receipt. */
$excluded = array(
	'_aditional_info_email',
	'_answer_info_notes',
	'_edit_lock',
	'_edit_last',
	'_last_editor_used_jetpack',
	'_jetpack_related_posts_cache',
	'wp_featherlight_disable',
	'slide_template',
	'_amp_validated_url_post_id',
	'_wp_old_date',
);

$report = array(
	'mode'          => $apply ? 'apply' : 'dry-run',
	'generated_at'  => gmdate( 'c' ),
	'source_type'   => $source_type,
	'target_type'   => $target_type,
	'excluded'      => $excluded,
	'receipts_file' => '' === $receipts_path ? 'none supplied' : $receipts_path,
	'receipts_loaded' => is_array( $receipts ),
	'receipt_overrides' => count( $overrides ),
);

// Index what has already been imported so a rerun cannot duplicate anything.
$already  = array();
$existing = get_posts(
	array(
		'post_type'      => $target_type,
		'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future', 'trash' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Offline migration must index every language and status for idempotence.
		'suppress_filters' => true,
		'no_found_rows'  => true,
	)
);

foreach ( $existing as $existing_id ) {
	$legacy = get_post_meta( (int) $existing_id, 'sct_legacy_id', true );

	if ( '' !== $legacy && null !== $legacy ) {
		$already[ (string) $legacy ] = (int) $existing_id;
	}
}

$report['previously_imported'] = count( $already );

$source_posts = get_posts(
	array(
		'post_type'      => $source_type,
		'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	)
);

$report['source_found'] = count( $source_posts );

// Term map, built from the legacy taxonomy so names and slugs survive.
$term_map     = array();
$new_terms    = 0;
$sct_new_term_ids = array();
$legacy_terms = get_terms(
	array(
		'taxonomy'   => 'ttshowcase_groups',
		'hide_empty' => false,
	)
);

if ( ! is_wp_error( $legacy_terms ) ) {
	foreach ( $legacy_terms as $term ) {
		$existing_term = get_term_by( 'slug', $term->slug, 'sct_category' );

		if ( $existing_term ) {
			$term_map[ (int) $term->term_id ] = (int) $existing_term->term_id;
			continue;
		}

		if ( ! $apply ) {
			$term_map[ (int) $term->term_id ] = 0;
			++$new_terms;
			continue;
		}

		$created = wp_insert_term( $term->name, 'sct_category', array( 'slug' => $term->slug, 'description' => $term->description ) );

		if ( is_wp_error( $created ) ) {
			WP_CLI::error( 'Category creation failed: ' . $created->get_error_message() );
		}

		$term_map[ (int) $term->term_id ] = (int) $created['term_id'];
		$sct_new_term_ids[] = (int) $created['term_id'];
		++$new_terms;
	}
}

if ( is_wp_error( $legacy_terms ) ) { WP_CLI::error( 'Unable to read legacy categories: ' . $legacy_terms->get_error_message() ); }
// Apply parent links only to newly imported categories, leaving existing authored terms intact.
if ( $apply ) {
	foreach ( $legacy_terms as $term ) {
		$mapped = $term_map[ (int) $term->term_id ] ?? 0;
		$target = get_term( $mapped, 'sct_category' );
		if ( in_array( $mapped, $sct_new_term_ids, true ) && $term->parent && ! is_wp_error( $target ) && $target && ! $target->parent ) {
			$result = wp_update_term( $mapped, 'sct_category', array( 'parent' => $term_map[ (int) $term->parent ] ?? 0 ) );
			if ( is_wp_error( $result ) ) { WP_CLI::error( 'Category hierarchy migration failed.' ); }
		}
	}
}
$report['terms_mapped'] = count( $term_map );
$report['terms_new']    = $new_terms;

$imported     = 0;
$skipped      = array();
$no_photo     = 0;
$pending      = 0;
$source_table = array();
$pair_table   = array();
$lookup_totals = array(
	'matched'   => 0,
	'not-found' => 0,
	'blocked'   => 0,
	'EMPTY'     => 0,
);
$empty_source = 0;
$empty_lookup = 0;
$provenance   = array();

foreach ( $source_posts as $source ) {
	$source_id = (int) $source->ID;

	if ( isset( $already[ (string) $source_id ] ) ) {
		$skipped[] = array(
			'source_id' => $source_id,
			'reason'    => 'already imported as ' . $already[ (string) $source_id ],
		);
		continue;
	}

	$name  = trim( (string) $source->post_title );
	$quote = trim( (string) get_post_meta( $source_id, '_aditional_info_short_testimonial', true ) );

	// Testimonials Showcase keeps the display text in meta and leaves post_content empty.
	if ( '' === $quote ) {
		$quote = trim( wp_strip_all_tags( (string) $source->post_content ) );
	}

	if ( '' === $name ) {
		$name = trim( (string) get_post_meta( $source_id, '_aditional_info_name', true ) );
	}

	if ( '' === $quote || '' === $name ) {
		$skipped[] = array(
			'source_id' => $source_id,
			'reason'    => '' === $quote ? 'no review text' : 'no reviewer name',
		);
		continue;
	}

	$thumbnail = (int) get_post_meta( $source_id, '_thumbnail_id', true );

	if ( $thumbnail <= 0 ) {
		++$no_photo;
	}

	/*
	 * Source and lookup provenance.
	 *
	 * sct_source is google only where the legacy record stores _rsp_google_review_id, which
	 * is written by the Google Business Profile capture workflow rather than typed in by an
	 * editor, so it is first-party evidence. Every other legacy record stores no
	 * third-party platform identifier at all, so its source is direct: naming a platform
	 * there would be a guess, and direct renders no third-party attribution.
	 *
	 * Platform words inside the quote text are not evidence. They describe how the client
	 * found the photographer, not where the client published the review, which is recorded
	 * in the receipts file under rejected_mentions.
	 *
	 * sct_source_lookup is not-found for a direct record because there is no external
	 * record to reconcile against, and blocked for a platform that refused or could not be
	 * read. matched is only ever set from a receipt that documents the confirmation.
	 */
	$google_id = trim( (string) get_post_meta( $source_id, '_rsp_google_review_id', true ) );
	$src       = '' !== $google_id ? 'google' : 'direct';

	$lookup   = 'direct' === $src ? 'not-found' : 'blocked';
	$src_note = 'direct' === $src
		? 'No third-party platform identifier is stored on the legacy record, so there is no external review to reconcile against.'
		: 'A Google review id is stored on the legacy record, but Google Maps review surfaces are not readable by an anonymous request and no signed-in browser confirmation was available.';

	$override = $overrides[ (string) $source_id ] ?? null;

	if ( is_array( $override ) ) {
		if ( isset( $override['source'] ) && is_string( $override['source'] ) && '' !== $override['source'] ) {
			$src = $override['source'];
		}

		if ( isset( $override['lookup'] ) && is_string( $override['lookup'] ) && '' !== $override['lookup'] ) {
			$lookup = $override['lookup'];
		}

		if ( isset( $override['note'] ) && is_string( $override['note'] ) && '' !== $override['note'] ) {
			$src_note = $override['note'];
		}
	}

	if ( ! in_array( $src, $valid_sources, true ) ) {
		$src = '';
		++$empty_source;
	}

	if ( ! in_array( $lookup, $valid_lookups, true ) ) {
		$lookup = '';
		++$empty_lookup;
	}

	$source_table[ $src ] = ( $source_table[ $src ] ?? 0 ) + 1;

	$pair_key              = ( '' !== $src ? $src : 'EMPTY' ) . ' / ' . ( '' !== $lookup ? $lookup : 'EMPTY' );
	$pair_table[ $pair_key ] = ( $pair_table[ $pair_key ] ?? 0 ) + 1;

	$lookup_totals[ '' !== $lookup ? $lookup : 'EMPTY' ] = ( $lookup_totals[ '' !== $lookup ? $lookup : 'EMPTY' ] ?? 0 ) + 1;

	$provenance[ (string) $source_id ] = array(
		'source' => $src,
		'lookup' => $lookup,
		'note'   => $src_note,
		'from_receipt' => isset( $overrides[ (string) $source_id ] ),
	);

	if ( 'pending' === $source->post_status ) {
		++$pending;
	}

	if ( ! $apply ) {
		++$imported;
		continue;
	}

	$new_id = wp_insert_post(
		wp_slash( array(
			'post_type'     => $target_type,
			'post_title'    => $name,
			'post_content'  => $quote,
			'post_excerpt'  => (string) $source->post_excerpt,
			'post_status'   => $source->post_status,
			'post_date'     => $source->post_date,
			'post_date_gmt' => $source->post_date_gmt,
			'post_author'   => (int) $source->post_author,
			'edit_date'     => true,
		) ),
		true
	);

	if ( is_wp_error( $new_id ) ) {
		WP_CLI::error( 'Import failed for legacy review ' . $source_id . ': ' . $new_id->get_error_message() );
	}

	$new_id = (int) $new_id;

	// Restate the date, because publishing a backdated post can reset it.
	$date_result = wp_update_post(
		array(
			'ID'            => $new_id,
			'post_date'     => $source->post_date,
			'post_date_gmt' => $source->post_date_gmt,
			'edit_date'     => true,
		), true
	);
	if ( is_wp_error( $date_result ) ) { WP_CLI::error( 'Review created but date preservation failed for ' . $new_id ); }

	update_post_meta( $new_id, 'sct_legacy_id', $source_id );
	update_post_meta( $new_id, 'sct_source', $src );
	update_post_meta( $new_id, 'sct_source_lookup', $lookup );
	update_post_meta( $new_id, 'sct_source_note', wp_slash( $src_note ) );

	foreach ( $meta_map as $from => $to ) {
		$value = get_post_meta( $source_id, $from, true );

		if ( '' !== $value && null !== $value ) {
			update_post_meta( $new_id, $to, wp_slash( $value ) );
		}
	}

	foreach ( $legacy_keep as $from => $to ) {
		$value = get_post_meta( $source_id, $from, true );

		if ( '' !== $value && null !== $value ) {
			update_post_meta( $new_id, $to, wp_slash( $value ) );
		}
	}

	// Reuse the existing attachment. No file is copied, re-uploaded or regenerated.
	if ( $thumbnail > 0 && 'attachment' === get_post_type( $thumbnail ) ) {
		set_post_thumbnail( $new_id, $thumbnail );
	}

	$source_terms = wp_get_object_terms( $source_id, 'ttshowcase_groups', array( 'fields' => 'ids' ) );

	if ( ! is_wp_error( $source_terms ) && array() !== $source_terms ) {
		$target_terms = array();

		foreach ( $source_terms as $source_term_id ) {
			if ( ! empty( $term_map[ (int) $source_term_id ] ) ) {
				$target_terms[] = (int) $term_map[ (int) $source_term_id ];
			}
		}

		if ( array() !== $target_terms ) {
			$assigned = wp_set_object_terms( $new_id, $target_terms, 'sct_category' );
			if ( is_wp_error( $assigned ) ) { WP_CLI::error( 'Review imported but category assignment failed for ' . $new_id ); }
		}
	}

	++$imported;
}

ksort( $source_table );
ksort( $pair_table );

$report['would_import']    = $apply ? null : $imported;
$report['imported']        = $apply ? $imported : null;
$report['skipped']         = $skipped;
$report['skipped_count']   = count( $skipped );
$report['without_photo']   = $no_photo;
$report['pending_status']  = $pending;
$report['source_table']    = $source_table;
$report['platform_lookup_table'] = $pair_table;
$report['lookup_totals']   = $lookup_totals;
$report['empty_source_count'] = $empty_source;
$report['empty_lookup_count'] = $empty_lookup;
$report['guessed_source_count'] = 0;

if ( $apply ) {
	$counts = wp_count_posts( $target_type );

	$report['target_total']         = (int) ( $counts->publish ?? 0 );
	$report['target_total_any']     = (int) array_sum(
		array_map(
			'intval',
			array_filter(
				(array) $counts,
				static fn( $key ): bool => 'trash' !== $key,
				ARRAY_FILTER_USE_KEY
			)
		)
	);
}

/*
 * Post-write audit. Re-reads every imported record rather than trusting the in-memory
 * tables, so the receipt reflects what is actually stored. Owner-authored reviews
 * without a legacy identity are outside this migration audit.
 */
if ( $apply ) {
	$audit_ids = get_posts(
		array(
			'post_type'      => $target_type,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'sct_legacy_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Offline migration identity audit.
		)
	);

	$audit = array(
		'total'          => count( $audit_ids ),
		'empty_source'   => array(),
		'empty_lookup'   => array(),
		'invalid_lookup' => array(),
		'source_table'   => array(),
		'lookup_table'   => array(),
	);

	foreach ( $audit_ids as $audit_id ) {
		$audit_id = (int) $audit_id;
		$a_src    = (string) get_post_meta( $audit_id, 'sct_source', true );
		$a_lookup = (string) get_post_meta( $audit_id, 'sct_source_lookup', true );

		if ( '' === trim( $a_src ) ) {
			$audit['empty_source'][] = $audit_id;
		} else {
			$audit['source_table'][ $a_src ] = ( $audit['source_table'][ $a_src ] ?? 0 ) + 1;
		}

		if ( '' === trim( $a_lookup ) ) {
			$audit['empty_lookup'][] = $audit_id;
		} elseif ( ! in_array( $a_lookup, $valid_lookups, true ) ) {
			$audit['invalid_lookup'][] = $audit_id;
		} else {
			$audit['lookup_table'][ $a_lookup ] = ( $audit['lookup_table'][ $a_lookup ] ?? 0 ) + 1;
		}
	}

	ksort( $audit['source_table'] );
	ksort( $audit['lookup_table'] );

	$report['stored_audit'] = $audit;

	$hard_failures = array();

	if ( array() !== $audit['empty_source'] ) {
		$hard_failures[] = 'records with empty sct_source: ' . count( $audit['empty_source'] );
	}

	if ( array() !== $audit['empty_lookup'] ) {
		$hard_failures[] = 'records with empty sct_source_lookup: ' . count( $audit['empty_lookup'] );
	}

	if ( array() !== $audit['invalid_lookup'] ) {
		$hard_failures[] = 'records with invalid sct_source_lookup: ' . count( $audit['invalid_lookup'] );
	}

	$report['hard_failures'] = $hard_failures;

	if ( array() !== $hard_failures ) {
		fwrite( STDOUT, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		fwrite( STDERR, "IMPORT INCOMPLETE: " . implode( '; ', $hard_failures ) . "\n" );
		exit( 1 );
	}
}

/* Write the per-record provenance map next to the report so it is itself a receipt. */
$report['provenance_sample'] = array_slice( $provenance, 0, 5, true );
$report['provenance_count']  = count( $provenance );

fwrite( STDOUT, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

if ( $apply && '' !== $receipts_path ) {
	$prov_file = preg_replace( '/\.json$/i', '', $receipts_path ) . '-applied-provenance.json';
	$written   = file_put_contents( $prov_file, wp_json_encode( $provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

	if ( false !== $written ) {
		fwrite( STDOUT, "applied provenance written to {$prov_file} ({$written} bytes)\n" );
	}
}
