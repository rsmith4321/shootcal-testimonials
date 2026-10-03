<?php
/**
 * Public submission form.
 *
 * [shootcal_testimonial_form] renders a plain HTML form that creates a pending
 * testimonial end to end. Every submission lands in the Testimonials queue and has to be
 * published by an editor before it can appear anywhere on the site.
 *
 * Deliberate properties:
 *
 * - No JavaScript required. The form posts, the server validates and inserts, and the
 *   response is a redirect to a confirmation. Nothing degrades without script.
 * - No outbound request. A submission writes one post and a handful of meta rows. It
 *   never calls a review provider, which is the failure mode that saturated PHP-FPM on
 *   this host in August 2026.
 * - The submitter's email goes to `_sct_submitter_email` only. That key is never passed to
 *   register_post_meta(), so it cannot be reached through the REST API. See Meta.
 * - Nothing is granted. No capability, no role, no user account. Post status is 'pending'
 *   in code and the author is whatever WordPress resolves for the current request, so
 *   neither can be steered from the client.
 * - Client wording is never rewritten. A quote that exceeds the length cap is rejected
 *   with an error rather than silently truncated, because trimming a review changes it.
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders [shootcal_testimonial_form].
 */
class Form {

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'shootcal_testimonial_form';

	/**
	 * Nonce action and field.
	 */
	public const NONCE_ACTION = 'sct_form_submit';
	public const NONCE_FIELD  = 'sct_form_nonce';

	/**
	 * Field names posted by the form.
	 */
	public const FIELD_NAME     = 'sct_name';
	public const FIELD_QUOTE    = 'sct_quote';
	public const FIELD_RATING   = 'sct_rating';
	public const FIELD_CATEGORY = 'sct_form_category';
	public const FIELD_EMAIL    = 'sct_email';
	public const FIELD_REDIRECT = 'sct_redirect';

	/**
	 * Honeypot field. Rendered off-screen and labelled "leave this empty", so a person
	 * never fills it and a bot that fills every input does.
	 */
	public const HONEYPOT_FIELD = 'sct_website';

	/**
	 * Query parameter carrying the one-time confirmation token.
	 */
	public const NOTICE_QUERY_VAR = 'sct_form';

	/**
	 * Query parameter that renders a dialog-mode form in place.
	 *
	 * The dialog trigger links here, so with script disabled the same click lands on the
	 * form rendered inline instead of a dialog that would never open.
	 */
	public const OPEN_QUERY_VAR = 'sct_form_open';

	/**
	 * Private meta key for the submitter's email.
	 *
	 * Leading underscore and, more importantly, absent from Meta::fields(). It is never
	 * registered, so headless and mobile consumers reading post meta through the REST API
	 * cannot see it. Do not register it.
	 */
	public const EMAIL_META_KEY = '_sct_submitter_email';

	/**
	 * Seconds one address waits between accepted submissions.
	 */
	public const COOLDOWN = 120;

	/**
	 * Seconds a confirmation message stays retrievable before it expires unread.
	 */
	public const NOTICE_TTL = 600;

	/**
	 * Length of the confirmation token in hex characters, and the bytes behind it.
	 */
	public const TOKEN_LENGTH = 32;
	public const TOKEN_BYTES  = 16;

	/**
	 * Input limits.
	 */
	public const NAME_MIN  = 2;
	public const NAME_MAX  = 100;
	public const QUOTE_MIN = 20;
	public const QUOTE_MAX = 4000;
	public const EMAIL_MAX = 100;

	/**
	 * Recorded against every submission, because a form entry has no platform record to
	 * reconcile against and saying so is more useful than leaving the field blank.
	 */
	public const SOURCE_NOTE = 'Submitted through the public form; no third-party platform record to reconcile against.';

	/**
	 * Field order used when building the error summary.
	 *
	 * @var string[]
	 */
	private const FIELD_ORDER = array( '_form', 'name', 'quote', 'rating', 'category', 'email' );

	/**
	 * Validation errors from the current request, keyed by field.
	 *
	 * '_form' holds the ones belonging to the submission as a whole rather than to a
	 * single control.
	 *
	 * @var array<string,string>
	 */
	private array $errors = array();

