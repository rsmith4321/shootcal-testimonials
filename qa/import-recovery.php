<?php
/**
 * Disposable WP-CLI integration fixture for importer interruption/retry safety.
 *
 * Run only against a disposable local WordPress copy with a separate database:
 *
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php setup
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php fail       # must exit nonzero
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php failed
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php resume
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php complete
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php owner-edit
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php preserved
 *   SCT_IMPORT_RECOVERY_TEST=1 wp eval-file qa/import-recovery.php cleanup
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'SCT_IMPORT_RECOVERY_TEST' ) ||
	! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost', 'shootcal-plugin-dev.local' ), true ) ) {
	throw new RuntimeException( 'This fixture requires an explicit disposable localhost WordPress run.' );
}

register_post_type( 'ttshowcase', array( 'public' => false ) );
register_taxonomy( 'ttshowcase_groups', 'ttshowcase', array( 'hierarchical' => true ) );

$mode = is_array( $args ?? null ) ? (string) ( $args[0] ?? '' ) : '';
$fixture = get_option( 'shootcal_testimonials_import_recovery_fixture', array() );
$importer = dirname( __DIR__ ) . '/bin/import-testimonials-showcase.php';

$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$targets = static function ( int $legacy_id ): array {
	return get_posts( array(
		'post_type'      => 'shootcal_testimonial',
		'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future', 'trash' ),
		'posts_per_page' => -1,
		'meta_key'       => 'shootcal_testimonials_legacy_id',
		'meta_value'     => $legacy_id,
	) );
};

if ( 'setup' === $mode ) {
	$check( ! is_array( $fixture ) || array() === $fixture, 'A recovery fixture already exists; clean it up first.' );
	$term = wp_insert_term( 'SCT recovery fixture', 'ttshowcase_groups', array( 'slug' => 'sct-recovery-fixture' ) );
	$check( ! is_wp_error( $term ), 'Could not create the legacy category.' );
	$quote = "Literal \\ path and client's exact words — preserved.\n\nSecond paragraph.";
	$legacy_id = wp_insert_post( wp_slash( array(
		'post_type'     => 'ttshowcase',
		'post_status'   => 'publish',
		'post_title'    => 'SCT recovery fixture',
		'post_date'     => '2018-04-03 10:11:12',
		'post_date_gmt' => '2018-04-03 14:11:12',
		'edit_date'     => true,
	) ), true );
	$check( ! is_wp_error( $legacy_id ) && (int) $legacy_id > 0, 'Could not create the legacy review.' );
	update_post_meta( $legacy_id, '_aditional_info_short_testimonial', wp_slash( $quote ) );
	update_post_meta( $legacy_id, '_aditional_info_email', 'private@example.test' );
	wp_set_object_terms( $legacy_id, array( (int) $term['term_id'] ), 'ttshowcase_groups' );
	update_option( 'shootcal_testimonials_import_recovery_fixture', array( 'legacy_id' => (int) $legacy_id, 'term_id' => (int) $term['term_id'] ), false );
	echo "PASS setup\n";
	return;
}

$check( is_array( $fixture ) && isset( $fixture['legacy_id'], $fixture['term_id'] ), 'Run setup first.' );
$legacy_id = (int) $fixture['legacy_id'];
$source = get_post( $legacy_id );
$check( $source instanceof WP_Post, 'Legacy fixture is missing.' );

if ( 'fail' === $mode ) {
	// Emulate a relationship write that did not persist. Core can report term IDs
	// even if its per-relationship insert fails, so the importer must read back.
	add_action( 'added_term_relationship', static function ( int $object_id, int $tt_id, string $taxonomy ): void {
		global $wpdb;
		if ( 'shootcal_testimonials_category' === $taxonomy && 'shootcal_testimonial' === get_post_type( $object_id ) ) {
			$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $object_id, 'term_taxonomy_id' => $tt_id ), array( '%d', '%d' ) );
		}
	}, 10, 3 );
	$args = array( 'apply' );
	require $importer;
	throw new RuntimeException( 'Importer unexpectedly accepted a missing category relationship.' );
}

if ( 'resume' === $mode || 'preserved' === $mode ) {
	$args = array( 'apply' );
	ob_start();
	require $importer;
	ob_end_clean();
}

$made = $targets( $legacy_id );
$check( 1 === count( $made ), 'Expected exactly one imported review.' );
$target = $made[0];
$check( '' === get_post_meta( $target->ID, '_shootcal_testimonials_submitter_email', true ), 'Private legacy email was copied.' );

if ( 'resume' === $mode ) {
	$check( 'complete' === get_post_meta( $target->ID, 'shootcal_testimonials_import_state', true ), 'Recovered review did not complete.' );
	echo "PASS interrupted import resumed the existing review\n";
} elseif ( 'failed' === $mode ) {
	$check( 'draft' === $target->post_status, 'Interrupted review became public.' );
	$check( 'pending' === get_post_meta( $target->ID, 'shootcal_testimonials_import_state', true ), 'Interrupted review was marked complete.' );
	$check( array() === wp_get_object_terms( $target->ID, 'shootcal_testimonials_category', array( 'fields' => 'ids' ) ), 'Category did not fail as intended.' );
	echo "PASS interrupted review is one recoverable draft with no category\n";
} elseif ( 'complete' === $mode ) {
	$source_terms = wp_get_object_terms( $legacy_id, 'ttshowcase_groups', array( 'fields' => 'slugs' ) );
	$target_terms = wp_get_object_terms( $target->ID, 'shootcal_testimonials_category', array( 'fields' => 'slugs' ) );
	$check( 'complete' === get_post_meta( $target->ID, 'shootcal_testimonials_import_state', true ) &&
		'publish' === $target->post_status && $source->post_date === $target->post_date &&
		$source->post_date_gmt === $target->post_date_gmt && $source_terms === $target_terms &&
		get_post_meta( $legacy_id, '_aditional_info_short_testimonial', true ) === $target->post_content,
		'Resumed review lost its status, date, category or exact wording.' );
	echo "PASS resumed review is complete with one identity and preserved content\n";
} elseif ( 'owner-edit' === $mode ) {
	wp_update_post( wp_slash( array( 'ID' => $target->ID, 'post_content' => 'Owner-edited review text.' ) ) );
	echo "PASS owner edit staged\n";
} elseif ( 'preserved' === $mode ) {
	$check( 'Owner-edited review text.' === $target->post_content, 'Rerun overwrote an authored edit.' );
	echo "PASS completed import rerun preserved the owner edit\n";
} elseif ( 'cleanup' === $mode ) {
	wp_delete_post( $target->ID, true );
	wp_delete_post( $legacy_id, true );
	$legacy_term = get_term( (int) $fixture['term_id'], 'ttshowcase_groups' );
	if ( $legacy_term instanceof WP_Term ) { wp_delete_term( $legacy_term->term_id, 'ttshowcase_groups' ); }
	$new_term = get_term_by( 'slug', 'sct-recovery-fixture', 'shootcal_testimonials_category' );
	if ( $new_term instanceof WP_Term ) { wp_delete_term( $new_term->term_id, 'shootcal_testimonials_category' ); }
	delete_option( 'shootcal_testimonials_import_recovery_fixture' );
	echo "PASS cleanup\n";
} else {
	throw new RuntimeException( 'Unknown phase: ' . $mode );
}
