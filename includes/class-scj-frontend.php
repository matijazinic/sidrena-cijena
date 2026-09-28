<?php
defined( 'ABSPATH' ) || exit;

/**
 * Prikaz na stranici: stil i kratki kodovi za cjenike bez WooCommercea.
 *
 * [sidrena_cjenik vrsta="usluge" grupa="slug"]  tablica stavki
 * [sidrena_cijena id="123"]                      stavka cjenika ili WooCommerce proizvod (ID, ili sifra="..." / SKU;
 *                                                bez id-a i šifre: trenutni proizvod u petlji)
 *   prikaz="sve|cijena|sidrena|najniza"          što ispisati; "sidrena" ide ispod postojeće cijene na stranici
 *   oznaka="Tekst {datum}:"                      vlastiti tekst ispred iznosa; oznaka="" = samo iznos
 * [sidrena_arhiva]                               poveznice na objavljene CSV/XML cjenike
 *
 * PHP (predlošci): scj_stavka( 123|'SIFRA' ) za stavke, scj_proizvod( $product|ID|'SKU' ) za Woo proizvode.
 */
class SCJ_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_shortcode( 'sidrena_cjenik', array( __CLASS__, 'sc_cjenik' ) );
		add_shortcode( 'sidrena_cijena', array( __CLASS__, 'sc_cijena' ) );
		add_shortcode( 'sidrena_arhiva', array( __CLASS__, 'sc_arhiva' ) );
	}

	public static function styles() {
		wp_register_style( 'scj', false, array(), SCJ_VERSION );
		wp_enqueue_style( 'scj' );
		$css = '.scj-info{display:block;margin-top:.25em;line-height:1.35}'
			. '.scj-sidrena,.scj-najniza{display:block;font-size:.8em;font-weight:400;opacity:.85}'
			. '.scj-sidrena .amount,.scj-najniza .amount{font-weight:400}'
			. '.scj-tablica{width:100%;border-collapse:collapse}'
			. '.scj-tablica th,.scj-tablica td{text-align:left;padding:.5em .6em;border-bottom:1px solid rgba(0,0,0,.1);vertical-align:top}'
			. '.scj-tablica td.scj-num,.scj-tablica th.scj-num{text-align:right;white-space:nowrap}'
			. '.scj-tablica del{opacity:.6;margin-right:.3em}'
			. '.scj-mala{display:block;font-size:.8em;opacity:.8}';
		wp_add_inline_style( 'scj', apply_filters( 'scj_css', $css ) );
	}

	public static function lowest( array $d ) {
		if ( ! $d['na_akciji'] ) {
			return null;
		}
		$low = SCJ_History::omnibus_lowest( $d['item'], $d['trenutna'], SCJ_Items::sale_from_ts( $d ) );
		return null === $low ? $d['redovna'] : $low;
	}

	/**
	 * Tekst ispred iznosa: zadani iz postavki ili vlastiti (atribut oznaka), "{datum}" se zamjenjuje.
	 */
	private static function label( $custom, $default, $ymd = '' ) {
		if ( null === $custom ) {
			return $default;
		}
		return trim( str_replace( '{datum}', SCJ_Format::date_hr( $ymd ), (string) $custom ) );
	}

	private static function line( $class, $label, $amount ) {
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( trim( $label . ' ' . SCJ_Format::money( $amount ) ) ) . '</span>';
	}

	private static function price_cell( array $d ) {
		$out = '';
		if ( $d['na_akciji'] ) {
			$out .= '<del>' . esc_html( SCJ_Format::money( $d['redovna'] ) ) . '</del> ';
		}
		$out .= '<strong>' . esc_html( SCJ_Format::money( $d['trenutna'] ) ) . '</strong>';
		if ( $d['napomena'] ) {
			$out .= ' <span class="scj-napomena">' . esc_html( $d['napomena'] ) . '</span>';
		}
		if ( $d['na_akciji'] ) {
			$out .= '<span class="scj-mala">' . esc_html( $d['akcija_naziv'] ) . '</span>';
			if ( SCJ_Settings::get( 'prikazi_najnizu' ) ) {
				$low = self::lowest( $d );
				if ( null !== $low ) {
					$out .= '<span class="scj-mala">' . esc_html( SCJ_Settings::get( 'oznaka_najnize' ) . ' ' . SCJ_Format::money( $low ) ) . '</span>';
				}
			}
		}
		return $out;
	}

	public static function sc_cjenik( $atts ) {
		$atts = shortcode_atts(
			array(
				'vrsta' => 'sve',
				'grupa' => '',
			),
			$atts,
			'sidrena_cjenik'
		);
		$tip  = 'usluge' === $atts['vrsta'] ? 'usluga' : ( 'proizvodi' === $atts['vrsta'] ? 'proizvod' : null );
		$ids  = SCJ_Items::ids( $tip );
		if ( $atts['grupa'] ) {
			$ids = array_values(
				array_filter(
					$ids,
					function ( $id ) use ( $atts ) {
						return has_term( array_map( 'trim', explode( ',', $atts['grupa'] ) ), SCJ_Items::TAX, $id );
					}
				)
			);
		}
		if ( ! $ids ) {
			return '';
		}

		$rows  = array();
		$dates = array();
		foreach ( $ids as $id ) {
			$d = SCJ_Items::data( $id );
			if ( ! $d || $d['iskljuci'] || null === $d['trenutna'] ) {
				continue;
			}
			$rows[]              = $d;
			$dates[ $d['datum'] ] = true;
		}
		if ( ! $rows ) {
			return '';
		}
		$show_anchor = true; // Postavka prikazi_sidrenu odnosi se samo na automatski prikaz uz Woo cijenu.
		$one_date    = 1 === count( $dates );
		$head_anchor = $one_date ? SCJ_Format::anchor_label( key( $dates ) ) : 'Sidrena cijena';

		$h  = '<table class="scj-tablica"><thead><tr><th>Naziv</th><th class="scj-num">Cijena</th>';
		$h .= $show_anchor ? '<th class="scj-num">' . esc_html( rtrim( $head_anchor, ': ' ) ) . '</th>' : '';
		$h .= '</tr></thead><tbody>';
		foreach ( $rows as $d ) {
			$h .= '<tr><td>' . esc_html( $d['naziv'] ) . '</td><td class="scj-num">' . self::price_cell( $d ) . '</td>';
			if ( $show_anchor ) {
				$a  = null === $d['sidrena'] ? '–' : esc_html( SCJ_Format::money( $d['sidrena'] ) );
				$a .= ( ! $one_date && null !== $d['sidrena'] ) ? '<span class="scj-mala">' . esc_html( SCJ_Format::date_hr( $d['datum'] ) ) . '</span>' : '';
				$h .= '<td class="scj-num">' . $a . '</td>';
			}
			$h .= '</tr>';
		}
		$h .= '</tbody></table>';
		return $h;
	}

	public static function sc_cijena( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'sifra'  => '',
				'prikaz' => 'sve',
				'oznaka' => null,
			),
			$atts,
			'sidrena_cijena'
		);
		$product = self::woo_target( $atts['id'], $atts['sifra'] );
		if ( $product ) {
			return self::sc_proizvod( $product, $atts );
		}
		$d = SCJ_Items::find( $atts['id'], $atts['sifra'] );
		if ( ! $d || null === $d['trenutna'] ) {
			return '';
		}
		switch ( $atts['prikaz'] ) {
			case 'sidrena':
				// Izričito zatraženo, pa se ispisuje neovisno o postavci automatskog prikaza.
				if ( null === $d['sidrena'] ) {
					return '';
				}
				return self::line( 'scj-sidrena', self::label( $atts['oznaka'], SCJ_Format::anchor_label( $d['datum'] ), $d['datum'] ), $d['sidrena'] );
			case 'najniza':
				$low = self::lowest( $d );
				if ( null === $low ) {
					return '';
				}
				return self::line( 'scj-najniza', self::label( $atts['oznaka'], (string) SCJ_Settings::get( 'oznaka_najnize' ) ), $low );
			case 'cijena':
				return '<span class="scj-cijena">' . self::price_cell( $d ) . '</span>';
		}
		$out = '<span class="scj-cijena">' . self::price_cell( $d );
		if ( null !== $d['sidrena'] ) {
			$out .= self::line( 'scj-sidrena', self::label( $atts['oznaka'], SCJ_Format::anchor_label( $d['datum'] ), $d['datum'] ), $d['sidrena'] );
		}
		return $out . '</span>';
	}

	/**
	 * WooCommerce proizvod za kratki kod: po ID-ju, po SKU-u (ako šifra nije stavka cjenika) ili trenutni proizvod u petlji.
	 */
	private static function woo_target( $id, $sifra ) {
		if ( ! SCJ_Plugin::woo_active() ) {
			return null;
		}
		$id = (int) $id;
		if ( $id ) {
			return in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ? wc_get_product( $id ) : null;
		}
		$sifra = trim( (string) $sifra );
		if ( '' !== $sifra ) {
			if ( SCJ_Items::id_by_sifra( $sifra ) ) {
				return null; // Šifra stavke cjenika ima prednost.
			}
			$pid = wc_get_product_id_by_sku( $sifra );
			return $pid ? wc_get_product( $pid ) : null;
		}
		global $product;
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		return 'product' === get_post_type() ? wc_get_product( get_the_ID() ) : null;
	}

	private static function sc_proizvod( WC_Product $product, array $atts ) {
		if ( SCJ_Woo::excluded( $product ) ) {
			return 'cijena' === $atts['prikaz'] ? SCJ_Woo::plain_price_html( $product ) : '';
		}
		switch ( $atts['prikaz'] ) {
			case 'sidrena':
				return SCJ_Woo::anchor_html( $product, $atts['oznaka'] );
			case 'najniza':
				return SCJ_Woo::lowest_html( $product, $atts['oznaka'] );
			case 'cijena':
				return '<span class="scj-cijena">' . SCJ_Woo::plain_price_html( $product ) . '</span>';
		}
		$extra  = SCJ_Woo::anchor_html( $product, $atts['oznaka'] );
		$extra .= SCJ_Settings::get( 'prikazi_najnizu' ) ? SCJ_Woo::lowest_html( $product ) : '';
		return '<span class="scj-cijena">' . SCJ_Woo::plain_price_html( $product ) . ( $extra ? '<span class="scj-info">' . $extra . '</span>' : '' ) . '</span>';
	}

	public static function sc_arhiva() {
		$d    = SCJ_Exporter::dir();
		$out  = '<div class="scj-arhiva"><p>Cjenik u strojno čitljivom obliku (NN 101/2026):</p><ul>';
		$seen = 0;
		foreach ( array( 'proizvodi', 'usluge' ) as $tip ) {
			foreach ( (array) SCJ_Settings::get( 'formati' ) as $fmt ) {
				$f = SCJ_Exporter::latest( $tip, $fmt );
				if ( $f ) {
					$out .= '<li><a href="' . esc_url( $f['url'] ) . '">' . esc_html( ucfirst( $tip ) . ' – ' . strtoupper( $fmt ) ) . '</a> <small>(' . esc_html( wp_date( 'j. n. Y. H:i', $f['mtime'] ) ) . ')</small></li>';
					$seen++;
				}
			}
		}
		$out .= '<li><a href="' . esc_url( $d['url'] . '/index.html' ) . '">Arhiva cjenika (30 dana)</a></li>';
		$out .= '</ul></div>';
		return $seen ? $out : '';
	}
}

