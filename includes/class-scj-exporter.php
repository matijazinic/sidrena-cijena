<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pisanje jedne datoteke cjenika (CSV ili XML) u stream.
 */
class SCJ_Writer {

	/** @var resource */
	public $handle;
	public $format;
	public $tip;
	public $count = 0;
	public $path  = '';
	public $tmp   = '';
	private $columns;
	private $sep;

	public static function columns( $tip ) {
		$cols = 'usluge' === $tip
			? array( 'naziv', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'bazna_cijena' )
			: array( 'naziv', 'sifra', 'marka', 'neto_kolicina', 'jedinica_mjere', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'cijena_za_jedinicu_mjere', 'barkod', 'ean_barkod', 'bazna_cijena' );
		// Pravilnik o načinu objave cjenika proizvoda (NN 132/2026), čl. 3. st. 2.-4.: redoslijed stupaca je propisan.
		// Dodatne vrijednosti ('naziv_posebnog_oblika_prodaje', 'datum_sidrene_cijene', 'dostupnost', 'sidrena_cijena')
		// i dalje postoje u retku i mogu se dodati ovim filterom.
		return apply_filters( 'scj_columns', $cols, $tip );
	}

	public function __construct( $handle, $format, $tip, array $meta ) {
		$this->handle  = $handle;
		$this->format  = $format;
		$this->tip     = $tip;
		$this->columns = self::columns( $tip );
		$this->sep     = (string) SCJ_Settings::get( 'decimalni_separator' );

		if ( 'csv' === $format ) {
			if ( SCJ_Settings::get( 'csv_bom' ) ) {
				fwrite( $this->handle, "\xEF\xBB\xBF" );
			}
			fputcsv( $this->handle, $this->columns, ';', '"', '' );
		} else {
			$root  = 'usluge' === $tip ? 'usluge' : 'proizvodi';
			$xml   = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			$xml  .= '<cjenik vrsta="' . $root . '" vrijeme_objave="' . self::x( $meta['vrijeme'] ) . '">' . "\n";
			$xml  .= "  <trgovac>\n";
			foreach ( array( 'naziv' => 'naziv_trgovca', 'oib' => 'oib', 'oblik_prodajnog_objekta' => 'oblik_objekta', 'adresa_prodajnog_objekta' => 'adresa', 'oznaka_prodajnog_objekta' => 'oznaka', 'broj_pohrane' => 'broj_pohrane' ) as $tag => $key ) {
				$xml .= '    <' . $tag . '>' . self::x( isset( $meta[ $key ] ) ? $meta[ $key ] : '' ) . '</' . $tag . ">\n";
			}
			$xml .= "  </trgovac>\n  <{$root}>\n";
			fwrite( $this->handle, $xml );
		}
	}