	/**
	 * Submitted values, echoed back into the form after a validation failure.
	 *
	 * The email is deliberately excluded: it is not needed to correct an error, and
	 * keeping it out means it is never written into page markup.
	 *
	 * @var array<string,string>
	 */
	private array $old = array();

	/**
	 * Whether a form rendered during this request.
	 */
	private bool $used = false;

	/**
	 * Id prefix for the form currently being rendered, so two forms on one page cannot
	 * collide.
	 */
	private string $id_base = 'sct-form-1';

	/**
	 * Forms rendered during this request.
	 */
	private int $instances = 0;

	/**
	 * Whether a dialog-mode form rendered during this request.
	 *
	 * The dialog needs frontend.js to open; a page carrying only a form never asks Assets
	 * for it, so this flag is what enqueue() keys the script off.
	 */
	private bool $dialog_used = false;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_shortcode' ) );
		/* Priority 11 keeps this after Post_Type registers the taxonomy on init at the
		   default 10. Any earlier and get_term_by() runs against an unregistered taxonomy
		   and returns false, which rejects every submission that chose a category. Still
		   inside init, so still before any output and the success path can redirect. */
		add_action( 'init', array( $this, 'handle_submission' ), 11 );
		add_action( 'wp_footer', array( $this, 'enqueue' ), 2 );
	}

	/**
	 * Register the shortcode.
	 */
	public function add_shortcode(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Process a posted form.
	 *
	 * Runs on init, before output, so the success path redirects and the failure path
	 * leaves errors for the shortcode to render in place. When the page the form posted to
	 * does not contain the shortcode the errors have nowhere to render, which is why the
	 * form posts to its own page rather than to a handler endpoint.
	 */
	public function handle_submission(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $this->has_input( self::NONCE_FIELD ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $this->posted_text( self::NONCE_FIELD ) ), self::NONCE_ACTION ) ) {
			$this->errors['_form'] = __( 'Your session expired before the form was submitted. Please fill it in again.', 'shootcal-testimonials' );

			return;
		}

		// A filled honeypot means a bot. Answer with the same confirmation a real
		// submission gets and create nothing, so the bot learns nothing from the response.
		if ( '' !== trim( $this->posted_text( self::HONEYPOT_FIELD ) ) ) {
			$this->redirect_with_notice( self::confirmation() );
		}

		$ip = $this->client_ip();

		if ( '' !== $ip && get_transient( $this->cooldown_key( $ip ) ) ) {
			$this->errors['_form'] = sprintf(
				/* translators: %d: seconds to wait. */
				__( 'Please wait about %d seconds before sending another testimonial.', 'shootcal-testimonials' ),
				self::COOLDOWN
			);

			return;
		}

		$name     = $this->posted_text( self::FIELD_NAME );
		$quote    = $this->posted_quote();
		$email    = $this->posted_email();
		$rating   = $this->posted_rating();
		$category = $this->posted_category();

		$this->old = array(
			'name'     => $name,
			'quote'    => $quote,
			'rating'   => $this->has_input( self::FIELD_RATING ) ? (string) absint( $this->posted_text( self::FIELD_RATING ) ) : '',
			'category' => $category,
		);

		$this->validate( $name, $quote, $email, $rating, $category );

		if ( array() !== $this->errors ) {
			return;
		}

		$post_id = $this->insert( $name, $quote, $rating, $category, $email );

		if ( 0 === $post_id ) {
			$this->errors['_form'] = __( 'Your testimonial could not be saved. Please try again in a moment.', 'shootcal-testimonials' );

			return;
		}

		// Set only after an accepted submission, so a visitor who makes a typo is not
		// locked out of correcting it.
		if ( '' !== $ip ) {
			set_transient( $this->cooldown_key( $ip ), 1, self::COOLDOWN );
		}

		$this->redirect_with_notice( self::confirmation() );
	}

	/**
	 * The confirmation shown after a submission is accepted.
	 *
	 * Identical for a real submission and a honeypot hit, so the response cannot be used
	 * to tell which path a request took.
	 */
	public static function confirmation(): string {
		return __( 'Thank you. Your testimonial has been received and will appear on the site once it has been reviewed.', 'shootcal-testimonials' );
	}

	/**
	 * Validate every input.
	 *
	 * @param string $name     Reviewer name.
	 * @param string $quote    Testimonial text.
	 * @param string $email    Optional email, already sanitized.
	 * @param int    $rating   Optional rating, 0 when unset.
	 * @param string $category Optional category slug.
	 */
	private function validate( string $name, string $quote, string $email, int $rating, string $category ): void {
		$name_length  = $this->length( $name );
		$quote_length = $this->length( $quote );

		if ( $name_length < self::NAME_MIN ) {
			$this->errors['name'] = __( 'Please enter your name.', 'shootcal-testimonials' );
		} elseif ( $name_length > self::NAME_MAX ) {
			$this->errors['name'] = sprintf(
				/* translators: %d: maximum name length. */
				__( 'Please keep your name under %d characters.', 'shootcal-testimonials' ),
				self::NAME_MAX
			);
		}

		if ( $quote_length < self::QUOTE_MIN ) {
			$this->errors['quote'] = sprintf(
				/* translators: %d: minimum quote length. */
				__( 'Please write at least %d characters so there is something to publish.', 'shootcal-testimonials' ),
				self::QUOTE_MIN
			);
		} elseif ( preg_match( '~(?:https?://|www\.)~i', $quote ) ) {
			$this->errors['quote'] = __( 'Please leave web links out of your testimonial. You can email any links to us separately.', 'shootcal-testimonials' );
		} elseif ( $quote_length > self::QUOTE_MAX ) {
			// Rejected rather than trimmed. Cutting a client's words would publish a
			// review they did not write.
			$this->errors['quote'] = sprintf(
				/* translators: %d: maximum quote length. */
				__( 'That is longer than the %d character limit. Please shorten it, or send the full text by email instead.', 'shootcal-testimonials' ),
				self::QUOTE_MAX
			);
		}

		if ( $this->has_input( self::FIELD_RATING ) && 0 === $rating ) {
			$this->errors['rating'] = __( 'Please choose a rating between 1 and 5 stars, or leave the rating empty.', 'shootcal-testimonials' );
		}

		if ( $this->has_input( self::FIELD_CATEGORY ) && '' === $category ) {
			$this->errors['category'] = __( 'The category you selected is no longer available. Please choose another, or leave it empty.', 'shootcal-testimonials' );
		}

		if ( $this->has_input( self::FIELD_EMAIL ) && '' === $email ) {
			$this->errors['email'] = __( 'That email address does not look right. Please correct it, or leave the field empty.', 'shootcal-testimonials' );
		}
	}

	/**
	 * Create the pending testimonial.
	 *
	 * @param string $name     Reviewer name, becomes post_title.
	 * @param string $quote    Testimonial text, becomes post_content.
	 * @param int    $rating   0 to 5, 0 stores nothing.
	 * @param string $category Term slug, empty stores nothing.
	 * @param string $email    Optional, stored only under the unregistered private key.
	 * @return int Post ID, or 0 on failure.
	 */
	private function insert( string $name, string $quote, int $rating, string $category, string $email ): int {
		$post_id = wp_insert_post(
			wp_slash( array(
				'post_type'      => POST_TYPE,
				// Server-side values. Neither status nor author is read from the request.
				'post_status'    => 'pending',
				'post_author'    => get_current_user_id(),
				'post_title'     => $name,
				'post_content'   => $quote,
				'post_date'      => current_time( 'mysql' ),
				'post_date_gmt'  => current_time( 'mysql', true ),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			) ),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			return 0;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, META_PREFIX . 'source', 'direct' );
		update_post_meta( $post_id, META_PREFIX . 'source_lookup', 'not-found' );
		update_post_meta( $post_id, META_PREFIX . 'source_note', self::SOURCE_NOTE );

		if ( $rating > 0 ) {
			update_post_meta( $post_id, META_PREFIX . 'rating', $rating );
		}

		if ( '' !== $email ) {
			// Unregistered on purpose. See EMAIL_META_KEY.
			update_post_meta( $post_id, self::EMAIL_META_KEY, $email );
		}

		if ( '' !== $category ) {
			$term = get_term_by( 'slug', $category, TAXONOMY );

			if ( $term instanceof \WP_Term ) {
				// Term ID, never the slug. Passing a name to wp_set_object_terms() would
				// create the term, which would let a submission invent categories.
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), TAXONOMY );
			}
		}

		/**
		 * Fires after a public submission created a pending testimonial.
		 *
		 * Lets a site hook up its own notification without this plugin sending mail or
		 * making a request on the submission path.
		 *
		 * @param int    $post_id  Pending testimonial ID.
		 * @param string $category Term slug, empty when none was chosen.
		 */
		do_action( 'sct_form_submitted', $post_id, $category );

		return $post_id;
	}

	/**
	 * Render the shortcode.
	 *
	 * Attributes: category preselects one sct_category slug, redirect sends the submitter
	 * somewhere other than the form page after a successful post, mode chooses the form in
	 * place (page) or a button that opens it in a dialog (dialog), and button_label names
	 * that button.
	 *
	 * The confirmation is read by this shortcode, so a redirect target needs the form on
	 * it for the message to be visible.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'category'     => '',
				'redirect'     => '',
				'mode'         => 'page',
				'button_label' => '',
			),
			$atts,
			self::SHORTCODE
		);

		( new Assets() )->register_assets();
		$this->used    = true;
		$this->id_base = wp_unique_id( 'sct-form-' );

		// One slug only. If a comma-separated list is passed, the first entry preselects
		// and the rest are ignored, because the control is a single select.
		$preselect_parts = explode( ',', (string) $atts['category'] );
		$preselect       = sanitize_title( trim( (string) $preselect_parts[0] ) );

		// Validated here and again on the way back in. Kept empty when the attribute is
		// empty so redirect_target() falls back to the referer rather than to whatever
		// wp_validate_redirect() makes of a blank location.
		$redirect_attr = trim( (string) $atts['redirect'] );
		$redirect      = '' !== $redirect_attr ? (string) wp_validate_redirect( $redirect_attr, '' ) : '';

		// Read before deciding how to render: the notice consumes its one-time token, so
		// it can only be fetched once, and whether it said anything is what tells us a
		// confirmation is waiting for this page load.
		$notice = $this->render_notice();
		$errors = $this->render_error_summary();

		$form  = '<div class="sct-form-wrap">';
		$form .= $notice;
		$form .= $errors;
		$form .= sprintf(
			'<form class="sct-form" method="post" aria-label="%s">',
			esc_attr__( 'Submit a testimonial', 'shootcal-testimonials' )
		);
		$form .= wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$form .= sprintf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( self::FIELD_REDIRECT ),
			esc_attr( $redirect )
		);
		$form .= $this->render_honeypot();
		$form .= $this->render_name();
		$form .= $this->render_quote();
		$form .= $this->render_rating();
		$form .= $this->render_category( $preselect );
		$form .= $this->render_email();
		$form .= $this->render_submit();
		$form .= '</form>';
		$form .= '</div>';

		// A dialog that hides what the visitor needs to read is worse than no dialog, so
		// validation failures, a waiting confirmation and the no-script open parameter all
		// render the form in place.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only open flag; nothing acts on it beyond choosing markup.
		$inline = 'dialog' !== $atts['mode']
			|| array() !== $this->errors
			|| '' !== $notice
			|| isset( $_GET[ self::OPEN_QUERY_VAR ] );

		if ( $inline ) {
			return $form;
		}

		$this->dialog_used = true;

		return $this->render_trigger( (string) $atts['button_label'] ) . $this->render_dialog( $form );
	}

	/**
	 * The button that opens a dialog-mode form.
	 *
	 * A real link to the no-script open parameter with the dialog id as fragment, so the
	 * form stays reachable with script disabled and the dialog is an enhancement over a
	 * path that already works.
	 *
	 * @param string $label Label from the shortcode attribute, empty for the default.
	 */
	private function render_trigger( string $label ): string {
		$text = trim( $label );
		$text = '' !== $text ? $text : __( 'Submit a testimonial', 'shootcal-testimonials' );

		return sprintf(
			'<p class="sct-form-trigger-wrap"><a class="sct-form-trigger" href="%1$s#%2$s" data-sct-form-trigger="%2$s">%3$s</a></p>',
			esc_url( add_query_arg( self::OPEN_QUERY_VAR, '1' ) ),
			esc_attr( $this->id_base . '-dialog' ),
			esc_html( $text )
		);
	}

	/**
	 * Wrap a rendered form in the shared dialog chrome.
	 *
	 * Reuses the testimonial dialog classes so one modal design covers both, with the
	 * close button and backdrop handling frontend.js already wires for [data-sct-close].
	 *
	 * @param string $form The form wrap markup.
	 */
	private function render_dialog( string $form ): string {
		return sprintf(
			'<dialog id="%1$s" class="sct-dialog sct-dialog--form" aria-labelledby="%2$s"><div class="sct-dialog__inner"><button type="button" class="sct-dialog__close" data-sct-close aria-label="%3$s">&#215;</button><div class="sct-dialog__content"><h2 class="sct-dialog__heading" id="%2$s">%4$s</h2>%5$s</div></div></dialog>',
			esc_attr( $this->id_base . '-dialog' ),
			esc_attr( $this->id_base . '-dialog-title' ),
			esc_attr__( 'Close', 'shootcal-testimonials' ),
			esc_html__( 'Submit a testimonial', 'shootcal-testimonials' ),
			$form
		);
	}

	/**
	 * The honeypot control.
	 *
	 * Off-screen rather than display:none, because some bots skip fields that were never
	 * laid out. aria-hidden and a negative tabindex keep it out of the accessibility tree
	 * and the tab order, so no keyboard user lands in it.
	 */
	private function render_honeypot(): string {
		$id = $this->id_base . '-website';

		return sprintf(
			'<p class="sct-form__hp" aria-hidden="true"><label for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="" tabindex="-1" autocomplete="off" /></p>',
			esc_attr( $id ),
			esc_html__( 'Leave this field empty', 'shootcal-testimonials' ),
			esc_attr( self::HONEYPOT_FIELD )
		);
	}

	/**
	 * Reviewer name.
	 */
	private function render_name(): string {
		return $this->field(
			'name',
			__( 'Your name', 'shootcal-testimonials' ),
			sprintf(
				'<input type="text" id="%1$s" name="%2$s" value="%3$s" maxlength="%4$d"%5$s required aria-required="true" />',
				esc_attr( $this->id_base . '-name' ),
				esc_attr( self::FIELD_NAME ),
				esc_attr( (string) ( $this->old['name'] ?? '' ) ),
				self::NAME_MAX,
				$this->control_attrs( 'name', false )
			),
			'',
			true
		);
	}

	/**
	 * Testimonial text.
	 */
	private function render_quote(): string {
		return $this->field(
			'quote',
			__( 'Your testimonial', 'shootcal-testimonials' ),
			sprintf(
				'<textarea id="%1$s" name="%2$s" rows="6" maxlength="%3$d"%4$s required aria-required="true">%5$s</textarea>',
				esc_attr( $this->id_base . '-quote' ),
				esc_attr( self::FIELD_QUOTE ),
				self::QUOTE_MAX,
				$this->control_attrs( 'quote', true ),
				esc_textarea( (string) ( $this->old['quote'] ?? '' ) )
			),
			__( 'Your wording is published as you write it, and line breaks are kept.', 'shootcal-testimonials' ),
			true
		);
	}

	/**
	 * Optional star rating.
	 */
	private function render_rating(): string {
		$selected = (string) ( $this->old['rating'] ?? '' );
		$options  = sprintf(
			'<option value="">%s</option>',
			esc_html__( 'No rating', 'shootcal-testimonials' )
		);

		foreach ( array( 5, 4, 3, 2, 1 ) as $value ) {
			$options .= sprintf(
				'<option value="%1$d"%2$s>%3$s</option>',
				$value,
				selected( $selected, (string) $value, false ),
				esc_html( sprintf( /* translators: %d: star rating. */ _n( '%d star', '%d stars', $value, 'shootcal-testimonials' ), $value ) )
			);
		}

		return $this->field(
			'rating',
			__( 'Rating', 'shootcal-testimonials' ),
			sprintf(
				'<select id="%1$s" name="%2$s"%3$s>%4$s</select>',
				esc_attr( $this->id_base . '-rating' ),
				esc_attr( self::FIELD_RATING ),
				$this->control_attrs( 'rating', true ),
				$options
			),
			__( 'Optional. Leave empty if you would rather not give a score.', 'shootcal-testimonials' ),
			false
		);
	}

	/**
	 * Optional category, offered only when the taxonomy has terms.
	 *
	 * @param string $preselect Slug from the shortcode attribute.
	 */
	private function render_category( string $preselect ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}

		$selected = (string) ( $this->old['category'] ?? $preselect );
		$options  = sprintf(
			'<option value="">%s</option>',
			esc_html__( 'No category', 'shootcal-testimonials' )
		);

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $term->slug ),
				selected( $selected, $term->slug, false ),
				esc_html( $term->name )
			);
		}

		return $this->field(
			'category',
			__( 'What was this for?', 'shootcal-testimonials' ),
			sprintf(
				'<select id="%1$s" name="%2$s"%3$s>%4$s</select>',
				esc_attr( $this->id_base . '-category' ),
				esc_attr( self::FIELD_CATEGORY ),
				$this->control_attrs( 'category', true ),
				$options
			),
			__( 'Optional. Helps us file your testimonial in the right place.', 'shootcal-testimonials' ),
			false
		);
	}

	/**
	 * Optional email.
	 *
	 * The value is never echoed back, not even after a validation failure, so the address
	 * is not written into page markup.
	 */
	private function render_email(): string {
		return $this->field(
			'email',
			__( 'Email', 'shootcal-testimonials' ),
			sprintf(
				'<input type="email" id="%1$s" name="%2$s" value="" maxlength="%3$d" autocomplete="email"%4$s />',
				esc_attr( $this->id_base . '-email' ),
				esc_attr( self::FIELD_EMAIL ),
				self::EMAIL_MAX,
				$this->control_attrs( 'email', true )
			),
			__( 'Optional and never published. Only site editors can see your email, and it is used only if we need to check a detail with you.', 'shootcal-testimonials' ),
			false
		);
	}

	/**
	 * Submit control and the expectation it sets.
	 */
	private function render_submit(): string {
		return sprintf(
			'<p class="sct-form__actions"><button type="submit" class="sct-form__submit">%1$s</button><span class="sct-form__note">%2$s</span></p>',
			esc_html__( 'Send testimonial', 'shootcal-testimonials' ),
			esc_html__( 'Submissions are reviewed before they appear on the site.', 'shootcal-testimonials' )
		);
	}

	/**
	 * aria-describedby and aria-invalid for one control.
	 *
	 * @param string $field    Error key, also the id fragment.
	 * @param bool   $has_help Whether the control renders a help span.
	 */
	private function control_attrs( string $field, bool $has_help ): string {
		$ids = array();

		if ( $has_help ) {
			$ids[] = $this->id_base . '-' . $field . '-help';
		}

		$invalid = isset( $this->errors[ $field ] );

		if ( $invalid ) {
			$ids[] = $this->id_base . '-' . $field . '-error';
		}

		$out = '';

		if ( array() !== $ids ) {
			$out .= ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"';
		}

		if ( $invalid ) {
			$out .= ' aria-invalid="true"';
		}

		return $out;
	}

	/**
	 * Wrap one labelled control with its help text and inline error.
	 *
	 * @param string $field    Error key, also used to build element ids.
	 * @param string $label    Visible label.
	 * @param string $control  Pre-escaped control markup.
	 * @param string $help     Optional help text.
	 * @param bool   $required Whether the control is required.
	 */
	private function field( string $field, string $label, string $control, string $help, bool $required ): string {
		$id = $this->id_base . '-' . $field;

		$out = sprintf(
			'<p class="sct-form__field sct-form__field--%1$s"><label class="sct-form__label" for="%2$s">%3$s%4$s</label>',
			esc_attr( $field ),
			esc_attr( $id ),
			esc_html( $label ),
			$required ? ' <span class="sct-form__required" aria-hidden="true">*</span>' : ''
		);

		if ( '' !== $help ) {
			$out .= sprintf(
				'<span class="sct-form__help" id="%1$s-help">%2$s</span>',
				esc_attr( $id ),
				esc_html( $help )
			);
		}

		$out .= $control;

		if ( isset( $this->errors[ $field ] ) ) {
			$out .= sprintf(
				'<span class="sct-form__error" id="%1$s-error">%2$s</span>',
				esc_attr( $id ),
				esc_html( $this->errors[ $field ] )
			);
		}

		return $out . '</p>';
	}

	/**
	 * The accessible error summary.
	 *
	 * role="alert" so screen readers announce it, tabindex="-1" so it can take focus, and
	 * every entry that belongs to a control links to it.
	 */
	private function render_error_summary(): string {
		if ( array() === $this->errors ) {
			return '';
		}

		$items = '';

		foreach ( self::FIELD_ORDER as $field ) {
			if ( ! isset( $this->errors[ $field ] ) ) {
				continue;
			}

			if ( '_form' === $field ) {
				$items .= '<li>' . esc_html( $this->errors[ $field ] ) . '</li>';
				continue;
			}

			$items .= sprintf(
				'<li><a href="#%1$s">%2$s</a></li>',
				esc_attr( $this->id_base . '-' . $field ),
				esc_html( $this->errors[ $field ] )
			);
		}

		return sprintf(
			'<div class="sct-form__errors" id="%1$s-errors" role="alert" tabindex="-1"><p class="sct-form__errors-title">%2$s</p><ul>%3$s</ul></div>',
			esc_attr( $this->id_base ),
			esc_html( _n( 'Please correct this before sending:', 'Please correct these before sending:', count( $this->errors ), 'shootcal-testimonials' ) ),
			$items
		);
	}

	/**
	 * The confirmation message left by a previous submission.
	 *
	 * Read once and deleted, so a refresh or a shared link cannot replay it.
	 */
	private function render_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-time token, read only.
		$token = isset( $_GET[ self::NOTICE_QUERY_VAR ] ) && ! is_array( $_GET[ self::NOTICE_QUERY_VAR ] )
			? sanitize_key( (string) wp_unslash( $_GET[ self::NOTICE_QUERY_VAR ] ) )
			: '';

		if ( self::TOKEN_LENGTH !== strlen( $token ) ) {
			return '';
		}

		$key     = $this->notice_key( $token );
		$message = get_transient( $key );

		delete_transient( $key );

		if ( ! is_string( $message ) || '' === trim( $message ) ) {
			return '';
		}

		return sprintf(
			'<p class="sct-form__notice" role="status">%s</p>',
			esc_html( $message )
		);
	}

	/**
	 * Store a message and send the submitter to the next page load.
	 *
	 * Post/redirect/get with a 303, so the follow-up request is a plain GET and a refresh
	 * cannot resubmit the form.
	 *
	 * @param string $message Confirmation text.
	 */
	private function redirect_with_notice( string $message ): void {
		$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );

		set_transient( $this->notice_key( $token ), $message, self::NOTICE_TTL );

		wp_safe_redirect(
			add_query_arg( self::NOTICE_QUERY_VAR, $token, $this->redirect_target() ),
			303
		);
		exit;
	}

	/**
	 * Where to send the submitter.
	 *
	 * The hidden field carries the shortcode's redirect attribute, which is a client value
	 * by the time it comes back, so it is validated again here. Anything off-host falls
	 * back to the same-host raw referer, including self-posted forms. WordPress
	 * deliberately omits same-page URLs from wp_get_referer(), so use the raw
	 * referer and validate it here. A missing referer falls back to the site root.
	 */
	private function redirect_target(): string {
		$posted   = $this->posted_text( self::FIELD_REDIRECT );
		$fallback = (string) wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) );

		return '' !== $posted ? (string) wp_validate_redirect( $posted, $fallback ) : $fallback;
	}

	/**
	 * Transient key holding one confirmation message.
	 *
	 * @param string $token Hex token from the query string.
	 */
	private function notice_key( string $token ): string {
		return 'sct_form_notice_' . $token;
	}

	/**
	 * Transient key for one address's cooldown.
	 *
	 * The address is hashed so raw visitor IPs are never written to the options table.
	 *
	 * @param string $ip Validated remote address.
	 */
	private function cooldown_key( string $ip ): string {
		return 'sct_form_ip_' . wp_hash( $ip );
	}

	/**
	 * The request's remote address.
	 *
	 * REMOTE_ADDR only. Forwarded headers are client-supplied on this host, so trusting
	 * them would make the cooldown trivially bypassable. When no valid address is present,
	 * which in practice means the CLI, the cooldown is skipped rather than applied to a
	 * shared bucket that would block unrelated visitors.
	 */
	private function client_ip(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) && ! is_array( $_SERVER['REMOTE_ADDR'] )
			? (string) $_SERVER['REMOTE_ADDR']
			: '';

		return false !== filter_var( $address, FILTER_VALIDATE_IP ) ? $address : '';
	}

	/**
	 * Whether a field was present in the submission and non-empty.
	 *
	 * Distinguishes "left empty" from "invalid", so an untouched optional field never
	 * produces an error.
	 *
	 * @param string $field Posted field name.
	 */
	private function has_input( string $field ): bool {
		if ( ! isset( $_POST[ $field ] ) || is_array( $_POST[ $field ] ) ) {
			return false;
		}

		return '' !== trim( (string) $_POST[ $field ] );
	}

	/**
	 * Read and sanitize one single-line input.
	 *
	 * @param string $field Posted field name.
	 */
	private function posted_text( string $field ): string {
		if ( ! isset( $_POST[ $field ] ) || is_array( $_POST[ $field ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
	}

	/**
	 * Read and sanitize the quote.
	 *
	 * sanitize_textarea_field() strips tags and keeps line breaks, which is what the
	 * renderer and the stylesheet both expect.
	 */
	private function posted_quote(): string {
		if ( ! isset( $_POST[ self::FIELD_QUOTE ] ) || is_array( $_POST[ self::FIELD_QUOTE ] ) ) {
			return '';
		}

		return sanitize_textarea_field( wp_unslash( (string) $_POST[ self::FIELD_QUOTE ] ) );
	}

	/**
	 * Read and sanitize the optional email.
	 *
	 * @return string A valid address, or empty.
	 */
	private function posted_email(): string {
		if ( ! $this->has_input( self::FIELD_EMAIL ) ) {
			return '';
		}

		$email = sanitize_email( wp_unslash( (string) $_POST[ self::FIELD_EMAIL ] ) );

		if ( '' === $email || false === is_email( $email ) || strlen( $email ) > self::EMAIL_MAX ) {
			return '';
		}

		return $email;
	}

	/**
	 * Read the optional rating.
	 *
	 * Out-of-range values come back as 0 and are reported as an error rather than clamped,
	 * because silently turning a posted 7 into 5 would record a score nobody gave.
	 */
	private function posted_rating(): int {
		if ( ! $this->has_input( self::FIELD_RATING ) ) {
			return 0;
		}

		$value = $this->posted_text( self::FIELD_RATING );

		return preg_match( '/^[1-5]$/D', $value ) ? (int) $value : 0;
	}

	/**
	 * Read the optional category and confirm it is a real term.
	 *
	 * @return string A slug that exists in the taxonomy, or empty.
	 */
	private function posted_category(): string {
		if ( ! $this->has_input( self::FIELD_CATEGORY ) ) {
			return '';
		}

		$slug = sanitize_title( $this->posted_text( self::FIELD_CATEGORY ) );
		$term = '' !== $slug ? get_term_by( 'slug', $slug, TAXONOMY ) : false;

		return $term instanceof \WP_Term ? $term->slug : '';
	}

	/**
	 * Character length, multibyte aware when the extension is loaded.
	 *
	 * @param string $value Sanitized input.
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * Enqueue the shared stylesheet on pages that render only a form.
	 *
	 * Assets registers the handle on wp_enqueue_scripts and enqueues it when a testimonial
	 * list rendered. A form-only page never fires that, so the same handle is enqueued here
	 * instead of registering a second stylesheet that would load the same file twice.
	 */
	public function enqueue(): void {
		if ( ! $this->used ) {
			return;
		}

		if ( ! wp_style_is( SLUG, 'registered' ) && ! wp_style_is( SLUG, 'enqueued' ) && ! wp_style_is( SLUG, 'done' ) ) {
			wp_register_style( SLUG, PLUGIN_URL . 'assets/css/frontend.css', array(), VERSION );
		}

		wp_enqueue_style( SLUG );

		// A dialog-mode form is the only form output that cannot work without the script.
		// Pages that also render a list with View more already have it from Assets, and
		// enqueuing twice is a no-op, so this only covers form-only pages.
		if ( $this->dialog_used ) {
			if ( ! wp_script_is( SLUG, 'registered' ) && ! wp_script_is( SLUG, 'enqueued' ) && ! wp_script_is( SLUG, 'done' ) ) {
				wp_register_script( SLUG, PLUGIN_URL . 'assets/js/frontend.js', array(), VERSION, true );
			}

			wp_enqueue_script( SLUG );
		}
	}
}
