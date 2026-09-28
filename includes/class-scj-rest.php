<?php
defined( 'ABSPATH' ) || exit;

/**
 * Javne krajnje točke za automatizirano preuzimanje u realnom vremenu.
 *
 * /wp-json/sidrena-cijena/v1/cjenik?vrsta=proizvodi&format=csv  – cjenik generiran u trenutku zahtjeva
 * /wp-json/sidrena-cijena/v1/arhiva                             – popis objavljenih datoteka (JSON)
 */
class SCJ_Rest {

	const NS = 'sidrena-cijena/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'scj_price_changed', array( __CLASS__, 'flush' ) );
		add_action( 'scj_published', array( __CLASS__, 'flush' ) );
		add_action( 'save_post_' . SCJ_Items::CPT, array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/cjenik',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'cjenik' ),
				'args'                => array(
					// Bez 'type' WordPress ne provjerava 'enum'.
					'vrsta'  => array(
						'type'    => 'string',
						'default' => 'proizvodi',
						'enum'    => array( 'proizvodi', 'usluge' ),
					),
					'format' => array(
						'type'    => 'string',
						'default' => 'csv',
						'enum'    => array( 'csv', 'xml' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/arhiva',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'arhiva' ),
			)
		);
	}

	public static function cjenik( WP_REST_Request $req ) {
		// Ključ predmemorije smije nastati samo iz poznatih vrijednosti.
		$tip    = 'usluge' === $req->get_param( 'vrsta' ) ? 'usluge' : 'proizvodi';
		$format = 'xml' === $req->get_param( 'format' ) ? 'xml' : 'csv';
		$cache  = 'scj_live_' . $tip . '_' . $format;
		$body   = get_transient( $cache );
		if ( false === $body ) {
			$body = SCJ_Exporter::build_live( $tip, $format );
			set_transient( $cache, $body, 5 * MINUTE_IN_SECONDS );
		}
		$type = 'csv' === $format ? 'text/csv; charset=utf-8' : 'application/xml; charset=utf-8';

		add_filter(
			'rest_pre_serve_request',
			function ( $served ) use ( $body, $type ) {
				if ( ! headers_sent() ) {
					header( 'Content-Type: ' . $type );
					header( 'Access-Control-Allow-Origin: *' );
					header( 'Cache-Control: public, max-age=300' );
				}
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			}
		);
		return new WP_REST_Response( null, 200 );
	}

	public static function arhiva() {
		$files = array_map(
			function ( $f ) {
				return array(
					'datoteka'   => $f['name'],
					'url'        => $f['url'],
					'vrsta'      => $f['tip'],
					'format'     => $f['format'],
					'objavljeno' => wp_date( 'c', $f['mtime'] ),
				);
			},
			SCJ_Exporter::files()
		);
		$res = new WP_REST_Response(
			array(
				'trgovac' => SCJ_Settings::get( 'naziv_trgovca' ),
				'cjenici' => $files,
			)
		);
		$res->header( 'Access-Control-Allow-Origin', '*' );
		return $res;
	}

	public static function live_url( $tip = 'proizvodi', $format = 'csv' ) {
		return add_query_arg(
			array(
				'vrsta'  => $tip,
				'format' => $format,
			),
			rest_url( self::NS . '/cjenik' )
		);
	}

	/**
	 * Briše predmemoriju nakon promjene cijena.
	 */
	public static function flush() {
		foreach ( array( 'proizvodi', 'usluge' ) as $t ) {
			foreach ( array( 'csv', 'xml' ) as $f ) {
				delete_transient( 'scj_live_' . $t . '_' . $f );
			}
		}
	}
}
