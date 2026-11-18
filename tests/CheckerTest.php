<?php
/**
 * The checker, against a small list of its own and the shipped one.
 *
 * @package SugarCommerce\DisposableEmails
 */

declare( strict_types=1 );

namespace SugarCommerce\DisposableEmails\Tests;

use SugarCommerce\DisposableEmails\Checker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class CheckerTest
 */
final class CheckerTest extends TestCase {

	/**
	 * Files a test wrote, removed after it.
	 *
	 * @var string[]
	 */
	private array $files = [];

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * A checker reading tests/fixtures.
	 *
	 * @param string[] $blocked Your blocked.
	 * @param string[] $allowed Your allowed.
	 *
	 * @return Checker
	 */
	private function checker( array $blocked = [], array $allowed = [] ): Checker {
		return new Checker( $blocked, $allowed, __DIR__ . '/fixtures' );
	}

	/**
	 * A path for a JSON file, removed after the test.
	 *
	 * @return string
	 */
	private function temporary(): string {
		$file          = tempnam( sys_get_temp_dir(), 'de' );
		$this->files[] = $file;
		unlink( $file );

		return $file;
	}

	/**
	 * What is disposable, against the fixture list.
	 *
	 * @param string $email    The address.
	 * @param bool   $expected Whether it is disposable.
	 */
	#[DataProvider( 'addresses' )]
	public function test_is_disposable( string $email, bool $expected ): void {
		$this->assertSame( $expected, $this->checker()->is_disposable( $email ) );
	}

	/**
	 * Addresses and answers.
	 *
	 * @return array
	 */
	public static function addresses(): array {
		return [
			'listed'                 => [ 'pat@mailinator.com', true ],
			'listed, any case'       => [ 'Pat@MAILINATOR.com', true ],
			'subdomain of listed'    => [ 'pat@x.y.mailinator.com', true ],
			'bare domain'            => [ 'mailinator.com', true ],
			'not listed'             => [ 'pat@example.com', false ],
			'listed as a suffix'     => [ 'pat@notmailinator.com', false ],
			'parent of listed'       => [ 'pat@example', false ],
			'allowlist wins'         => [ 'pat@listed.allowed.example', false ],
			'comment is not a domain' => [ 'pat@# a comment', false ],
			'empty'                  => [ '', false ],
			'no domain'              => [ 'pat@', false ],
			'last @ is the split'    => [ '"a@b"@mailinator.com', true ],
			'surrounding whitespace' => [ ' pat@mailinator.com ', true ],
		];
	}

	/**
	 * A bare top-level domain is never looked up, so a list naming "com"
	 * would not block the world.
	 */
	public function test_the_top_level_domain_alone_is_not_asked_about(): void {
		$dir = sys_get_temp_dir() . '/de-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/disposable.txt', "com\n" );

		$checker = new Checker( [], [], $dir );

		$this->assertFalse( $checker->is_disposable( 'pat@example.com' ) );
		$this->assertFalse( $checker->is_disposable( 'com' ) );

