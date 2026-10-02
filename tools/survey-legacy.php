<?php
/**
 * Read-only audit of the legacy Testimonials Showcase (ttshowcase) data.
 *
 * Writes nothing. Its purpose is to establish, from evidence already stored on the site,
 * which platform each legacy review came from, so that sct_source and sct_source_lookup
 * can be filled from receipts rather than guessed.
 *
 * Run with WP-CLI. The single positional argument is the JSON report path:
 *   wp eval-file tools/survey-legacy.php /tmp/sct-survey.json
 *
 * A positional argument is used rather than a --flag because WP-CLI rejects unknown
 * assoc parameters before eval-file ever sees them.
 *
 * Privacy: _aditional_info_email and _answer_info_notes are counted but their values are
 * never read into the report.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress.\n" );
	exit( 1 );
}

/*
 * Only wp eval-file's own positional $args are trusted here. $_SERVER['argv'] is NOT
 * scanned: argv[0] is the wp-cli phar path, which a first-positional-argument rule would
 * happily accept as an output path and then attempt to overwrite.
 */
$cli_args = is_array( $args ?? null ) ? $args : array();

$out_file = '';

foreach ( $cli_args as $arg ) {
	if ( is_string( $arg ) && '' !== $arg && '-' !== $arg[0] ) {
		$out_file = $arg;
		break;
	}
}

if ( '' === $out_file ) {
	$out_file = '/tmp/sct-survey.json';
}

global $wpdb;

/** Keys whose values must never be echoed into a report. */
$private_keys = array( '_aditional_info_email', '_answer_info_notes' );

/** Platform evidence patterns. Each entry is a hint key and a case-insensitive regex. */
$patterns = array(
	'theknot'        => '/the\s*knot|theknot\.com/i',
	'weddingwire'    => '/wedding\s*wire|weddingwire\.com/i',
	'zola'           => '/\bzola\b|zola\.com/i',
	'yelp'           => '/\byelp\b|yelp\.com/i',
	'facebook'       => '/\bfacebook\b|fb\.com/i',
	'google'         => '/\bgoogle\b|goo\.gl|google\.com/i',
	'marthastewart'  => '/martha\s*stewart/i',
	'weddingspot'    => '/wedding\s*spot|weddingspot\.com/i',
	'herecomesthebride' => '/here\s*comes\s*the\s*bride/i',
	'grandstrandbride'  => '/grand\s*strand\s*bride/i',
	'instagram'      => '/\binstagram\b/i',
	'etsy'           => '/\betsy\b/i',
);

$report = array(
	'generated_at' => gmdate( 'c' ),
	'site_url'     => home_url(),
	'mode'         => 'read-only',
);

/* ---------------------------------------------------------------- counts */

$status_counts = array();

foreach ( array( 'publish', 'pending', 'draft', 'private', 'future', 'trash' ) as $status ) {
	$status_counts[ $status ] = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", 'ttshowcase', $status )
	);
}

$report['status_counts']    = $status_counts;
$report['status_counts_any'] = array_sum( $status_counts );

/* ------------------------------------------------------------ meta shape */

$meta_rows = $wpdb->get_results(
	"SELECT pm.meta_key AS k, COUNT(*) AS c
	   FROM {$wpdb->postmeta} pm
  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
      WHERE p.post_type = 'ttshowcase'
   GROUP BY pm.meta_key
   ORDER BY c DESC, k ASC"
);

$meta_keys = array();

foreach ( (array) $meta_rows as $meta_row ) {
	$meta_keys[ (string) $meta_row->k ] = array(
		'count'   => (int) $meta_row->c,
		'private' => in_array( (string) $meta_row->k, $private_keys, true ),
	);
}

$report['meta_keys'] = $meta_keys;

/* -------------------------------------------------------------- taxonomies */

$taxonomies = get_object_taxonomies( 'ttshowcase', 'objects' );
$tax_report = array();

foreach ( $taxonomies as $tax ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $tax->name,
			'hide_empty' => false,
		)
	);

	$term_rows = array();

	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$term_rows[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'slug'  => (string) $term->slug,
				'count' => (int) $term->count,
			);
		}
	}

	$tax_report[ $tax->name ] = array(
		'label' => (string) $tax->label,
		'terms' => $term_rows,
	);
}

$report['taxonomies'] = $tax_report;

/* --------------------------------------------------------- per-post survey */

$ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ttshowcase' ORDER BY ID ASC"
);

