<?php
defined( 'ABSPATH' ) || exit;

/**
 * Alati: skupno popunjavanje sidrenih cijena, izvoz i uvoz sidrenih cijena (CSV).
 */
class SCJ_Tools {

	public static function init() {
		add_action( 'admin_post_scj_fill', array( __CLASS__, 'handle_fill' ) );
		add_action( 'admin_post_scj_export_anchors', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_scj_import_anchors', array( __CLASS__, 'handle_import' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( SCJ_Admin::cap() ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		check_admin_referer( $action );
	}

	private static function back( $args ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=scj-alati' ) ) );
		exit;
	}

	/**
	 * Svi WooCommerce proizvodi koji nose cijenu (jednostavni, vanjski, varijacije).
	 */
	public static function woo_priced_products() {
		if ( ! SCJ_Plugin::woo_active() ) {
			return;
		}
		$page = 1;
		do {
			$ids = wc_get_products(
				array(
					'status' => array( 'publish', 'private', 'draft', 'pending' ),
					'type'   => array( 'simple', 'variable', 'external' ),
					'limit'  => 200,
					'page'   => $page,
					'return' => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					continue;
				}
				if ( $p->is_type( 'variable' ) ) {
					foreach ( $p->get_children() as $cid ) {
						$v = wc_get_product( $cid );
						if ( $v ) {
							yield $v;
						}
					}
				} else {
					yield $p;
				}
			}
			$page++;
		} while ( count( $ids ) === 200 );
	}

	/**
	 * Izvan cjenika (varijacija nasljeđuje isključenje roditelja).
	 */
	private static function excluded( $p ) {
		if ( 'yes' === $p->get_meta( '_scj_iskljuci', true ) ) {
			return true;
		}
		return $p->is_type( 'variation' ) && 'yes' === get_post_meta( $p->get_parent_id(), '_scj_iskljuci', true );
	}

	/**
	 * Upisuje trenutnu redovnu cijenu kao sidrenu gdje sidrena nedostaje.
	 *
	 * @return array{woo:int, stavke:int}
	 */
	public static function fill_missing( $dry = false ) {
		$n = array(
			'woo'    => 0,
			'stavke' => 0,
		);
		foreach ( self::woo_priced_products() as $p ) {
			if ( '' !== (string) $p->get_meta( '_scj_sidrena', true ) || '' === (string) $p->get_regular_price() || self::excluded( $p ) ) {
				continue;
			}
			$n['woo']++;
			if ( ! $dry ) {
				$p->update_meta_data( '_scj_sidrena', wc_format_decimal( $p->get_regular_price() ) );
				$p->save_meta_data();
			}
		}
		foreach ( get_posts(
			array(
				'post_type'      => SCJ_Items::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $id ) {
			$cijena = get_post_meta( $id, '_scj_cijena', true );
			if ( '' !== (string) get_post_meta( $id, '_scj_sidrena', true ) || '' === (string) $cijena || '1' === (string) get_post_meta( $id, '_scj_iskljuci', true ) ) {
				continue;
			}
			$n['stavke']++;
			if ( ! $dry ) {
				update_post_meta( $id, '_scj_sidrena', $cijena );
			}
		}
		if ( ! $dry ) {
			SCJ_Rest::flush();
		}
		return $n;
	}

	public static function handle_fill() {
		self::guard( 'scj_fill' );
		$n = self::fill_missing( false );
		self::back(
			array(
				'scj_msg' => 'fill',
				'woo'     => $n['woo'],
				'st'      => $n['stavke'],
			)
		);
	}

	/** Stupci izvoza; uvoz prepoznaje iste nazive (redoslijed nije bitan). */
	const COLUMNS = array( 'vrsta', 'id', 'sifra', 'naziv', 'tip', 'redovna_cijena', 'akcijska_cijena', 'akcija_naziv', 'akcija_od', 'akcija_do', 'sidrena_cijena', 'datum_sidrene_cijene', 'jedinica', 'napomena', 'grupa', 'redoslijed', 'marka', 'barkod', 'neto_kolicina', 'dostupno', 'iskljuci' );

	public static function handle_export() {
		self::guard( 'scj_export_anchors' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=sidrene-cijene-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel treba BOM za hrvatska slova.
		fputcsv( $out, self::COLUMNS, ';', '"', '' );
		$row = function ( array $values ) use ( $out ) {
			$line = array();
			foreach ( self::COLUMNS as $c ) {
				$line[] = isset( $values[ $c ] ) ? $values[ $c ] : '';
			}
			fputcsv( $out, $line, ';', '"', '' );
		};
		foreach ( self::woo_priced_products() as $p ) {
			$row(
				array(
					'vrsta'                => 'woo',
					'id'                   => $p->get_id(),
					'sifra'                => $p->get_sku(),
					'naziv'                => wp_strip_all_tags( $p->get_name() ),
					'redovna_cijena'       => wc_format_localized_price( $p->get_regular_price() ),
					'sidrena_cijena'       => wc_format_localized_price( $p->get_meta( '_scj_sidrena', true ) ),
					'datum_sidrene_cijene' => $p->get_meta( '_scj_datum', true ),
				)
			);
		}
		foreach ( get_posts(
			array(
				'post_type'      => SCJ_Items::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		) as $post ) {
			$d     = SCJ_Items::data( $post );
			$m     = function ( $k ) use ( $post ) {
				return (string) get_post_meta( $post->ID, '_scj_' . $k, true );
			};
			$terms = get_the_terms( $post->ID, SCJ_Items::TAX );
			$row(
				array(
					'vrsta'                => 'stavka',
					'id'                   => $post->ID,
					'sifra'                => $m( 'sifra' ),
					'naziv'                => $d['naziv'],
					'tip'                  => $d['tip'],
					'redovna_cijena'       => SCJ_Format::number( $d['redovna'] ),
					'akcijska_cijena'      => SCJ_Format::number( SCJ_Format::parse_price( $m( 'akcijska' ) ) ),
					'akcija_naziv'         => $m( 'akcija_naziv' ),
					'akcija_od'            => $m( 'akcija_od' ),
					'akcija_do'            => $m( 'akcija_do' ),
					'sidrena_cijena'       => SCJ_Format::number( $d['sidrena'] ),
					'datum_sidrene_cijene' => $m( 'datum' ),
					'jedinica'             => $m( 'jedinica' ),
					'napomena'             => $m( 'napomena' ),
					'grupa'                => ( $terms && ! is_wp_error( $terms ) ) ? implode( ' | ', wp_list_pluck( $terms, 'name' ) ) : '',
					'redoslijed'           => $post->menu_order,
					'marka'                => $m( 'marka' ),
					'barkod'               => $m( 'barkod' ),
					'neto_kolicina'        => SCJ_Format::number( SCJ_Format::parse_price( $m( 'kolicina' ) ) ),
					'dostupno'             => $d['dostupno'] ? 'da' : 'ne',
					'iskljuci'             => $d['iskljuci'] ? 'da' : 'ne',
				)
			);
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/** Datum iz CSV-a: 2026-09-10, 10. 9. 2026., 10.09.2026 ... */
	private static function parse_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		$ts = strtotime( rtrim( str_replace( ' ', '', $date ), '.' ) );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	private static function yes_no( $v ) {
		return in_array( strtolower( trim( (string) $v ) ), array( 'da', '1', 'yes', 'y', 'true', 'x' ), true ) ? '1' : '0';
	}

	/**
	 * Uvoz CSV-a (isti stupci kao izvoz; dovoljni su oni koji se mijenjaju).
	 *
	 * - WooCommerce proizvod (id ili sifra = SKU): upisuje se samo sidrena cijena i datum.
	 * - Stavka cjenika (id, sifra ili točan naziv): ažuriraju se popunjena polja; "-" briše vrijednost.
	 * - Nepoznata stavka s nazivom i cijenom: stvara se nova (objavljena), redoslijed prema retku u datoteci.
	 * Prazna sidrena cijena kod uvoza stavki ostaje prazna (Alati -> Popuni), ne upisuje se današnja.
	 *
	 * @return array{ok:int, created:int, skip:int, errors:string[]}
	 */
	public static function import_file( $path ) {
		$res = array(
			'ok'      => 0,
			'created' => 0,
			'skip'    => 0,
			'errors'  => array(),
		);
		$h   = is_readable( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore
		if ( ! $h ) {
			$res['errors'][] = 'Datoteku nije moguće otvoriti.';
			return $res;
		}
		$first = fgets( $h );
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $first );
		$delim = substr_count( $first, ';' ) >= substr_count( $first, ',' ) ? ';' : ',';
		$head  = array_map( 'strtolower', array_map( 'trim', str_getcsv( $first, $delim, '"', '' ) ) );
		$alias = array(
			'cijena'   => 'redovna_cijena',
			'kolicina' => 'neto_kolicina',
		);
		$col   = array();
		foreach ( $head as $i => $name ) {
			$col[ isset( $alias[ $name ] ) ? $alias[ $name ] : $name ] = $i;
		}
		if ( ! isset( $col['id'] ) && ! isset( $col['sifra'] ) && ! isset( $col['naziv'] ) ) {
			fclose( $h ); // phpcs:ignore
			$res['errors'][] = 'Zaglavlje mora imati barem jedan od stupaca id, sifra ili naziv.';
			return $res;
		}

		SCJ_Items::$no_auto_anchor = true;
		SCJ_Anchor_Log::$source    = 'uvoz CSV';
		$line = 1;
		while ( ( $r = fgetcsv( $h, 0, $delim, '"', '' ) ) !== false ) {
			$line++;
			if ( array( null ) === $r || '' === trim( implode( '', $r ) ) ) {
				continue;
			}
			$get   = function ( $k ) use ( $r, $col ) {
				return isset( $col[ $k ], $r[ $col[ $k ] ] ) ? trim( $r[ $col[ $k ] ] ) : '';
			};
			$vrsta = strtolower( $get( 'vrsta' ) );
			$id    = absint( $get( 'id' ) );
			$sifra = $get( 'sifra' );
			$naziv = sanitize_text_field( $get( 'naziv' ) );

			// 1) WooCommerce proizvod: samo sidrena cijena i datum.
			$pid = 0;
			if ( 'stavka' !== $vrsta && SCJ_Plugin::woo_active() ) {
				if ( $id && wc_get_product( $id ) ) {
					$pid = $id;
				} elseif ( '' !== $sifra ) {
					$pid = (int) wc_get_product_id_by_sku( $sifra );
				}
			}
			if ( $pid ) {
				$anchor = SCJ_Format::parse_price( $get( 'sidrena_cijena' ) );
				if ( null === $anchor ) {
					$res['skip']++;
					continue;
				}
				$p    = wc_get_product( $pid );
				$date = self::parse_date( $get( 'datum_sidrene_cijene' ) );
				$p->update_meta_data( '_scj_sidrena', wc_format_decimal( $anchor ) );
				if ( '' !== $date ) {
					$p->update_meta_data( '_scj_datum', $date );
				}
				$p->save_meta_data();
				$res['ok']++;
				continue;
			}
			if ( 'woo' === $vrsta ) {
				$res['errors'][] = "Redak {$line}: nije pronađen WooCommerce proizvod (id {$id}, šifra {$sifra}).";
				continue;
			}

			// 2) Stavka cjenika: postojeća po id-u, šifri ili točnom nazivu.
			$sid = ( $id && SCJ_Items::CPT === get_post_type( $id ) ) ? $id : 0;
			if ( ! $sid && '' !== $sifra ) {
				$sid = SCJ_Items::id_by_sifra( $sifra );
			}
			if ( ! $sid && ! $id && '' === $sifra && '' !== $naziv ) {
				$same = get_posts(
					array(
						'post_type'      => SCJ_Items::CPT,
						'post_status'    => 'any',
						'title'          => $naziv,
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);
				$sid = $same ? (int) $same[0] : 0;
			}
			$created = false;
			if ( ! $sid ) {
				if ( '' === $naziv || null === SCJ_Format::parse_price( $get( 'redovna_cijena' ) ) ) {
					$res['errors'][] = "Redak {$line}: stavka nije pronađena" . ( $id || '' !== $sifra ? " (id {$id}, šifra {$sifra})" : '' ) . ', a za novu trebaju naziv i redovna_cijena.';
					continue;
				}
				$sid = wp_insert_post(
					array(
						'post_type'   => SCJ_Items::CPT,
						'post_status' => 'publish',
						'post_title'  => $naziv,
						'menu_order'  => '' !== $get( 'redoslijed' ) ? (int) $get( 'redoslijed' ) : $line,
					),
					true
				);
				if ( is_wp_error( $sid ) ) {
					$res['errors'][] = "Redak {$line}: " . $sid->get_error_message();
					continue;
				}
				update_post_meta( $sid, '_scj_tip', 'usluga' );
				update_post_meta( $sid, '_scj_dostupno', '1' );
				update_post_meta( $sid, '_scj_iskljuci', '0' );
				$created = true;
			} else {
				$update = array( 'ID' => $sid );
				if ( '' !== $naziv && get_post_field( 'post_title', $sid ) !== $naziv ) {
					$update['post_title'] = $naziv;
				}
				if ( '' !== $get( 'redoslijed' ) ) {
					$update['menu_order'] = (int) $get( 'redoslijed' );
				}
				if ( count( $update ) > 1 ) {
					wp_update_post( $update );
				}
			}

			$fields = array(
				'sifra'                => array( 'sifra', 'text' ),
				'tip'                  => array( 'tip', 'tip' ),
				'redovna_cijena'       => array( 'cijena', 'price' ),
				'akcijska_cijena'      => array( 'akcijska', 'price' ),
				'akcija_naziv'         => array( 'akcija_naziv', 'text' ),
				'akcija_od'            => array( 'akcija_od', 'date' ),
				'akcija_do'            => array( 'akcija_do', 'date' ),
				'sidrena_cijena'       => array( 'sidrena', 'price' ),
				'datum_sidrene_cijene' => array( 'datum', 'date' ),
				'jedinica'             => array( 'jedinica', 'text' ),
				'napomena'             => array( 'napomena', 'text' ),
				'marka'                => array( 'marka', 'text' ),
				'barkod'               => array( 'barkod', 'text' ),
				'neto_kolicina'        => array( 'kolicina', 'price' ),
				'dostupno'             => array( 'dostupno', 'bool' ),
				'iskljuci'             => array( 'iskljuci', 'bool' ),
			);
			foreach ( $fields as $column => $def ) {
				$raw = $get( $column );
				if ( '' === $raw ) {
					continue;
				}
				if ( '-' === $raw ) {
					update_post_meta( $sid, '_scj_' . $def[0], '' );
					continue;
				}
				switch ( $def[1] ) {
					case 'price':
						$v = SCJ_Format::parse_price( $raw );
						if ( null === $v ) {
							$res['errors'][] = "Redak {$line}: {$column} \"{$raw}\" nije broj, preskočeno.";
							continue 2;
						}
						$v = (string) $v;
						break;
					case 'date':
						$v = self::parse_date( $raw );
						break;
					case 'bool':
						$v = self::yes_no( $raw );
						break;
					case 'tip':
						$v = 0 === strpos( strtolower( $raw ), 'proizvod' ) ? 'proizvod' : 'usluga';
						break;
					default:
						$v = sanitize_text_field( $raw );
				}
				update_post_meta( $sid, '_scj_' . $def[0], $v );
			}
			if ( '' !== $get( 'grupa' ) ) {
				$groups = '-' === $get( 'grupa' ) ? array() : array_filter( array_map( 'trim', explode( '|', $get( 'grupa' ) ) ) );
				wp_set_object_terms( $sid, array_map( 'sanitize_text_field', $groups ), SCJ_Items::TAX );
			}

			SCJ_Items::after_change( $sid );
			$created ? $res['created']++ : $res['ok']++;
		}
		SCJ_Items::$no_auto_anchor = false;
		SCJ_Anchor_Log::$source    = null;
		fclose( $h ); // phpcs:ignore
		SCJ_Rest::flush();
		return $res;
	}

	public static function handle_import() {
		self::guard( 'scj_import_anchors' );
		if ( empty( $_FILES['scj_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['scj_file']['tmp_name'] ) ) { // phpcs:ignore
			self::back( array( 'scj_msg' => 'nofile' ) );
		}
		$res = self::import_file( $_FILES['scj_file']['tmp_name'] ); // phpcs:ignore
		set_transient( 'scj_import_result_' . get_current_user_id(), $res, 10 * MINUTE_IN_SECONDS );
		self::back( array( 'scj_msg' => 'import' ) );
	}
}