if ( ! function_exists( 'scj_stavka' ) ) {
	/**
	 * Podaci stavke cjenika za predloške: scj_stavka( 123 ) ili scj_stavka( 'SIFRA' ).
	 *
	 * Uz polja iz SCJ_Items::data() vraća 'najniza' (kod akcije) i gotove tekstove
	 * 'oznaka_sidrene' ("Cijena na dan 10. 9. 2026.:"), 'sidrena_tekst' i 'cijena_tekst' ("45,00 €").
	 *
	 * @param int|string $id_or_sifra ID stavke ili šifra.
	 * @return array|null
	 */
	function scj_stavka( $id_or_sifra ) {
		$d = is_numeric( $id_or_sifra ) ? SCJ_Items::find( (int) $id_or_sifra ) : SCJ_Items::find( 0, (string) $id_or_sifra );
		if ( ! $d ) {
			return null;
		}
		$d['najniza']        = SCJ_Frontend::lowest( $d );
		$d['oznaka_sidrene'] = SCJ_Format::anchor_label( $d['datum'] );
		$d['sidrena_tekst']  = null === $d['sidrena'] ? '' : SCJ_Format::money( $d['sidrena'] );
		$d['cijena_tekst']   = null === $d['trenutna'] ? '' : SCJ_Format::money( $d['trenutna'] );
		return $d;
	}
}

