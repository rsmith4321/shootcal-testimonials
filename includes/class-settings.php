<?php
/**
 * Admin settings.
 *
 * One small page. The defaults drive shortcode output; the only judgement call exposed
 * here is review structured data, which is off by default and carries its warning inline
 * rather than in a tooltip, because the consequence of getting it wrong is a manual action
 * rather than a cosmetic problem.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the settings page.
 */
class Settings {

	/**
	 * Capability required to change settings.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'shootcal_testimonials_settings';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
	}

	/**
	 * Add the submenu under Testimonials.
	 */
	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . POST_TYPE,
			__( 'Testimonial Settings', 'shootcal-testimonials' ),
			__( 'Settings', 'shootcal-testimonials' ),
			self::CAPABILITY,
			SLUG . '-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Persist a submitted settings form.
	 */
	public function handle_post(): void {
		if ( ! isset( $_POST['shootcal_testimonials_settings_nonce'] ) ) {
			return;
		}

		if ( ! is_string( $_POST['shootcal_testimonials_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['shootcal_testimonials_settings_nonce'] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'shootcal-testimonials' ) );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'shootcal-testimonials' ) );
		}
		$current = get_option( OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();
		$next = array_merge( Config::defaults(), $current );
		$scalar = static function ( string $key, string $fallback = '' ): string {
			return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : $fallback;
		};
		$next['business_name'] = sanitize_text_field( $scalar( 'business_name' ) );
		$as_of = trim( $scalar( 'rating_as_of' ) );
		$next['rating_as_of'] = Config::valid_as_of_date( $as_of ) ? $as_of : '';
		$next['default_columns'] = Config::normalize_columns( $scalar( 'default_columns', '3' ) );
		$next['default_count'] = max( 1, min( Shortcode::CEILING, (int) $scalar( 'default_count', '21' ) ) );
		$next['default_more'] = 'show' === $scalar( 'default_more' ) ? 'show' : 'hide';
		foreach ( array( 'schema_enabled', 'show_photo', 'show_rating', 'show_date', 'show_category', 'show_source', 'show_form_credit', 'public_single_urls' ) as $flag ) {
			$next[ $flag ] = '1' === $scalar( $flag );
		}
		update_option( OPTION_KEY, $next, false );
		if ( (bool) ( $current['public_single_urls'] ?? false ) !== $next['public_single_urls'] ) {
			( new Post_Type() )->register_post_type();
			flush_rewrite_rules( false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => POST_TYPE,
					'page'      => SLUG . '-settings',
					'updated'   => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		Config::ensure_defaults();

		$columns = Config::normalize_columns( Config::get( 'default_columns' ) );
		$count   = (int) Config::get( 'default_count' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Testimonial Settings', 'shootcal-testimonials' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'shootcal-testimonials' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'These defaults apply to every testimonial list. Individual lists can override them with shortcode attributes.', 'shootcal-testimonials' ); ?></p>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE, 'shootcal_testimonials_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Layout', 'shootcal-testimonials' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="default_columns"><?php esc_html_e( 'Columns', 'shootcal-testimonials' ); ?></label></th>
						<td>
							<select name="default_columns" id="default_columns">
								<?php foreach ( array( 1, 2, 3 ) as $option ) : ?>
									<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $columns, $option ); ?>>
										<?php echo esc_html( (string) $option ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Cards narrow to two columns on tablets and one on phones regardless of this setting.', 'shootcal-testimonials' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="default_count"><?php esc_html_e( 'Testimonials shown', 'shootcal-testimonials' ); ?></label></th>
						<td>
							<input type="number" class="small-text" name="default_count" id="default_count" min="1" max="<?php echo esc_attr( (string) Shortcode::CEILING ); ?>" value="<?php echo esc_attr( (string) $count ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %d: maximum items per list. */
									esc_html__( 'Maximum %d per list to protect page weight.', 'shootcal-testimonials' ),
									esc_html( (int) Shortcode::CEILING )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'View more', 'shootcal-testimonials' ); ?></th>
						<td>
							<label><input type="radio" name="default_more" value="show" <?php checked( Config::get( 'default_more' ), 'show' ); ?> /> <?php esc_html_e( 'Show a View more button', 'shootcal-testimonials' ); ?></label><br />
							<label><input type="radio" name="default_more" value="hide" <?php checked( Config::get( 'default_more' ), 'hide' ); ?> /> <?php esc_html_e( 'Show the list only', 'shootcal-testimonials' ); ?></label>
								<p class="description"><?php esc_html_e( 'Reveals at least nine already-rendered cards per click, rounding up to complete rows when possible. A visitor click makes no database or provider request.', 'shootcal-testimonials' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Shown on each card', 'shootcal-testimonials' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Elements', 'shootcal-testimonials' ); ?></th>
						<td>
							<?php
							$flags = array(
								'show_photo'    => __( 'Photo', 'shootcal-testimonials' ),
								'show_rating'   => __( 'Star rating', 'shootcal-testimonials' ),
								'show_date'     => __( 'Review date', 'shootcal-testimonials' ),
								'show_source'   => __( 'Source attribution', 'shootcal-testimonials' ),
								'show_category' => __( 'Category', 'shootcal-testimonials' ),
							);

							foreach ( $flags as $key => $label ) :
								?>
								<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( (bool) Config::get( $key ) ); ?> /> <?php echo esc_html( $label ); ?></label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Form credit', 'shootcal-testimonials' ); ?></th>
						<td><label><input type="checkbox" name="show_form_credit" value="1" <?php checked( (bool) Config::get( 'show_form_credit', false ) ); ?> /> <?php esc_html_e( 'Show a small ShootCal Testimonials link below the review form', 'shootcal-testimonials' ); ?></label><p class="description"><?php esc_html_e( 'Optional and off by default. Uncheck this to remove the credit.', 'shootcal-testimonials' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Single URLs', 'shootcal-testimonials' ); ?></th>
						<td>
							<label><input type="checkbox" name="public_single_urls" value="1" <?php checked( (bool) Config::get( 'public_single_urls', false ) ); ?> /> <?php esc_html_e( 'Give each testimonial its own public permalink', 'shootcal-testimonials' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. Testimonials normally render inside lists, which is how the existing site works.', 'shootcal-testimonials' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Review structured data', 'shootcal-testimonials' ); ?></h2>

				<div class="notice notice-warning inline" style="max-width:720px">
					<p><strong><?php esc_html_e( 'Read this before enabling.', 'shootcal-testimonials' ); ?></strong></p>
					<p>
						<?php
						esc_html_e( 'Google treats reviews of your own business hosted on your own site as self-serving, which makes them ineligible for star rich results. Its guidelines also prohibit aggregating reviews or ratings from other websites, and warn that violating them can lead to a manual action against your structured data.', 'shootcal-testimonials' );
						?>
					</p>
					<p>
						<?php
						esc_html_e( 'Leave this off for a business site showing its own reviews. It exists for directory-style sites that review other businesses using ratings collected directly from their own users, which Google does support.', 'shootcal-testimonials' );
						?>
					</p>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Emit markup', 'shootcal-testimonials' ); ?></th>
						<td>
							<label><input type="checkbox" name="schema_enabled" value="1" <?php checked( (bool) Config::get( 'schema_enabled', false ) ); ?> /> <?php esc_html_e( 'Output Review and AggregateRating JSON-LD for testimonials rendered on the page', 'shootcal-testimonials' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="business_name"><?php esc_html_e( 'Business name', 'shootcal-testimonials' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="business_name" id="business_name" value="<?php echo esc_attr( (string) Config::get( 'business_name', '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Defaults to the site title. Only used when structured data is enabled.', 'shootcal-testimonials' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rating_as_of"><?php esc_html_e( 'Rating "as of" date', 'shootcal-testimonials' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" name="rating_as_of" id="rating_as_of" value="<?php echo esc_attr( (string) Config::get( 'rating_as_of', '' ) ); ?>" placeholder="2026-10-01" />
							<p class="description"><?php esc_html_e( 'Enter the date you checked the ratings, in YYYY-MM-DD format. Use today or an earlier date. Optional aggregate data is shown only when this is a valid date.', 'shootcal-testimonials' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
