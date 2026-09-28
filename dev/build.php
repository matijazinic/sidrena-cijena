<?php
/**
 * Gradi build/sidrena-cijena.zip i build/info.json iz trenutnog commita.
 *
 *   php dev/build.php                       paket za GitHub release (v<verzija>)
 *   php dev/build.php --base-url=URL        paket se preuzima s URL/sidrena-cijena.zip (lokalni test ažuriranja)
 */

const REPO = 'matijazinic/sidrena-cijena';

$root = dirname( __DIR__ );
chdir( $root );

function fail( $msg ) {
	fwrite( STDERR, "GREŠKA: $msg\n" );
	exit( 1 );
}

function header_field( $text, $name ) {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $text, $m ) ? trim( $m[1] ) : '';
}

function esc( $s ) {
	return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
}

/** readme.txt odjeljak ("= naslov =" i "* stavka") u jednostavan HTML. */
function section_html( $readme, $title ) {
	if ( ! preg_match( '/^== ' . preg_quote( $title, '/' ) . ' ==\s*$(.*?)(?=^== |\z)/ms', $readme, $m ) ) {
		return '';
	}
	$html = '';
	$list = false;
	foreach ( preg_split( '/\R/', trim( $m[1] ) ) as $line ) {
		$line = trim( $line );
		$item = preg_match( '/^[*-]\s+(.*)$/', $line, $im ) || preg_match( '/^\d+\.\s+(.*)$/', $line, $im );
		if ( $list && ! $item ) {
			$html .= '</ul>';
			$list  = false;
		}
		if ( '' === $line ) {
			continue;
		}
		if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $hm ) ) {
			$html .= '<h4>' . esc( $hm[1] ) . '</h4>';
		} elseif ( $item ) {
			$html .= ( $list ? '' : '<ul>' ) . '<li>' . esc( $im[1] ) . '</li>';
			$list  = true;
		} else {
			$html .= '<p>' . esc( $line ) . '</p>';
		}
	}
	return $html . ( $list ? '</ul>' : '' );
}

$opts     = getopt( '', array( 'base-url:' ) );
$main     = file_get_contents( 'sidrena-cijena.php' );
$readme   = file_get_contents( 'readme.txt' );
$version  = header_field( $main, 'Version' );
$constant = preg_match( "/define\(\s*'SCJ_VERSION',\s*'([^']+)'/", $main, $m ) ? $m[1] : '';
$stable   = header_field( $readme, 'Stable tag' );

if ( ! $version || $version !== $constant || $version !== $stable ) {
	fail( "verzije se ne slažu: zaglavlje '$version', SCJ_VERSION '$constant', readme Stable tag '$stable'." );
}
if ( false === strpos( $readme, "= $version =" ) ) {
	fail( "readme.txt nema changelog za $version." );
}

$base = isset( $opts['base-url'] ) ? rtrim( $opts['base-url'], '/' ) : 'https://github.com/' . REPO . '/releases/download/v' . $version;

@mkdir( 'build' );
@unlink( 'build/sidrena-cijena.zip' );
// Pakira trenutno stanje datoteka u projektu (i necommitane izmjene i nove datoteke), tako da su paket i info.json
// uvijek iz istih datoteka. Popis daje git (poštuje .gitignore), a izostavlja se sve s export-ignore u .gitattributes
// (dev/, tests/...). release.sh ionako traži čisto stablo, pa je objavljeni paket jednak commitu.
$ignore = array();
foreach ( file( '.gitattributes', FILE_IGNORE_NEW_LINES ) ?: array() as $attr ) {
	if ( preg_match( '#^/?(\S+)\s+export-ignore#', trim( $attr ), $m ) ) {
		$ignore[] = rtrim( $m[1], '/' );
	}
}
$files = array_filter(
	explode( "\n", trim( (string) shell_exec( 'git ls-files --cached --others --exclude-standard' ) ) ),
	function ( $f ) use ( $ignore ) {
		foreach ( $ignore as $i ) {
			if ( $f === $i || 0 === strpos( $f, $i . '/' ) ) {
				return false;
			}
		}
		return '' !== $f && is_file( $f );
	}
);
if ( ! in_array( 'sidrena-cijena.php', $files, true ) ) {
	fail( 'popis datoteka ne sadrži sidrena-cijena.php.' );
}
sort( $files );
$zip = new ZipArchive();
if ( true !== $zip->open( 'build/sidrena-cijena.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fail( 'ne mogu stvoriti build/sidrena-cijena.zip' );
}
foreach ( $files as $f ) {
	$zip->addFile( $f, 'sidrena-cijena/' . $f );
}
$zip->close();
$dirty = '' !== trim( (string) shell_exec( 'git status --porcelain' ) );

$info = array(
	'name'         => 'Sidrena cijena i cjenik',
	'version'      => $version,
	'download_url' => $base . '/sidrena-cijena.zip',
	'requires'     => header_field( $readme, 'Requires at least' ),
	'tested'       => header_field( $readme, 'Tested up to' ),
	'requires_php' => header_field( $readme, 'Requires PHP' ),
	'released'     => gmdate( 'Y-m-d H:i:s' ),
	'description'  => section_html( $readme, 'Opis' ),
	'changelog'    => section_html( $readme, 'Changelog' ),
);
file_put_contents( 'build/info.json', json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );

echo ( $dirty ? "Napomena: paket sadrži necommitane izmjene (lokalni build, ne za objavu).\n" : '' ) . "Verzija $version\n  build/sidrena-cijena.zip (" . round( filesize( 'build/sidrena-cijena.zip' ) / 1024 ) . " kB)\n  build/info.json -> {$info['download_url']}\n";
