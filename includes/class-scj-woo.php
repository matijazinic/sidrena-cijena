<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce: polja sidrene cijene, prikaz uz cijenu, najniža cijena u 30 dana,
 * bilježenje povijesti i podaci za cjenik.
 */
class SCJ_Woo {

	public static function init() {
		// Uređivanje proizvoda.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );

		// Povijest cijena i automatska sidrena cijena za nove proizvode (admin, uvoz, REST API, CLI).
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'on_saved' ), 20, 1 );

		// Stupac "Sidrena" je inače stisnut jer WooCommerce fiksira širine stupaca.
		add_action( 'admin_head-edit.php', array( __CLASS__, 'admin_column_css' ) );

		// Prikaz.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 50, 2 );

		// Popis proizvoda u adminu.
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'admin_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'admin_column_value' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Sidrena cijena                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Je li proizvod u kategorijama kojima vrijedi stari datum (2. 5. 2025.).
	 */
	public static function in_old_categories( $product ) {
		$cats = array_filter( array_map( 'absint', (array) SCJ_Settings::get( 'stare_kategorije' ) ) );
		if ( ! $cats ) {
			return false;
		}
		$pid = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$ids = wc_get_product_cat_ids( $pid );
		foreach ( $ids as $cid ) {
			if ( in_array( $cid, $cats, true ) ) {
				return true;
			}
			foreach ( get_ancestors( $cid, 'product_cat', 'taxonomy' ) as $anc ) {
				if ( in_array( (int) $anc, $cats, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Sidrena cijena i datum.
	 *
	 * @return array{0: float|null, 1: string}
	 */
	public static function anchor( $product ) {
		$price = SCJ_Format::parse_price( $product->get_meta( '_scj_sidrena', true ) );
		$date  = (string) $product->get_meta( '_scj_datum', true );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = self::in_old_categories( $product ) ? (string) SCJ_Settings::get( 'stari_datum' ) : (string) SCJ_Settings::get( 'sidreni_datum' );
		}
		return array( $price, $date );
	}

	/**
	 * Je li proizvod nastao nakon referentnog datuma (dakle nije postojao 10. 9.).
	 */
	private static function created_after_reference( $product ) {
		$check = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$check = $parent;
			}
		}
		$created = $check->get_date_created();
		$ref_end = new DateTimeImmutable( SCJ_Settings::get( 'sidreni_datum' ) . ' 23:59:59', wp_timezone() );
		return ! $created || $created->getTimestamp() > $ref_end->getTimestamp();
	}

	/**
	 * Je li proizvod (i roditelj varijacije) objavljen, tj. u ponudi.
	 */
	private static function is_offered( $product ) {
		if ( 'publish' !== $product->get_status() ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			return 'publish' === get_post_status( $product->get_parent_id() );
		}
		return true;
	}

	/**
	 * Sidrena = redovna cijena na dan kad je proizvod prvi put u ponudi (skica nije u ponudi).
	 */
	private static function maybe_auto_anchor( $product ) {
		if ( ! SCJ_Settings::get( 'auto_nova_sidrena' ) || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return false;
		}
		if ( '' !== (string) $product->get_meta( '_scj_sidrena', true ) || ! self::is_offered( $product ) ) {
			return false;
		}
		$regular = $product->get_regular_price();
		if ( '' === (string) $regular || ! self::created_after_reference( $product ) ) {
			return false;
		}
		$product->update_meta_data( '_scj_sidrena', wc_format_decimal( $regular ) );
		$product->update_meta_data( '_scj_datum', wp_date( 'Y-m-d' ) );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Admin polja                                                          */
	/* ------------------------------------------------------------------ */

	public static function product_fields() {
		global $product_object;
		$sdate = SCJ_Format::date_hr( (string) SCJ_Settings::get( 'sidreni_datum' ) );
		echo '<div class="options_group scj-woo-fields">';
		echo '<p class="form-field" style="margin-bottom:0"><strong>Sidrena cijena i cjenik</strong></p>';

		echo '<div class="show_if_simple show_if_external">';
		woocommerce_wp_text_input(
			array(
				'id'          => '_scj_sidrena',
				'label'       => 'Sidrena cijena (' . get_woocommerce_currency_symbol() . ')',
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => 'Redovna cijena na dan ' . $sdate . ', bez akcije i posebnih uvjeta (loyalty, popust za umirovljenike...), unesena na isti način kao redovna cijena. Ako je proizvod tada bio na akciji, upišite redovnu cijenu prije akcije. Kasnije izmjene se bilježe.',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_scj_datum',
				'label'       => 'Datum sidrene cijene',
				'type'        => 'date',
				'desc_tip'    => true,
				'description' => 'Prazno = automatski (' . $sdate . ', ili 2. 5. 2025. za kategorije iz postavki). Za proizvod uveden kasnije: datum prvog uvrštenja u prodaju.',
			)
		);
		echo '</div>';

		woocommerce_wp_text_input(
			array(
				'id'          => '_scj_akcija_naziv',
				'label'       => 'Naziv akcije',
				'placeholder' => (string) SCJ_Settings::get( 'naziv_akcije' ),
				'desc_tip'    => true,
				'description' => 'Naziv posebnog oblika prodaje u cjeniku kad je proizvod na sniženju.',
			)
		);
		if ( 'meta' === SCJ_Settings::get( 'marka_izvor' ) || ( 'auto' === SCJ_Settings::get( 'marka_izvor' ) && ! taxonomy_exists( 'product_brand' ) ) ) {
			woocommerce_wp_text_input(
				array(
					'id'          => '_scj_marka',
					'label'       => 'Marka',
					'placeholder' => (string) SCJ_Settings::get( 'marka_zadana' ),
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id'          => '_scj_jedinica',
				'label'       => 'Jedinica mjere',
				'placeholder' => (string) SCJ_Settings::get( 'jedinica_zadana' ),
				'desc_tip'    => true,
				'description' => 'npr. kg, l, m, kom.',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_scj_kolicina',
				'label'       => 'Neto količina',
				'placeholder' => 'npr. 0,5',
				'desc_tip'    => true,
				'description' => 'Količina u jedinici mjere (500 g = 0,5 kg). Služi za izračun cijene za jedinicu mjere.',
			)
		);
		if ( ! self::has_gtin_field() && ! SCJ_Settings::get( 'barkod_meta' ) ) {
			woocommerce_wp_text_input(
				array(
					'id'    => '_scj_barkod',
					'label' => 'Barkod (EAN)',
				)
			);
		}
		woocommerce_wp_checkbox(
			array(
				'id'          => '_scj_iskljuci',
				'label'       => 'Izvan cjenika',
				'description' => 'Ne uključuj u javni CSV/XML cjenik (npr. prodaja samo tvrtkama)',
			)
		);
		echo '</div>';
	}

	public static function save_product( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce provjerava nonce.
		$map = array( '_scj_akcija_naziv', '_scj_marka', '_scj_jedinica', '_scj_barkod' );
		foreach ( $map as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$product->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}
		if ( isset( $_POST['_scj_kolicina'] ) ) {
			$k = SCJ_Format::parse_price( sanitize_text_field( wp_unslash( $_POST['_scj_kolicina'] ) ) );
			$product->update_meta_data( '_scj_kolicina', null === $k ? '' : (string) $k );
		}
		if ( isset( $_POST['_scj_sidrena'] ) && ! $product->is_type( 'variable' ) ) {
			$product->update_meta_data( '_scj_sidrena', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_scj_sidrena'] ) ) ) );
		}
		if ( isset( $_POST['_scj_datum'] ) ) {
			$d = sanitize_text_field( wp_unslash( $_POST['_scj_datum'] ) );
			$product->update_meta_data( '_scj_datum', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '' );
		}
		$product->update_meta_data( '_scj_iskljuci', isset( $_POST['_scj_iskljuci'] ) ? 'yes' : 'no' );
		// phpcs:enable

		self::maybe_auto_anchor( $product );
	}

	public static function variation_fields( $loop, $variation_data, $variation ) {
		$v     = wc_get_product( $variation->ID );
		$sdate = SCJ_Format::date_hr( (string) SCJ_Settings::get( 'sidreni_datum' ) );
		woocommerce_wp_text_input(
			array(
				'id'            => "scj_var_sidrena_{$loop}",
				'name'          => "scj_var_sidrena[{$loop}]",
				'value'         => $v ? $v->get_meta( '_scj_sidrena', true ) : '',
				'label'         => 'Sidrena cijena (' . get_woocommerce_currency_symbol() . ')',
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-first',
				'desc_tip'      => true,
				'description'   => 'Redovna cijena na dan ' . $sdate . ', bez akcije.',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "scj_var_datum_{$loop}",
				'name'          => "scj_var_datum[{$loop}]",
				'value'         => $v ? $v->get_meta( '_scj_datum', true ) : '',
				'label'         => 'Datum sidrene cijene',
				'type'          => 'date',
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "scj_var_kolicina_{$loop}",
				'name'          => "scj_var_kolicina[{$loop}]",
				'value'         => $v ? $v->get_meta( '_scj_kolicina', true ) : '',
				'label'         => 'Neto količina (za cijenu po jedinici)',
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		if ( ! self::has_gtin_field() && ! SCJ_Settings::get( 'barkod_meta' ) ) {
			woocommerce_wp_text_input(
				array(
					'id'            => "scj_var_barkod_{$loop}",
					'name'          => "scj_var_barkod[{$loop}]",
					'value'         => $v ? $v->get_meta( '_scj_barkod', true ) : '',
					'label'         => 'Barkod (EAN)',
					'wrapper_class' => 'form-row form-row-last',
				)
			);
		}
	}

	public static function save_variation( $v, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['scj_var_sidrena'][ $i ] ) ) {
			$v->update_meta_data( '_scj_sidrena', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['scj_var_sidrena'][ $i ] ) ) ) );
		}
		if ( isset( $_POST['scj_var_datum'][ $i ] ) ) {
			$d = sanitize_text_field( wp_unslash( $_POST['scj_var_datum'][ $i ] ) );
			$v->update_meta_data( '_scj_datum', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '' );
		}
		if ( isset( $_POST['scj_var_kolicina'][ $i ] ) ) {
			$k = SCJ_Format::parse_price( sanitize_text_field( wp_unslash( $_POST['scj_var_kolicina'][ $i ] ) ) );
			$v->update_meta_data( '_scj_kolicina', null === $k ? '' : (string) $k );
		}
		if ( isset( $_POST['scj_var_barkod'][ $i ] ) ) {
			$v->update_meta_data( '_scj_barkod', sanitize_text_field( wp_unslash( $_POST['scj_var_barkod'][ $i ] ) ) );
		}
		// phpcs:enable
		self::maybe_auto_anchor( $v ); // WooCommerce sprema varijaciju odmah nakon ovog hooka.
	}

	public static function has_gtin_field() {
		return method_exists( 'WC_Product', 'get_global_unique_id' );
	}

	/* ------------------------------------------------------------------ */
	/* Povijest                                                             */
	/* ------------------------------------------------------------------ */

	public static function incl_tax( $product, $price ) {
		if ( null === $price || '' === $price ) {
			return null;
		}
		return (float) wc_get_price_including_tax(
			$product,
			array(
				'price' => (float) $price,
				'qty'   => 1,
			)
		);
	}

	public static function on_saved( $product ) {
		if ( ! $product || $product->is_type( 'grouped' ) ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			// Objava varijabilnog proizvoda: varijacije spremljene dok je bio skica tek sad ulaze u ponudu.
			if ( 'publish' === $product->get_status() && SCJ_Settings::get( 'auto_nova_sidrena' ) ) {
				foreach ( $product->get_children() as $cid ) {
					$v = wc_get_product( $cid );
					if ( $v && self::maybe_auto_anchor( $v ) ) {
						$v->save_meta_data();
					}
				}
			}
			return;
		}
		if ( ! self::is_offered( $product ) ) {
			return;
		}
		if ( self::maybe_auto_anchor( $product ) ) {
			$product->save_meta_data();
		}
		$regular = '' === (string) $product->get_regular_price() ? null : self::incl_tax( $product, $product->get_regular_price() );
		$price   = '' === (string) $product->get_price() ? null : self::incl_tax( $product, $product->get_price() );
		SCJ_History::record( 'wc:' . $product->get_id(), $regular, $price );
	}

	/**
	 * Najniža cijena u 30 dana prije sniženja (za prikaz), s PDV-om.
	 */
	public static function lowest_30( $product ) {
		if ( ! $product->is_on_sale() ) {
			return null;
		}
		$current = self::incl_tax( $product, $product->get_price() );
		$from    = $product->get_date_on_sale_from();
		$lowest  = SCJ_History::omnibus_lowest( 'wc:' . $product->get_id(), $current, $from ? $from->getTimestamp() : null );
		if ( null === $lowest ) {
			// Nema povijesti: prije sniženja vrijedila je redovna cijena.
			$lowest = self::incl_tax( $product, $product->get_regular_price() );
		}
		return $lowest;
	}

	/* ------------------------------------------------------------------ */
	/* Prikaz uz cijenu                                                     */
	/* ------------------------------------------------------------------ */

	private static function to_display( $product, $incl_price ) {
		// Povijest je s PDV-om; prikaz slijedi postavku trgovine. Omjer se računa na referentnom iznosu
		// jer 'incl_tax(1)' daje 1 kad su cijene unesene s PDV-om, a prikazuju se bez njega.
		$ref_incl = self::incl_tax( $product, 100 );
		if ( $ref_incl <= 0 ) {
			return $incl_price;
		}
		return $incl_price * (float) wc_get_price_to_display( $product, array( 'price' => 100, 'qty' => 1 ) ) / $ref_incl;
	}

	/** @var bool Privremeno isključuje dodatke uz cijenu (kratki kod prikaz="cijena"). */
	public static $skip_price_html = false;

	public static function excluded( $product ) {
		if ( 'yes' === $product->get_meta( '_scj_iskljuci', true ) ) {
			return true;
		}
		return $product->is_type( 'variation' ) && 'yes' === get_post_meta( $product->get_parent_id(), '_scj_iskljuci', true );
	}

	/**
	 * Tekst ispred iznosa: zadani ili vlastiti ("{datum}" se zamjenjuje, "" = samo iznos).
	 */
	private static function label( $custom, $default, $ymd = '' ) {
		return null === $custom ? $default : trim( str_replace( '{datum}', SCJ_Format::date_hr( $ymd ), (string) $custom ) );
	}

	/**
	 * Sidrena cijena za prikaz (s PDV-om ili bez, prema postavci trgovine) i datum.
	 * Varijabilni proizvod: raspon, samo ako sve varijacije imaju isti datum.
	 *
	 * @return array{0: float|null, 1: float|null, 2: string} najmanja, najveća, datum
	 */
	public static function anchor_display( $product ) {
		if ( $product->is_type( 'grouped' ) ) {
			return array( null, null, '' );
		}
		if ( $product->is_type( 'variable' ) ) {
			$vals  = array();
			$dates = array();
			foreach ( $product->get_visible_children() as $cid ) {
				$c = wc_get_product( $cid );
				if ( ! $c ) {
					continue;
				}
				list( $a, $d ) = self::anchor( $c );
				if ( null !== $a ) {
					$vals[]      = (float) wc_get_price_to_display( $c, array( 'price' => $a ) );
					$dates[ $d ] = true;
				}
			}
			if ( ! $vals || 1 !== count( $dates ) ) {
				return array( null, null, '' );
			}
			return array( min( $vals ), max( $vals ), (string) key( $dates ) );
		}
		list( $a, $d ) = self::anchor( $product );
		if ( null === $a ) {
			return array( null, null, $d );
		}
		$v = (float) wc_get_price_to_display( $product, array( 'price' => $a ) );
		return array( $v, $v, $d );
	}

	/**
	 * Red "Cijena na dan 10. 9. 2026.: 40,00 €" ili ''.
	 */
	public static function anchor_html( $product, $label = null ) {
		list( $min, $max, $date ) = self::anchor_display( $product );
		if ( null === $min ) {
			return '';
		}
		$value = abs( $min - $max ) < 0.005 ? wc_price( $min ) : wc_format_price_range( $min, $max );
		$text  = self::label( $label, SCJ_Format::anchor_label( $date ), $date );
		return '<span class="scj-sidrena">' . ( '' === $text ? '' : esc_html( $text ) . ' ' ) . $value . '</span>';
	}

	/**
	 * Red "Najniža cijena u zadnjih 30 dana prije sniženja: 45,00 €" ili '' (samo kod akcije).
	 */
	public static function lowest_html( $product, $label = null ) {
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || ! $product->is_on_sale() ) {
			return '';
		}
		$low = self::lowest_30( $product );
		if ( null === $low ) {
			return '';
		}
		$text = self::label( $label, (string) SCJ_Settings::get( 'oznaka_najnize' ) );
		return '<span class="scj-najniza">' . ( '' === $text ? '' : esc_html( $text ) . ' ' ) . wc_price( self::to_display( $product, $low ) ) . '</span>';
	}

	/**
	 * Cijena proizvoda kakvu ispisuje WooCommerce, bez dodataka ovog plugina.
	 */
	public static function plain_price_html( $product ) {
		self::$skip_price_html = true;
		$html                  = $product->get_price_html();
		self::$skip_price_html = false;
		return $html;
	}

	public static function price_html( $html, $product ) {
		if ( self::$skip_price_html || '' === $html || ( is_admin() && ! wp_doing_ajax() ) || self::excluded( $product ) ) {
			return $html;
		}
		$extra  = SCJ_Settings::get( 'prikazi_sidrenu' ) ? self::anchor_html( $product ) : '';
		$extra .= SCJ_Settings::get( 'prikazi_najnizu' ) ? self::lowest_html( $product ) : '';
		return $extra ? $html . '<span class="scj-info">' . $extra . '</span>' : $html;
	}

	/* ------------------------------------------------------------------ */
	/* Admin stupac                                                         */
	/* ------------------------------------------------------------------ */

	public static function admin_column( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'price' === $k ) {
				$new['scj_sidrena'] = 'Sidrena';
			}
		}
		if ( ! isset( $new['scj_sidrena'] ) ) {
			$new['scj_sidrena'] = 'Sidrena';
		}
		return $new;
	}

	public static function admin_column_css() {
		if ( 'product' === get_current_screen()->post_type ) {
			echo '<style>.wp-list-table .column-scj_sidrena{width:10ch}</style>';
		}
	}

	public static function admin_column_value( $col, $post_id ) {
		if ( 'scj_sidrena' !== $col ) {
			return;
		}
		$p = wc_get_product( $post_id );
		if ( ! $p ) {
			return;
		}
		if ( 'yes' === $p->get_meta( '_scj_iskljuci', true ) ) {
			echo '<small>izvan cjenika</small>';
			return;
		}
		if ( $p->is_type( 'variable' ) ) {
			$missing = 0;
			$ids     = $p->get_children();
			foreach ( $ids as $cid ) {
				$c = wc_get_product( $cid );
				if ( $c && null === self::anchor( $c )[0] ) {
					$missing++;
				}
			}
			echo $missing ? '<span style="color:#b32d2e">' . (int) $missing . '/' . count( $ids ) . ' varijacija bez sidrene</span>' : '✓ ' . count( $ids ) . ' varijacija'; // phpcs:ignore
			return;
		}
		list( $a, $d ) = self::anchor( $p );
		if ( null === $a ) {
			echo '<span style="color:#b32d2e;font-weight:600">nedostaje</span>';
		} else {
			echo wc_price( $a ) . '<br><small>' . esc_html( SCJ_Format::date_hr( $d ) ) . '</small>'; // phpcs:ignore
		}
	}

	/* ------------------------------------------------------------------ */
	/* Podaci za cjenik                                                     */
	/* ------------------------------------------------------------------ */

	private static function brand( $product ) {
		$pid    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$source = (string) SCJ_Settings::get( 'marka_izvor' );
		$taxes  = array();
		if ( 'auto' === $source ) {
			$taxes = array( 'product_brand', 'pa_marka', 'pa_brand', 'pwb-brand', 'yith_product_brand' );
		} elseif ( 'meta' !== $source && '' !== $source ) {
			$taxes = array( $source );
		}
		foreach ( $taxes as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $pid, $tax );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return $terms[0]->name;
				}
			}
		}
		$meta = get_post_meta( $pid, '_scj_marka', true );
		return '' !== (string) $meta ? $meta : (string) SCJ_Settings::get( 'marka_zadana' );
	}

	private static function barcode( $product ) {
		$key = (string) SCJ_Settings::get( 'barkod_meta' );
		if ( '' !== $key ) {
			$v = $product->get_meta( $key, true );
			if ( '' === (string) $v && $product->is_type( 'variation' ) ) {
				$v = get_post_meta( $product->get_parent_id(), $key, true );
			}
			return (string) $v;
		}
		if ( self::has_gtin_field() && '' !== (string) $product->get_global_unique_id() ) {
			return (string) $product->get_global_unique_id();
		}
		return (string) $product->get_meta( '_scj_barkod', true );
	}

	private static function parent_meta( $product, $key ) {
		$v = $product->get_meta( $key, true );
		if ( '' === (string) $v && $product->is_type( 'variation' ) ) {
			$v = get_post_meta( $product->get_parent_id(), $key, true );
		}
		return (string) $v;
	}

	/**
	 * Redak cjenika za jedan jednostavni proizvod ili varijaciju.
	 */
	public static function row( $product ) {
		if ( '' === (string) $product->get_price() ) {
			return null;
		}
		$current  = self::incl_tax( $product, $product->get_price() );
		$regular  = '' === (string) $product->get_regular_price() ? $current : self::incl_tax( $product, $product->get_regular_price() );
		$on_sale  = $product->is_on_sale();
		list( $anchor, $date ) = self::anchor( $product );
		$jedinica = self::parent_meta( $product, '_scj_jedinica' );
		$jedinica = '' === $jedinica ? (string) SCJ_Settings::get( 'jedinica_zadana' ) : $jedinica;
		$kolicina = SCJ_Format::parse_price( self::parent_meta( $product, '_scj_kolicina' ) );
		if ( null === $kolicina && in_array( strtolower( $jedinica ), array( 'kom', 'komad', 'pak', 'kpl' ), true ) ) {
			$kolicina = 1.0;
		}
		$naziv = $on_sale ? self::parent_meta( $product, '_scj_akcija_naziv' ) : '';
		if ( $on_sale && '' === $naziv ) {
			$naziv = (string) SCJ_Settings::get( 'naziv_akcije' );
		}
		$tip = ( SCJ_Settings::get( 'virtualni_kao_usluge' ) && $product->is_virtual() ) ? 'usluga' : 'proizvod';

		return array(
			'item'            => 'wc:' . $product->get_id(),
			'tip'             => $tip,
			'naziv'           => wp_strip_all_tags( $product->get_name() ),
			'sifra'           => $product->get_sku() ? $product->get_sku() : 'WC-' . $product->get_id(),
			'marka'           => self::brand( $product ),
			'jedinica'        => $jedinica,
			'cijena_jedinica' => ( $kolicina && $kolicina > 0 ) ? $current / $kolicina : null,
			'redovna'         => $regular,
			'trenutna'        => $current,
			'na_akciji'       => $on_sale,
			'akcija_naziv'    => $naziv,
			'sidrena'         => null === $anchor ? null : self::incl_tax( $product, $anchor ),
			'datum'           => $date,
			'barkod'          => self::barcode( $product ),
			// Pojašnjenje Ministarstva: dostupno samo ako je stvarno na zalihi u trenu objave; "na narudžbu" je nedostupno.
			'dostupno'        => 'instock' === $product->get_stock_status(),
		);
	}

	/**
	 * Svi retci iz WooCommercea (generator, u serijama zbog memorije).
	 */
	public static function rows() {
		$page = 1;
		do {
			$ids = wc_get_products(
				array(
					'status'  => 'publish',
					'type'    => array( 'simple', 'variable', 'external' ),
					'limit'   => 200,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product || 'yes' === $product->get_meta( '_scj_iskljuci', true ) ) {
					continue;
				}
				if ( $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $cid ) {
						$v = wc_get_product( $cid );
						if ( ! $v || 'publish' !== $v->get_status() ) {
							continue;
						}
						$row = self::row( $v );
						if ( $row ) {
							yield $row;
						}
					}
				} else {
					$row = self::row( $product );
					if ( $row ) {
						yield $row;
					}
				}
			}
			$page++;
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $ids ) === 200 );
	}
}
