<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administracija: izbornik, postavke, status objave, alati i upozorenja.
 */
class SCJ_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_scj_publish', array( __CLASS__, 'handle_publish' ) );
		add_action( 'admin_post_scj_check', array( __CLASS__, 'handle_check' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCJ_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'option_page_capability_scj_settings_group', array( __CLASS__, 'cap' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	/**
	 * Ovlast za stranice plugina. Uz WooCommerce i voditelj trgovine (shop_manager), koji nema manage_options.
	 */
	public static function cap() {
		return (string) apply_filters( 'scj_capability', SCJ_Plugin::woo_active() ? 'manage_woocommerce' : 'manage_options' );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=scj-postavke' ) ) . '">Postavke</a>' );
		return $links;
	}

	public static function menu() {
		$cap = self::cap();
		add_menu_page( 'Sidrene cijene', 'Sidrene cijene', $cap, 'scj-status', array( __CLASS__, 'page_status' ), 'dashicons-tag', 56 );
		add_submenu_page( 'scj-status', 'Status i objava', 'Status i objava', $cap, 'scj-status', array( __CLASS__, 'page_status' ) );
		add_submenu_page( 'scj-status', 'Stavke cjenika', 'Stavke cjenika', 'edit_posts', 'edit.php?post_type=' . SCJ_Items::CPT );
		add_submenu_page( 'scj-status', 'Grupe cjenika', 'Grupe cjenika', 'manage_categories', 'edit-tags.php?taxonomy=' . SCJ_Items::TAX . '&post_type=' . SCJ_Items::CPT );
		add_submenu_page( 'scj-status', 'Postavke', 'Postavke', $cap, 'scj-postavke', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'scj-status', 'Alati', 'Alati', $cap, 'scj-alati', array( __CLASS__, 'page_tools' ) );
	}

	/**
	 * Na ekranima stavki i grupa otvori i označi izbornik "Sidrene cijene".
	 */
	public static function parent_file( $parent ) {
		$screen = get_current_screen();
		return ( $screen && SCJ_Items::CPT === $screen->post_type ) ? 'scj-status' : $parent;
	}

	public static function submenu_file( $file ) {
		$screen = get_current_screen();
		if ( ! $screen || SCJ_Items::CPT !== $screen->post_type ) {
			return $file;
		}
		return 'edit-tags' === $screen->base ? 'edit-tags.php?taxonomy=' . SCJ_Items::TAX . '&post_type=' . SCJ_Items::CPT : 'edit.php?post_type=' . SCJ_Items::CPT;
	}

	public static function register_settings() {
		register_setting(
			'scj_settings_group',
			SCJ_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'SCJ_Settings', 'sanitize' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Provjere stanja                                                      */
	/* ------------------------------------------------------------------ */

	public static function timezone_ok() {
		return 'Europe/Zagreb' === wp_timezone_string();
	}

	public static function reliable_cron() {
		$seen = (int) get_option( 'scj_external_seen', 0 );
		if ( $seen && time() - $seen < 3 * HOUR_IN_SECONDS ) {
			return 'vanjski okidač (zadnji poziv ' . wp_date( 'j. n. H:i', $seen ) . self::caller() . ')';
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return 'sistemski cron (DISABLE_WP_CRON)';
		}
		return '';
	}

	/**
	 * Tko je zadnji pozvao vanjski okidač (prepoznate usluge).
	 */
	private static function caller() {
		$ua = strtolower( (string) get_option( 'scj_external_ua', '' ) );
		foreach ( array( 'cron-job.org' => 'cron-job.org', 'uptimerobot' => 'UptimeRobot', 'wget' => 'cron na hostingu', 'curl' => 'cron na hostingu' ) as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return ', ' . $name;
			}
		}
		return '';
	}

	public static function last_log() {
		$log = get_option( 'scj_log', array() );
		return is_array( $log ) && $log ? end( $log ) : null;
	}

	public static function checks() {
		$c    = array();
		$miss = SCJ_Settings::missing();
		$c[]  = array(
			$miss ? 'err' : 'ok',
			'Podaci o prodajnom objektu',
			$miss ? 'Nedostaje: ' . implode( ', ', $miss ) . '. Ulaze u naziv datoteke.' : esc_html( SCJ_Settings::get( 'oblik_objekta' ) . ', ' . SCJ_Settings::get( 'adresa' ) . ', oznaka ' . SCJ_Settings::get( 'oznaka' ) ),
		);
		$c[]  = array(
			self::timezone_ok() ? 'ok' : 'err',
			'Vremenska zona',
			self::timezone_ok() ? 'Europe/Zagreb' : 'Postavljeno: ' . esc_html( wp_timezone_string() ) . '. Postavite "Zagreb" u Postavke -> Općenito, inače se vremena objave i nazivi datoteka pomiču (UTC ili fiksni pomak ne prati ljetno vrijeme).',
		);
		if ( SCJ_Plugin::woo_active() ) {
			$c[] = self::tax_check();
		}
		$cron = self::reliable_cron();
		$c[]  = array(
			$cron ? 'ok' : 'warn',
			'Pouzdano pokretanje',
			$cron ? 'Aktivno: ' . esc_html( $cron ) : ( get_option( 'scj_external_seen' ) ? 'Vanjski okidač nije pozvao stranicu više od 3 sata (zadnji poziv ' . esc_html( wp_date( 'j. n. Y. H:i', (int) get_option( 'scj_external_seen' ) ) ) . '). Provjerite uslugu za okidanje.' : 'WordPress pokreće zadatke samo kad netko posjeti stranicu. Ako noću nema posjeta, cjenik se može objaviti nakon 8:00, a ni upozorenje e-mailom se neće poslati. Postavite vanjski okidač (upute niže, 5 minuta).' ),
		);
		$today = SCJ_Scheduler::published_today();
		$first = get_option( 'scj_first_success_today' );
		$next  = wp_next_scheduled( 'scj_daily' );
		$c[]   = array(
			$today ? ( SCJ_Scheduler::published_today_on_time() ? 'ok' : 'warn' ) : ( SCJ_Scheduler::now()->format( 'H:i' ) >= '08:00' ? 'err' : 'warn' ),
			'Današnja objava',
			$today ? 'Objavljeno danas u ' . esc_html( is_array( $first ) ? $first['time'] : '' ) . ( SCJ_Scheduler::published_today_on_time() ? ' (prije 8:00).' : ' (nakon 8:00).' ) : 'Još nije objavljeno.' . ( $next ? ' Zakazano: ' . esc_html( wp_date( 'j. n. Y. H:i', (int) $next ) ) : '' ),
		);
		$last = self::last_log();
		if ( $last ) {
			$c[] = array(
				$last['missing'] ? 'err' : 'ok',
				'Sidrene cijene',
				$last['missing'] ? (int) $last['missing'] . ' ' . SCJ_Format::plural( $last['missing'], 'stavka u zadnjem cjeniku nema', 'stavke u zadnjem cjeniku nemaju', 'stavki u zadnjem cjeniku nema' ) . ' sidrenu cijenu. <a href="' . esc_url( admin_url( 'admin.php?page=scj-alati' ) ) . '">Alati</a>' : 'Sve stavke u zadnjem cjeniku imaju sidrenu cijenu.',
			);
		}
		$bot = get_option( 'scj_bot_check' );
		$c[] = array(
			is_array( $bot ) ? ( $bot['ok'] ? 'ok' : 'err' ) : 'warn',
			'Dostupnost robotima',
			is_array( $bot ) ? esc_html( $bot['msg'] ) . ' <small>(' . esc_html( wp_date( 'j. n. H:i', $bot['time'] ) ) . ')</small>' : 'Nije provjereno. Kliknite "Provjeri dostupnost robotima".',
		);
		return $c;
	}

	/**
	 * Potrošačima se cijene moraju prikazivati s PDV-om. Plugin sidrenu cijenu prikazuje isto kao cijenu,
	 * pa trgovina koja prikazuje cijene bez PDV-a i sidrenu prikazuje bez njega (CSV je uvijek s PDV-om).
	 */
	public static function tax_check() {
		if ( ! wc_tax_enabled() ) {
			return array( 'ok', 'PDV u trgovini', 'Porezi u WooCommerceu su isključeni: cijene se prikazuju i objavljuju kako su unesene (npr. obrt izvan sustava PDV-a).' );
		}
		$excl = array();
		if ( 'excl' === get_option( 'woocommerce_tax_display_shop' ) ) {
			$excl[] = 'u trgovini';
		}
		if ( 'excl' === get_option( 'woocommerce_tax_display_cart' ) ) {
			$excl[] = 'u košarici i blagajni';
		}
		if ( $excl ) {
			$msg = 'Cijene se ' . implode( ' i ', $excl ) . ' prikazuju bez PDV-a';
			$msg .= in_array( 'u trgovini', $excl, true ) ? ', pa se i sidrena cijena i najniža cijena u 30 dana uz proizvod prikazuju bez PDV-a. ' : '. ';
			$msg .= 'Potrošačima se cijene prikazuju s PDV-om: WooCommerce -> Postavke -> Porez -> "' . __( 'Display prices in the shop', 'woocommerce' ) . '" i "' . __( 'Display prices during cart and checkout', 'woocommerce' ) . '": "' . __( 'Including tax', 'woocommerce' ) . '". Objavljeni cjenik (CSV/XML) je uvijek s PDV-om.';
			return array( 'warn', 'PDV u trgovini', $msg );
		}
		return array( 'ok', 'PDV u trgovini', 'Cijene se prikazuju s PDV-om. Sidrena cijena upisuje se ' . ( wc_prices_include_tax() ? 's PDV-om' : 'bez PDV-a' ) . ', isto kao redovna cijena.' );
	}

	/* ------------------------------------------------------------------ */
	/* Akcije                                                               */
	/* ------------------------------------------------------------------ */

	public static function handle_publish() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		check_admin_referer( 'scj_publish' );
		delete_transient( 'scj_lock' );
		$ok = SCJ_Exporter::publish( 'ručna objava' );
		wp_safe_redirect( add_query_arg( 'scj_msg', $ok ? 'published' : 'failed', admin_url( 'admin.php?page=scj-status' ) ) );
		exit;
	}

	/**
	 * Dohvaća zadnji cjenik kao program (ne preglednik) i provjerava da nije vraćena HTML stranica ili blokada.
	 */
	public static function bot_check() {
		$targets = array();
		foreach ( array( 'proizvodi', 'usluge' ) as $tip ) {
			foreach ( (array) SCJ_Settings::get( 'formati' ) as $fmt ) {
				$f = SCJ_Exporter::latest( $tip, $fmt );
				if ( $f ) {
					$targets[] = $f['url'];
				}
			}
		}
		$targets[] = SCJ_Exporter::dir()['url'] . '/index.json';
		if ( 1 === count( $targets ) ) {
			return array(
				'ok'   => false,
				'msg'  => 'Još nema objavljenog cjenika.',
				'time' => time(),
			);
		}
		$problems = array();
		foreach ( $targets as $url ) {
			foreach ( array( 'curl/8.5.0', 'python-requests/2.32.3' ) as $ua ) {
				$r = wp_remote_get(
					$url,
					array(
						'timeout'     => 20,
						'redirection' => 3,
						'user-agent'  => $ua,
						'headers'     => array( 'Accept' => '*/*' ),
					)
				);
				if ( is_wp_error( $r ) ) {
					$problems[] = basename( $url ) . ': ' . $r->get_error_message();
					continue;
				}
				$code = wp_remote_retrieve_response_code( $r );
				$type = (string) wp_remote_retrieve_header( $r, 'content-type' );
				$body = substr( (string) wp_remote_retrieve_body( $r ), 0, 2000 );
				if ( 200 !== $code ) {
					$problems[] = basename( $url ) . " ({$ua}): HTTP {$code}";
				} elseif ( false !== stripos( $type, 'text/html' ) || preg_match( '/<html|cf-chl|challenge-platform|captcha/i', $body ) ) {
					$problems[] = basename( $url ) . " ({$ua}): vraćena je HTML stranica umjesto datoteke (vjerojatno zaštita od botova)";
				}
			}
		}
		return array(
			'ok'   => ! $problems,
			'msg'  => $problems ? implode( '; ', array_unique( $problems ) ) : 'Datoteke se mogu preuzeti programski (curl, Python) bez blokade.',
			'time' => time(),
		);
	}

	public static function handle_check() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( 'Nemate ovlasti.' );
		}
		check_admin_referer( 'scj_check' );
		update_option( 'scj_bot_check', self::bot_check(), false );
		wp_safe_redirect( add_query_arg( 'scj_msg', 'checked', admin_url( 'admin.php?page=scj-status' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Upozorenja                                                           */
	/* ------------------------------------------------------------------ */

	public static function notices() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$screen = get_current_screen();
		$ours   = $screen && false !== strpos( (string) $screen->id, 'scj' );

		if ( ! $ours && SCJ_Settings::missing() ) {
			echo '<div class="notice notice-warning"><p><strong>Sidrena cijena:</strong> upišite podatke o prodajnom objektu da bi se cjenik mogao objavljivati. <a href="' . esc_url( admin_url( 'admin.php?page=scj-postavke' ) ) . '">Postavke</a></p></div>';
			return;
		}
		$last = get_option( 'scj_last_success' );
		if ( ! $ours && ( $last || SCJ_Scheduler::now()->format( 'Y-m-d' ) >= '2026-10-01' ) && ! SCJ_Scheduler::published_today() && SCJ_Scheduler::now()->format( 'H:i' ) >= '08:00' ) {
			echo '<div class="notice notice-error"><p><strong>Sidrena cijena:</strong> današnji cjenik nije objavljen, a rok je bio 8:00. <a href="' . esc_url( admin_url( 'admin.php?page=scj-status' ) ) . '">Status i objava</a></p></div>';
		}
	}

	private static function flash() {
		$msg = isset( $_GET['scj_msg'] ) ? sanitize_key( $_GET['scj_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'published' => array( 'success', 'Cjenik je objavljen.' ),
			'failed'    => array( 'error', 'Objava nije uspjela. Pogledajte dnevnik niže.' ),
			'checked'   => array( 'info', 'Provjera dostupnosti je završena.' ),
			'nofile'    => array( 'error', 'Odaberite CSV datoteku.' ),
		);
		if ( 'fill' === $msg ) {
			$woo = isset( $_GET['woo'] ) ? absint( $_GET['woo'] ) : 0; // phpcs:ignore
			$st  = isset( $_GET['st'] ) ? absint( $_GET['st'] ) : 0; // phpcs:ignore
			echo '<div class="notice notice-success"><p>Sidrena cijena upisana za ' . (int) $woo . ' WooCommerce proizvoda/varijacija i ' . (int) $st . ' stavki cjenika.</p></div>';
		} elseif ( 'import' === $msg ) {
			$r = get_transient( 'scj_import_result_' . get_current_user_id() );
			if ( is_array( $r ) ) {
				echo '<div class="notice notice-' . ( $r['errors'] ? 'warning' : 'success' ) . '"><p>Ažurirano: ' . (int) $r['ok'] . ', novih stavki: ' . (int) ( isset( $r['created'] ) ? $r['created'] : 0 ) . ', preskočeno (proizvodi bez sidrene cijene u datoteci): ' . (int) $r['skip'] . '.</p>';
				if ( $r['errors'] ) {
					echo '<ul style="list-style:disc;margin-left:20px">';
					foreach ( array_slice( $r['errors'], 0, 30 ) as $e ) {
						echo '<li>' . esc_html( $e ) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
		} elseif ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $msg ][0] ) . '"><p>' . esc_html( $map[ $msg ][1] ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Stranice                                                             */
	/* ------------------------------------------------------------------ */

	private static function css() {
		echo '<style>
			.scj-wrap .card{max-width:none}
			.scj-checks td{vertical-align:top;padding:8px 10px}
			.scj-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px}
			.scj-ok{background:#00a32a}.scj-warn{background:#dba617}.scj-err{background:#d63638}
			.scj-code{font-family:monospace;background:#f6f7f7;padding:6px 8px;display:block;word-break:break-all;margin:4px 0 10px}
			.scj-actions form{display:inline-block;margin-right:8px}
		</style>';
	}

	public static function page_status() {
		self::css();
		echo '<div class="wrap scj-wrap"><h1>Sidrene cijene – status i objava</h1>';
		self::flash();

		echo '<table class="widefat striped scj-checks" style="max-width:1000px"><tbody>';
		foreach ( self::checks() as $c ) {
			echo '<tr><td style="width:220px"><span class="scj-dot scj-' . esc_attr( $c[0] ) . '"></span><strong>' . esc_html( $c[1] ) . '</strong></td><td>' . wp_kses_post( $c[2] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="scj-actions" style="margin-top:16px">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scj_publish' );
		echo '<input type="hidden" name="action" value="scj_publish"><button class="button button-primary">Objavi cjenik sada</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scj_check' );
		echo '<input type="hidden" name="action" value="scj_check"><button class="button">Provjeri dostupnost robotima</button></form>';
		echo '</p>';

		$dir = SCJ_Exporter::dir();
		echo '<h2>Poveznice</h2>';
		echo '<p><strong>Javna arhiva (stavite poveznicu u podnožje stranice):</strong><code class="scj-code">' . esc_html( $dir['url'] . '/index.html' ) . '</code>';
		echo 'Ili kratki kod <code>[sidrena_arhiva]</code> na bilo kojoj stranici.</p>';
		echo '<p><strong>Popis objava za programe (JSON):</strong><code class="scj-code">' . esc_html( $dir['url'] . '/index.json' ) . '</code></p>';
		echo '<p><strong>Cjenik u realnom vremenu:</strong><code class="scj-code">' . esc_html( SCJ_Rest::live_url( 'proizvodi', 'csv' ) ) . '</code><code class="scj-code">' . esc_html( SCJ_Rest::live_url( 'usluge', 'xml' ) ) . '</code></p>';

		$trigger = SCJ_Scheduler::external_url();
		echo '<h2 id="okidac">Vanjski okidač (preporučeno, 5 minuta)</h2>';
		echo '<p>WordPress sam od sebe ne radi noću ako nitko ne posjeti stranicu. Besplatna usluga koja svaki sat "posjeti" ovu adresu osigura da se cjenik objavi na vrijeme. Ne treba pristup hostingu.</p>';
		echo '<ol style="max-width:1000px">';
		echo '<li><strong>Kopirajte adresu</strong> (tajna je, ne dijelite je javno):<br><code class="scj-code" id="scj-trigger">' . esc_html( $trigger ) . '</code><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'scj-trigger\').textContent);this.textContent=\'Kopirano\'">Kopiraj adresu</button></li>';
		echo '<li><strong>Otvorite besplatni račun</strong> na <a href="https://cron-job.org" target="_blank" rel="noopener">cron-job.org</a> (dovoljni su e-mail i lozinka), dodajte novi zadatak, zalijepite adresu i odaberite izvođenje svakih sat vremena. Može i <a href="https://uptimerobot.com" target="_blank" rel="noopener">UptimeRobot</a>: novi HTTP(s) monitor s intervalom od 5 minuta, koji usput javlja i kad stranica ne radi.</li>';
		echo '<li><strong>Provjera:</strong> kad usluga prvi put pozove adresu, red "Pouzdano pokretanje" gore postane zelen i pokaže vrijeme zadnjeg poziva. Osvježite ovu stranicu nekoliko minuta nakon postavljanja.</li>';
		echo '</ol>';
		echo '<p>Adresa vraća grešku ako cjenik nakon zadanog vremena nije objavljen, pa vam usluga (cron-job.org ili UptimeRobot) može poslati obavijest i kad slanje e-pošte sa stranice ne radi. Uključite obavijesti o greškama u postavkama te usluge.</p>';
		echo '<details style="max-width:1000px"><summary>Za administratore: sistemski cron na hostingu</summary><p>Primjer za cPanel (svaki sat u 5. minuti):</p><code class="scj-code">5 * * * * wget -q -O - "' . esc_html( $trigger ) . '" &gt;/dev/null 2&gt;&amp;1</code></details>';
		echo '<p>Ako koristite Cloudflare ili sigurnosni dodatak (Wordfence i sl.), izuzmite putanje <code>' . esc_html( wp_parse_url( $dir['url'], PHP_URL_PATH ) ) . '/*</code> i <code>/wp-json/sidrena-cijena/*</code> od provjera za botove. Odluka traži da programi mogu preuzimati cijene.</p>';

		$files = array_slice( SCJ_Exporter::files(), 0, 12 );
		echo '<h2>Zadnje objavljene datoteke</h2>';
		if ( $files ) {
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Datoteka</th><th>Vrsta</th><th>Objavljeno</th><th>Veličina</th></tr></thead><tbody>';
			foreach ( $files as $f ) {
				echo '<tr><td><a href="' . esc_url( $f['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $f['name'] ) . '</a></td><td>' . esc_html( $f['tip'] ) . '</td><td>' . esc_html( wp_date( 'j. n. Y. H:i:s', $f['mtime'] ) ) . '</td><td>' . esc_html( size_format( $f['size'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Još nema objava.</p>';
		}

		$log = array_reverse( (array) get_option( 'scj_log', array() ) );
		echo '<h2>Dnevnik objava</h2>';
		if ( $log ) {
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Vrijeme</th><th>Povod</th><th>Stavke</th><th>Napomene</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 15 ) as $l ) {
				$counts = isset( $l['counts'] ) ? 'proizvodi: ' . (int) $l['counts']['proizvodi'] . ', usluge: ' . (int) $l['counts']['usluge'] : '';
				echo '<tr><td><span class="scj-dot scj-' . ( $l['ok'] ? ( $l['errors'] ? 'warn' : 'ok' ) : 'err' ) . '"></span>' . esc_html( wp_date( 'j. n. Y. H:i:s', $l['time'] ) ) . '</td><td>' . esc_html( $l['reason'] ) . '</td><td>' . esc_html( $counts ) . '</td><td>' . esc_html( implode( ' ', (array) $l['errors'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Prazno.</p>';
		}
		self::disclaimer();
		echo '</div>';
	}

	/**
	 * Kratko odricanje od odgovornosti na dnu stranica plugina.
	 */
	private static function disclaimer() {
		echo '<p class="description" style="max-width:1000px;margin-top:24px;border-top:1px solid #dcdcde;padding-top:12px">Dodatak je izrađen prema Odlukama iz NN 101/2026 i dostupnim pojašnjenjima Ministarstva gospodarstva, ali nije pravni savjet i ne jamči usklađenost s propisima. Propisi i njihova tumačenja se mijenjaju, a moguće su i greške u dodatku. Koristite ga na vlastitu odgovornost i redovito provjeravajte prikazane i objavljene cijene. Za točnost cijena i usklađenost poslovanja odgovara trgovac, odnosno pružatelj usluge.</p>';
		echo '<p class="description" style="max-width:1000px">Trebate pomoć s postavljanjem ili održavanjem? <a href="https://zin-digital.com/" target="_blank" rel="noopener">Matija Žinić, zin-digital.com</a></p>';
	}

	private static function field( $key, $label, $type = 'text', $desc = '', $extra = '' ) {
		$v    = SCJ_Settings::get( $key );
		$name = SCJ_Settings::OPTION . '[' . $key . ']';
		echo '<tr><th scope="row"><label for="scj_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'checkbox' === $type ) {
			echo '<label><input type="checkbox" id="scj_' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $v, 1, false ) . '> ' . wp_kses_post( $desc ) . '</label>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="scj_' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $v ) . '" ' . $extra . '>'; // phpcs:ignore
			if ( $desc ) {
				echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
			}
		}
		echo '</td></tr>';
	}

	public static function page_settings() {
		self::css();
		$o = SCJ_Settings::OPTION;
		echo '<div class="wrap scj-wrap"><h1>Sidrene cijene – postavke</h1>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'scj_settings_group' );

		echo '<h2>Prodajni objekt</h2><p>Ovi podaci ulaze u naziv datoteke cjenika (točka VI. Odluke) kako su upisani, prema primjeru Ministarstva: <code>prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.csv</code>. Oznaka je ista kao na računima (oznaka poslovnog prostora).</p><table class="form-table">';
		self::field( 'naziv_trgovca', 'Naziv trgovca' );
		self::field( 'oib', 'OIB', 'text', 'Neobavezno. Upisuje se u zaglavlje XML-a.' );
		self::field( 'oblik_objekta', 'Oblik prodajnog objekta', 'text', 'Za web trgovinu Ministarstvo navodi "webshop". Za ostalo npr. prodavaonica, servis, salon, frizerski salon.' );
		self::field( 'adresa', 'Adresa prodajnog objekta', 'text', 'npr. Ilica 150 Zagreb. Za web trgovinu adresa sjedišta.' );
		self::field( 'oznaka', 'Oznaka prodajnog objekta', 'text', 'Oznaka poslovnog prostora, npr. P-01. Tko nema internu oznaku, može upisati npr. P-01, NP ili 01. Svaka lokacija (i web trgovina) ima svoju oznaku i svoj cjenik.' );
		echo '</table>';

		echo '<h2>Sidrena cijena</h2><table class="form-table">';
		self::field( 'sidreni_datum', 'Referentni datum', 'date', 'Prema NN 101/2026: 10. 9. 2026.' );
		self::field( 'stari_datum', 'Raniji referentni datum', 'date', 'Samo za trgovce koji su već imali obvezu po staroj odluci (NN 75/2025): za hranu, piće, kozmetiku, sredstva za čišćenje, toaletne potrepštine i proizvode za kućanstvo zadržavaju 2. 5. 2025. Tko tada nije bio obveznik, za sve proizvode koristi 10. 9. 2026. i ne označava kategorije niže.' );
		if ( SCJ_Plugin::woo_active() ) {
			$cats = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			$sel  = array_map( 'absint', (array) SCJ_Settings::get( 'stare_kategorije' ) );
			echo '<tr><th scope="row">Kategorije s ranijim datumom</th><td><div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:8px;max-width:420px;background:#fff">';
			if ( ! is_wp_error( $cats ) ) {
				foreach ( $cats as $cat ) {
					echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $o ) . '[stare_kategorije][]" value="' . (int) $cat->term_id . '" ' . checked( in_array( (int) $cat->term_id, $sel, true ), true, false ) . '> ' . esc_html( $cat->name ) . '</label>';
				}
			}
			echo '</div><p class="description">Označite samo ako ste bili obveznik stare odluke (NN 75/2025). Proizvodi u odabranim kategorijama (i podkategorijama) koriste raniji datum, osim ako proizvod ima vlastiti datum. Za te proizvode ne ističe se i datum 10. 9. 2026.</p></td></tr>';
		}
		self::field( 'auto_nova_sidrena', 'Novi proizvodi', 'checkbox', 'Proizvodima i uslugama objavljenima nakon referentnog datuma automatski upiši sidrenu cijenu = cijena prvog dana ponude, s tim datumom. Isti proizvod s novom šifrom ili nazivom nije novi proizvod: ako ga unesete ponovo, upišite mu staru sidrenu cijenu i datum.' );
		echo '</table>';

		echo '<h2>Prikaz kupcima</h2><table class="form-table">';
		self::field( 'prikazi_sidrenu', 'Sidrena cijena', 'checkbox', 'Automatski prikaži uz cijenu WooCommerce proizvoda. Isključite ako je trgovina napravljena u graditelju koji ne prikazuje red ispravno, pa koristite kratki kod <code>[sidrena_cijena prikaz="sidrena"]</code> (kratki kodovi rade i kad je ovo isključeno).' );
		self::field( 'oznaka_sidrene', 'Tekst uz sidrenu cijenu', 'text', '<code>{datum}</code> se zamjenjuje datumom. Ministarstvo preporučuje samo datum, bez riječi "sidrena cijena", npr. "Cijena na 10.9.2026."' );
		self::field( 'prikazi_najnizu', 'Najniža cijena u 30 dana', 'checkbox', 'Kod sniženja prikaži najnižu cijenu u 30 dana prije sniženja (Zakon o zaštiti potrošača). Isključite ako to već radi drugi dodatak, da se red ne prikazuje dvaput.' );
		self::field( 'oznaka_najnize', 'Tekst uz najnižu cijenu' );
		self::field( 'naziv_akcije', 'Zadani naziv akcije', 'text', 'Upisuje se u cjenik kao naziv posebnog oblika prodaje ako proizvod nema vlastiti.' );
		echo '</table>';

		if ( SCJ_Plugin::woo_active() ) {
			$taxes = array(
				'auto' => 'Automatski (Brands, pa_marka, pa_brand...)',
				'meta' => 'Polje "Marka" na proizvodu',
			);
			foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
				if ( ! in_array( $tax->name, array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
					$taxes[ $tax->name ] = $tax->label . ' (' . $tax->name . ')';
				}
			}
			echo '<h2>WooCommerce podaci</h2><table class="form-table">';
			echo '<tr><th scope="row">Izvor marke</th><td><select name="' . esc_attr( $o ) . '[marka_izvor]">';
			foreach ( $taxes as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( SCJ_Settings::get( 'marka_izvor' ), $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
			self::field( 'marka_zadana', 'Zadana marka', 'text', 'Kad proizvod nema marku (npr. vlastiti proizvodi: naziv obrta).' );
			self::field( 'barkod_meta', 'Meta ključ barkoda', 'text', 'Prazno = WooCommerce polje GTIN/EAN. Upišite ključ ako barkod drži drugi dodatak (npr. <code>_alg_ean</code>).' );
			self::field( 'jedinica_zadana', 'Zadana jedinica mjere', 'text', 'npr. kom.' );
			self::field( 'virtualni_kao_usluge', 'Virtualni proizvodi', 'checkbox', 'Virtualne proizvode objavi u cjeniku usluga (npr. montaža, dostava kao usluga).' );
			echo '</table>';
		}

		echo '<h2>Objava cjenika</h2><table class="form-table">';
		$f = (array) SCJ_Settings::get( 'formati' );
		echo '<tr><th scope="row">Formati</th><td><label><input type="checkbox" name="' . esc_attr( $o ) . '[formati][]" value="csv" ' . checked( in_array( 'csv', $f, true ), true, false ) . '> CSV</label> &nbsp; <label><input type="checkbox" name="' . esc_attr( $o ) . '[formati][]" value="xml" ' . checked( in_array( 'xml', $f, true ), true, false ) . '> XML</label><p class="description">Odluka traži jedan od dva formata.</p></td></tr>';
		self::field( 'vrijeme_objave', 'Vrijeme dnevne objave', 'time', 'Rok je 8:00. Preporuka je 01:00: WordPress zadatke pokreće tek kad netko posjeti stranicu, pa ranije vrijeme daje više prilika da se cjenik objavi prije roka (ako objava ne uspije, pokušava se svaki sat). Promjene cijena tijekom dana ne traže novu objavu.' );
		echo '<tr><th scope="row">Decimalni separator (CSV)</th><td><select name="' . esc_attr( $o ) . '[decimalni_separator]"><option value="," ' . selected( SCJ_Settings::get( 'decimalni_separator' ), ',', false ) . '>zarez (12,50)</option><option value="." ' . selected( SCJ_Settings::get( 'decimalni_separator' ), '.', false ) . '>točka (12.50)</option></select><p class="description">Razdjelnik stupaca je točka-zarez. XML uvijek koristi točku.</p></td></tr>';
		self::field( 'csv_bom', 'UTF-8 BOM', 'checkbox', 'Dodaj BOM (Excel tada ispravno prikazuje hrvatska slova, ali neki programi ga ne vole).' );
		self::field( 'zadrzi_dana', 'Čuvaj datoteke (dana)', 'number', 'Najmanje 31. Prethodni cjenici moraju biti javno dostupni najmanje 30 dana od objave.', 'min="31" max="400"' );
		self::field( 'email_upozorenja', 'E-mail za upozorenja', 'email', 'Šalje se kad objava ne uspije ili cjenik nije objavljen do 7:45.' );
		self::field( 'cron_kljuc', 'Ključ vanjskog okidača', 'text', 'Promijenite ako je adresa okidača procurila.' );
		self::field( 'obrisi_pri_deinstalaciji', 'Deinstalacija', 'checkbox', 'Pri brisanju dodatka obriši i sve podatke (sidrene cijene, povijest, stavke). Objavljene datoteke ostaju.' );
		echo '</table>';

		submit_button( 'Spremi postavke' );
		echo '</form>';
		self::disclaimer();
		echo '</div>';
	}

	public static function page_tools() {
		self::css();
		$dry = SCJ_Tools::fill_missing( true );
		echo '<div class="wrap scj-wrap"><h1>Sidrene cijene – alati</h1>';
		self::flash();

		echo '<div class="card"><h2>1. Popuni nedostajuće sidrene cijene</h2>';
		echo '<p>Bez sidrene cijene: <strong>' . (int) $dry['woo'] . '</strong> WooCommerce proizvoda/varijacija i <strong>' . (int) $dry['stavke'] . '</strong> stavki cjenika.</p>';
		echo '<p>Upisuje <em>trenutnu redovnu cijenu</em> kao sidrenu. Točno je samo za proizvode kojima se cijena nije mijenjala od ' . esc_html( SCJ_Format::date_hr( (string) SCJ_Settings::get( 'sidreni_datum' ) ) ) . '. Za proizvode kojima se cijena mijenjala koristite uvoz (korak 2) ili ih ispravite ručno.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Upisati trenutnu redovnu cijenu kao sidrenu za sve stavke koje je nemaju?\')">';
		wp_nonce_field( 'scj_fill' );
		echo '<input type="hidden" name="action" value="scj_fill"><button class="button button-primary" ' . disabled( 0, $dry['woo'] + $dry['stavke'], false ) . '>Popuni</button></form></div>';

		echo '<div class="card"><h2>2. Izvoz i uvoz (CSV)</h2>';
		echo '<p>Preuzmite popis, u Excelu upišite stupac <code>sidrena_cijena</code> (i po potrebi <code>datum_sidrene_cijene</code>, npr. 2026-09-10 ili 10. 9. 2026.), pa ga vratite. WooCommerce proizvodi se prepoznaju po <code>id</code> ili <code>sifra</code> (SKU) i za njih se upisuje samo sidrena cijena.</p>';
		echo '<p><strong>Stavke cjenika</strong> se uvozom mogu i stvoriti: redak s nazivom i cijenom koji ne odgovara postojećoj stavci (po <code>id</code>, <code>sifra</code> ili nazivu) postaje nova stavka. Prazne ćelije ne mijenjaju ništa, a <code>-</code> briše vrijednost. Stupci: ' . esc_html( implode( ', ', SCJ_Tools::COLUMNS ) ) . '. Dovoljni su oni koje trebate, npr. <code>sifra;naziv;redovna_cijena;sidrena_cijena;grupa</code>. Više grupa odvojite znakom <code>|</code>.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:12px">';
		wp_nonce_field( 'scj_export_anchors' );
		echo '<input type="hidden" name="action" value="scj_export_anchors"><button class="button">Preuzmi CSV</button></form>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'scj_import_anchors' );
		echo '<input type="hidden" name="action" value="scj_import_anchors"><input type="file" name="scj_file" accept=".csv,text/csv"> <button class="button button-primary">Uvezi</button></form>';
		echo '<p class="description">Savjet: cijene s 10. 9. često se mogu rekonstruirati iz narudžbi tog dana, kopije baze ili arhive stranice (web.archive.org).</p></div>';

		echo '<div class="card"><h2>3. Promjene sidrenih cijena</h2>';
		echo '<p>Sidrena cijena je cijena na dan ' . esc_html( SCJ_Format::date_hr( (string) SCJ_Settings::get( 'sidreni_datum' ) ) ) . ' i ne bi se smjela mijenjati. Ovdje se bilježi svaka izmjena već upisane sidrene cijene ili datuma (prvi upis ne), da se može objasniti ako netko pita. Zadnjih 100 promjena:</p>';
		SCJ_Anchor_Log::render();
		echo '</div>';

		echo '<div class="card"><h2>4. Kratki kodovi (stranice bez WooCommercea)</h2>';
		echo '<p><code>[sidrena_cjenik vrsta="usluge"]</code> tablica usluga sa sidrenom cijenom<br>';
		echo '<code>[sidrena_cjenik vrsta="proizvodi" grupa="slug-grupe"]</code> tablica proizvoda iz grupe<br>';
		echo '<code>[sidrena_cijena id="123"]</code> cijena jedne stavke<br>';
		echo '<code>[sidrena_arhiva]</code> poveznice na CSV/XML cjenike</p>';
		echo '<p>Stavke unosite u <a href="' . esc_url( admin_url( 'edit.php?post_type=' . SCJ_Items::CPT ) ) . '">Stavke cjenika</a>.</p></div>';

		echo '</div>';
	}
}
