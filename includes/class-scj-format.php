<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pomoćne funkcije za formatiranje cijena, datuma i naziva datoteka.
 */
class SCJ_Format {

	/**
	 * Pretvara unos (npr. "1.234,56" ili "12,5") u float ili null.
	 */
	public static function parse_price( $value ) {
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$value = str_replace( array( ' ', "\xc2\xa0", '€', 'EUR', 'eur' ), '', $value );
		if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
			// Pretpostavka: točka je separator tisućica, zarez decimala (hrvatski zapis).
			if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
				$value = str_replace( '.', '', $value );
				$value = str_replace( ',', '.', $value );
			} else {
				$value = str_replace( ',', '', $value );
			}
		} else {
			$value = str_replace( ',', '.', $value );
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		return round( (float) $value, 4 );
	}

	/**
	 * Iznos za prikaz kupcu (npr. "45,00 €").
	 */
	public static function money( $amount ) {
		if ( null === $amount || '' === $amount ) {
			return '';
		}
		if ( function_exists( 'wc_price' ) ) {
			// Običan tekst (i za scj_stavka()): bez oznaka i HTML entiteta (&nbsp;&euro;).
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return number_format( (float) $amount, 2, ',', '.' ) . ' €';
	}

	/**
	 * Iznos za datoteku cjenika.
	 */
	public static function number( $amount, $separator = ',' ) {
		if ( null === $amount || '' === $amount ) {
			return '';
		}
		return number_format( (float) $amount, 2, $separator, '' );
	}

	/**
	 * Datum u obliku koji preporučuje Ministarstvo: "10. 9. 2026."
	 */
	public static function date_hr( $ymd ) {
		$ts = strtotime( $ymd . ' 12:00:00' );
		if ( ! $ts ) {
			return (string) $ymd;
		}
		return gmdate( 'j. n. Y.', $ts );
	}

	/**
	 * Dio naziva datoteke prema primjeru Ministarstva (pojašnjenje od 18. 9. 2026.): tekst ostaje kako je upisan
	 * (razmaci, dijakritici), uklanjaju se samo znakovi nedopušteni u nazivu datoteke i zarezi; "_" je razdjelnik dijelova.
	 */
	public static function name_part( $text ) {
		$text = str_replace( array( '/', '\\', '_' ), '-', (string) $text );
		$text = preg_replace( '/[\x00-\x1F\x7F:*?"<>|,]+/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return '' === $text ? 'x' : $text;
	}

	/**
	 * Hrvatska množina: 1 stavka, 2 stavke, 5 stavki, 21 stavka.
	 */
	public static function plural( $n, $one, $few, $many ) {
		$n  = abs( (int) $n );
		$d  = $n % 10;
		$dd = $n % 100;
		if ( 1 === $d && 11 !== $dd ) {
			return $one;
		}
		return ( $d >= 2 && $d <= 4 && ( $dd < 12 || $dd > 14 ) ) ? $few : $many;
	}

	/**
	 * Oznaka sidrene cijene s datumom.
	 */
	public static function anchor_label( $ymd ) {
		$label = (string) SCJ_Settings::get( 'oznaka_sidrene' );
		if ( false === strpos( $label, '{datum}' ) ) {
			$label .= ' {datum}';
		}
		return str_replace( '{datum}', self::date_hr( $ymd ), $label );
	}
}
