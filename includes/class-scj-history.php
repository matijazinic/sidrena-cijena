<?php
defined( 'ABSPATH' ) || exit;

/**
 * Povijest cijena. Bilježi se samo promjena (redovna i stvarna cijena),
 * pa je tablica mala i dovoljna za izračun najniže cijene u 30 dana.
 */
class SCJ_History {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'scj_price_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item varchar(40) NOT NULL,
			regular decimal(14,4) NULL,
			price decimal(14,4) NULL,
			logged_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY item_time (item, logged_at)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'scj_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'scj_db_version' ) !== self::DB_VERSION ) {
			self::install();
			SCJ_Settings::ensure_defaults();
		}
	}

	/**
	 * Zadnji zapis za stavku.
	 */
	public static function last( $item ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT regular, price, logged_at FROM {$table} WHERE item = %s ORDER BY logged_at DESC, id DESC LIMIT 1", $item ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function same( $a, $b ) {
		if ( null === $a || null === $b ) {
			return null === $a && null === $b;
		}
		return abs( (float) $a - (float) $b ) < 0.005;
	}

	/**
	 * Zapisuje cijenu ako se promijenila od zadnjeg zapisa.
	 *
	 * @param string     $item    npr. "wc:123" ili "st:45".
	 * @param float|null $regular redovna cijena.
	 * @param float|null $price   cijena koju kupac trenutačno plaća.
	 */
	public static function record( $item, $regular, $price ) {
		global $wpdb;
		$regular = ( null === $regular || '' === $regular ) ? null : round( (float) $regular, 4 );
		$price   = ( null === $price || '' === $price ) ? null : round( (float) $price, 4 );
		$last    = self::last( $item );
		if ( $last && self::same( $last->regular, $regular ) && self::same( $last->price, $price ) ) {
			return false;
		}
		$wpdb->insert(
			self::table(),
			array(
				'item'      => $item,
				'regular'   => $regular,
				'price'     => $price,
				'logged_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%f', '%f', '%s' )
		);
		do_action( 'scj_price_changed', $item );
		return true;
	}

	/**
	 * Najniža stvarna cijena u $days dana prije trenutka $ref_ts (UTC timestamp).
	 */
	public static function lowest_before( $item, $ref_ts, $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$ref   = gmdate( 'Y-m-d H:i:s', $ref_ts );
		$start = gmdate( 'Y-m-d H:i:s', $ref_ts - $days * DAY_IN_SECONDS );

		$prices = $wpdb->get_col( $wpdb->prepare( "SELECT price FROM {$table} WHERE item = %s AND logged_at >= %s AND logged_at < %s AND price IS NOT NULL", $item, $start, $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT price FROM {$table} WHERE item = %s AND logged_at < %s AND price IS NOT NULL ORDER BY logged_at DESC, id DESC LIMIT 1", $item, $start ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null !== $before ) {
			$prices[] = $before;
		}
		if ( empty( $prices ) ) {
			return null;
		}
		return (float) min( array_map( 'floatval', $prices ) );
	}

	/**
	 * Trenutak (UTC timestamp) od kojeg vrijedi trenutna cijena, prema povijesti.
	 */
	public static function current_price_since( $item, $current_price ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT price, logged_at FROM {$table} WHERE item = %s ORDER BY logged_at DESC, id DESC LIMIT 100", $item ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$since = null;
		foreach ( $rows as $row ) {
			if ( self::same( $row->price, $current_price ) ) {
				$since = strtotime( $row->logged_at . ' UTC' );
			} else {
				break;
			}
		}
		return $since;
	}

	/**
	 * Najniža cijena u 30 dana prije sniženja (Omnibus pravilo).
	 *
	 * @param string   $item          ključ stavke.
	 * @param float    $current_price trenutna (snižena) cijena.
	 * @param int|null $sale_from_ts  početak akcije ako je poznat (UTC timestamp).
	 * @return float|null null ako nema dovoljno podataka.
	 */
	public static function omnibus_lowest( $item, $current_price, $sale_from_ts = null ) {
		$ref = $sale_from_ts ? $sale_from_ts : self::current_price_since( $item, $current_price );
		if ( ! $ref ) {
			return null;
		}
		return self::lowest_before( $item, $ref, 30 );
	}

	/**
	 * Briše zapise starije od godinu dana, osim zadnjeg zapisa po stavci.
	 */
	public static function purge() {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s AND id NOT IN (SELECT keep_id FROM (SELECT MAX(id) AS keep_id FROM {$table} GROUP BY item) AS k)", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
