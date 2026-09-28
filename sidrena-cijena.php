<?php
/**
 * Plugin Name:       Sidrena cijena i cjenik
 * Description:       Isticanje dodatne (sidrene) cijene, najniže cijene u zadnjih 30 dana i automatska dnevna objava cjenika u CSV/XML formatu prema odlukama NN 101/2026. Radi s WooCommerceom i bez njega (usluge i proizvodi kao stavke cjenika).
 * Version:           1.0.0
 * Author:            Matija Žinić
 * Author URI:        https://zin-digital.com/
 * Plugin URI:        https://github.com/matijazinic/sidrena-cijena
 * Update URI:        https://github.com/matijazinic/sidrena-cijena
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       sidrena-cijena
 * WC requires at least: 7.0
 * WC tested up to:   11.1
 * License:           GPLv2 or later
 *
 * Ovaj program je slobodan softver pod licencom GNU GPL (verzija 2 ili novija). Distribuira se u nadi da će biti
 * koristan, ali BEZ IKAKVOG JAMSTVA, čak i bez prešutnog jamstva prikladnosti za prodaju ili određenu namjenu.
 * Nije pravni savjet i ne jamči usklađenost s propisima. Koristite ga na vlastitu odgovornost.
 */

defined( 'ABSPATH' ) || exit;

define( 'SCJ_VERSION', '1.0.0' );
define( 'SCJ_FILE', __FILE__ );
define( 'SCJ_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCJ_URL', plugin_dir_url( __FILE__ ) );

require_once SCJ_DIR . 'includes/class-scj-settings.php';
require_once SCJ_DIR . 'includes/class-scj-format.php';
require_once SCJ_DIR . 'includes/class-scj-history.php';
require_once SCJ_DIR . 'includes/class-scj-items.php';
require_once SCJ_DIR . 'includes/class-scj-anchor-log.php';
require_once SCJ_DIR . 'includes/class-scj-woo.php';
require_once SCJ_DIR . 'includes/class-scj-exporter.php';
require_once SCJ_DIR . 'includes/class-scj-scheduler.php';
require_once SCJ_DIR . 'includes/class-scj-rest.php';
require_once SCJ_DIR . 'includes/class-scj-frontend.php';
require_once SCJ_DIR . 'includes/class-scj-tools.php';
require_once SCJ_DIR . 'includes/class-scj-admin.php';
require_once SCJ_DIR . 'includes/class-scj-updater.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SCJ_DIR . 'includes/class-scj-cli.php';
}

/**
 * Glavna klasa plugina.
 */
final class SCJ_Plugin {

	/** @var SCJ_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	public function boot() {
		SCJ_History::maybe_upgrade();
		SCJ_Items::init();
		SCJ_Anchor_Log::init(); // Svi konteksti: admin, REST, WP-CLI, cron.
		if ( self::woo_active() ) {
			SCJ_Woo::init();
		}
		SCJ_Scheduler::init();
		SCJ_Rest::init();
		SCJ_Frontend::init();
		SCJ_Updater::init(); // Ne samo u adminu: provjera ažuriranja radi i kroz WP-Cron.
		if ( is_admin() ) {
			SCJ_Admin::init();
			SCJ_Tools::init();
		}
	}

	public static function woo_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public static function activate() {
		SCJ_History::install();
		SCJ_Settings::ensure_defaults();
		SCJ_Exporter::ensure_dir();
		SCJ_Items::register();
		flush_rewrite_rules();
		SCJ_Scheduler::schedule();
	}

	public static function deactivate() {
		SCJ_Scheduler::unschedule();
	}
}

register_activation_hook( __FILE__, array( 'SCJ_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCJ_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SCJ_FILE, true );
		}
	}
);

SCJ_Plugin::instance();