$hint_totals       = array();
$hint_field_totals = array();
$host_totals       = array();
$records           = array();
$probe_totals  = array(
	'has_google_review_id'      => 0,
	'has_google_reviewer'       => 0,
	'has_date_provenance'       => 0,
	'has_legacy_url'            => 0,
	'has_legacy_custom_url'     => 0,
	'has_rating'                => 0,
	'has_review_title'          => 0,
	'quote_in_meta'             => 0,
	'quote_in_content'          => 0,
	'quote_nowhere'             => 0,
	'name_in_title'             => 0,
	'name_in_meta'              => 0,
	'name_nowhere'              => 0,
	'has_thumbnail'             => 0,
	'private_email_present'     => 0,
	'private_notes_present'     => 0,
);

foreach ( (array) $ids as $id ) {
	$id = (int) $id;

	$post = get_post( $id );

	if ( ! $post ) {
		continue;
	}

	$all_meta = get_post_meta( $id );
	$row      = array(
		'id'            => $id,
		'status'        => (string) $post->post_status,
		'date'          => (string) $post->post_date,
		'name'          => trim( (string) $post->post_title ),
		'terms'         => array(),
		'hints'         => array(),
		'hint_evidence' => array(),
		'hosts'         => array(),
		'google_id'     => '',
	);

	/* Terms across every legacy taxonomy. */
	foreach ( array_keys( $tax_report ) as $tax_name ) {
		$term_ids = wp_get_object_terms( $id, $tax_name, array( 'fields' => 'id=>slug' ) );

		if ( is_wp_error( $term_ids ) ) {
			continue;
		}

		foreach ( $term_ids as $term_id => $slug ) {
			$row['terms'][] = $tax_name . ':' . $slug;
		}
	}

	/*
	 * Platform evidence is sought only in human-authored fields.
	 *
	 * Machine meta is deliberately excluded. Keys such as _facebook_shares, swp_*,
	 * _wpas_done_all, _publicize_pending and _jetpack_related_posts_cache are written by
	 * social-share and publicize plugins and mention platforms that have nothing to do
	 * with where a review came from. Scanning them produces false positives, which is
	 * exactly the guessing this audit exists to prevent.
	 */
	$evidence_fields = array(
		'post_title'   => (string) $post->post_title,
		'post_content' => (string) $post->post_content,
		'post_excerpt' => (string) $post->post_excerpt,
		'quote'        => (string) ( $all_meta['_aditional_info_short_testimonial'][0] ?? '' ),
		'review_title' => (string) ( $all_meta['_aditional_info_review_title'][0] ?? '' ),
		'response'     => (string) ( $all_meta['_answer_info_answer'][0] ?? '' ),
		'url'          => (string) ( $all_meta['_aditional_info_url'][0] ?? '' ),
		'custom_url'   => (string) ( $all_meta['_aditional_info_custom_url'][0] ?? '' ),
	);

	foreach ( $patterns as $hint => $pattern ) {
		foreach ( $evidence_fields as $field => $text ) {
			if ( '' === $text || 1 !== preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$offset  = (int) $match[0][1];
			$snippet = substr( $text, max( 0, $offset - 30 ), 90 );

			$row['hints'][] = $hint;

			if ( ! isset( $row['hint_evidence'][ $hint ] ) ) {
				$row['hint_evidence'][ $hint ] = array();
			}

			$row['hint_evidence'][ $hint ][] = array(
				'field'   => $field,
				'snippet' => trim( preg_replace( '/\s+/', ' ', $snippet ) ?? $snippet ),
			);

			$hint_field_totals[ $hint ][ $field ] = ( $hint_field_totals[ $hint ][ $field ] ?? 0 ) + 1;
		}
	}

	$row['hints'] = array_values( array_unique( $row['hints'] ) );

	foreach ( $row['hints'] as $hint ) {
		$hint_totals[ $hint ] = ( $hint_totals[ $hint ] ?? 0 ) + 1;
	}

	/* Private keys are counted for presence only; their values are never read out. */
	if ( '' !== trim( (string) ( $all_meta['_aditional_info_email'][0] ?? '' ) ) ) {
		++$probe_totals['private_email_present'];
	}

	if ( '' !== trim( (string) ( $all_meta['_answer_info_notes'][0] ?? '' ) ) ) {
		++$probe_totals['private_notes_present'];
	}

	/* URL hosts from the legacy link fields, which are client/business links. */
	foreach ( array( '_aditional_info_url', '_aditional_info_custom_url' ) as $url_key ) {
		$url = trim( (string) ( $all_meta[ $url_key ][0] ?? '' ) );

		if ( '' === $url ) {
			continue;
		}

		$host = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' );
		$host = strtolower( preg_replace( '/^www\./', '', $host ) ?? $host );

		if ( '' !== $host ) {
			$row['hosts'][]        = $host;
			$host_totals[ $host ]  = ( $host_totals[ $host ] ?? 0 ) + 1;
		}
	}

	$google_id = trim( (string) ( $all_meta['_rsp_google_review_id'][0] ?? '' ) );
	$row['google_id'] = '' !== $google_id ? 'present' : '';

	if ( '' !== $google_id ) {
		++$probe_totals['has_google_review_id'];
	}
	if ( '' !== trim( (string) ( $all_meta['_rsp_google_reviewer_profile'][0] ?? '' ) ) ) {
		++$probe_totals['has_google_reviewer'];
	}
	if ( '' !== trim( (string) ( $all_meta['_rsp_review_date_provenance'][0] ?? '' ) ) ) {
		++$probe_totals['has_date_provenance'];
	}
	if ( '' !== trim( (string) ( $all_meta['_aditional_info_url'][0] ?? '' ) ) ) {
		++$probe_totals['has_legacy_url'];
	}
	if ( '' !== trim( (string) ( $all_meta['_aditional_info_custom_url'][0] ?? '' ) ) ) {
		++$probe_totals['has_legacy_custom_url'];
	}
	if ( '' !== trim( (string) ( $all_meta['_aditional_info_rating'][0] ?? '' ) ) ) {
		++$probe_totals['has_rating'];
	}
	if ( '' !== trim( (string) ( $all_meta['_aditional_info_review_title'][0] ?? '' ) ) ) {
		++$probe_totals['has_review_title'];
	}

	$quote_meta = trim( (string) ( $all_meta['_aditional_info_short_testimonial'][0] ?? '' ) );

	if ( '' !== $quote_meta ) {
		++$probe_totals['quote_in_meta'];
	} elseif ( '' !== trim( wp_strip_all_tags( (string) $post->post_content ) ) ) {
		++$probe_totals['quote_in_content'];
	} else {
		++$probe_totals['quote_nowhere'];
	}

	if ( '' !== $row['name'] ) {
		++$probe_totals['name_in_title'];
	} elseif ( '' !== trim( (string) ( $all_meta['_aditional_info_name'][0] ?? '' ) ) ) {
		++$probe_totals['name_in_meta'];
		$row['name'] = trim( (string) $all_meta['_aditional_info_name'][0] );
	} else {
		++$probe_totals['name_nowhere'];
	}

	if ( (int) ( $all_meta['_thumbnail_id'][0] ?? 0 ) > 0 ) {
		++$probe_totals['has_thumbnail'];
	}

	$records[] = $row;
}

arsort( $hint_totals );
arsort( $host_totals );

$report['records_scanned']  = count( $records );
$report['field_probes']     = $probe_totals;
$report['hint_totals']      = $hint_totals;
$report['hint_field_totals'] = $hint_field_totals;
$report['url_host_totals']  = $host_totals;
$report['records']          = $records;

$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

if ( '' !== $out_file ) {
	$written = file_put_contents( $out_file, $json . "\n" );
	fwrite( STDOUT, "wrote {$out_file} ({$written} bytes)\n" );
}

/* Short summary to stdout so the receipt is readable without the JSON. */
$summary = $report;
unset( $summary['records'], $summary['meta_keys'], $summary['taxonomies'] );
fwrite( STDOUT, wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

fwrite( STDOUT, "\n--- taxonomies ---\n" );

foreach ( $tax_report as $tax_name => $tax ) {
	fwrite( STDOUT, "{$tax_name} ({$tax['label']}): " . count( $tax['terms'] ) . " terms\n" );

	foreach ( $tax['terms'] as $term ) {
		fwrite( STDOUT, sprintf( "  %-8s %-30s %s\n", $term['id'], $term['slug'], $term['name'] ) );
	}
}

fwrite( STDOUT, "\n--- meta keys ---\n" );

foreach ( $meta_keys as $key => $info ) {
	fwrite( STDOUT, sprintf( "  %-42s %4d%s\n", $key, $info['count'], $info['private'] ? '   [PRIVATE - values not read]' : '' ) );
}
