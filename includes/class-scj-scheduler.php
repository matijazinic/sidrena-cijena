<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zakazivanje objave.
 *
 * - "scj_daily" jednokratni događaj u zadano lokalno vrijeme, ponovno se zakazuje nakon svakog izvođenja
 *   (točno i kod prelaska na ljetno/zimsko vrijeme).
 * - "scj_tick" svaki sat: ako današnja objava nije uspjela, pokušava ponovno (zaštita od propuštenog crona).
 * - "scj_publish_now" jednokratna objava nakon promjene cijene usluge.
 * - Vanjski okidač: https://stranica.hr/?scj_cron=KLJUC (za cron-job.org ili sistemski cron).
 */
class SCJ_Scheduler {

	public static function init() {
		add_action( 'scj_daily', array( __CLASS__, 'run_daily' ) );
		add_action( 'scj_tick', array( __CLASS__, 'tick' ) );
		add_action( 'scj_publish_now', array( __CLASS__, 'run_on_change' ) );
		add_action( 'init', array( __CLASS__, 'external_trigger' ), 1 );
		add_action( 'update_option_' . SCJ_Settings::OPTION, array( __CLASS__, 'reschedule' ), 10, 0 );

		if ( ! wp_next_scheduled( 'scj_tick' ) || ! wp_next_scheduled( 'scj_daily' ) ) {
			self::schedule();
		}
	}

	public static function now() {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Sljedeći trenutak objave (UTC timestamp).
	 */
	public static function next_publish_ts() {
		list( $h, $m ) = array_map( 'intval', explode( ':', (string) SCJ_Settings::get( 'vrijeme_objave' ) ) );
		$now    = self::now();
		$target = $now->setTime( $h, $m, 0 );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'scj_tick' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'scj_tick' );
		}
		if ( ! wp_next_scheduled( 'scj_daily' ) ) {
			wp_schedule_single_event( self::next_publish_ts(), 'scj_daily' );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( 'scj_tick' );
		wp_clear_scheduled_hook( 'scj_daily' );
		wp_clear_scheduled_hook( 'scj_publish_now' );
	}

	public static function reschedule() {
		SCJ_Settings::flush_cache();
		wp_clear_scheduled_hook( 'scj_daily' );
		wp_schedule_single_event( self::next_publish_ts(), 'scj_daily' );
	}

	public static function run_daily() {
		SCJ_Exporter::publish( 'dnevna objava' );
		SCJ_History::purge();
		wp_clear_scheduled_hook( 'scj_daily' );
		wp_schedule_single_event( self::next_publish_ts(), 'scj_daily' );
	}

	public static function run_on_change() {
		SCJ_Exporter::publish( 'promjena cijene' );
	}

	/**
	 * Zakazuje objavu za 2 minute (više promjena zaredom = jedna objava).
	 */
	public static function queue_publish() {
		if ( ! wp_next_scheduled( 'scj_publish_now' ) ) {
			wp_schedule_single_event( time() + 120, 'scj_publish_now' );
		}
	}

	/**
	 * Je li današnja objava već uspješno napravljena?
	 */
	public static function published_today() {
		$last = get_option( 'scj_last_success' );
		if ( ! $last ) {
			return false;
		}
		$today = self::now()->format( 'Y-m-d' );
		return wp_date( 'Y-m-d', (int) $last ) === $today;
	}

	/**
	 * Je li današnja objava bila prije roka (8:00)?
	 */
	public static function published_today_on_time() {
		$first = get_option( 'scj_first_success_today' );
		if ( ! is_array( $first ) || empty( $first['date'] ) || self::now()->format( 'Y-m-d' ) !== $first['date'] ) {
			return false;
		}
		return $first['time'] <= '08:00';
	}

	/**
	 * Današnje vrijeme objave (lokalno).
	 */
	public static function today_target() {
		list( $h, $m ) = array_map( 'intval', explode( ':', (string) SCJ_Settings::get( 'vrijeme_objave' ) ) );
		return self::now()->setTime( $h, $m, 0 );
	}

	public static function tick() {
		SCJ_Items::sync_scheduled_sales();
		$now    = self::now();
		$target = self::today_target();

		if ( $now >= $target && ! self::published_today() ) {
			$ok = SCJ_Exporter::publish( 'kontrolna objava' );
			if ( ! $ok ) {
				self::maybe_alert( 'Objava cjenika nije uspjela.' );
			}
		}

		if ( $now->format( 'H:i' ) >= '07:45' && ! self::published_today() ) {
			self::maybe_alert( 'Današnji cjenik još nije objavljen, a rok je 8:00.' );
		}

		if ( ! wp_next_scheduled( 'scj_daily' ) ) {
			wp_schedule_single_event( self::next_publish_ts(), 'scj_daily' );
		}
	}

	/**
	 * E-mail upozorenje, najviše jednom dnevno.
	 */
	public static function maybe_alert( $message ) {
		$email = SCJ_Settings::get( 'email_upozorenja' );
		$today = self::now()->format( 'Y-m-d' );
		if ( ! $email || get_option( 'scj_alert_sent' ) === $today ) {
			return;
		}
		update_option( 'scj_alert_sent', $today, false );
		$log  = get_option( 'scj_log', array() );
		$last = $log ? end( $log ) : null;
		$body = $message . "\n\nStranica: " . home_url( '/' );
		if ( $last && ! empty( $last['errors'] ) ) {
			$body .= "\nZadnja greška: " . implode( '; ', (array) $last['errors'] );
		}
		$body .= "\n\nStatus: " . admin_url( 'admin.php?page=scj-status' );
		wp_mail( $email, '[Sidrena cijena] ' . $message, $body );
	}

	/**
	 * Vanjski okidač: /?scj_cron=KLJUC
	 */
	public static function external_trigger() {
		if ( empty( $_GET['scj_cron'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$key = (string) SCJ_Settings::get( 'cron_kljuc' );
		if ( '' === $key || ! hash_equals( $key, (string) wp_unslash( $_GET['scj_cron'] ) ) ) { // phpcs:ignore
			status_header( 403 );
			exit( 'Neispravan ključ.' );
		}
		nocache_headers();
		// UptimeRobot zove svakih 5 minuta; zapis ne treba češće od 10 minuta.
		if ( time() - (int) get_option( 'scj_external_seen', 0 ) > 10 * MINUTE_IN_SECONDS ) {
			update_option( 'scj_external_seen', time(), false );
			update_option( 'scj_external_ua', substr( sanitize_text_field( wp_unslash( isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '' ) ), 0, 120 ), false ); // phpcs:ignore
		}
		self::tick();
		header( 'Content-Type: text/plain; charset=utf-8' );
		if ( self::published_today() ) {
			echo 'OK: cjenik je danas objavljen.';
		} elseif ( self::now() < self::today_target() ) {
			echo 'OK: današnja objava je zakazana za ' . esc_html( (string) SCJ_Settings::get( 'vrijeme_objave' ) ) . '.';
		} else {
			// Greška (HTTP 500) da servis za okidanje javi korisniku, neovisno o e-mailu sa stranice.
			status_header( 500 );
			echo 'GREŠKA: cjenik danas nije objavljen. Pogledajte Sidrene cijene -> Status.';
		}
		exit;
	}

	public static function external_url() {
		return add_query_arg( 'scj_cron', rawurlencode( (string) SCJ_Settings::get( 'cron_kljuc' ) ), home_url( '/' ) );
	}
}
