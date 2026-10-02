<?php
/**
 * Integrity snapshot and verification for the ShootCal Testimonials trial.
 *
 * Two modes. `backup` writes a recoverable snapshot plus a checksum manifest OUTSIDE the
 * public webroot. `verify` recomputes the same checksums and reports any drift.
 *
 * The trial imports legacy Testimonials Showcase records into a new post type. It must
 * not change a single legacy post, its meta, its terms, its media, the five pages that
 * render testimonials, or any other plugin. Hashing all of it up front turns that
 * requirement from an intention into something a receipt can prove afterwards.
 *
 * Run with WP-CLI. The first positional argument is the mode, the second the output
 * directory:
 *   wp eval-file tools/backup-integrity.php backup /path/to/backup-dir
 *   wp eval-file tools/backup-integrity.php verify /path/to/backup-dir
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

$cli_args = is_array( $args ?? null ) ? $args : array();
$mode     = isset( $cli_args[0] ) ? (string) $cli_args[0] : '';
$out_dir  = isset( $cli_args[1] ) ? rtrim( (string) $cli_args[1], '/' ) : '';

if ( ! in_array( $mode, array( 'backup', 'verify' ), true ) ) {
	fwrite( STDERR, "Usage: wp eval-file tools/backup-integrity.php <backup|verify> <output-dir>\n" );
	exit( 1 );
}

if ( '' === $out_dir ) {
	fwrite( STDERR, "An output directory is required.\n" );
	exit( 1 );
}

if ( 'backup' === $mode && ! is_dir( $out_dir ) && ! wp_mkdir_p( $out_dir ) ) {
	fwrite( STDERR, "Cannot create {$out_dir}\n" );
	exit( 1 );
}

/** Page IDs that render testimonials and must not change. */
$protected_pages = array( 10575, 10630, 10647, 10702, 12960 );

$source_type = 'ttshowcase';
$manifest    = is_dir( $out_dir ) && is_readable( $out_dir . '/integrity.json' )
	? json_decode( (string) file_get_contents( $out_dir . '/integrity.json' ), true )
	: null;

/**
 * Stable hash of an array. Keys are sorted recursively so ordering cannot change the
 * digest, which is what makes a re-run comparable.
 *
 * @param mixed $value Value to normalize.
 * @return mixed
 */
function sct_stable( $value ) {
	if ( is_array( $value ) ) {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		if ( ! $is_list ) {
			ksort( $value );
		}

		foreach ( $value as $k => $v ) {
			$value[ $k ] = sct_stable( $v );
		}
	}

	return $value;
}

/**
 * SHA-256 of a stable JSON encoding of a value.
 *
 * @param mixed $value Value to hash.
 */
function sct_hash( $value ): string {
	return hash( 'sha256', (string) wp_json_encode( sct_stable( $value ) ) );
}

global $wpdb;

/* ---------------------------------------------------- legacy post snapshot */

$post_fields = array(
	'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
	'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password',
	'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
	'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
	'post_mime_type', 'comment_count',
);

/** Keys whose values are never written into any artifact. */
$private_keys = array( '_aditional_info_email', '_answer_info_notes' );

$legacy_ids = array_map( 'intval', (array) $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC", $source_type )
) );

$legacy_snapshot = array();
$attachment_ids  = array();

foreach ( $legacy_ids as $legacy_id ) {
	$post = get_post( $legacy_id );

	if ( ! $post ) {
		continue;
	}

	$row = array();

	foreach ( $post_fields as $field ) {
		$row[ $field ] = is_object( $post ) ? (string) ( $post->{$field} ?? '' ) : '';
	}

	$meta    = array();
	$raw_all = get_post_meta( $legacy_id );

	foreach ( $raw_all as $key => $values ) {
		$key = (string) $key;

		/* Presence is recorded for private keys; values are never read out. */
		if ( in_array( $key, $private_keys, true ) ) {
			$meta[ $key ] = array( '__private_redacted__' => count( (array) $values ) );
			continue;
		}

		$meta[ $key ] = array_map( 'strval', (array) $values );
	}

	$terms = array();

	foreach ( get_object_taxonomies( $source_type ) as $tax_name ) {
		$slugs = wp_get_object_terms( $legacy_id, $tax_name, array( 'fields' => 'slugs' ) );

		if ( ! is_wp_error( $slugs ) && array() !== $slugs ) {
			$terms[ $tax_name ] = array_map( 'strval', (array) $slugs );
		}
	}

	$thumb = (int) get_post_meta( $legacy_id, '_thumbnail_id', true );

	if ( $thumb > 0 ) {
		$attachment_ids[ $thumb ] = true;
	}

	$legacy_snapshot[ (string) $legacy_id ] = array(
		'row'   => $row,
		'meta'  => $meta,
		'terms' => $terms,
		'thumb' => $thumb,
	);
}

