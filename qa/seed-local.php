<?php
/**
 * Local seed data for visual QA. Not shipped: qa/ is in .distignore.
 *
 * Run with:
 *   wp eval-file qa/seed-local.php
 *
 * Creates placeholder photos, the session-type categories the live site uses, a set of
 * sample testimonials covering the interesting variations, and a page carrying the
 * shortcode. Names and quotes are invented. Nothing here touches a real client review.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress.\n" );
	exit( 1 );
}

$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
	throw new RuntimeException( 'Synthetic seed is restricted to a localhost WP-CLI environment.' );
}
$report = array();

/**
 * Generate a gradient JPEG so photo cards can be checked without real client images.
 *
 * Palettes are explicit rather than derived from arithmetic, because hue offsets can push a
 * channel past 255 and imagecolorallocate() throws a ValueError in PHP 8.
 *
 * @param string               $path Destination file.
 * @param array{0:int[],1:int[]} $palette Top and bottom RGB triplets.
 */
function sct_make_photo( string $path, array $palette ): bool {
	$w   = 900;
	$h   = 675;
	$img = imagecreatetruecolor( $w, $h );

	if ( ! $img ) {
		return false;
	}

	$clamp = static fn( float $v ): int => (int) max( 0, min( 255, round( $v ) ) );

	$top = imagecolorallocate( $img, $clamp( $palette[0][0] ), $clamp( $palette[0][1] ), $clamp( $palette[0][2] ) );
	$bot = imagecolorallocate( $img, $clamp( $palette[1][0] ), $clamp( $palette[1][1] ), $clamp( $palette[1][2] ) );

	if ( false === $top || false === $bot ) {
		imagedestroy( $img );
		return false;
	}

	$tr = ( $top >> 16 ) & 0xFF;
	$tg = ( $top >> 8 ) & 0xFF;
	$tb = $top & 0xFF;
	$br = ( $bot >> 16 ) & 0xFF;
	$bg = ( $bot >> 8 ) & 0xFF;
	$bb = $bot & 0xFF;

	for ( $y = 0; $y < $h; $y++ ) {
		$t   = $y / $h;
		$col = imagecolorallocate(
			$img,
			$clamp( ( 1 - $t ) * $tr + $t * $br ),
			$clamp( ( 1 - $t ) * $tg + $t * $bg ),
			$clamp( ( 1 - $t ) * $tb + $t * $bb )
		);
		imagefilledrectangle( $img, 0, $y, $w, $y, (int) $col );
	}

	// A soft light blob and a horizon so cropping and object-fit are visible.
	$glow = imagecolorallocatealpha( $img, 255, 248, 235, 88 );
	imagefilledellipse( $img, (int) ( $w * 0.72 ), (int) ( $h * 0.26 ), 300, 300, (int) $glow );
	$land = imagecolorallocatealpha( $img, 70, 62, 55, 55 );
	imagefilledrectangle( $img, 0, (int) ( $h * 0.78 ), $w, $h, (int) $land );

	$ok = imagejpeg( $img, $path, 82 );
	imagedestroy( $img );

	return (bool) $ok;
}

