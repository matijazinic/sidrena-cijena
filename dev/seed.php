<?php
/**
 * Testni podaci za lokalnu instalaciju:  wp eval-file dev/seed.php
 * Briše prethodne testne proizvode (SKU "T-...") i stavke pa ih stvara ponovo.
 */

// PDV 25 %, cijene unesene s PDV-om, prikaz s PDV-om.
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_tax_display_shop', 'incl' );
update_option( 'woocommerce_tax_display_cart', 'incl' );
update_option( 'woocommerce_tax_based_on', 'base' );
global $wpdb;
if ( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates" ) ) {
	WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'HR',
			'tax_rate'          => '25.0000',
			'tax_rate_name'     => 'PDV',
			'tax_rate_priority' => 1,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);
}

SCJ_Settings::update(
	array(
		'adresa'      => 'Ilica 1, Zagreb',
		'oznaka'      => 'WEB1',
		'oib'         => '12345678903',
		'marka_zadana' => 'Vlastita marka',
	)
);

// Čišćenje.
foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'any', 'return' => 'ids' ) ) as $id ) {
	$p = wc_get_product( $id );
	if ( $p && 0 === strpos( (string) $p->get_sku(), 'T-' ) ) {
		$p->delete( true );
	}
}
foreach ( get_posts( array( 'post_type' => 'scj_stavka', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
$wpdb->query( "TRUNCATE {$wpdb->prefix}scj_price_log" );

function scj_seed_cat( $name, $parent = 0 ) {
	$t = term_exists( $name, 'product_cat', $parent );
	if ( ! $t ) {
		$t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	}
	return (int) $t['term_id'];
}
$cat_hrana  = scj_seed_cat( 'Hrana' );
$cat_kava   = scj_seed_cat( 'Kava', $cat_hrana );
$cat_odjeca = scj_seed_cat( 'Odjeća' );
SCJ_Settings::update( array( 'stare_kategorije' => array( $cat_hrana ) ) );

$old = new WC_DateTime( '2026-08-01 10:00:00', new DateTimeZone( 'UTC' ) );

function scj_seed_simple( $a ) {
	$p = isset( $a['class'] ) ? new $a['class']() : new WC_Product_Simple();
	$p->set_name( $a['name'] );
	$p->set_sku( $a['sku'] );
	$p->set_regular_price( $a['regular'] );
	if ( isset( $a['sale'] ) ) {
		$p->set_sale_price( $a['sale'] );
	}
	if ( isset( $a['from'] ) ) {
		$p->set_date_on_sale_from( $a['from'] );
	}
	if ( isset( $a['to'] ) ) {
		$p->set_date_on_sale_to( $a['to'] );
	}
	if ( isset( $a['created'] ) ) {
		$p->set_date_created( $a['created'] );
	}
	$p->set_status( isset( $a['status'] ) ? $a['status'] : 'publish' );
	$p->set_category_ids( isset( $a['cats'] ) ? $a['cats'] : array() );
	if ( isset( $a['stock'] ) ) {
		$p->set_stock_status( $a['stock'] );
	}
	if ( isset( $a['gtin'] ) ) {
		$p->set_global_unique_id( $a['gtin'] );
	}
	if ( isset( $a['url'] ) ) {
		$p->set_product_url( $a['url'] );
	}
	if ( ! empty( $a['virtual'] ) ) {
		$p->set_virtual( true );
	}
	foreach ( isset( $a['meta'] ) ? $a['meta'] : array() as $k => $v ) {
		$p->update_meta_data( $k, $v );
	}
	$p->save();
	return $p->get_id();
}

$ids = array();
// Stari proizvod bez sidrene (postojao 10. 9.).
$ids['stari']   = scj_seed_simple( array( 'name' => 'Majica "Zagreb" & <b>logo</b>', 'sku' => 'T-MAJICA', 'regular' => '20', 'created' => $old, 'cats' => array( $cat_odjeca ), 'gtin' => '3850000000011' ) );
// Stari proizvod sa sidrenom, na akciji bez datuma.
$ids['akcija']  = scj_seed_simple( array( 'name' => 'Hlače', 'sku' => 'T-HLACE', 'regular' => '50', 'sale' => '39.99', 'created' => $old, 'cats' => array( $cat_odjeca ), 'meta' => array( '_scj_sidrena' => '50', '_scj_akcija_naziv' => 'Jesensko sniženje' ) ) );
// Hrana (stari datum), s neto količinom.
$ids['kava']    = scj_seed_simple( array( 'name' => 'Kava 500 g', 'sku' => 'T-KAVA', 'regular' => '8.49', 'created' => $old, 'cats' => array( $cat_kava ), 'meta' => array( '_scj_sidrena' => '7.99', '_scj_jedinica' => 'kg', '_scj_kolicina' => '0.5' ) ) );
// Akcija s datumom početka u prošlosti.
$ids['datirana'] = scj_seed_simple( array( 'name' => 'Jakna', 'sku' => 'T-JAKNA', 'regular' => '100', 'sale' => '80', 'from' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ), 'created' => $old, 'meta' => array( '_scj_sidrena' => '100' ) ) );
// Akcija zakazana u budućnosti (trenutno nije na akciji).
$ids['buduca']  = scj_seed_simple( array( 'name' => 'Kapa', 'sku' => 'T-KAPA', 'regular' => '15', 'sale' => '10', 'from' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ), 'created' => $old, 'meta' => array( '_scj_sidrena' => '15' ) ) );
// Novi proizvod (auto sidrena).
$ids['novi']    = scj_seed_simple( array( 'name' => 'Šal (novi)', 'sku' => 'T-SAL', 'regular' => '25' ) );
// Nedostupan.
$ids['nema']    = scj_seed_simple( array( 'name' => 'Čarape', 'sku' => 'T-CARAPE', 'regular' => '5', 'created' => $old, 'stock' => 'outofstock', 'meta' => array( '_scj_sidrena' => '5' ) ) );
// Isključen iz cjenika.
$ids['iskljucen'] = scj_seed_simple( array( 'name' => 'B2B paleta', 'sku' => 'T-B2B', 'regular' => '999', 'created' => $old, 'meta' => array( '_scj_iskljuci' => 'yes' ) ) );
// Skica.
$ids['skica']   = scj_seed_simple( array( 'name' => 'Skica proizvoda', 'sku' => 'T-DRAFT', 'regular' => '1', 'status' => 'draft' ) );
// Vanjski.
$ids['vanjski'] = scj_seed_simple( array( 'class' => 'WC_Product_External', 'name' => 'Vanjski proizvod', 'sku' => 'T-EXT', 'regular' => '30', 'created' => $old, 'url' => 'https://example.com', 'meta' => array( '_scj_sidrena' => '28' ) ) );
// Virtualni (usluga).
$ids['virtualni'] = scj_seed_simple( array( 'name' => 'Montaža (virtualno)', 'sku' => 'T-MONTAZA', 'regular' => '40', 'created' => $old, 'virtual' => true, 'meta' => array( '_scj_sidrena' => '40' ) ) );

// Varijabilni.
$attr = new WC_Product_Attribute();
$attr->set_name( 'Veličina' );
$attr->set_options( array( 'S', 'M', 'L' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$var = new WC_Product_Variable();
$var->set_name( 'Duksa' );
$var->set_sku( 'T-DUKSA' );
$var->set_attributes( array( $attr ) );
$var->set_date_created( $old );
$var->set_category_ids( array( $cat_odjeca ) );
$var->set_status( 'publish' );
$var->save();
foreach ( array( 'S' => array( '30', null, '30' ), 'M' => array( '32', '28', '32' ), 'L' => array( '34', null, null ) ) as $size => $pr ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var->get_id() );
	$v->set_attributes( array( sanitize_title( 'Veličina' ) => $size ) );
	$v->set_sku( 'T-DUKSA-' . $size );
	$v->set_regular_price( $pr[0] );
	if ( $pr[1] ) {
		$v->set_sale_price( $pr[1] );
	}
	if ( $pr[2] ) {
		$v->update_meta_data( '_scj_sidrena', $pr[2] );
	}
	$v->set_status( 'publish' );
	$v->save();
}
WC_Product_Variable::sync( $var->get_id() );
$ids['varijabilni'] = $var->get_id();

// Stavke cjenika (usluge bez Woo-a).
function scj_seed_item( $title, $meta ) {
	$id = wp_insert_post( array( 'post_type' => 'scj_stavka', 'post_status' => 'publish', 'post_title' => $title ) );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, '_scj_' . $k, $v );
	}
	SCJ_Items::after_change( $id );
	return $id;
}
$ids['usluga1'] = scj_seed_item( 'Radni sat servisera', array( 'tip' => 'usluga', 'cijena' => '45', 'sidrena' => '40', 'jedinica' => 'sat' ) );
$ids['usluga2'] = scj_seed_item( 'Dostava po m²', array( 'tip' => 'usluga', 'cijena' => '12', 'akcijska' => '10', 'sidrena' => '12', 'akcija_naziv' => 'Listopadska akcija' ) );

// Stranica s kratkim kodovima.
$page = get_page_by_path( 'cjenik-test' );
if ( ! $page ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'cjenik-test',
			'post_title'   => 'Cjenik test',
			'post_content' => "[sidrena_cjenik vrsta=\"usluge\"]\n\n[sidrena_cijena id=\"{$ids['usluga1']}\"]\n\n[sidrena_arhiva]",
		)
	);
} else {
	wp_update_post( array( 'ID' => $page->ID, 'post_content' => "[sidrena_cjenik vrsta=\"usluge\"]\n\n[sidrena_cijena id=\"{$ids['usluga1']}\"]\n\n[sidrena_arhiva]" ) );
}

wp_clear_scheduled_hook( 'scj_publish_now' );
echo wp_json_encode( $ids ) . "\n";