/* ---------------------------------------------------------- legacy terms */

$term_snapshot = array();

foreach ( get_object_taxonomies( $source_type ) as $tax_name ) {
	$terms = get_terms( array( 'taxonomy' => $tax_name, 'hide_empty' => false ) );

	if ( is_wp_error( $terms ) ) {
		continue;
	}

	foreach ( $terms as $term ) {
		$term_snapshot[] = array(
			'taxonomy'   => $tax_name,
			'term_id'    => (int) $term->term_id,
			'name'       => (string) $term->name,
			'slug'       => (string) $term->slug,
			'parent'     => (int) $term->parent,
			'count'      => (int) $term->count,
			'description' => (string) $term->description,
		);
	}
}

/* ------------------------------------------------------- media integrity */

$media_snapshot = array();

foreach ( array_keys( $attachment_ids ) as $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$file          = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
	$abs           = '' !== $file ? (string) WP_CONTENT_DIR . '/uploads/' . ltrim( $file, '/' ) : '';

	/* Resolve a date-sharded path when the stored relative path is not directly present. */
	if ( '' !== $abs && ! file_exists( $abs ) ) {
		$upload = wp_get_upload_dir();
		$cand   = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . ltrim( $file, '/' );

		if ( file_exists( $cand ) ) {
			$abs = $cand;
		}
	}

	$entry = array(
		'attachment_id' => $attachment_id,
		'relative_file' => $file,
		'exists'        => '' !== $abs && file_exists( $abs ),
		'post_status'   => (string) ( get_post_status( $attachment_id ) ?: '' ),
		'post_title'    => (string) ( get_post_field( 'post_title', $attachment_id ) ?: '' ),
		'post_mime'     => (string) ( get_post_field( 'post_mime_type', $attachment_id ) ?: '' ),
		'meta_hash'     => sct_hash( get_post_meta( $attachment_id ) ),
	);

	if ( $entry['exists'] ) {
		$entry['filesize']  = (int) filesize( $abs );
		$entry['file_md5']  = (string) md5_file( $abs );
		$entry['file_mtime'] = (int) filemtime( $abs );
	}

	$media_snapshot[ (string) $attachment_id ] = $entry;
}

/* ------------------------------------------------- protected page hashes */

$page_hashes = array();

foreach ( $protected_pages as $page_id ) {
	$post = get_post( $page_id );

	if ( ! $post ) {
		$page_hashes[ (string) $page_id ] = array( 'exists' => false );
		continue;
	}

	$content_fields = array();

	foreach ( $post_fields as $field ) {
		if ( in_array( $field, array( 'post_modified', 'post_modified_gmt' ), true ) ) {
			continue;
		}

		$content_fields[ $field ] = (string) ( $post->{$field} ?? '' );
	}

	$meta = array();

	foreach ( get_post_meta( $page_id ) as $key => $values ) {
		$meta[ (string) $key ] = array_map( 'strval', (array) $values );
	}

	$terms = array();

	foreach ( get_object_taxonomies( 'page' ) as $tax_name ) {
		$slugs = wp_get_object_terms( $page_id, $tax_name, array( 'fields' => 'slugs' ) );

		if ( ! is_wp_error( $slugs ) && array() !== $slugs ) {
			$terms[ $tax_name ] = array_map( 'strval', (array) $slugs );
		}
	}

	$row_all = array();

	foreach ( $post_fields as $field ) {
		$row_all[ $field ] = (string) ( $post->{$field} ?? '' );
	}

	$page_hashes[ (string) $page_id ] = array(
		'exists'         => true,
		'post_title'     => (string) $post->post_title,
		'post_name'      => (string) $post->post_name,
		'permalink'      => (string) get_permalink( $page_id ),
		/* Authored content, meta and terms. Excludes post_modified on purpose so an
		   unrelated touch does not read as a content change. */
		'content_sha256' => sct_hash( array( 'row' => $content_fields, 'meta' => $meta, 'terms' => $terms ) ),
		/* Everything, including post_modified. */
		'row_sha256'     => sct_hash( array( 'row' => $row_all, 'meta' => $meta, 'terms' => $terms ) ),
		'post_modified'  => (string) $post->post_modified,
	);
}

/* ------------------------------------------------------- plugin inventory */

$plugin_inventory = array();

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

foreach ( get_plugins() as $plugin_file => $plugin_data ) {
	$plugin_inventory[ (string) $plugin_file ] = array(
		'name'    => (string) ( $plugin_data['Name'] ?? '' ),
		'version' => (string) ( $plugin_data['Version'] ?? '' ),
		'active'  => is_plugin_active( (string) $plugin_file ),
	);
}

ksort( $plugin_inventory );

