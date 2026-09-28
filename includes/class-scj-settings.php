<?php
defined( 'ABSPATH' ) || exit;

/**
 * Postavke plugina (jedna opcija, polje ključeva).
 */
class SCJ_Settings {

	const OPTION = 'scj_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			// Podaci o prodajnom objektu (ulaze u naziv datoteke).
			'naziv_trgovca'        => get_bloginfo( 'name' ),
			'oib'                  => '',
			'oblik_objekta'        => ( class_exists( 'WooCommerce' ) ? 'webshop' : '' ), // Ministarstvo: za web trgovinu npr. "webshop".
			'adresa'               => '',
			'oznaka'               => '',

			// Sidrena cijena.
			'sidreni_datum'        => '2026-09-10',
			'stari_datum'          => '2025-05-02',
			'stare_kategorije'     => array(),
			'auto_nova_sidrena'    => 1,

			// Prikaz.
			'prikazi_sidrenu'      => 1,
			'oznaka_sidrene'       => 'Cijena na dan {datum}:',
			'prikazi_najnizu'      => 1,
			'oznaka_najnize'       => 'Najniža cijena u zadnjih 30 dana prije sniženja:',
			'naziv_akcije'         => 'Akcija',

			// Izvori podataka (WooCommerce).
			'marka_izvor'          => 'auto',
			'marka_zadana'         => '',
			'barkod_meta'          => '',
			'jedinica_zadana'      => 'kom',
			'virtualni_kao_usluge' => 0,

			// Objava.
			'formati'              => array( 'csv', 'xml' ),
			// Rano: WP-Cron se pokreće tek posjetom, pa bilo koji posjet (i bot) između 1 i 8 sati objavi cjenik.
			// Ne u ponoć, jer WooCommerce tada pokreće i završava zakazane akcije.
			'vrijeme_objave'       => '01:00',
			'decimalni_separator'  => ',',
			'csv_bom'              => 0,
			'zadrzi_dana'          => 35,
			'email_upozorenja'     => get_option( 'admin_email' ),
			'cron_kljuc'           => '',

			'obrisi_pri_deinstalaciji' => 0,
		);
	}

	public static function ensure_defaults() {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = array_merge( self::defaults(), $current );
		if ( empty( $merged['cron_kljuc'] ) ) {
			$merged['cron_kljuc'] = wp_generate_password( 24, false, false );
		}
		update_option( self::OPTION, $merged, false );
		self::$cache = null;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$merged = array_merge( self::all(), $values );
		update_option( self::OPTION, $merged, false );
		self::$cache = null;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitizacija unosa sa stranice postavki.
	 */
	public static function sanitize( $input ) {
		$d   = self::all();
		$out = $d;
		if ( ! is_array( $input ) ) {
			return $out;
		}

		foreach ( array( 'naziv_trgovca', 'oib', 'oblik_objekta', 'adresa', 'oznaka', 'oznaka_sidrene', 'oznaka_najnize', 'naziv_akcije', 'marka_izvor', 'marka_zadana', 'barkod_meta', 'jedinica_zadana', 'cron_kljuc' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $input[ $k ] ) );
			}
		}

		foreach ( array( 'sidreni_datum', 'stari_datum' ) as $k ) {
			if ( isset( $input[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input[ $k ] ) ) {
				$out[ $k ] = $input[ $k ];
			}
		}

		if ( isset( $input['vrijeme_objave'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $input['vrijeme_objave'] ) ) {
			$out['vrijeme_objave'] = $input['vrijeme_objave'];
		}

		$out['decimalni_separator'] = ( isset( $input['decimalni_separator'] ) && '.' === $input['decimalni_separator'] ) ? '.' : ',';
		$out['zadrzi_dana']         = isset( $input['zadrzi_dana'] ) ? max( 31, min( 400, absint( $input['zadrzi_dana'] ) ) ) : $d['zadrzi_dana'];
		$out['email_upozorenja']    = isset( $input['email_upozorenja'] ) ? sanitize_email( $input['email_upozorenja'] ) : $d['email_upozorenja'];

		foreach ( array( 'auto_nova_sidrena', 'prikazi_sidrenu', 'prikazi_najnizu', 'csv_bom', 'virtualni_kao_usluge', 'obrisi_pri_deinstalaciji' ) as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}

		$formati = isset( $input['formati'] ) ? array_intersect( (array) $input['formati'], array( 'csv', 'xml' ) ) : array();
		$out['formati'] = $formati ? array_values( $formati ) : array( 'csv' );

		$out['stare_kategorije'] = isset( $input['stare_kategorije'] ) ? array_map( 'absint', (array) $input['stare_kategorije'] ) : array();

		if ( empty( $out['cron_kljuc'] ) ) {
			$out['cron_kljuc'] = wp_generate_password( 24, false, false );
		}

		self::$cache = null;
		return $out;
	}

	/**
	 * Popis nedostajućih obveznih postavki (za upozorenja).
	 */
	public static function missing() {
		$missing = array();
		if ( '' === trim( (string) self::get( 'oblik_objekta' ) ) ) {
			$missing[] = 'oblik prodajnog objekta';
		}
		if ( '' === trim( (string) self::get( 'adresa' ) ) ) {
			$missing[] = 'adresa prodajnog objekta';
		}
		if ( '' === trim( (string) self::get( 'oznaka' ) ) ) {
			$missing[] = 'oznaka prodajnog objekta';
		}
		return $missing;
	}
}
