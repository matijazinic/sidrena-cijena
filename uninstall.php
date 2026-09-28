<?php
/**
 * Brisanje podataka pri deinstalaciji (samo ako je uključeno u postavkama).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$scj_settings = get_option( 'scj_settings', array() );
if ( empty( $scj_settings['obrisi_pri_deinstalaciji'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scj_price_log" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_scj\\_%'" ); // phpcs:ignore

$scj_ids = get_posts(
	array(
		'post_type'      => 'scj_stavka',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $scj_ids as $scj_id ) {
	wp_delete_post( $scj_id, true );
}

foreach ( array( 'scj_settings', 'scj_db_version', 'scj_counter', 'scj_log', 'scj_last_success', 'scj_first_success_today', 'scj_bot_check', 'scj_external_seen', 'scj_alert_sent', 'scj_anchor_log', 'scj_external_ua' ) as $scj_opt ) {
	delete_option( $scj_opt );
}
delete_site_transient( 'scj_update_info' );
