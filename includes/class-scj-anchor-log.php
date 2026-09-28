<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zapis promjena već upisane sidrene cijene i njezina datuma.
 *
 * Sidrena cijena je cijena na dan 10. 9. i ne bi se smjela mijenjati. Ako se ipak promijeni (ispravak greške),
 * trgovac mora moći reći tko je, kad i zašto. Prvi upis se ne bilježi, samo izmjena postojeće vrijednosti.
 *
 * Hvata se WordPressov "update_post_meta" (prije zapisa, za update_metadata i update_metadata_by_mid), pa zapis
 * pokriva uređivanje proizvoda, varijacija i stavki, uvoz, REST, WP-CLI i druge dodatke.
 */
class SCJ_Anchor_Log {

	const OPTION = 'scj_anchor_log';
	const MAX    = 2000;
	const KEYS   = array( '_scj_sidrena', '_scj_datum' );

	/** @var string|null Izvor promjene ako ga postavi alat (npr. "uvoz CSV"). */
	public static $source = null;

	/** @var array<int,bool> Postovi kojima je sidrena upravo prvi put upisana: njihov datum nije izmjena. */
	private static $fresh = array();

	public static function init() {
		add_action( 'update_post_meta', array( __CLASS__, 'before_update' ), 10, 4 );
		add_action( 'add_post_meta', array( __CLASS__, 'before_add' ), 10, 3 );
		add_action( 'admin_footer-post.php', array( __CLASS__, 'confirm_script' ) );
	}

	private static function source() {
		if ( null !== self::$source ) {
			return self::$source;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'WP-CLI';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'REST API';
		}
		if ( wp_doing_cron() ) {
			return 'automatski (cron)';
		}
		if ( wp_doing_ajax() ) {
			return 'uređivanje (varijacija)';
		}
		return is_admin() ? 'uređivanje' : 'drugo';
	}

	/**
	 * Dodavanje polja (prije nije postojalo): sidrena je prvi upis, a datum je izmjena samo ako sidrena već postoji.
	 */
	public static function before_add( $object_id, $meta_key, $new ) {
		if ( '_scj_sidrena' === $meta_key ) {
			self::$fresh[ (int) $object_id ] = true;
		} elseif ( '_scj_datum' === $meta_key && is_scalar( $new ) && '' !== (string) $new ) {
			self::maybe_log( $object_id, $meta_key, '', (string) $new );
		}
	}

	/**
	 * @param int    $meta_id   ID zapisa.
	 * @param int    $object_id ID posta.
	 * @param string $meta_key  Ključ.
	 * @param mixed  $new       Nova vrijednost.
	 */
	public static function before_update( $meta_id, $object_id, $meta_key, $new ) {
		if ( ! in_array( $meta_key, self::KEYS, true ) ) {
			return;
		}
		$row = get_metadata_by_mid( 'post', $meta_id );
		$old = $row ? (string) $row->meta_value : (string) get_post_meta( $object_id, $meta_key, true );
		self::maybe_log( $object_id, $meta_key, $old, is_scalar( $new ) ? (string) $new : '' );
	}

	private static function maybe_log( $object_id, $meta_key, $old, $new ) {
		if ( '_scj_sidrena' === $meta_key ) {
			$o = SCJ_Format::parse_price( $old );
			$n = SCJ_Format::parse_price( $new );
			if ( null === $o ) {
				self::$fresh[ (int) $object_id ] = true; // Prvi upis.
				return;
			}
			if ( null !== $n && abs( $o - $n ) < 0.005 ) {
				return;
			}
		} else {
			if ( $old === $new || isset( self::$fresh[ (int) $object_id ] ) ) {
				return;
			}
			// Datum je zanimljiv samo ako sidrena cijena već postoji (prazan datum = referentni datum).
			if ( '' === (string) get_post_meta( $object_id, '_scj_sidrena', true ) ) {
				return;
			}
		}

		$log   = get_option( self::OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			't'   => time(),
			'u'   => get_current_user_id(),
			'id'  => (int) $object_id,
			'k'   => '_scj_sidrena' === $meta_key ? 'sidrena' : 'datum',
			'old' => $old,
			'new' => $new,
			'src' => self::source(),
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );
	}

	public static function entries( $limit = 100 ) {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_slice( array_reverse( $log ), 0, $limit ) : array();
	}

	private static function value( $e, $which ) {
		$v = $e[ $which ];
		if ( 'datum' === $e['k'] ) {
			return '' === $v ? 'referentni (' . SCJ_Format::date_hr( (string) SCJ_Settings::get( 'sidreni_datum' ) ) . ')' : SCJ_Format::date_hr( $v );
		}
		return '' === $v ? 'prazno' : SCJ_Format::money( SCJ_Format::parse_price( $v ) );
	}

	/**
	 * Tablica za stranicu Alati.
	 */
	public static function render() {
		$entries = self::entries( 100 );
		if ( ! $entries ) {
			echo '<p>Nema promjena. Bilježi se svaka izmjena već upisane sidrene cijene ili datuma (prvi upis ne).</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Vrijeme</th><th>Stavka / proizvod</th><th>Polje</th><th>Staro -&gt; novo</th><th>Tko</th><th>Izvor</th></tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$user  = $e['u'] ? get_userdata( $e['u'] ) : false;
			$title = get_post_field( 'post_title', $e['id'] );
			$link  = get_edit_post_link( 'product_variation' === get_post_type( $e['id'] ) ? wp_get_post_parent_id( $e['id'] ) : $e['id'] );
			echo '<tr><td>' . esc_html( wp_date( 'j. n. Y. H:i', $e['t'] ) ) . '</td>';
			echo '<td>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ? $title : '#' . $e['id'] ) . '</a>' : esc_html( $title ? $title : '#' . $e['id'] . ' (obrisano)' ) ) . '</td>';
			echo '<td>' . ( 'sidrena' === $e['k'] ? 'sidrena cijena' : 'datum' ) . '</td>';
			echo '<td>' . esc_html( self::value( $e, 'old' ) . ' -> ' . self::value( $e, 'new' ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '-' ) . '</td>';
			echo '<td>' . esc_html( $e['src'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Upozorenje pri izmjeni već upisane sidrene cijene (proizvod, varijacija, stavka).
	 */
	public static function confirm_script() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'product', SCJ_Items::CPT ), true ) ) {
			return;
		}
		$msg = 'Sidrena cijena je cijena na dan ' . SCJ_Format::date_hr( (string) SCJ_Settings::get( 'sidreni_datum' ) ) . ' i u pravilu se ne mijenja. Mijenjajte je samo ako ispravljate grešku. Promjena se bilježi (Sidrene cijene -> Alati). Nastaviti?';
		?>
		<script>
		( function () {
			var sel = '#_scj_sidrena, #scj_sidrena, input[name^="scj_var_sidrena"]';
			document.addEventListener( 'focusin', function ( e ) {
				if ( e.target.matches && e.target.matches( sel ) && ! e.target.hasAttribute( 'data-scj-orig' ) ) {
					e.target.setAttribute( 'data-scj-orig', e.target.value );
				}
			} );
			document.addEventListener( 'change', function ( e ) {
				var el = e.target;
				if ( ! el.matches || ! el.matches( sel ) ) {
					return;
				}
				var orig = el.getAttribute( 'data-scj-orig' ) || '';
				if ( '' === orig.trim() || orig === el.value ) {
					return;
				}
				if ( window.confirm( <?php echo wp_json_encode( $msg ); ?> ) ) {
					el.setAttribute( 'data-scj-orig', el.value );
				} else {
					el.value = orig;
				}
			} );
		} )();
		</script>
		<?php
	}
}
