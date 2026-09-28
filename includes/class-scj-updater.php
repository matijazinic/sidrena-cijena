<?php
defined( 'ABSPATH' ) || exit;

/**
 * Automatska ažuriranja iz GitHub releasea (plugin nije na wordpress.org).
 *
 * Svaki release ima dvije datoteke (dev/release.sh):
 *   sidrena-cijena.zip  instalacijski paket (mapa sidrena-cijena/)
 *   info.json           verzija, poveznica na paket, zahtjevi i changelog
 *
 * Čita se https://github.com/<repo>/releases/latest/download/info.json – obična preusmjerena datoteka,
 * ne GitHub API, pa nema ograničenja od 60 zahtjeva na sat po IP-u (dijeljeni hosting).
 * Zaglavlje "Update URI" jamči da WordPress ovaj plugin nikad ne zamijeni istoimenim s wordpress.org.
 */
class SCJ_Updater {

	const REPO      = 'matijazinic/sidrena-cijena';
	const CACHE     = 'scj_update_info';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		// "Provjeri ponovno" na stranici Ažuriranja treba zaobići predmemoriju.
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_flush_on_force' ) );
	}

	public static function info_url() {
		$url = defined( 'SCJ_UPDATE_INFO_URL' ) ? SCJ_UPDATE_INFO_URL : 'https://github.com/' . self::REPO . '/releases/latest/download/info.json';
		return (string) apply_filters( 'scj_update_info_url', $url );
	}

	/**
	 * Podaci o zadnjem releaseu ili null (greška se predmemorira kraće da ne usporava admin).
	 */
	public static function info() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$r    = wp_remote_get(
			self::info_url(),
			array(
				'timeout'     => 10,
				'redirection' => 5,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		$data = null;
		if ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ) {
			$json = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( is_array( $json ) && ! empty( $json['version'] ) && ! empty( $json['download_url'] ) && self::safe_url( $json['download_url'] ) ) {
				$data = $json;
			}
		}
		set_site_transient( self::CACHE, $data ? $data : array( 'version' => '' ), $data ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Paket samo preko HTTPS-a (http dopušten samo na lokalnoj razvojnoj instalaciji, za test ažuriranja).
	 */
	private static function safe_url( $url ) {
		return 0 === strpos( $url, 'https://' ) || ( 'local' === wp_get_environment_type() && 0 === strpos( $url, 'http://' ) );
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	public static function maybe_flush_on_force() {
		if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::flush();
		}
	}

	/**
	 * Filter update_plugins_github.com: WordPress ga poziva za plugine s "Update URI" na github.com.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( SCJ_FILE ) !== $plugin_file ) {
			return $update;
		}
		$info = self::info();
		if ( ! $info ) {
			return $update;
		}
		return array(
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => 'sidrena-cijena',
			'plugin'       => $plugin_file,
			'version'      => $info['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $info['download_url'],
			'requires'     => isset( $info['requires'] ) ? $info['requires'] : '',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'icons'        => array(),
		);
	}

	/**
	 * Prozor "Prikaži detalje verzije" na stranici dodataka.
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'sidrena-cijena' !== $args->slug ) {
			return $result;
		}
		$info = self::info();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Sidrena cijena i cjenik',
			'slug'          => 'sidrena-cijena',
			'version'       => $info['version'],
			'author'        => '<a href="https://zin-digital.com/">Matija Žinić</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $info['download_url'],
			'requires'      => isset( $info['requires'] ) ? $info['requires'] : '',
			'tested'        => isset( $info['tested'] ) ? $info['tested'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'last_updated'  => isset( $info['released'] ) ? $info['released'] : '',
			'sections'      => array(
				'description' => isset( $info['description'] ) ? wp_kses_post( $info['description'] ) : '',
				'changelog'   => isset( $info['changelog'] ) ? wp_kses_post( $info['changelog'] ) : '',
			),
		);
	}
}
