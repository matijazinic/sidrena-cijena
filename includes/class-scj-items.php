<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stavke cjenika bez WooCommercea: usluge i proizvodi koji se ne prodaju kroz webshop.
 */
class SCJ_Items {

	const CPT = 'scj_stavka';
	const TAX = 'scj_grupa';

	/** @var bool Uvoz CSV-a: prazna sidrena ostaje prazna (uvoze se postojeće usluge, ne nove). */
	public static $no_auto_anchor = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'columns_css' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_remove' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_remove' ) );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => 'Stavke cjenika',
					'singular_name' => 'Stavka cjenika',
					'add_new'       => 'Dodaj stavku',
					'add_new_item'  => 'Nova stavka cjenika',
					'edit_item'     => 'Uredi stavku cjenika',
					'all_items'     => 'Stavke cjenika',
					'search_items'  => 'Traži stavke',
					'not_found'     => 'Nema stavki.',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false, // Podizbornik dodaje SCJ_Admin::menu().
				'supports'     => array( 'title', 'page-attributes' ),
				'hierarchical' => false,
				'menu_icon'    => 'dashicons-tag',
			)
		);
		register_taxonomy(
			self::TAX,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => 'Grupe cjenika',
					'singular_name' => 'Grupa cjenika',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
			)
		);
	}

	public static function meta_boxes() {
		add_meta_box( 'scj_stavka_podaci', 'Cijena i podaci za cjenik', array( __CLASS__, 'render_box' ), self::CPT, 'normal', 'high' );
	}

	private static function meta( $id, $key, $default = '' ) {
		$v = get_post_meta( $id, '_scj_' . $key, true );
		return '' === $v ? $default : $v;
	}

	/**
	 * ID stavke po šifri (bilo koji status osim smeća) ili 0.
	 */
	public static function id_by_sifra( $sifra ) {
		$sifra = trim( (string) $sifra );
		if ( '' === $sifra ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_scj_sifra',
						'value' => $sifra,
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Stavka po ID-ju ili šifri (za kratke kodove i scj_stavka()).
	 */
	public static function find( $id = 0, $sifra = '' ) {
		$id = (int) $id;
		if ( ! $id && '' !== trim( (string) $sifra ) ) {
			$id = self::id_by_sifra( $sifra );
		}
		return ( $id && self::CPT === get_post_type( $id ) ) ? self::data( $id ) : null;
	}

	private static function ymd( $v ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '';
	}

	/**
	 * Početak akcije kao UTC timestamp (ponoć lokalnog vremena) ili null.
	 */
	public static function sale_from_ts( array $d ) {
		if ( empty( $d['akcija_od'] ) ) {
			return null;
		}
		return ( new DateTimeImmutable( $d['akcija_od'] . ' 00:00:00', wp_timezone() ) )->getTimestamp();
	}

	/**
	 * Akcije s datumom počinju i završavaju same: bilježi promjenu cijene i objavljuje cjenik usluga.
	 * Poziva se iz satne kontrole (scj_tick).
	 */
	public static function sync_scheduled_sales() {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_scj_akcija_od',
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => '_scj_akcija_do',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		$changed = false;
		foreach ( $ids as $id ) {
			$d = self::data( $id );
			if ( $d && SCJ_History::record( $d['item'], $d['redovna'], $d['trenutna'] ) ) {
				$changed = true;
			}
		}
		if ( $changed ) {
			SCJ_Scheduler::queue_publish();
		}
		return $changed;
	}

	/**
	 * Normalizirani podaci stavke.
	 */
	public static function data( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}
		$id       = $post->ID;
		$regular  = SCJ_Format::parse_price( self::meta( $id, 'cijena' ) );
		$sale     = SCJ_Format::parse_price( self::meta( $id, 'akcijska' ) );
		$od       = self::ymd( self::meta( $id, 'akcija_od' ) );
		$do       = self::ymd( self::meta( $id, 'akcija_do' ) );
		$today    = wp_date( 'Y-m-d' );
		$in_dates = ( '' === $od || $today >= $od ) && ( '' === $do || $today <= $do ); // "do" uključuje taj dan.
		$on_sale  = null !== $sale && null !== $regular && $sale < $regular && $in_dates;
		$anchor   = SCJ_Format::parse_price( self::meta( $id, 'sidrena' ) );
		$date     = self::meta( $id, 'datum' );
		$kolicina = SCJ_Format::parse_price( self::meta( $id, 'kolicina' ) );

		return array(
			'id'           => $id,
			'item'         => 'st:' . $id,
			// Ne get_the_title(): ona tipografski pretvara "-" i navodnike u HTML entitete (&#8211;), koji bi završili u CSV/XML-u.
			'naziv'        => html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ),
			'tip'          => 'usluga' === self::meta( $id, 'tip', 'usluga' ) ? 'usluga' : 'proizvod',
			'sifra'        => self::meta( $id, 'sifra', 'ST-' . $id ),
			'marka'        => self::meta( $id, 'marka', (string) SCJ_Settings::get( 'marka_zadana' ) ),
			'jedinica'     => self::meta( $id, 'jedinica', '' ),
			'kolicina'     => $kolicina,
			'redovna'      => $regular,
			'trenutna'     => $on_sale ? $sale : $regular,
			'na_akciji'    => $on_sale,
			'akcija_naziv' => $on_sale ? self::meta( $id, 'akcija_naziv', (string) SCJ_Settings::get( 'naziv_akcije' ) ) : '',
			'akcijska'     => $sale,
			'akcija_od'    => $od,
			'akcija_do'    => $do,
			'sidrena'      => $anchor,
			'datum'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? $date : (string) SCJ_Settings::get( 'sidreni_datum' ),
			'barkod'       => self::meta( $id, 'barkod', '' ),
			'dostupno'     => '0' !== (string) self::meta( $id, 'dostupno', '1' ),
			'iskljuci'     => '1' === (string) self::meta( $id, 'iskljuci', '0' ),
			'napomena'     => self::meta( $id, 'napomena', '' ),
		);
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'scj_stavka_save', 'scj_stavka_nonce' );
		$d     = self::data( $post );
		$f     = function ( $k ) use ( $post ) {
			return esc_attr( get_post_meta( $post->ID, '_scj_' . $k, true ) );
		};
		$tip   = get_post_meta( $post->ID, '_scj_tip', true );
		$tip   = $tip ? $tip : 'usluga';
		$sdate = SCJ_Format::date_hr( (string) SCJ_Settings::get( 'sidreni_datum' ) );
		?>
		<style>
			.scj-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px 20px}
			.scj-grid label{display:block;font-weight:600;margin-bottom:4px}
			.scj-grid input[type=text],.scj-grid select{width:100%}
			.scj-grid .desc{color:#646970;font-size:12px;margin-top:3px}
			.scj-sep{margin:18px 0 10px;border-top:1px solid #dcdcde;padding-top:12px;font-weight:600}
		</style>
		<div class="scj-grid">
			<p><label for="scj_tip">Vrsta</label>
				<select name="scj[tip]" id="scj_tip">
					<option value="usluga" <?php selected( $tip, 'usluga' ); ?>>Usluga</option>
					<option value="proizvod" <?php selected( $tip, 'proizvod' ); ?>>Proizvod</option>
				</select></p>
			<p><label for="scj_cijena">Maloprodajna cijena (s PDV-om) €</label>
				<input type="text" name="scj[cijena]" id="scj_cijena" value="<?php echo $f( 'cijena' ); // phpcs:ignore ?>" inputmode="decimal" placeholder="npr. 45,00"></p>
			<p><label for="scj_sidrena">Sidrena cijena €</label>
				<input type="text" name="scj[sidrena]" id="scj_sidrena" value="<?php echo $f( 'sidrena' ); // phpcs:ignore ?>" inputmode="decimal">
				<span class="desc">Redovna cijena na dan <?php echo esc_html( $sdate ); ?>, bez akcije i posebnih uvjeta (loyalty, popust za umirovljenike...). Kasnije izmjene se bilježe. <a href="#" id="scj_copy_price">Kopiraj trenutnu cijenu</a></span></p>
			<p><label for="scj_datum">Datum sidrene cijene</label>
				<input type="date" name="scj[datum]" id="scj_datum" value="<?php echo $f( 'datum' ); // phpcs:ignore ?>">
				<span class="desc">Prazno = <?php echo esc_html( $sdate ); ?>. Za stavke koje nisu postojale tog dana upišite datum prve ponude<?php echo SCJ_Settings::get( 'auto_nova_sidrena' ) ? ' (nova stavka ga dobije automatski pri objavi ako je sidrena cijena prazna)' : ''; ?>.</span></p>
			<p><label for="scj_jedinica">Jedinica mjere</label>
				<input type="text" name="scj[jedinica]" id="scj_jedinica" value="<?php echo $f( 'jedinica' ); // phpcs:ignore ?>" placeholder="sat, m², kom, kg">
				<span class="desc">Za usluge je dobro navesti jedinicu i u nazivu (npr. "Radni sat servisera"). Usluga bez unaprijed određene cijene: unesite elemente od kojih se cijena računa (sat rada, m²...) kao zasebne stavke.</span></p>
			<p><label for="scj_sifra">Šifra</label>
				<input type="text" name="scj[sifra]" id="scj_sifra" value="<?php echo $f( 'sifra' ); // phpcs:ignore ?>" placeholder="ST-<?php echo (int) $post->ID; ?>">
				<span class="desc">Po želji. Za uvoz CSV-a i kratki kod <code>[sidrena_cijena sifra="..."]</code>; kod proizvoda ide u cjenik.</span></p>
			<p><label for="scj_napomena">Napomena uz cijenu (prikaz)</label>
				<input type="text" name="scj[napomena]" id="scj_napomena" value="<?php echo $f( 'napomena' ); // phpcs:ignore ?>" placeholder="npr. po m²"></p>
		</div>

		<div class="scj-sep">Akcija / poseban oblik prodaje</div>
		<div class="scj-grid">
			<p><label for="scj_akcijska">Akcijska cijena €</label>
				<input type="text" name="scj[akcijska]" id="scj_akcijska" value="<?php echo $f( 'akcijska' ); // phpcs:ignore ?>" inputmode="decimal">
				<span class="desc">Prazno = nema akcije.</span></p>
			<p><label for="scj_akcija_naziv">Naziv posebnog oblika prodaje</label>
				<input type="text" name="scj[akcija_naziv]" id="scj_akcija_naziv" value="<?php echo $f( 'akcija_naziv' ); // phpcs:ignore ?>" placeholder="<?php echo esc_attr( SCJ_Settings::get( 'naziv_akcije' ) ); ?>"></p>
			<p><label for="scj_akcija_od">Akcija od</label>
				<input type="date" name="scj[akcija_od]" id="scj_akcija_od" value="<?php echo $f( 'akcija_od' ); // phpcs:ignore ?>">
				<span class="desc">Prazno = odmah.</span></p>
			<p><label for="scj_akcija_do">Akcija do (uključivo)</label>
				<input type="date" name="scj[akcija_do]" id="scj_akcija_do" value="<?php echo $f( 'akcija_do' ); // phpcs:ignore ?>">
				<span class="desc">Prazno = dok je ne maknete. Akcija sama počinje i završava, a cjenik se objavi.</span></p>
		</div>

		<div class="scj-sep">Samo za proizvode</div>
		<div class="scj-grid">
			<p><label for="scj_marka">Marka</label>
				<input type="text" name="scj[marka]" id="scj_marka" value="<?php echo $f( 'marka' ); // phpcs:ignore ?>"></p>
			<p><label for="scj_barkod">Barkod (EAN)</label>
				<input type="text" name="scj[barkod]" id="scj_barkod" value="<?php echo $f( 'barkod' ); // phpcs:ignore ?>"></p>
			<p><label for="scj_kolicina">Neto količina u jedinici mjere</label>
				<input type="text" name="scj[kolicina]" id="scj_kolicina" value="<?php echo $f( 'kolicina' ); // phpcs:ignore ?>" inputmode="decimal" placeholder="npr. 0,5 za 500 g">
				<span class="desc">Služi za izračun cijene za jedinicu mjere.</span></p>
			<p><label>Dostupnost</label>
				<label style="font-weight:400"><input type="checkbox" name="scj[dostupno]" value="1" <?php checked( $d['dostupno'] ); ?>> Dostupno</label>
				<span class="desc">Samo ako je stvarno na zalihi u ovom objektu.</span></p>
		</div>

		<div class="scj-sep">Prikaz na stranici</div>
		<p>Kratki kodovi za ovu stavku (kopirajte na stranicu, u predložak ili element "Shortcode" u graditelju stranica):</p>
		<p><code>[sidrena_cijena id="<?php echo (int) $post->ID; ?>"]</code> cijena i sidrena cijena<br>
			<code>[sidrena_cijena id="<?php echo (int) $post->ID; ?>" prikaz="sidrena"]</code> samo "Cijena na dan ...", ispod postojeće cijene<br>
			<code>[sidrena_cijena id="<?php echo (int) $post->ID; ?>" prikaz="najniza"]</code> samo najniža cijena u 30 dana (kad je na akciji)</p>

		<div class="scj-sep">Cjenik</div>
		<p><label><input type="checkbox" name="scj[iskljuci]" value="1" <?php checked( $d['iskljuci'] ); ?>> Ne uključuj u javni cjenik (npr. usluga samo za tvrtke)</label></p>
		<script>
			document.getElementById('scj_copy_price').addEventListener('click', function(e){
				e.preventDefault();
				document.getElementById('scj_sidrena').value = document.getElementById('scj_cijena').value;
			});
		</script>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['scj_stavka_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scj_stavka_nonce'] ) ), 'scj_stavka_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['scj'] ) && is_array( $_POST['scj'] ) ? wp_unslash( $_POST['scj'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$fields = array(
			'tip'          => array( 'usluga', 'proizvod' ),
			'sifra'        => 'text',
			'marka'        => 'text',
			'jedinica'     => 'text',
			'napomena'     => 'text',
			'akcija_naziv' => 'text',
			'barkod'       => 'text',
			'datum'        => 'date',
			'akcija_od'    => 'date',
			'akcija_do'    => 'date',
			'cijena'       => 'price',
			'akcijska'     => 'price',
			'sidrena'      => 'price',
			'kolicina'     => 'price',
		);
		foreach ( $fields as $key => $type ) {
			$raw = isset( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';
			if ( is_array( $type ) ) {
				$val = in_array( $raw, $type, true ) ? $raw : $type[0];
			} elseif ( 'price' === $type ) {
				$p   = SCJ_Format::parse_price( $raw );
				$val = null === $p ? '' : (string) $p;
			} elseif ( 'date' === $type ) {
				$val = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
			} else {
				$val = sanitize_text_field( $raw );
			}
			update_post_meta( $post_id, '_scj_' . $key, $val );
		}
		update_post_meta( $post_id, '_scj_dostupno', empty( $in['dostupno'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_scj_iskljuci', empty( $in['iskljuci'] ) ? '0' : '1' );

		self::after_change( $post_id );
	}

	/**
	 * Bilježi povijest i zakazuje objavu ako je riječ o usluzi.
	 */
	public static function after_change( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			self::maybe_auto_anchor( $post );
			$d = self::data( $post );
			SCJ_History::record( $d['item'], $d['redovna'], $d['trenutna'] );
		}
		// Pružatelj usluge objavljuje cjenik nakon svake promjene.
		SCJ_Scheduler::queue_publish();
	}

	/**
	 * Nova stavka (objavljena nakon referentnog datuma): sidrena = redovna cijena pri prvom uvrštenju.
	 */
	private static function maybe_auto_anchor( WP_Post $post ) {
		if ( self::$no_auto_anchor || ! SCJ_Settings::get( 'auto_nova_sidrena' ) || '' !== (string) get_post_meta( $post->ID, '_scj_sidrena', true ) ) {
			return;
		}
		$cijena = (string) get_post_meta( $post->ID, '_scj_cijena', true );
		if ( '' === $cijena ) {
			return;
		}
		$published = '0000-00-00 00:00:00' === $post->post_date_gmt ? time() : strtotime( $post->post_date_gmt . ' UTC' );
		$ref_end   = new DateTimeImmutable( SCJ_Settings::get( 'sidreni_datum' ) . ' 23:59:59', wp_timezone() );
		if ( $published <= $ref_end->getTimestamp() ) {
			return;
		}
		update_post_meta( $post->ID, '_scj_sidrena', $cijena );
		update_post_meta( $post->ID, '_scj_datum', wp_date( 'Y-m-d' ) );
	}

	public static function on_remove( $post_id ) {
		if ( self::CPT === get_post_type( $post_id ) ) {
			SCJ_Scheduler::queue_publish();
		}
	}

	public static function columns_css() {
		$screen = get_current_screen();
		if ( $screen && 'edit-' . self::CPT === $screen->id ) {
			echo '<style>.wp-list-table .column-scj_id{width:5ch}.wp-list-table .column-scj_sifra{width:12ch}</style>';
		}
	}

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['scj_id']      = 'ID';
				$new['scj_sifra']   = 'Šifra';
				$new['scj_tip']     = 'Vrsta';
				$new['scj_cijena']  = 'Cijena';
				$new['scj_sidrena'] = 'Sidrena cijena';
			}
		}
		return $new;
	}

	public static function column( $col, $post_id ) {
		$d = self::data( $post_id );
		switch ( $col ) {
			case 'scj_id':
				echo (int) $post_id;
				break;
			case 'scj_sifra':
				echo esc_html( (string) get_post_meta( $post_id, '_scj_sifra', true ) );
				break;
			case 'scj_tip':
				echo 'usluga' === $d['tip'] ? 'Usluga' : 'Proizvod';
				if ( $d['iskljuci'] ) {
					echo '<br><small>izvan cjenika</small>';
				}
				break;
			case 'scj_cijena':
				echo esc_html( SCJ_Format::money( $d['trenutna'] ) );
				if ( $d['na_akciji'] ) {
					echo '<br><small>' . esc_html( $d['akcija_naziv'] . ( $d['akcija_do'] ? ' do ' . SCJ_Format::date_hr( $d['akcija_do'] ) : '' ) ) . '</small>';
				} elseif ( null !== $d['akcijska'] && $d['akcija_od'] && wp_date( 'Y-m-d' ) < $d['akcija_od'] ) {
					echo '<br><small>akcija ' . esc_html( SCJ_Format::money( $d['akcijska'] ) . ' od ' . SCJ_Format::date_hr( $d['akcija_od'] ) ) . '</small>';
				} elseif ( null !== $d['akcijska'] && $d['akcija_do'] && wp_date( 'Y-m-d' ) > $d['akcija_do'] ) {
					echo '<br><small>akcija istekla ' . esc_html( SCJ_Format::date_hr( $d['akcija_do'] ) ) . '</small>';
				}
				break;
			case 'scj_sidrena':
				if ( null === $d['sidrena'] ) {
					echo '<span style="color:#b32d2e;font-weight:600">nedostaje</span>';
				} else {
					echo esc_html( SCJ_Format::money( $d['sidrena'] ) ) . '<br><small>' . esc_html( SCJ_Format::date_hr( $d['datum'] ) ) . '</small>';
				}
				break;
		}
	}

	/**
	 * Sve objavljene stavke (ID-jevi).
	 */
	public static function ids( $tip = null ) {
		$args = array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'  => true,
		);
		if ( $tip ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_scj_tip',
					'value' => $tip,
				),
			);
		}
		return get_posts( $args );
	}
}
