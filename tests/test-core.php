<?php
require __DIR__ . '/harness.php'; // Pokretanje: php tests/test-core.php

$fails = 0;
function ok( $cond, $msg ) { global $fails; echo ( $cond ? "  PASS " : "  FAIL " ) . $msg . "\n"; if ( ! $cond ) { $fails++; } }

echo "== Format\n";
ok( SCJ_Format::parse_price( '1.234,56' ) === 1234.56, 'parse 1.234,56' );
ok( SCJ_Format::parse_price( '12,5 €' ) === 12.5, 'parse 12,5 €' );
ok( SCJ_Format::parse_price( '1,234.50' ) === 1234.5, 'parse 1,234.50' );
ok( SCJ_Format::parse_price( '' ) === null && SCJ_Format::parse_price( 'abc' ) === null, 'parse prazno/abc = null' );
ok( SCJ_Format::date_hr( '2026-09-10' ) === '10. 9. 2026.', 'datum 10. 9. 2026.' );
ok( SCJ_Format::money( 45 ) === '45,00 €', 'money 45,00 €' );
ok( 'Ulica Ivana Gundulića 5 Čakovec' === SCJ_Format::name_part( 'Ulica Ivana Gundulića 5, Čakovec' ) && 'Vukovarska 20-2 Osijek' === SCJ_Format::name_part( 'Vukovarska 20/2  Osijek' ), 'dio naziva datoteke: kako je upisan, bez zareza i kose crte' );
$pl = array();
foreach ( array( 1, 2, 5, 11, 12, 14, 21, 22, 25, 101, 111 ) as $n ) {
	$pl[] = $n . ' ' . SCJ_Format::plural( $n, 'stavka', 'stavke', 'stavki' );
}
ok( '1 stavka,2 stavke,5 stavki,11 stavki,12 stavki,14 stavki,21 stavka,22 stavke,25 stavki,101 stavka,111 stavki' === implode( ',', $pl ), 'hrvatska množina' );

echo "== Postavke\n";
SCJ_Settings::ensure_defaults();
ok( count( SCJ_Settings::missing() ) === 3, 'bez WooCommercea nedostaju oblik, adresa i oznaka (oblik se mora upisati)' );
SCJ_Settings::update( array( 'oblik_objekta' => 'Internet trgovina', 'adresa' => 'Ilica 1, Zagreb', 'oznaka' => 'WEB1', 'oib' => '12345678901' ) );
ok( ! SCJ_Settings::missing(), 'postavke potpune' );
$san = SCJ_Settings::sanitize( array( 'zadrzi_dana' => '5', 'formati' => array( 'pdf' ), 'vrijeme_objave' => '25:00', 'decimalni_separator' => ';' ) );
ok( 31 === $san['zadrzi_dana'] && array( 'csv' ) === $san['formati'] && '01:00' === $san['vrijeme_objave'] && ',' === $san['decimalni_separator'], 'sanitize: min 31 dan, neispravni format/vrijeme odbijeni' );

echo "== Stavke\n";
$u1 = t_add_post( 'Šišanje, muško', array( 'tip' => 'usluga', 'cijena' => '15', 'sidrena' => '14' ), 'publish', 1 );
$u2 = t_add_post( 'Bojanje "kratka kosa"; s pranjem', array( 'tip' => 'usluga', 'cijena' => '40', 'akcijska' => '32', 'akcija_naziv' => 'Jesenska akcija', 'sidrena' => '40' ), 'publish', 2 );
$u3 = t_add_post( 'Usluga bez sidrene', array( 'tip' => 'usluga', 'cijena' => '10' ), 'publish', 3 );
$u4 = t_add_post( 'Samo B2B', array( 'tip' => 'usluga', 'cijena' => '99', 'sidrena' => '99', 'iskljuci' => '1' ) );
$u5 = t_add_post( 'Skica', array( 'tip' => 'usluga', 'cijena' => '5' ), 'draft' );
$p1 = t_add_post( 'Šampon 500 ml', array( 'tip' => 'proizvod', 'cijena' => '8', 'sidrena' => '7.5', 'datum' => '2025-05-02', 'jedinica' => 'l', 'kolicina' => '0.5', 'barkod' => '3850000000001', 'marka' => 'Kozmo', 'sifra' => 'SAM-500', 'dostupno' => '0' ) );
$d = SCJ_Items::data( $u2 );
ok( $d['na_akciji'] && 32.0 === $d['trenutna'] && 40.0 === $d['redovna'], 'akcijska cijena prepoznata' );
ok( '2026-09-10' === SCJ_Items::data( $u1 )['datum'], 'zadani datum 10. 9. 2026.' );
ok( '2025-05-02' === SCJ_Items::data( $p1 )['datum'], 'vlastiti datum 2. 5. 2025.' );
ok( count( SCJ_Items::ids( 'usluga' ) ) === 4 && count( SCJ_Items::ids() ) === 5, 'filtriranje po vrsti i statusu' );

