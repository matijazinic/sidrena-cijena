<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI naredbe.
 *
 *   wp sidrena objavi          objavi cjenik odmah
 *   wp sidrena status          stanje zadnje objave
 *   wp sidrena popuni [--dry-run]  upiši trenutnu redovnu cijenu kao sidrenu gdje nedostaje
 *   wp sidrena uvoz <datoteka.csv>   sidrene cijene i stavke cjenika (stvara nove)
 */
class SCJ_CLI {

	/**
	 * Objavi cjenik odmah.
	 */
	public function objavi() {
		delete_transient( 'scj_lock' );
		$ok  = SCJ_Exporter::publish( 'WP-CLI' );
		$log = get_option( 'scj_log', array() );
		$l   = end( $log );
		foreach ( (array) $l['files'] as $f ) {
			WP_CLI::log( '  ' . $f );
		}
		foreach ( (array) $l['errors'] as $e ) {
			WP_CLI::warning( $e );
		}
		$ok ? WP_CLI::success( 'Cjenik objavljen.' ) : WP_CLI::error( 'Objava nije uspjela.' );
	}

	/**
	 * Stanje objave.
	 */
	public function status() {
		foreach ( SCJ_Admin::checks() as $c ) {
			WP_CLI::log( sprintf( '[%s] %s: %s', strtoupper( $c[0] ), $c[1], wp_strip_all_tags( $c[2] ) ) );
		}
	}

	/**
	 * Upiši trenutnu redovnu cijenu kao sidrenu gdje nedostaje.
	 *
	 * [--dry-run]
	 * : Samo prebroji.
	 */
	public function popuni( $args, $assoc ) {
		$dry = ! empty( $assoc['dry-run'] );
		$n   = SCJ_Tools::fill_missing( $dry );
		WP_CLI::success( ( $dry ? 'Bilo bi popunjeno: ' : 'Popunjeno: ' ) . $n['woo'] . ' WooCommerce, ' . $n['stavke'] . ' stavki.' );
	}

	/**
	 * Uvezi sidrene cijene i stavke cjenika iz CSV-a (isti stupci kao izvoz u Alatima).
	 *
	 * <datoteka>
	 * : Put do CSV datoteke.
	 */
	public function uvoz( $args ) {
		if ( ! is_readable( $args[0] ) ) {
			WP_CLI::error( 'Datoteka ne postoji ili se ne može čitati: ' . $args[0] );
		}
		$r = SCJ_Tools::import_file( $args[0] );
		foreach ( $r['errors'] as $e ) {
			WP_CLI::warning( $e );
		}
		WP_CLI::success( 'Ažurirano: ' . $r['ok'] . ', novih stavki: ' . $r['created'] . ', preskočeno: ' . $r['skip'] );
	}
}

WP_CLI::add_command( 'sidrena', 'SCJ_CLI' );