	private static function x( $v ) {
		return htmlspecialchars( (string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	public function values( array $r ) {
		$sep = 'xml' === $this->format ? '.' : $this->sep;
		$barkod = isset( $r['barkod'] ) ? trim( (string) $r['barkod'] ) : '';
		$ean    = (bool) preg_match( '/^(\d{8}|\d{12,14})$/', $barkod );
		$all    = array(
			'naziv'                         => $r['naziv'],
			'sifra'                         => isset( $r['sifra'] ) ? $r['sifra'] : '',
			'marka'                         => isset( $r['marka'] ) ? $r['marka'] : '',
			'jedinica_mjere'                => isset( $r['jedinica'] ) ? $r['jedinica'] : '',
			'cijena_za_jedinicu_mjere'      => isset( $r['cijena_jedinica'] ) ? SCJ_Format::number( $r['cijena_jedinica'], $sep ) : '',
			'maloprodajna_cijena'           => SCJ_Format::number( $r['trenutna'], $sep ),
			'posebni_oblik_prodaje'         => $r['na_akciji'] ? 'da' : 'ne',
			'naziv_posebnog_oblika_prodaje' => $r['na_akciji'] ? $r['akcija_naziv'] : '',
			'neto_kolicina'                 => isset( $r['kolicina'] ) && $r['kolicina'] > 0 ? SCJ_Format::number( $r['kolicina'], $sep ) : '',
			'bazna_cijena'                  => SCJ_Format::number( $r['sidrena'], $sep ),
			'sidrena_cijena'                => SCJ_Format::number( $r['sidrena'], $sep ),
			'datum_sidrene_cijene'          => isset( $r['datum'] ) ? $r['datum'] : '',
			// Pravilnik razlikuje "barkod" i "EAN barkod": kod od 8, 12, 13 ili 14 znamenki ide u EAN stupac, ostalo u barkod.
			'barkod'                        => $ean ? '' : $barkod,
			'ean_barkod'                    => $ean ? $barkod : '',
			'dostupnost'                    => ! isset( $r['dostupno'] ) || $r['dostupno'] ? 'dostupno' : 'nedostupno',
		);
		$all = apply_filters( 'scj_row_values', $all, $r, $this->tip );
		$out = array();
		foreach ( $this->columns as $c ) {
			$out[ $c ] = isset( $all[ $c ] ) ? (string) $all[ $c ] : '';
		}
		return $out;
	}

	public function write( array $row ) {
		$vals = $this->values( $row );
		if ( 'csv' === $this->format ) {
			fputcsv( $this->handle, array_values( $vals ), ';', '"', '' );
		} else {
			$el  = 'usluge' === $this->tip ? 'usluga' : 'proizvod';
			$xml = "    <{$el}>";
			foreach ( $vals as $k => $v ) {
				$xml .= "<{$k}>" . self::x( $v ) . "</{$k}>";
			}
			fwrite( $this->handle, $xml . "</{$el}>\n" );
		}
		$this->count++;
	}

	public function finish() {
		if ( 'xml' === $this->format ) {
			$root = 'usluge' === $this->tip ? 'usluge' : 'proizvodi';
			fwrite( $this->handle, "  </{$root}>\n</cjenik>\n" );
		}
	}
}

/**
 * Objava cjenika: datoteke s propisanim nazivom, arhiva 30+ dana, popis objava.
 */
class SCJ_Exporter {

	const DIR = 'cjenici';

	public static function dir() {
		$u = wp_upload_dir( null, false );
		return array(
			'path' => trailingslashit( $u['basedir'] ) . self::DIR,
			'url'  => trailingslashit( $u['baseurl'] ) . self::DIR,
		);
	}

	public static function ensure_dir() {
		$d = self::dir();
		if ( ! is_dir( $d['path'] ) ) {
			wp_mkdir_p( $d['path'] );
		}
		if ( ! file_exists( $d['path'] . '/index.html' ) ) {
			self::write_index();
		}
		return is_dir( $d['path'] ) && wp_is_writable( $d['path'] );
	}

	/**
	 * Svi izvori redaka (WooCommerce + stavke cjenika).
	 */
	public static function all_rows() {
		if ( SCJ_Plugin::woo_active() ) {
			foreach ( SCJ_Woo::rows() as $row ) {
				yield $row;
			}
		}
		foreach ( SCJ_Items::ids() as $id ) {
			$d = SCJ_Items::data( $id );
			if ( ! $d || $d['iskljuci'] || null === $d['trenutna'] ) {
				continue;
			}
			$row                    = $d;
			$row['cijena_jedinica'] = ( $d['kolicina'] && $d['kolicina'] > 0 ) ? $d['trenutna'] / $d['kolicina'] : ( 'proizvod' === $d['tip'] ? $d['trenutna'] : null );
			if ( 'proizvod' === $d['tip'] && '' === $row['jedinica'] ) {
				$row['jedinica'] = (string) SCJ_Settings::get( 'jedinica_zadana' );
			}
			yield $row;
		}
	}

	/**
	 * Naziv prema Pravilniku o načinu objave cjenika proizvoda (NN 132/2026), čl. 6. st. 3. i 4.:
	 * OIB (pravna osoba) ili matični broj (fizička osoba), prodajno mjesto, adresa, oznaka, roba ili usluge,
	 * broj pohrane, vremenska oznaka GGGGMMDD_SSMM, npr.
	 * "12345678903_prodavaonica_Ilica 150 Zagreb_P-01_roba_104_20261117_0745.csv".
	 * Isti cjenik za sva prodajna mjesta: "12345678903_ZA_SVA_PRODAJNA_MJESTA_roba_104_20261117_0745.csv".
	 */
	public static function file_name( $tip, $broj, DateTimeImmutable $time, $ext ) {
		$id    = trim( (string) SCJ_Settings::get( 'maticni_broj' ) );
		$id    = '' !== $id ? $id : trim( (string) SCJ_Settings::get( 'oib' ) );
		$parts = '' !== $id ? array( SCJ_Format::name_part( $id ) ) : array();
		if ( SCJ_Settings::get( 'sva_mjesta' ) ) {
			$parts[] = 'ZA_SVA_PRODAJNA_MJESTA';
		} else {
			$parts[] = SCJ_Format::name_part( SCJ_Settings::get( 'oblik_objekta' ) );
			$parts[] = SCJ_Format::name_part( SCJ_Settings::get( 'adresa' ) );
			$parts[] = SCJ_Format::name_part( SCJ_Settings::get( 'oznaka' ) );
		}
		$parts[] = 'usluge' === $tip ? 'usluge' : 'roba';
		$parts[] = (string) (int) $broj;
		$parts[] = $time->format( 'Ymd' );
		$parts[] = $time->format( 'Hi' );
		return apply_filters( 'scj_file_name', implode( '_', $parts ) . '.' . $ext, $tip, $broj, $time, $ext );
	}

	private static function meta( $broj, DateTimeImmutable $time ) {
		return array(
			'vrijeme'       => $time->format( 'c' ),
			'naziv_trgovca' => SCJ_Settings::get( 'naziv_trgovca' ),
			'oib'           => SCJ_Settings::get( 'oib' ),
			'oblik_objekta' => SCJ_Settings::get( 'oblik_objekta' ),
			'adresa'        => SCJ_Settings::get( 'adresa' ),
			'oznaka'        => SCJ_Settings::get( 'oznaka' ),
			'broj_pohrane'  => $broj,
		);
	}

	/**
	 * Glavna objava. Vraća true ako je barem jedna datoteka objavljena bez greške.
	 */
	public static function publish( $reason = 'ručno' ) {
		if ( get_transient( 'scj_lock' ) ) {
			return false;
		}
		set_transient( 'scj_lock', 1, 10 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Zakazane akcije (od-do) WooCommerce uključuje/isključuje svojim cronom oko ponoći; ako još nije stigao,
		// napravi to sada da cjenik ne objavi jučerašnje cijene.
		if ( SCJ_Plugin::woo_active() && function_exists( 'wc_scheduled_sales' ) ) {
			wc_scheduled_sales();
		}

		$started = microtime( true );
		$errors  = array();
		$files   = array();
		$counts  = array(
			'proizvodi' => 0,
			'usluge'    => 0,
		);
		$missing = 0;

		try {
			SCJ_Settings::flush_cache();
			$miss = SCJ_Settings::missing();
			if ( $miss ) {
				$errors[] = 'Nedostaju postavke: ' . implode( ', ', $miss ) . '.';
			}
			if ( ! self::ensure_dir() ) {
				throw new Exception( 'Mapa za cjenike nije dostupna za pisanje: ' . self::dir()['path'] );
			}

			$broj    = (int) get_option( 'scj_counter', 0 ) + 1;
			$time    = SCJ_Scheduler::now();
			$meta    = self::meta( $broj, $time );
			$formats = (array) SCJ_Settings::get( 'formati' );
			$dir     = self::dir();
			$writers = array();
			$nums    = array();

			foreach ( self::all_rows() as $row ) {
				$tip = 'usluga' === $row['tip'] ? 'usluge' : 'proizvodi';
				if ( ! isset( $writers[ $tip ] ) ) {
					// Svaka vrsta cjenika je zasebna datoteka sa svojim brojem pohrane (CSV i XML iste vrste dijele broj).
					$nums[ $tip ]    = $broj + count( $writers );
					$writers[ $tip ] = array();
					$tip_meta        = array_merge( $meta, array( 'broj_pohrane' => $nums[ $tip ] ) );
					foreach ( $formats as $fmt ) {
						// Privremeni naziv; konačni se određuje na kraju, nakon zatvaranja datoteke.
						$tmp = $dir['path'] . '/.scj-' . $broj . '-' . $tip . '.' . $fmt . '.tmp';
						$h   = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
						if ( ! $h ) {
							throw new Exception( 'Ne mogu stvoriti privremenu datoteku u ' . $dir['path'] );
						}
						$w                 = new SCJ_Writer( $h, $fmt, $tip, $tip_meta );
						$w->tmp            = $tmp;
						$writers[ $tip ][] = $w;
					}
				}
				foreach ( $writers[ $tip ] as $w ) {
					$w->write( $row );
				}
				$counts[ $tip ]++;
				if ( null === $row['sidrena'] ) {
					$missing++;
				}
				SCJ_History::record( $row['item'], $row['redovna'], $row['trenutna'] );
			}

			foreach ( $writers as $tip => $list ) {
				foreach ( $list as $w ) {
					$w->finish();
					fclose( $w->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$name = self::file_name( $tip, $nums[ $tip ], $time, $w->format );
					$path = $dir['path'] . '/' . $name;
					$i    = 1;
					while ( file_exists( $path ) ) {
						$path = $dir['path'] . '/' . preg_replace( '/\.' . $w->format . '$/', '-' . ( ++$i ) . '.' . $w->format, $name );
					}
					// Atomično preimenovanje: robot nikad ne vidi napola zapisanu datoteku.
					if ( ! rename( $w->tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
						throw new Exception( 'Ne mogu spremiti ' . $name );
					}
					$w->path = $path;
					$files[] = basename( $path );
				}
			}

			if ( ! $files ) {
				$errors[] = 'Nema proizvoda ni usluga za objavu.';
			} else {
				update_option( 'scj_counter', max( $nums ), false );
			}
			if ( $missing ) {
				$errors[] = $missing . ' ' . SCJ_Format::plural( $missing, 'stavka nema', 'stavke nemaju', 'stavki nema' ) . ' upisanu sidrenu cijenu.';
			}
		} catch ( Throwable $e ) {
			$errors[] = $e->getMessage();
			foreach ( isset( $writers ) ? $writers : array() as $list ) {
				foreach ( $list as $w ) {
					if ( is_resource( $w->handle ) ) {
						fclose( $w->handle ); // phpcs:ignore
					}
					@unlink( $w->tmp ); // phpcs:ignore
				}
			}
		}

		$ok = ! empty( $files );
		if ( $ok ) {
			$now = SCJ_Scheduler::now();
			update_option( 'scj_last_success', time(), false );
			// Važeći cjenik = datoteke zadnje objave. Vrsta koja se više ne objavljuje ostaje samo u arhivi.
			update_option( 'scj_current_files', array_values( $files ), false );
			$first = get_option( 'scj_first_success_today' );
			if ( ! is_array( $first ) || ( isset( $first['date'] ) && $first['date'] !== $now->format( 'Y-m-d' ) ) ) {
				update_option(
					'scj_first_success_today',
					array(
						'date' => $now->format( 'Y-m-d' ),
						'time' => $now->format( 'H:i' ),
					),
					false
				);
			}
			self::cleanup();
			self::write_index();
		}

		self::log(
			array(
				'time'    => time(),
				'reason'  => $reason,
				'ok'      => $ok,
				'files'   => $files,
				'counts'  => $counts,
				'missing' => $missing,
				'errors'  => $errors,
				'sec'     => round( microtime( true ) - $started, 2 ),
			)
		);
		delete_transient( 'scj_lock' );
		do_action( 'scj_published', $ok, $files, $errors );
		return $ok;
	}

	private static function log( array $entry ) {
		$log   = get_option( 'scj_log', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		if ( count( $log ) > 40 ) {
			$log = array_slice( $log, -40 );
		}
		update_option( 'scj_log', $log, false );
	}

	/**
	 * Objavljene datoteke, najnovije prve.
	 */
	public static function files() {
		$d   = self::dir();
		$out = array();
		if ( ! is_dir( $d['path'] ) ) {
			return $out;
		}
		foreach ( (array) scandir( $d['path'] ) as $f ) {
			if ( ! preg_match( '/\.(csv|xml)$/', $f, $m ) ) {
				continue;
			}
			$p     = $d['path'] . '/' . $f;
			$out[] = array(
				'name'   => $f,
				'url'    => $d['url'] . '/' . rawurlencode( $f ),
				'format' => $m[1],
				'tip'    => self::detect_tip( $p, $f, $m[1] ),
				'mtime'  => filemtime( $p ),
				'size'   => filesize( $p ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['mtime'] <=> $a['mtime'] ?: strcmp( $b['name'], $a['name'] );
			}
		);
		return $out;
	}

	/**
	 * Vrsta cjenika iz naziva (stari formati: _proizvodi / _usluge) ili iz sadržaja datoteke (današnji naziv je nema).
	 */
	private static function detect_tip( $path, $name, $format ) {
		static $cache = array();
		if ( false !== strpos( $name, '_usluge' ) ) {
			return 'usluge';
		}
		if ( false !== strpos( $name, '_proizvodi' ) || false !== strpos( $name, '_roba_' ) ) {
			return 'proizvodi';
		}
		$key = $name . filemtime( $path );
		if ( ! isset( $cache[ $key ] ) ) {
			$head = (string) file_get_contents( $path, false, null, 0, 400 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( 'xml' === $format ) {
				$cache[ $key ] = false !== strpos( $head, 'vrsta="usluge"' ) ? 'usluge' : 'proizvodi';
			} else {
				$line          = trim( strtok( preg_replace( '/^\xEF\xBB\xBF/', '', $head ), "\n" ) );
				// Starije datoteke (do 1.1.x) nemaju vrstu u nazivu: cjenik usluga nema stupac šifre ni jedinice mjere.
				$cols          = explode( ';', $line );
				$cache[ $key ] = ( in_array( 'sifra', $cols, true ) || in_array( 'jedinica_mjere', $cols, true ) ) ? 'proizvodi' : 'usluge';
			}
		}
		return $cache[ $key ];
	}

	/**
	 * Najnovija datoteka po vrsti i formatu.
	 */
	public static function latest( $tip, $format ) {
		foreach ( self::files() as $f ) {
			if ( $f['tip'] === $tip && $f['format'] === $format ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Važeća datoteka po vrsti i formatu: iz zadnje uspješne objave. Ako vrsta u zadnjoj objavi nije bila
	 * (npr. trgovac više nema usluga ili su virtualni proizvodi prebačeni u usluge), vraća null.
	 * Instalacije bez zapisa zadnje objave (prije 1.1.1) vraćaju najnoviju datoteku.
	 */
	public static function current( $tip, $format ) {
		$names = get_option( 'scj_current_files', null );
		if ( ! is_array( $names ) || ! $names ) {
			return self::latest( $tip, $format );
		}
		foreach ( self::files() as $f ) {
			if ( $f['tip'] === $tip && $f['format'] === $format && in_array( $f['name'], $names, true ) ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Briše datoteke starije od zadanog broja dana, ali nikad zadnju po vrsti i formatu.
	 */
	public static function cleanup() {
		$days   = max( 31, (int) SCJ_Settings::get( 'zadrzi_dana' ) );
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$keep   = array();
		$d      = self::dir();
		foreach ( self::files() as $f ) {
			$k = $f['tip'] . $f['format'];
			if ( ! isset( $keep[ $k ] ) ) {
				$keep[ $k ] = true;
				continue;
			}
			if ( $f['mtime'] < $cutoff ) {
				wp_delete_file( $d['path'] . '/' . $f['name'] );
			}
		}
	}

	/**
	 * index.html i index.json u mapi cjenika (javni popis objava).
	 */
	public static function write_index() {
		$d = self::dir();
		if ( ! is_dir( $d['path'] ) ) {
			return;
		}
		$files = self::files();
		$json  = array(
			'trgovac'  => SCJ_Settings::get( 'naziv_trgovca' ),
			'azurirano' => SCJ_Scheduler::now()->format( 'c' ),
			'cjenici'  => array_map(
				function ( $f ) {
					return array(
						'datoteka' => $f['name'],
						'url'      => $f['url'],
						'vrsta'    => $f['tip'],
						'format'   => $f['format'],
						'objavljeno' => wp_date( 'c', $f['mtime'] ),
					);
				},
				$files
			),
		);
		file_put_contents( $d['path'] . '/index.json', wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore

		$html  = '<!doctype html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		$html .= '<title>Cjenici – ' . esc_html( SCJ_Settings::get( 'naziv_trgovca' ) ) . '</title>';
		$html .= '<style>body{font-family:system-ui,sans-serif;max-width:860px;margin:2rem auto;padding:0 1rem;color:#1d2327}table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:.4rem .6rem;border-bottom:1px solid #ddd;font-size:.95rem}</style></head><body>';
		$html .= '<h1>Cjenici</h1><p>' . esc_html( SCJ_Settings::get( 'naziv_trgovca' ) ) . ' · ' . esc_html( SCJ_Settings::get( 'oblik_objekta' ) ) . ', ' . esc_html( SCJ_Settings::get( 'adresa' ) ) . '</p>';
		$html .= '<p>Objava cjenika prema Pravilniku o načinu objave cjenika proizvoda (NN 132/2026). Strojno čitljiv popis: <a href="index.json">index.json</a></p>';
		$html .= '<table><thead><tr><th>Datoteka</th><th>Vrsta</th><th>Objavljeno</th></tr></thead><tbody>';
		foreach ( $files as $f ) {
			$html .= '<tr><td><a href="' . esc_attr( rawurlencode( $f['name'] ) ) . '">' . esc_html( $f['name'] ) . '</a></td><td>' . esc_html( $f['tip'] ) . '</td><td>' . esc_html( wp_date( 'j. n. Y. H:i', $f['mtime'] ) ) . '</td></tr>';
		}
		$html .= '</tbody></table></body></html>';
		file_put_contents( $d['path'] . '/index.html', $html ); // phpcs:ignore
	}

	/**
	 * Cjenik generiran u trenutku zahtjeva (za REST krajnju točku).
	 */
	public static function build_live( $tip, $format ) {
		$h    = fopen( 'php://temp', 'w+b' ); // phpcs:ignore
		$time = SCJ_Scheduler::now();
		$w    = new SCJ_Writer( $h, $format, $tip, self::meta( (int) get_option( 'scj_counter', 0 ), $time ) );
		foreach ( self::all_rows() as $row ) {
			$row_tip = 'usluga' === $row['tip'] ? 'usluge' : 'proizvodi';
			if ( $row_tip === $tip ) {
				$w->write( $row );
			}
		}
		$w->finish();
		rewind( $h );
		$out = stream_get_contents( $h );
		fclose( $h ); // phpcs:ignore
		return $out;
	}
}
