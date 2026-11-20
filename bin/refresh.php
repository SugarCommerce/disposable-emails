<?php
/**
 * Rebuilds data/disposable.txt from its upstream source.
 *
 * The list is disposable-email-domains
 * (https://github.com/disposable-email-domains/disposable-email-domains),
 * CC0, kept by people who verify each entry. This fetches its
 * disposable_email_blocklist.conf -- or reads a copy you already have --
 * and writes it here sorted, lower-cased and once each.
 *
 * Usage:
 *
 *     composer refresh                          # fetch and write
 *     php bin/refresh.php path/to/blocklist.conf  # from a file
 *     php bin/refresh.php --check               # say what would change, write nothing
 *     php bin/refresh.php --to=other.txt        # write somewhere else
 *
 * It refuses, and leaves the file as it was, when what came back is not a
 * list: a line that is not a domain (an error page, "404: Not Found" --
 * the old library shipped that as its allowlist once upstream dropped the
 * file), fewer than MINIMUM domains, or under half as many as are there
 * now (a truncated download). data/allowlist.txt is kept by hand and not
 * touched.
 *
 * @package SugarCommerce\DisposableEmails
 */

declare( strict_types=1 );

use SugarCommerce\DisposableEmails\Checker;

// Runs without Composer's install (the weekly workflow does not do one), so
// the package's own classes are found here.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SugarCommerce\\DisposableEmails\\';

		if ( str_starts_with( $class_name, $prefix ) ) {
			require dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		}
	}
);

const SOURCE  = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';
const MINIMUM = 5000;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$check  = false;
$files  = [];
$target = dirname( __DIR__ ) . '/data/disposable.txt';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--check' === $arg ) {
		$check = true;
	} elseif ( str_starts_with( $arg, '--to=' ) ) {
		$target = substr( $arg, 5 );
	} else {
		$files[] = $arg;
	}
}

/**
 * Stop with a message.
 *
 * @param string $message Why.
 *
 * @return never
 */
function refresh_fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

if ( isset( $files[0] ) ) {
	$text = is_readable( $files[0] ) ? file_get_contents( $files[0] ) : false;
	$from = $files[0];
} else {
	$context = stream_context_create(
		[
			'http' => [
				'timeout'       => 30,
				'ignore_errors' => true,
				'header'        => "User-Agent: sugarcommerce-disposable-emails-refresh\r\n",
			],
		]
	);
	$text    = file_get_contents( SOURCE, false, $context );
	$status  = $http_response_header[0] ?? '';
	$from    = SOURCE;

	if ( ! preg_match( '#^HTTP/\S+ 200\b#', $status ) ) {
		refresh_fail( 'Refused: ' . SOURCE . ' answered "' . $status . '".' );
	}
}

if ( false === $text ) {
	refresh_fail( 'Refused: could not read ' . $from . '.' );
}

$domains = array_values( array_unique( Checker::parse( $text ) ) );

foreach ( $domains as $domain ) {
	if ( ! preg_match( Checker::DOMAIN_PATTERN, $domain ) ) {
		refresh_fail( 'Refused: "' . substr( $domain, 0, 80 ) . '" is not a domain.' );
	}
}

$current = is_readable( $target ) ? Checker::parse( (string) file_get_contents( $target ) ) : [];

if ( count( $domains ) < MINIMUM || count( $domains ) < count( $current ) / 2 ) {
	refresh_fail( sprintf( 'Refused: %d domains, against %d now.', count( $domains ), count( $current ) ) );
}

sort( $domains, SORT_STRING );

$added   = count( array_diff( $domains, $current ) );
$removed = count( array_diff( $current, $domains ) );

fwrite( STDOUT, sprintf( "%d domains: %d added, %d removed.\n", count( $domains ), $added, $removed ) );

if ( $check || ( 0 === $added && 0 === $removed ) ) {
	exit( 0 );
}

// Written beside and renamed over, so a reader never sees half a list.
$temporary = $target . '.tmp';

if ( false === file_put_contents( $temporary, implode( "\n", $domains ) . "\n" ) || ! rename( $temporary, $target ) ) {
	refresh_fail( 'Could not write ' . $target . '.' );
}
