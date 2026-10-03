<?php
/** Category icon controls and static SVG validation. @package ShootCalTestimonials */
declare( strict_types=1 );
namespace ShootCalTestimonials;
defined( 'ABSPATH' ) || exit;

class Category_Icons {

	public static function presets(): array {
		return array( '' => __( 'No icon', 'shootcal-testimonials' ), 'ring' => __( 'Ring — weddings', 'shootcal-testimonials' ), 'heart' => __( 'Heart — engagements', 'shootcal-testimonials' ), 'users' => __( 'People — families', 'shootcal-testimonials' ), 'graduation-cap' => __( 'Graduation cap — seniors', 'shootcal-testimonials' ), 'house' => __( 'House — real estate', 'shootcal-testimonials' ), 'camera' => __( 'Camera — photography', 'shootcal-testimonials' ), 'custom' => __( 'Custom SVG', 'shootcal-testimonials' ) );
	}

	public function register(): void {
		if ( is_admin() ) {
			add_action( TAXONOMY . '_add_form_fields', array( $this, 'add_fields' ) );
			add_action( TAXONOMY . '_edit_form_fields', array( $this, 'edit_fields' ) );
			add_action( 'created_' . TAXONOMY, array( $this, 'save' ) );
			add_action( 'edited_' . TAXONOMY, array( $this, 'save' ) );
		}
	}

	private function controls( int $id ): void {
		wp_nonce_field( 'sct_category_icon', 'sct_category_icon_nonce' );
		$selected = (string) get_term_meta( $id, 'sct_icon', true );
		echo '<label for="sct_icon">' . esc_html__( 'Category icon', 'shootcal-testimonials' ) . '</label><br><select id="sct_icon" name="sct_icon">';
		foreach ( self::presets() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Shown beside the category beneath the reviewer’s name. The built-in icons are from Font Awesome Free.', 'shootcal-testimonials' ) . '</p><label for="sct_icon_svg">' . esc_html__( 'Custom SVG code', 'shootcal-testimonials' ) . '</label><br><textarea id="sct_icon_svg" name="sct_icon_svg" rows="5" class="large-text code" maxlength="12000">' . esc_textarea( (string) get_term_meta( $id, 'sct_icon_svg', true ) ) . '</textarea><p class="description">' . esc_html__( 'Choose Custom SVG above and paste the code for a simple icon. Scripts, links and embedded content are not supported. Leave this blank to use no custom icon.', 'shootcal-testimonials' ) . '</p>';
		if ( get_term_meta( $id, 'sct_icon_invalid', true ) ) {
			echo '<p class="description">' . esc_html__( 'The last icon could not be saved. Paste a simple SVG made of shapes and paths; your previous icon was kept.', 'shootcal-testimonials' ) . '</p>';
		}
	}

	public function add_fields(): void {
		echo '<div class="form-field">';
		$this->controls( 0 );
		echo '</div>';
	}

	public function edit_fields( \WP_Term $term ): void {
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Review category icon', 'shootcal-testimonials' ) . '</th><td>';
		$this->controls( $term->term_id );
		echo '</td></tr>';
	}

	public function save( int $id ): void {
		$nonce = $_POST['sct_category_icon_nonce'] ?? null;
		if ( ! current_user_can( 'edit_term', $id ) || ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $nonce ) ), 'sct_category_icon' ) ) { return; }
		if ( ! isset( $_POST['sct_icon'], $_POST['sct_icon_svg'] ) || ! is_string( $_POST['sct_icon'] ) || ! is_string( $_POST['sct_icon_svg'] ) ) { return; }
		$preset = sanitize_key( wp_unslash( $_POST['sct_icon'] ) );
		if ( ! array_key_exists( $preset, self::presets() ) ) { return; }
		$raw = trim( wp_unslash( $_POST['sct_icon_svg'] ) );
		$svg = self::sanitize_svg( $raw );
		if ( 'custom' === $preset && '' !== $raw && '' === $svg ) {
			update_term_meta( $id, 'sct_icon_invalid', '1' );
			return;
		}
		update_term_meta( $id, 'sct_icon', $preset );
		update_term_meta( $id, 'sct_icon_svg', wp_slash( $svg ) );
		delete_term_meta( $id, 'sct_icon_invalid' );
	}

	/** Accept static shapes only; no entity expansion, scripting, CSS or external resources. */
	public static function sanitize_svg( string $raw ): string {
		if ( '' === trim( $raw ) || strlen( $raw ) > 12000 || ! class_exists( '\\DOMDocument' ) || preg_match( '/<!DOCTYPE|<!ENTITY|<\?/i', $raw ) ) { return ''; }
		$prior = libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$ok = $doc->loadXML( $raw, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );
		if ( ! $ok || ! $doc->documentElement || 'svg' !== $doc->documentElement->tagName ) { return ''; }
		$tags = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon' );
		$attributes = array( 'viewBox', 'd', 'fill', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'fill-opacity', 'stroke-opacity', 'transform', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'points', 'xmlns' );
		foreach ( $doc->getElementsByTagName( '*' ) as $element ) {
			if ( ! in_array( $element->tagName, $tags, true ) || ( $element->namespaceURI && 'http://www.w3.org/2000/svg' !== $element->namespaceURI ) ) { return ''; }
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				if ( ! in_array( $attribute->name, $attributes, true ) ) { $element->removeAttributeNode( $attribute ); continue; }
				$value = $attribute->value;
				if ( preg_match( '/url\s*\(|javascript|data:|https?:|[<>]/i', $value ) && 'xmlns' !== $attribute->name ) { return ''; }
				if ( 'xmlns' === $attribute->name && 'http://www.w3.org/2000/svg' !== $value ) { return ''; }
			}
		}
		$root = $doc->documentElement;
		$root->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		$root->setAttribute( 'aria-hidden', 'true' );
		$root->setAttribute( 'focusable', 'false' );
		if ( ! $root->hasAttribute( 'fill' ) ) { $root->setAttribute( 'fill', 'currentColor' ); }
		return (string) $doc->saveXML( $root );
	}

	public static function icon( int $id ): string {
		$preset = (string) get_term_meta( $id, 'sct_icon', true );
		if ( 'custom' === $preset ) { return self::sanitize_svg( (string) get_term_meta( $id, 'sct_icon_svg', true ) ); }
		if ( '' === $preset || ! array_key_exists( $preset, self::presets() ) ) { return ''; }
		static $icons = array();
		if ( ! isset( $icons[ $preset ] ) ) { $icons[ $preset ] = self::sanitize_svg( (string) file_get_contents( PLUGIN_DIR . 'assets/category-icons/' . $preset . '.svg' ) ); }
		return $icons[ $preset ];
	}
}