// Clear any previous seed so reruns stay predictable.
$previous = get_posts(
	array(
		'post_type'      => 'sct_testimonial',
		'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'trash' ),
		'meta_key'       => '_sct_qa_seed',
		'meta_value'     => '1',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $previous as $old ) {
	wp_delete_post( (int) $old, true );
}

$report['cleared_previous'] = count( $previous );

// Categories mirroring the live site's session types.
$categories = array(
	'family-pictures'     => 'Family Pictures',
	'wedding-photography' => 'Wedding Photography',
	'engagement-pictures' => 'Engagement Pictures',
	'senior-portraits'    => 'Senior Portraits',
);

foreach ( $categories as $slug => $label ) {
	if ( ! term_exists( $slug, 'sct_category' ) ) {
		wp_insert_term( $label, 'sct_category', array( 'slug' => $slug ) );
	}
}

$report['categories'] = count( $categories );

// Placeholder photos.
$upload_dir = wp_upload_dir();
$seed_dir   = trailingslashit( $upload_dir['basedir'] ) . 'sct-seed';
wp_mkdir_p( $seed_dir );

$photo_ids = array();

$palettes = array(
	array( array( 233, 216, 195 ), array( 154, 128, 100 ) ),
	array( array( 215, 223, 234 ), array( 108, 126, 152 ) ),
	array( array( 207, 227, 224 ), array( 100, 138, 130 ) ),
	array( array( 230, 211, 221 ), array( 142, 112, 128 ) ),
	array( array( 228, 226, 205 ), array( 130, 126, 98 ) ),
);

foreach ( $palettes as $index => $palette ) {
	$file = $seed_dir . '/sample-' . ( $index + 1 ) . '.jpg';

	if ( ! sct_make_photo( $file, $palette ) ) {
		continue;
	}

	$attachment_id = media_handle_sideload(
		array(
			'name'     => 'sample-' . ( $index + 1 ) . '.jpg',
			'tmp_name' => $file,
		),
		0,
		'Sample session photo ' . ( $index + 1 )
	);

	if ( ! is_wp_error( $attachment_id ) ) {
		$photo_ids[] = (int) $attachment_id;
	}
}

$report['photos'] = count( $photo_ids );

/**
 * Sample set. Deliberately covers the cases that stress the layout: a very long review
 * beside a one-liner, a four-star item, items with no photo, every source platform, and
 * one carrying best-of provenance.
 */
$samples = array(
	array(
		'name'   => 'Sample Family Review',
		'quote'  => "Ryan has a gift for making people forget the camera is there. We had our two year old and a very opinionated seven year old with us, and somehow we came away with photographs that look like us rather than a stiff family portrait.\n\nHe answered every random question my son asked until he finally got a real smile out of him, and he never once made the session feel like a performance. First time we have all been photographed together and I could not be happier with how every single image turned out.",
		'rating' => 5,
		'date'   => '2026-10-01 12:00:00',
		'source' => 'google',
		'url'    => 'https://www.google.com/maps/contrib/sample/reviews',
		'cat'    => 'family-pictures',
		'photo'  => 0,
		'reason' => 'Chosen over a shorter Google-only draft: this version is the fuller account of the same session.',
	),
	array(
		'name'   => 'Sample Wedding Review',
		'quote'  => 'Our ceremony moved indoors at the last minute because of weather and Ryan did not miss a beat. He scouted the new room in about four minutes, worked out where the light was coming from, and the photographs look like we planned it that way the whole time.',
		'rating' => 5,
		'date'   => '2026-09-25 12:00:00',
		'source' => 'zola',
		'url'    => 'https://www.zola.com/wedding-vendors/wedding-photographers/sample',
		'cat'    => 'wedding-photography',
		'photo'  => 1,
		'reason' => 'Same client reviewed on Google the same day with two lines. The Zola version is longer and more specific, so it was chosen.',
	),
	array(
		'name'   => 'Short Quote Example',
		'quote'  => 'He was amazing and the pictures turned out so good!',
		'rating' => 5,
		'date'   => '2026-09-12 15:08:54',
		'source' => 'google',
		'url'    => '',
		'cat'    => 'engagement-pictures',
		'photo'  => 2,
		'reason' => '',
	),
	array(
		'name'   => 'No Photo Example',
		'quote'  => 'Booking was simple, the session started on time, and the gallery arrived sooner than promised. What I remember most is that nobody in my family felt awkward, which is not a small thing when half of us hate being photographed.',
		'rating' => 5,
		'date'   => '2026-09-10 15:29:24',
		'source' => 'weddingwire',
		'url'    => '',
		'cat'    => 'wedding-photography',
		'photo'  => -1,
		'reason' => '',
	),
	array(
		'name'   => 'Four Star Example',
		'quote'  => 'Really happy with the photographs themselves. Scheduling took a couple of attempts to pin down, but the session itself was relaxed and the results are lovely.',
		'rating' => 4,
		'date'   => '2026-08-21 17:55:46',
		'source' => 'theknot',
		'url'    => '',
		'cat'    => 'family-pictures',
		'photo'  => 3,
		'reason' => '',
	),
	array(
		'name'   => 'Direct Submission Example',
		'quote'  => 'Sent to us directly rather than posted publicly, so there is no third-party source line on this card. Useful for checking that a direct testimonial renders cleanly with no attribution markup at all.',
		'rating' => 5,
		'date'   => '2026-08-08 19:00:43',
		'source' => 'direct',
		'url'    => '',
		'cat'    => 'senior-portraits',
		'photo'  => -1,
		'reason' => '',
	),
	array(
		'name'   => 'Very Long Review Example',
		'quote'  => "I want to be specific about why this was worth every penny, because I spent a long time choosing.\n\nWe had a tight window, a large family travelling in from two time zones, and a venue that only allowed photographs in one part of the property. Ryan asked about all of that before the day, not during it, and arrived with a plan that already accounted for the light and the traffic between rooms.\n\nHe was calm with a grandmother who was not sure about being in photographs, patient with a toddler who had strong feelings about the order of events, and completely unobtrusive during the part of the evening that mattered most to us. The gallery came back in under two weeks with a selection that somehow included moments none of us noticed happening.\n\nMy only regret is that we did not book a second session for the morning after.",
		'rating' => 5,
		'date'   => '2026-08-06 12:00:00',
		'source' => 'google',
		'url'    => 'https://www.google.com/maps/contrib/sample-long/reviews',
		'cat'    => 'wedding-photography',
		'photo'  => 4,
		'reason' => '',
	),
	array(
		'name'   => 'Hidden Card One',
		'quote'  => 'This card starts collapsed behind View more, so the button has something to reveal.',
		'rating' => 5,
		'date'   => '2026-07-31 15:28:17',
		'source' => 'google',
		'url'    => '',
		'cat'    => 'family-pictures',
		'photo'  => -1,
		'reason' => '',
	),
	array(
		'name'   => 'Hidden Card Two',
		'quote'  => 'Revealing one row at a time keeps the page calm instead of dumping everything at once.',
		'rating' => 5,
		'date'   => '2026-06-24 14:14:21',
		'source' => 'facebook',
		'url'    => '',
		'cat'    => 'engagement-pictures',
		'photo'  => 0,
		'reason' => '',
	),
	array(
		'name'   => 'Hidden Card Three',
		'quote'  => 'An unrated item renders no stars at all rather than an empty row.',
		'rating' => 0,
		'date'   => '2026-06-24 14:14:14',
		'source' => 'direct',
		'url'    => '',
		'cat'    => 'senior-portraits',
		'photo'  => -1,
		'reason' => '',
	),
	array(
		'name'   => 'Hidden Card Four',
		'quote'  => 'Card heights stay level across the row even though these quotes differ a lot in length.',
		'rating' => 5,
		'date'   => '2026-04-23 11:23:48',
		'source' => 'google',
		'url'    => '',
		'cat'    => 'wedding-photography',
		'photo'  => 1,
		'reason' => '',
	),
	array(
		'name'   => 'Hidden Card Five',
		'quote'  => 'The last card, so View more removes itself once everything is shown.',
		'rating' => 5,
		'date'   => '2026-04-17 13:10:43',
		'source' => 'yelp',
		'url'    => '',
		'cat'    => 'family-pictures',
		'photo'  => -1,
		'reason' => '',
	),
);

$created = array();

foreach ( $samples as $sample ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'sct_testimonial',
			'post_title'   => $sample['name'],
			'post_content' => $sample['quote'],
			'post_status'  => 'publish',
			'post_date'    => $sample['date'],
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		continue;
	}

	update_post_meta( $post_id, '_sct_qa_seed', '1' );
	// WordPress replaces post_date when publishing a backdated draft, so restate it.
	wp_update_post(
		array(
			'ID'            => $post_id,
			'post_date'     => $sample['date'],
			'post_date_gmt' => get_gmt_from_date( $sample['date'] ),
			'edit_date'     => true,
		)
	);

	update_post_meta( $post_id, 'sct_rating', $sample['rating'] );
	update_post_meta( $post_id, 'sct_source', $sample['source'] );
	update_post_meta( $post_id, 'sct_source_url', $sample['url'] );
	update_post_meta( $post_id, 'sct_consent_recorded', '2026-10-01' );

	if ( '' !== $sample['reason'] ) {
		update_post_meta( $post_id, 'sct_selection_reason', $sample['reason'] );
		update_post_meta(
			$post_id,
			'sct_alternates',
			wp_json_encode(
				array(
					array(
						'platform' => 'google',
						'url'      => '',
						'date'     => $sample['date'],
						'note'     => 'Shorter wording for the same session; not chosen.',
					),
				)
			)
		);
	}

	if ( $sample['photo'] >= 0 && isset( $photo_ids[ $sample['photo'] ] ) ) {
		set_post_thumbnail( $post_id, $photo_ids[ $sample['photo'] ] );
	}

	wp_set_object_terms( $post_id, $sample['cat'], 'sct_category' );

	$created[] = $post_id;
}

$report['testimonials'] = count( $created );
$report['ids']          = $created;

// The QA page.
$existing_page = get_page_by_path( 'testimonial-qa' );

if ( $existing_page instanceof WP_Post && '1' !== get_post_meta( $existing_page->ID, '_sct_qa_seed', true ) ) {
	throw new RuntimeException( 'Refusing to replace an authored testimonial-qa page.' );
}
if ( $existing_page instanceof WP_Post ) {
	wp_delete_post( $existing_page->ID, true );
}

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Testimonial QA',
		'post_name'    => 'testimonial-qa',
		'post_status'  => 'publish',
		'post_content' => '[shootcal_testimonials heading="What clients remember" eyebrow="Kind words" intro="Sample data for local visual QA. Names and quotes are invented." count="6" total="12" columns="3" more="show"]',
	),
	true
);

if ( ! is_wp_error( $page_id ) ) { update_post_meta( $page_id, '_sct_qa_seed', '1' ); }

$report['page_id']  = is_wp_error( $page_id ) ? $page_id->get_error_message() : $page_id;
$report['page_url'] = is_wp_error( $page_id ) ? '' : get_permalink( $page_id );

fwrite( STDOUT, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
