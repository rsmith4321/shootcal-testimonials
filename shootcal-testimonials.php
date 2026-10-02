<?php
/**
 * Plugin Name:       ShootCal Testimonials
 * Description:       A simple testimonials library with automatic SEO output, native WordPress storage, and optional review structured data.
 * Version:           0.4.3
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            ShootCal
 * Author URI:        https://www.shootcal.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shootcal-testimonials
 *
 * @package ShootCalTestimonials
 */

declare( strict_types=1 );

namespace ShootCalTestimonials;

defined( 'ABSPATH' ) || exit;

const VERSION    = '0.4.3';
const SLUG       = 'shootcal-testimonials';
const OPTION_KEY = 'shootcal_testimonials_options';

/** Post type and taxonomy slugs. */
const POST_TYPE = 'sct_testimonial';
const TAXONOMY  = 'sct_category';

/** Meta prefix. Registered keys are exposed through the REST API; private keys are not registered at all. */
const META_PREFIX = 'sct_';

define( __NAMESPACE__ . '\\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal namespace autoloader.
 *
 * Maps ShootCalTestimonials\Post_Type to includes/class-post-type.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( strpos( $class_name, __NAMESPACE__ . '\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ . '\\' ) );
		$file     = strtolower( str_replace( '_', '-', $relative ) );
		$path     = PLUGIN_DIR . 'includes/class-' . $file . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Register the plugin's WordPress integrations.
 */
function bootstrap(): void {
	( new Post_Type() )->register();
	( new Meta() )->register();
	( new Assets() )->register();
	( new Shortcode() )->register();
	( new Form() )->register();
	( new Block() )->register();
	( new Schema() )->register();
	if ( is_admin() ) {
		( new Settings() )->register();
		( new Editor() )->register();
	}
	( new Compatibility() )->register();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

register_activation_hook(
	__FILE__,
	static function (): void {
		Config::ensure_defaults();

		// Flush once so the CPT and taxonomy permalinks resolve immediately.
		$types = new Post_Type();
		$types->register_post_type();
		$types->register_taxonomy();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);
