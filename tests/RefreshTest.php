<?php
/**
 * bin/refresh.php: what it writes, and what it refuses to.
 *
 * @package SugarCommerce\DisposableEmails
 */

declare( strict_types=1 );

namespace SugarCommerce\DisposableEmails\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Class RefreshTest
 */
final class RefreshTest extends TestCase {

	/**
	 * A folder for the test's files.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/de-refresh-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
	}

	/**
	 * Run the script from a source file into a target.
	 *
	 * @param string $source The upstream text.
	 * @param string $extra  Further arguments.
	 *
	 * @return array{0: int, 1: string} Exit code and output.
	 */
	private function run_refresh( string $source, string $extra = '' ): array {
		file_put_contents( $this->dir . '/source.conf', $source );

		$command = sprintf(
			'%s %s %s %s %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( dirname( __DIR__ ) . '/bin/refresh.php' ),
			escapeshellarg( $this->dir . '/source.conf' ),
			escapeshellarg( '--to=' . $this->dir . '/disposable.txt' ),
			$extra
		);

		exec( $command, $output, $code );

		return [ $code, implode( "\n", $output ) ];
	}

	/**
	 * Lots of plausible domains.
	 *
	 * @param int $count How many.
	 *
	 * @return string[]
	 */
	private static function domains( int $count ): array {
		$domains = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$domains[] = sprintf( 'mail%05d.example', $i );
		}

		return $domains;
	}

	/**
	 * Sorted, lower-cased, once each, comments gone.
	 */
	public function test_writes_the_list(): void {
		$domains = self::domains( 5000 );
		[ $code, $output ] = $this->run_refresh( "# header\nZZZ.example\n" . implode( "\n", array_reverse( $domains ) ) . "\nmail00001.example\n" );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( '5001 domains: 5001 added, 0 removed.', $output );

		$written = file( $this->dir . '/disposable.txt', FILE_IGNORE_NEW_LINES );

		$this->assertSame( array_merge( $domains, [ 'zzz.example' ] ), $written );
		$this->assertFileDoesNotExist( $this->dir . '/disposable.txt.tmp' );

		// Again: nothing changed.
		[ $code, $output ] = $this->run_refresh( implode( "\n", $domains ) . "\nzzz.example" );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '0 added, 0 removed', $output );
	}

	/**
	 * --check says and writes nothing.
	 */
	public function test_check_writes_nothing(): void {
		[ $code, $output ] = $this->run_refresh( implode( "\n", self::domains( 5000 ) ), '--check' );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( '5000 added', $output );
		$this->assertFileDoesNotExist( $this->dir . '/disposable.txt' );
	}

	/**
	 * An error page, a short list and a truncated one leave the file alone.
	 */
	public function test_refuses_what_is_not_a_list(): void {
		file_put_contents( $this->dir . '/disposable.txt', implode( "\n", self::domains( 12000 ) ) );
		$before = file_get_contents( $this->dir . '/disposable.txt' );

		$cases = [
			'not found'  => '404: Not Found',
			'html'       => "<html>\n" . implode( "\n", self::domains( 12000 ) ),
			'too few'    => implode( "\n", self::domains( 4999 ) ),
			'truncated'  => implode( "\n", self::domains( 5999 ) ),
		];

		foreach ( $cases as $name => $source ) {
			[ $code, $output ] = $this->run_refresh( $source );

			$this->assertSame( 1, $code, $name );
			$this->assertStringStartsWith( 'Refused', $output, $name );
			$this->assertSame( $before, file_get_contents( $this->dir . '/disposable.txt' ), $name );
		}

		// With nothing there yet, the floor still holds.
		unlink( $this->dir . '/disposable.txt' );
		[ $code, $output ] = $this->run_refresh( implode( "\n", self::domains( 4999 ) ) );
		$this->assertSame( 1, $code );
		$this->assertStringStartsWith( 'Refused', $output );
		$this->assertFileDoesNotExist( $this->dir . '/disposable.txt' );

		file_put_contents( $this->dir . '/disposable.txt', $before );

		// Half exactly is accepted.
		[ $code ] = $this->run_refresh( implode( "\n", self::domains( 6000 ) ) );
		$this->assertSame( 0, $code );
	}
}
