<?php
/**
 * Plugin options.
 *
 * Deliberately small. The settings surface is a handful of defaults plus one
 * advanced switch for review structured data, because almost everything else is
 * either automatic or belongs on the shortcode.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single plugin option.
 */
class Config {

	/** Accept a real ISO calendar date, not merely a nonempty value. */
	public static function valid_as_of_date( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date instanceof \DateTimeImmutable
			&& $date->format( 'Y-m-d' ) === $value
			&& '0000' !== substr( $value, 0, 4 )
			&& $value <= current_time( 'Y-m-d' );
	}

	/**
	 * Option defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Review structured data. Off by default: Google treats reviews of your own
			// business hosted on your own site as self-serving, so they are ineligible for
			// star rich results, and marking up reviews aggregated from another website can
			// draw a structured-data manual action. Intended for directory-style sites that
			// review other businesses using ratings collected directly from users.
			'schema_enabled'    => false,
			'schema_entity'     => 'LocalBusiness',
			'business_name'     => '',
			// Site-entered date for the optional aggregate; malformed dates must never
			// enable rating markup.
			'rating_as_of'      => '',

			'default_columns'   => 3,
			'default_count'     => 21,
			'default_more'      => 'hide',

			'show_photo'        => true,
			// Off by default: a row of stars on a page of your own reviews reads as
			// spammy (Ryan, 2026-10-02). Opt in per site under Testimonial Settings.
			'show_rating'       => false,
			'show_date'         => true,
			'show_category'     => false,
			'show_source'       => true,
			'show_form_credit'  => false,

			'public_single_urls' => false,
		);
	}

	/**
	 * Persist defaults for any key that is not set yet.
	 *
	 * Never overwrites an existing value, so a later option addition does not
	 * clobber what an editor already chose.
	 */
	public static function ensure_defaults(): void {
		$current  = get_option( OPTION_KEY, array() );
		$current  = is_array( $current ) ? $current : array();
		$defaults = self::defaults();
		$merged   = $current;

		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $merged ) ) {
				$merged[ $key ] = $value;
			}
		}

		if ( $merged !== $current ) {
			update_option( OPTION_KEY, $merged, false );
		}
	}

	/**
	 * Read one option, falling back to its default.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $fallback Used when the key has no default.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$defaults = self::defaults();
		$current  = get_option( OPTION_KEY, array() );
		$current  = is_array( $current ) ? $current : array();

		if ( array_key_exists( $key, $current ) ) {
			return $current[ $key ];
		}

		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : $fallback;
	}

	/**
	 * Write one option.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value to store.
	 */
	public static function set( string $key, $value ): void {
		$current = get_option( OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();

		$current[ $key ] = $value;

		update_option( OPTION_KEY, $current, false );
	}

	/**
	 * Clamp columns to the 1 to 3 range shared with the ShootCal Websites block.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function normalize_columns( $value ): int {
		$columns = (int) $value;

		return max( 1, min( 3, $columns ) );
	}
}