/* ------------------------------------------------------------- assemble */

$current = array(
	'generated_at'     => gmdate( 'c' ),
	'site_url'         => home_url(),
	'wp_version'       => (string) $GLOBALS['wp_version'],
	'legacy_count'     => count( $legacy_snapshot ),
	'legacy_sha256'    => sct_hash( $legacy_snapshot ),
	'legacy_terms_sha256' => sct_hash( $term_snapshot ),
	'media_sha256'     => sct_hash( $media_snapshot ),
	'media_count'      => count( $media_snapshot ),
	'pages_sha256'     => sct_hash( $page_hashes ),
	'pages'            => $page_hashes,
	'plugins_sha256'   => sct_hash( $plugin_inventory ),
	'plugin_count'     => count( $plugin_inventory ),
);

$failures = array();
$report   = array( 'mode' => $mode, 'directory' => $out_dir );

if ( 'backup' === $mode ) {
	$snapshot_file = $out_dir . '/legacy-snapshot.json';
	$media_file    = $out_dir . '/media-snapshot.json';
	$terms_file    = $out_dir . '/legacy-terms.json';
	$plugins_file  = $out_dir . '/plugin-inventory.json';
	$manifest_file = $out_dir . '/integrity.json';

	$writes = array(
		$snapshot_file => $legacy_snapshot,
		$media_file    => $media_snapshot,
		$terms_file    => $term_snapshot,
		$plugins_file  => $plugin_inventory,
		$manifest_file => $current,
	);

	foreach ( $writes as $path => $payload ) {
		$json    = (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$written = file_put_contents( $path, $json . "\n" );

		if ( false === $written ) {
			$failures[] = "could not write {$path}";
			continue;
		}

		@chmod( $path, 0600 );
		$report['written'][ basename( (string) $path ) ] = array(
			'bytes'   => $written,
			'sha256'  => hash( 'sha256', $json . "\n" ),
		);
	}

	$report['legacy_count']  = count( $legacy_snapshot );
	$report['media_count']   = count( $media_snapshot );
	$report['plugin_count']  = count( $plugin_inventory );
	$report['checksums']     = $current;
	$report['failures']      = $failures;
} else {
	if ( ! is_array( $manifest ) ) {
		fwrite( STDERR, "No integrity.json in {$out_dir}. Run backup mode first.\n" );
		exit( 1 );
	}

	$checks = array(
		'legacy_count', 'legacy_sha256', 'legacy_terms_sha256', 'media_sha256',
		'media_count', 'pages_sha256', 'plugins_sha256', 'plugin_count',
	);

	foreach ( $checks as $key ) {
		$before = (string) ( $manifest[ $key ] ?? '' );
		$after  = (string) ( $current[ $key ] ?? '' );
		$ok     = '' !== $before && $before === $after;

		$report['checks'][] = array(
			'key'    => $key,
			'ok'     => $ok,
			'before' => $before,
			'after'  => $after,
		);

		if ( ! $ok ) {
			$failures[] = $key;
		}
	}

	/* Per-page detail, because the five protected pages are called out by ID. */
	$report['pages'] = array();

	foreach ( $protected_pages as $page_id ) {
		$before = $manifest['pages'][ (string) $page_id ] ?? array();
		$after  = $current['pages'][ (string) $page_id ] ?? array();

		$content_ok = ( (string) ( $before['content_sha256'] ?? '' ) ) === ( (string) ( $after['content_sha256'] ?? '' ) ) && '' !== (string) ( $after['content_sha256'] ?? '' );
		$row_ok     = ( (string) ( $before['row_sha256'] ?? '' ) ) === ( (string) ( $after['row_sha256'] ?? '' ) );

		$report['pages'][ (string) $page_id ] = array(
			'title'          => (string) ( $after['post_title'] ?? '' ),
			'permalink'      => (string) ( $after['permalink'] ?? '' ),
			'content_match'  => $content_ok,
			'row_match'      => $row_ok,
			'content_sha256' => (string) ( $after['content_sha256'] ?? '' ),
			'baseline_sha256' => (string) ( $before['content_sha256'] ?? '' ),
			'post_modified'  => (string) ( $after['post_modified'] ?? '' ),
		);

		if ( ! $content_ok ) {
			$failures[] = 'page ' . $page_id . ' content';
		}
	}

	$report['ok']       = array() === $failures;
	$report['failures'] = $failures;

	$verify_file = $out_dir . '/verify-' . gmdate( 'Ymd-His' ) . '.json';
	$verify_json = (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	file_put_contents( $verify_file, $verify_json . "\n" );
	@chmod( $verify_file, 0600 );
	$report['verify_receipt'] = $verify_file;
}

fwrite( STDOUT, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

if ( array() !== $failures && 'verify' === $mode ) {
	exit( 1 );
}
