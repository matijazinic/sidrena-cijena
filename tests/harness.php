<?php
/**
 * Testni okvir: simulira WordPress funkcije koje plugin koristi (bez WooCommercea),
 * s pravom SQLite bazom za povijest cijena.
 */
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
date_default_timezone_set( 'UTC' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['T_UP']    = sys_get_temp_dir() . '/scj-test-' . getmypid();
@mkdir( $GLOBALS['T_UP'], 0777, true );
$GLOBALS['T_OPT']   = array( 'admin_email' => 'test@example.com', 'timezone_string' => 'Europe/Zagreb' );
$GLOBALS['T_TR']    = array();
$GLOBALS['T_POSTS'] = array();
$GLOBALS['T_META']  = array();
$GLOBALS['T_CRON']  = array();
$GLOBALS['T_ACT']   = array();

// ---- opcije / transienti
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['T_OPT'] ) ? $GLOBALS['T_OPT'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['T_OPT'][ $k ] = $v; do_action( 'update_option_' . $k ); return true; }
function delete_option( $k ) { unset( $GLOBALS['T_OPT'][ $k ] ); }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['T_TR'] ) ? $GLOBALS['T_TR'][ $k ] : false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['T_TR'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['T_TR'][ $k ] ); }
// ---- hookovi
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['T_ACT'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { add_action( $h, $cb ); }
function do_action( $h, ...$a ) { foreach ( $GLOBALS['T_ACT'][ $h ] ?? array() as $cb ) { call_user_func_array( $cb, $a ); } }
function apply_filters( $h, $v, ...$a ) { return $v; }
function add_shortcode() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
// ---- razno
function get_bloginfo( $k ) { return 'Test obrt'; }
function wp_generate_password( $n = 12 ) { return substr( str_repeat( 'abc123', 10 ), 0, $n ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $s ) ); }
function wp_unslash( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function remove_accents( $s ) { return strtr( $s, array( 'č' => 'c', 'ć' => 'c', 'ž' => 'z', 'š' => 's', 'đ' => 'd', 'Č' => 'C', 'Ć' => 'C', 'Ž' => 'Z', 'Š' => 'S', 'Đ' => 'D' ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_timezone_string() { return 'Europe/Zagreb'; }
function wp_timezone() { return new DateTimeZone( 'Europe/Zagreb' ); }
function wp_date( $f, $ts = null ) { $d = new DateTime( '@' . ( null === $ts ? time() : $ts ) ); $d->setTimezone( wp_timezone() ); return $d->format( $f ); }
function wp_upload_dir( $a = null, $b = true ) { return array( 'basedir' => $GLOBALS['T_UP'], 'baseurl' => 'https://primjer.hr/wp-content/uploads' ); }
function wp_mkdir_p( $p ) { return @mkdir( $p, 0777, true ) || is_dir( $p ); }
function wp_is_writable( $p ) { return is_writable( $p ); }
function wp_delete_file( $p ) { @unlink( $p ); }
function home_url( $p = '' ) { return 'https://primjer.hr' . $p; }
function admin_url( $p = '' ) { return 'https://primjer.hr/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://primjer.hr/wp-json/' . $p; }
function add_query_arg( $a, $b = null, $c = null ) { if ( is_array( $a ) ) { return $b . '?' . http_build_query( $a ); } return $c . '?' . $a . '=' . $b; }
function wp_next_scheduled( $h ) { return $GLOBALS['T_CRON'][ $h ] ?? false; }
function wp_schedule_single_event( $ts, $h ) { $GLOBALS['T_CRON'][ $h ] = $ts; }
function wp_schedule_event( $ts, $r, $h ) { $GLOBALS['T_CRON'][ $h ] = $ts; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['T_CRON'][ $h ] ); }
function wp_mail() { $GLOBALS['T_MAIL'][] = func_get_args(); return true; }
function wp_cache_flush_runtime() {}
function selected() { return ''; }
function checked() { return ''; }
// ---- postovi i meta
function t_add_post( $title, array $meta, $status = 'publish', $order = 0 ) {
	$id = count( $GLOBALS['T_POSTS'] ) + 100;
	$GLOBALS['T_POSTS'][ $id ] = (object) array( 'ID' => $id, 'post_title' => $title, 'post_type' => 'scj_stavka', 'post_status' => $status, 'menu_order' => $order );
	foreach ( $meta as $k => $v ) { $GLOBALS['T_META'][ $id ][ '_scj_' . $k ] = $v; }
	return $id;
}
function get_post( $p ) { if ( is_object( $p ) ) { return $p; } return $GLOBALS['T_POSTS'][ (int) $p ] ?? null; }
function get_the_title( $p ) { return get_post( $p )->post_title; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_meta( $id, $k, $s = true ) { return $GLOBALS['T_META'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['T_META'][ $id ][ $k ] = $v; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['T_POSTS'] as $id => $p ) {
		if ( 'publish' === ( $args['post_status'] ?? 'publish' ) && 'publish' !== $p->post_status ) { continue; }
		if ( ! empty( $args['meta_query'] ) ) {
			$mq = $args['meta_query'][0];
			if ( get_post_meta( $id, $mq['key'] ) !== $mq['value'] ) { continue; }
		}
		$out[] = $id;
	}
	return $out;
}
// ---- $wpdb na SQLiteu
class T_WPDB {
	public $prefix = 'wp_';
	public $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_scj_price_log (id INTEGER PRIMARY KEY AUTOINCREMENT, item TEXT, regular REAL NULL, price REAL NULL, logged_at TEXT)' );
	}
	public function prepare( $q, ...$a ) {
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( $a, &$i ) {
			$v = $a[ $i++ ];
			return '%s' === $m[0] ? $this->pdo->quote( (string) $v ) : ( '%d' === $m[0] ? (int) $v : (float) $v );
		}, $q );
	}
	public function get_row( $q ) { $r = $this->pdo->query( $q )->fetch( PDO::FETCH_OBJ ); return $r ?: null; }
	public function get_col( $q ) { return $this->pdo->query( $q )->fetchAll( PDO::FETCH_COLUMN ); }
	public function get_var( $q ) { $r = $this->pdo->query( $q )->fetchColumn(); return false === $r ? null : $r; }
	public function get_results( $q ) { return $this->pdo->query( $q )->fetchAll( PDO::FETCH_OBJ ); }
	public function query( $q ) { return $this->pdo->exec( $q ); }
	public function get_charset_collate() { return ''; }
	public function insert( $t, $d, $f ) {
		$cols = implode( ',', array_keys( $d ) );
		$vals = implode( ',', array_map( function ( $v ) { return null === $v ? 'NULL' : $this->pdo->quote( (string) $v ); }, $d ) );
		$this->pdo->exec( "INSERT INTO $t ($cols) VALUES ($vals)" );
	}
}
$GLOBALS['wpdb'] = new T_WPDB();

// ---- učitaj plugin (bez glavne datoteke koja poziva plugin_dir_path)
define( 'SCJ_VERSION', 'test' );
define( 'SCJ_FILE', __FILE__ );
$dir = getenv( 'SCJ_DIR' ) ?: dirname( __DIR__ );
foreach ( array( 'settings', 'format', 'history', 'items', 'exporter', 'scheduler', 'rest', 'frontend' ) as $c ) {
	require_once "$dir/includes/class-scj-$c.php";
}
final class SCJ_Plugin { public static function woo_active() { return false; } }
function shortcode_atts( $d, $a ) { return array_merge( $d, (array) $a ); }
function has_term() { return true; }