echo "== Povijest i najniža cijena u 30 dana\n";
global $wpdb;
$ins = function ( $item, $reg, $price, $days_ago ) use ( $wpdb ) {
	$wpdb->insert( 'wp_scj_price_log', array( 'item' => $item, 'regular' => $reg, 'price' => $price, 'logged_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * 86400 ) ), array() );
};
$ins( 'st:X', 50, 50, 60 );   // 50 od prije 60 dana
$ins( 'st:X', 50, 45, 20 );   // kratka akcija 45 prije 20 dana
$ins( 'st:X', 50, 50, 15 );   // natrag na 50
$ins( 'st:X', 50, 40, 2 );    // nova akcija 40 prije 2 dana
ok( 45.0 === SCJ_History::omnibus_lowest( 'st:X', 40 ), 'najniža prije sniženja = 45 (akcija unutar 30 dana)' );
$ins( 'st:Y', 50, 30, 50 );
$ins( 'st:Y', 50, 50, 40 );
$ins( 'st:Y', 50, 42, 1 );
ok( 50.0 === SCJ_History::omnibus_lowest( 'st:Y', 42 ), 'akcija starija od 30 dana se ne računa (=50)' );
ok( null === SCJ_History::omnibus_lowest( 'st:NEMA', 10 ), 'bez povijesti = null' );
ok( true === SCJ_History::record( 'st:Z', 10, 10 ) && false === SCJ_History::record( 'st:Z', 10, 10 ) && true === SCJ_History::record( 'st:Z', 10, 9 ), 'bilježi samo promjene' );
$ins( 'st:OLD', 1, 1, 500 );
$ins( 'st:OLD', 1, 2, 450 );
$ins( 'st:OLD', 1, 3, 10 );
$ins( 'st:ONLYOLD', 1, 5, 500 );
SCJ_History::purge();
ok( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_scj_price_log WHERE item='st:OLD'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_scj_price_log WHERE item='st:ONLYOLD'" ), 'purge briše staro, čuva zadnji zapis' );

echo "== Objava\n";
ok( SCJ_Exporter::publish( 'test' ) === true, 'publish vraća true' );
$files = SCJ_Exporter::files();
$names = array_column( $files, 'name' );
print_r( $names );
ok( count( $files ) === 4, '4 datoteke (proizvodi/usluge × csv/xml)' );
$re = '/^Internet trgovina_Ilica 1 Zagreb_WEB1_(\d+)_\d{2}\.\d{2}\.\d{4}_\d{2}:\d{2}\.csv$/';
$np = preg_match( $re, SCJ_Exporter::latest( 'proizvodi', 'csv' )['name'], $mp );
$nu = preg_match( $re, SCJ_Exporter::latest( 'usluge', 'csv' )['name'], $mu );
ok( $np && $nu, 'nazivi kao primjer Ministarstva: oblik_adresa_oznaka_broj_DD.MM.GGGG_HH:MM, bez nastavka' );
ok( $np && $nu && array( 1, 2 ) === array( min( (int) $mp[1], (int) $mu[1] ), max( (int) $mp[1], (int) $mu[1] ) ) && 2 === (int) get_option( 'scj_counter' ), 'proizvodi i usluge: zasebni brojevi pohrane (1 i 2), brojač na 2' );
$csv = file_get_contents( $GLOBALS['T_UP'] . '/cjenici/' . SCJ_Exporter::latest( 'usluge', 'csv' )['name'] );
echo $csv;
$lines = array_map( function ( $l ) { return str_getcsv( $l, ';', '"', '' ); }, array_filter( explode( "\n", $csv ) ) );
ok( $lines[0] === array( 'naziv', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'sidrena_cijena' ), 'zaglavlje usluga' );
ok( count( $lines ) === 4, '3 usluge (isključena i skica nisu u cjeniku)' );
ok( $lines[2] === array( 'Bojanje "kratka kosa"; s pranjem', '32,00', 'da', 'Jesenska akcija', '40,00' ), 'akcija, navodnici i ; ispravno' );
ok( $lines[3][4] === '', 'nedostajuća sidrena ostaje prazna' );
$pcsv = file_get_contents( $GLOBALS['T_UP'] . '/cjenici/' . SCJ_Exporter::latest( 'proizvodi', 'csv' )['name'] );
echo $pcsv;
$pl = str_getcsv( explode( "\n", $pcsv )[1], ';', '"', '' );
ok( $pl === array( 'Šampon 500 ml', 'SAM-500', 'Kozmo', 'l', '16,00', '8,00', 'ne', '', '7,50', '3850000000001', 'nedostupno' ), 'redak proizvoda: cijena/l = 16,00, nedostupno' );

$xmlf = $GLOBALS['T_UP'] . '/cjenici/' . SCJ_Exporter::latest( 'usluge', 'xml' )['name'];
libxml_use_internal_errors( true );
$x = simplexml_load_file( $xmlf );
ok( false !== $x, 'XML je ispravno formiran' );
ok( $x && 3 === count( $x->usluge->usluga ) && '32.00' === (string) $x->usluge->usluga[1]->maloprodajna_cijena && 'WEB1' === (string) $x->trgovac->oznaka_prodajnog_objekta, 'XML sadržaj (točka kao decimala)' );
echo file_get_contents( $xmlf );

$log = get_option( 'scj_log' );
$l   = end( $log );
ok( 1 === $l['missing'] && 3 === $l['counts']['usluge'] && 1 === $l['counts']['proizvodi'], 'dnevnik: 1 bez sidrene, brojevi' );
ok( SCJ_Scheduler::published_today(), 'published_today()' );
$json = json_decode( file_get_contents( $GLOBALS['T_UP'] . '/cjenici/index.json' ), true );
ok( 4 === count( $json['cjenici'] ), 'index.json navodi 4 datoteke' );
ok( file_exists( $GLOBALS['T_UP'] . '/cjenici/index.html' ), 'index.html postoji' );
ok( ! glob( $GLOBALS['T_UP'] . '/cjenici/*.tmp' ), 'nema zaostalih .tmp' );

echo "== Druga objava ne prepisuje, arhiva i čišćenje\n";
sleep( 1 );
SCJ_Exporter::publish( 'test 2' );
ok( 8 === count( SCJ_Exporter::files() ), 'druga objava = nove datoteke (8 ukupno)' );
ok( 4 === (int) get_option( 'scj_counter' ) && false === strpos( SCJ_Exporter::latest( 'usluge', 'csv' )['name'], '_WEB1_1_' ) && false === strpos( SCJ_Exporter::latest( 'usluge', 'csv' )['name'], '_WEB1_2_' ), 'broj pohrane raste (druga objava: 3 i 4)' );
$old = $GLOBALS['T_UP'] . '/cjenici/Internet-trgovina_Ilica-1-Zagreb_WEB1_00000_20260801-060000_usluge.csv';
file_put_contents( $old, "x" );
touch( $old, time() - 40 * 86400 );
$recent = $GLOBALS['T_UP'] . '/cjenici/Internet-trgovina_Ilica-1-Zagreb_WEB1_00000_20260901-060000_usluge.xml';
file_put_contents( $recent, "x" );
touch( $recent, time() - 25 * 86400 );
SCJ_Exporter::cleanup();
ok( ! file_exists( $old ) && file_exists( $recent ), 'briše >35 dana, čuva <30 dana' );

echo "== Uživo (REST)\n";
$live = SCJ_Exporter::build_live( 'usluge', 'csv' );
ok( 4 === count( array_filter( explode( "\n", $live ) ) ), 'build_live usluge csv' );

echo "== Raspored\n";
SCJ_Settings::update( array( 'vrijeme_objave' => '06:00' ) );
$ts = SCJ_Scheduler::next_publish_ts();
ok( '06:00' === wp_date( 'H:i', $ts ) && $ts > time() && $ts - time() <= 86400, 'sljedeća objava u 06:00 po zagrebačkom vremenu' );

echo "== Akcije s datumom (stavke)\n";
$jucer = wp_date( 'Y-m-d', time() - 86400 );
$sutra = wp_date( 'Y-m-d', time() + 86400 );
$danas = wp_date( 'Y-m-d' );
$a1 = t_add_post( 'Akcija buduća', array( 'tip' => 'usluga', 'cijena' => '50', 'akcijska' => '40', 'akcija_od' => $sutra, 'sidrena' => '50' ), 'draft' );
$a2 = t_add_post( 'Akcija traje', array( 'tip' => 'usluga', 'cijena' => '50', 'akcijska' => '40', 'akcija_od' => $jucer, 'akcija_do' => $danas, 'sidrena' => '50' ), 'draft' );
$a3 = t_add_post( 'Akcija istekla', array( 'tip' => 'usluga', 'cijena' => '50', 'akcijska' => '40', 'akcija_do' => $jucer, 'sidrena' => '50' ), 'draft' );
$d1 = SCJ_Items::data( $a1 ); $d2 = SCJ_Items::data( $a2 ); $d3 = SCJ_Items::data( $a3 );
ok( ! $d1['na_akciji'] && 50.0 === $d1['trenutna'], 'akcija od sutra još ne vrijedi' );
ok( $d2['na_akciji'] && 40.0 === $d2['trenutna'], 'akcija od jučer do danas (uključivo) vrijedi' );
ok( ! $d3['na_akciji'] && 50.0 === $d3['trenutna'], 'akcija do jučer je istekla' );
ok( SCJ_Items::sale_from_ts( $d2 ) === ( new DateTimeImmutable( $jucer . ' 00:00:00', wp_timezone() ) )->getTimestamp() && null === SCJ_Items::sale_from_ts( $d3 ), 'početak akcije za najnižu cijenu = ponoć po zagrebačkom vremenu' );

echo "== Kratki kod\n";
$html = SCJ_Frontend::sc_cjenik( array( 'vrsta' => 'usluge' ) );
ok( false !== strpos( $html, 'Cijena na dan 10. 9. 2026.' ) && false !== strpos( $html, '<del>40,00 €</del>' ), 'tablica s naslovom sidrene cijene i precrtanom cijenom' );
ok( false !== strpos( $html, 'Najniža cijena u zadnjih 30 dana prije sniženja: 40,00 €' ), 'najniža cijena kod akcije (bez povijesti = redovna)' );
echo $html, "\n";
$sid = SCJ_Frontend::sc_cijena( array( 'id' => $u1, 'prikaz' => 'sidrena' ) );
ok( '<span class="scj-sidrena">Cijena na dan 10. 9. 2026.: 14,00 €</span>' === $sid, 'prikaz="sidrena" ispisuje samo sidrenu cijenu' );
ok( '<span class="scj-sidrena">14,00 €</span>' === SCJ_Frontend::sc_cijena( array( 'id' => $u1, 'prikaz' => 'sidrena', 'oznaka' => '' ) ), 'oznaka="" = samo iznos' );
ok( false !== strpos( SCJ_Frontend::sc_cijena( array( 'id' => $u1, 'prikaz' => 'sidrena', 'oznaka' => 'Na dan {datum}' ) ), 'Na dan 10. 9. 2026. 14,00 €' ), 'vlastita oznaka s {datum}' );
ok( '' === SCJ_Frontend::sc_cijena( array( 'id' => $u3, 'prikaz' => 'sidrena' ) ) && '' === SCJ_Frontend::sc_cijena( array( 'id' => $u1, 'prikaz' => 'najniza' ) ), 'bez sidrene / bez akcije = prazno' );
ok( false !== strpos( SCJ_Frontend::sc_cijena( array( 'id' => $u2, 'prikaz' => 'najniza' ) ), '40,00 €' ) && false === strpos( SCJ_Frontend::sc_cijena( array( 'id' => $u2, 'prikaz' => 'cijena' ) ), 'scj-sidrena' ), 'prikaz="najniza" i prikaz="cijena"' );
ok( false !== strpos( SCJ_Frontend::sc_cijena( array( 'sifra' => 'SAM-500', 'prikaz' => 'sidrena' ) ), '7,50 €' ) && '' === SCJ_Frontend::sc_cijena( array( 'sifra' => 'NEMA' ) ), 'odabir stavke po šifri' );
$st = scj_stavka( 'SAM-500' );
ok( $st && '7,50 €' === $st['sidrena_tekst'] && 'Cijena na dan 2. 5. 2025.:' === $st['oznaka_sidrene'] && null === scj_stavka( 99999 ), 'scj_stavka() za predloške' );

echo "\n" . ( $fails ? "NEUSPJELO: $fails" : "SVE PROŠLO" ) . "\n";
exit( $fails ? 1 : 0 );