if ( ! function_exists( 'scj_proizvod' ) ) {
	/**
	 * Sidrena cijena WooCommerce proizvoda za predloške: scj_proizvod( $product ), scj_proizvod( 123 ) ili scj_proizvod( 'SKU' ).
	 *
	 * Iznosi su za prikaz (s PDV-om ili bez, prema postavci trgovine). Varijabilni proizvod: raspon ako sve varijacije
	 * imaju isti datum. 'sidrena_html' i 'najniza_html' su gotovi redovi kao uz cijenu.
	 *
	 * @param WC_Product|int|string $product Proizvod, ID ili SKU.
	 * @return array|null
	 */
	function scj_proizvod( $product ) {
		if ( ! SCJ_Plugin::woo_active() ) {
			return null;
		}
		if ( ! $product instanceof WC_Product ) {
			$product = is_numeric( $product ) ? wc_get_product( (int) $product ) : wc_get_product( wc_get_product_id_by_sku( (string) $product ) );
		}
		if ( ! $product ) {
			return null;
		}
		list( $min, $max, $date ) = SCJ_Woo::anchor_display( $product );
		$low                      = ( ! $product->is_type( 'variable' ) && $product->is_on_sale() ) ? SCJ_Woo::lowest_30( $product ) : null;
		return array(
			'id'             => $product->get_id(),
			'sidrena'        => $min,
			'sidrena_do'     => $max,
			'datum'          => $date,
			'oznaka_sidrene' => $date ? SCJ_Format::anchor_label( $date ) : '',
			'sidrena_tekst'  => null === $min ? '' : ( abs( $min - $max ) < 0.005 ? SCJ_Format::money( $min ) : SCJ_Format::money( $min ) . ' - ' . SCJ_Format::money( $max ) ),
			'najniza'        => $low,
			'izvan_cjenika'  => SCJ_Woo::excluded( $product ),
			'sidrena_html'   => SCJ_Woo::anchor_html( $product ),
			'najniza_html'   => SCJ_Woo::lowest_html( $product ),
		);
	}
}