		unlink( $dir . '/disposable.txt' );
		rmdir( $dir );
	}

	/**
	 * Anything with a domain() method is read by it; anything without is
	 * nothing.
	 */
	public function test_an_object_is_read_by_its_domain(): void {
		$email = new class() {
			public function domain(): string {
				return 'Mailinator.com';
			}
		};

		$invalid = new class() {
			public function domain(): string {
				return '';
			}
		};

		$this->assertTrue( $this->checker()->is_disposable( $email ) );
		$this->assertFalse( $this->checker()->is_disposable( $invalid ) );
		$this->assertFalse( $this->checker()->is_disposable( new \stdClass() ) );
	}

	/**
	 * The order the answers are reached in.
	 */
	public function test_precedence(): void {
		$checker = $this->checker( [ 'spam.example', 'listed.allowed.example' ], [ 'mailinator.com' ] );

		// Your allowed beats the list.
		$this->assertFalse( $checker->is_disposable( 'pat@mailinator.com' ) );
		$this->assertFalse( $checker->is_blocked( 'pat@mailinator.com' ) );

		// Your blocked is disposable and blocked.
		$this->assertTrue( $checker->is_disposable( 'pat@spam.example' ) );
		$this->assertTrue( $checker->is_blocked( 'pat@spam.example' ) );

		// Blocked but on the shipped allowlist: blocked, not disposable.
		$this->assertFalse( $checker->is_disposable( 'pat@listed.allowed.example' ) );
		$this->assertTrue( $checker->is_blocked( 'pat@listed.allowed.example' ) );

		// The list alone blocks.
		$this->assertTrue( $checker->is_blocked( 'pat@tempmail.example' ) );
		$this->assertFalse( $checker->is_blocked( 'pat@example.com' ) );
		$this->assertFalse( $checker->is_blocked( '' ) );

		// Both blocked and allowed by you: allowed.
		$both = $this->checker( [ 'both.example' ], [ 'both.example' ] );
		$this->assertFalse( $both->is_blocked( 'pat@both.example' ) );
		$this->assertFalse( $both->is_disposable( 'pat@both.example' ) );

		// Your blocked is exact: a subdomain is not blocked by it.
		$this->assertFalse( $checker->is_disposable( 'pat@x.spam.example' ) );
	}

	/**
	 * Allowed: by you or by the allowlist.
	 */
	public function test_is_allowed(): void {
		$checker = $this->checker( [], [ 'friend.example' ] );

		$this->assertTrue( $checker->is_allowed( 'pat@friend.example' ) );
		$this->assertTrue( $checker->is_allowed( 'pat@listed.allowed.example' ) );
		$this->assertFalse( $checker->is_allowed( 'pat@mailinator.com' ) );
		$this->assertFalse( $checker->is_allowed( 'pat@' ) );
	}

	/**
	 * Domains are kept however they were typed.
	 */
	public function test_domains_are_normalised(): void {
		$checker = $this->checker()->block( [ ' @Spam.Example ', 'pat@Other.Example', '', '  ' ] )->allow( 'FRIEND.example' );

		$this->assertSame( [ 'spam.example', 'other.example' ], $checker->get_custom_blocked() );
		$this->assertSame( [ 'friend.example' ], $checker->get_custom_allowed() );
		$this->assertSame( 2, $checker->get_custom_blocked_count() );
		$this->assertSame( 1, $checker->get_custom_allowed_count() );
		$this->assertTrue( $checker->is_custom_blocked( '@SPAM.example' ) );
		$this->assertTrue( $checker->is_custom_allowed( 'friend.example' ) );
		$this->assertFalse( $checker->is_custom_blocked( 'x.spam.example' ) );

		$checker->unblock( 'Spam.Example' )->disallow( '@friend.example' );

		$this->assertSame( [ 'other.example' ], $checker->get_custom_blocked() );
		$this->assertSame( [], $checker->get_custom_allowed() );
	}

	/**
	 * Runtime adding and removing, with no file named.
	 */
	public function test_add_and_remove_without_a_file(): void {
		$checker = $this->checker();

		$this->assertTrue( $checker->add_blocked( 'spam.example' ) );
		$this->assertFalse( $checker->add_blocked( '  ' ) );
		$this->assertTrue( $checker->is_blocked( 'pat@spam.example' ) );
		$this->assertTrue( $checker->remove_blocked( 'spam.example' ) );
		$this->assertFalse( $checker->remove_blocked( 'spam.example' ) );

		$this->assertTrue( $checker->add_allowed( 'mailinator.com' ) );
		$this->assertFalse( $checker->add_allowed( '' ) );
		$this->assertFalse( $checker->is_disposable( 'pat@mailinator.com' ) );
		$this->assertTrue( $checker->remove_allowed( 'mailinator.com' ) );
		$this->assertFalse( $checker->remove_allowed( 'mailinator.com' ) );
		$this->assertTrue( $checker->is_disposable( 'pat@mailinator.com' ) );

		// Nowhere to save to.
		$this->assertFalse( $checker->save_custom_blocked() );
		$this->assertFalse( $checker->save_custom_allowed() );
	}

	/**
	 * Your domains survive the request once a file is named.
	 */
	public function test_custom_lists_persist(): void {
		$blocked = $this->temporary();
		$allowed = $this->temporary();

		$first = $this->checker()->load_custom_blocked( $blocked )->load_custom_allowed( $allowed );

		$this->assertSame( [], $first->get_custom_blocked() );
		$this->assertTrue( $first->add_blocked( 'zeta.example' ) );
		$this->assertTrue( $first->add_blocked( 'Alpha.example' ) );
		$this->assertTrue( $first->add_allowed( 'friend.example' ) );
		$this->assertTrue( $first->add_blocked( 'unsaved.example', false ) );

		// Sorted, and the unsaved one is not there.
		$this->assertSame( [ 'alpha.example', 'zeta.example' ], json_decode( (string) file_get_contents( $blocked ), true ) );

		$second = $this->checker()->load_custom_blocked( $blocked )->load_custom_allowed( $allowed );

		$this->assertTrue( $second->is_blocked( 'pat@alpha.example' ) );
		$this->assertTrue( $second->is_allowed( 'pat@friend.example' ) );
		$this->assertFalse( $second->is_custom_blocked( 'unsaved.example' ) );

		$this->assertTrue( $second->remove_blocked( 'zeta.example' ) );
		$this->assertTrue( $second->remove_allowed( 'friend.example' ) );
		$this->assertSame( [ 'alpha.example' ], json_decode( (string) file_get_contents( $blocked ), true ) );
		$this->assertSame( [], json_decode( (string) file_get_contents( $allowed ), true ) );

		$this->assertTrue( $second->save_custom_allowed( $copy = $this->temporary() ) );
		$this->assertSame( '[]', file_get_contents( $copy ) );
	}

	/**
	 * clear_custom() empties the lists, and their files when asked.
	 */
	public function test_clear_custom(): void {
		$blocked = $this->temporary();
		$checker = $this->checker()->load_custom_blocked( $blocked );
		$checker->add_blocked( 'spam.example' );
		$checker->allow( 'friend.example' );

		$checker->clear_custom();

		$this->assertSame( [], $checker->get_custom_blocked() );
		$this->assertSame( [], $checker->get_custom_allowed() );
		$this->assertSame( [ 'spam.example' ], json_decode( (string) file_get_contents( $blocked ), true ) );

		$checker->clear_custom( true );

		$this->assertSame( [], json_decode( (string) file_get_contents( $blocked ), true ) );
	}

	/**
	 * A file that is missing or not a JSON list is an empty list.
	 */
	public function test_bad_json_is_empty(): void {
		$file = $this->temporary();
		file_put_contents( $file, '{"not": ' );

		$this->assertSame( [], $this->checker()->load_custom_blocked( $file )->get_custom_blocked() );

		file_put_contents( $file, '["Spam.example", 7, null, ""]' );

		$this->assertSame( [ 'spam.example' ], $this->checker()->load_custom_blocked( $file )->get_custom_blocked() );
		$this->assertSame( [], $this->checker()->load_custom_allowed( '/nonexistent/x.json' )->get_custom_allowed() );
	}

	/**
	 * A missing data folder is empty lists, not an error.
	 */
	public function test_a_missing_data_folder_is_empty(): void {
		$checker = new Checker( [], [], '/nonexistent' );

		$this->assertSame( 0, $checker->count() );
		$this->assertFalse( $checker->is_disposable( 'pat@mailinator.com' ) );
	}

	/**
	 * parse() skips comments and blank lines, and lower-cases.
	 */
	public function test_parse(): void {
		$this->assertSame( [ 'a.example', 'b.example' ], Checker::parse( "# x\n\n  A.example  \r\nb.example\n#c.example" ) );
		$this->assertSame( 3, $this->checker()->count() );
	}

	/**
	 * The shipped list: current, well-formed, and the allowlist not the
	 * "404: Not Found" the old library shipped.
	 */
	public function test_the_shipped_data(): void {
		$checker = new Checker();
		$data    = dirname( __DIR__ ) . '/data';

		$this->assertGreaterThan( 10000, $checker->count() );
		$this->assertTrue( $checker->is_disposable( 'pat@mailinator.com' ) );
		$this->assertTrue( $checker->is_disposable( 'pat@guerrillamail.com' ) );
		$this->assertFalse( $checker->is_disposable( 'pat@gmail.com' ) );
		$this->assertFalse( $checker->is_disposable( 'pat@outlook.com' ) );

		foreach ( [ 'disposable.txt', 'allowlist.txt' ] as $name ) {
			foreach ( Checker::parse( (string) file_get_contents( $data . '/' . $name ) ) as $domain ) {
				$this->assertMatchesRegularExpression( Checker::DOMAIN_PATTERN, $domain, $name );
			}
		}
	}

	/**
	 * shared() is one checker.
	 */
	public function test_shared(): void {
		$this->assertSame( Checker::shared(), Checker::shared() );
		$this->assertTrue( Checker::shared()->is_disposable( 'pat@mailinator.com' ) );
	}
}
