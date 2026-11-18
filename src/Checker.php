<?php
/**
 * Checker
 *
 * Whether an email address belongs to a disposable provider.
 *
 * @package SugarCommerce\DisposableEmails
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\DisposableEmails;

/**
 * Checker Class
 *
 * Checks an address against data/disposable.txt -- the
 * disposable-email-domains list, rebuilt by bin/refresh.php -- and
 * data/allowlist.txt, the domains never to call disposable. Your own
 * blocked and allowed domains sit alongside both, and win.
 *
 *     if ( Checker::shared()->is_disposable( $email ) ) {
 *         $errors->add( 'disposable_email', __( 'Please use a permanent email address.', 'my-plugin' ) );
 *     }
 *
 * The lists are read once, when first asked, and held as keys so a lookup
 * does not walk ten thousand lines. A subdomain of a listed domain is
 * listed: `x.mailinator.com` is `mailinator.com`.
 *
 * In what order the answers are reached:
 *
 *  - is_disposable(): your allowed, the shipped allowlist, your blocked,
 *    then the list. A domain you block that the allowlist names is not
 *    disposable -- it is blocked.
 *  - is_blocked(): your allowed, your blocked, then is_disposable(). The
 *    question to ask at a signup form.
 *
 * Your own domains are runtime only until you name a file to keep them in:
 * load_custom_blocked() and load_custom_allowed() read a JSON list, and
 * add_blocked() and the rest write it back.
 *
 * @since 1.0.0
 */
final class Checker {

	/**
	 * What a line of a list must look like: a host name, lower case.
	 */
	public const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/';

	/**
	 * The instance shared() hands out.
	 *
	 * @var self|null
	 */
	private static ?self $shared = null;

	/**
	 * The disposable domains, as keys; null until first asked.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $disposable = null;

	/**
	 * The shipped allowlist, as keys; null until first asked.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $allowlist = null;

	/**
	 * Your blocked domains, as keys.
	 *
	 * @var array<string, int>
	 */
	private array $custom_blocked = [];

	/**
	 * Your allowed domains, as keys.
	 *
	 * @var array<string, int>
	 */
	private array $custom_allowed = [];

	/**
	 * The JSON file your blocked domains are kept in, once named.
	 *
	 * @var string|null
	 */
	private ?string $custom_blocked_file = null;

	/**
	 * The JSON file your allowed domains are kept in, once named.
	 *
	 * @var string|null
	 */
	private ?string $custom_allowed_file = null;

	/**
	 * The folder disposable.txt and allowlist.txt are read from.
	 *
	 * @var string
	 */
	private string $data_path;

	/**
	 * Create a checker.
	 *
	 * @param string[]    $blocked   Domains of your own to block.
	 * @param string[]    $allowed   Domains of your own to allow.
	 * @param string|null $data_path A folder holding disposable.txt and
	 *                               allowlist.txt, instead of data/.
	 */
	public function __construct( array $blocked = [], array $allowed = [], ?string $data_path = null ) {
		$this->data_path = $data_path ?? dirname( __DIR__ ) . '/data';

		$this->block( $blocked );
		$this->allow( $allowed );
	}

	/**
	 * One checker for the request, so the list is read once however many
	 * addresses are asked about.
	 *
	 * Domains blocked or allowed on it are seen by every caller that asks
	 * it. A plugin wanting its own exceptions makes its own checker.
	 *
	 * @return self
	 */
	public static function shared(): self {
		return self::$shared ??= new self();
	}

	/**
	 * Whether an address is from a disposable provider.
	 *
	 * @param string|object $email An address, a bare domain, or anything
	 *                             with a domain() method -- such as
	 *                             SugarCommerce\Network\Email.
	 *
	 * @return bool
	 */
	public function is_disposable( string|object $email ): bool {
		$domain = self::domain_of( $email );

		if ( null === $domain ) {
			return false;
		}

		if ( isset( $this->custom_allowed[ $domain ] ) || isset( $this->allowlist()[ $domain ] ) ) {
			return false;
		}

		if ( isset( $this->custom_blocked[ $domain ] ) ) {
			return true;
		}

		return self::listed( $domain, $this->disposable() );
	}

	/**
	 * Whether an address should be turned away: blocked by you, or
	 * disposable. A domain you allowed never is.
	 *
	 * @param string|object $email An address, a bare domain, or anything
	 *                             with a domain() method.
	 *
	 * @return bool
	 */
	public function is_blocked( string|object $email ): bool {
		$domain = self::domain_of( $email );

		if ( null === $domain || isset( $this->custom_allowed[ $domain ] ) ) {
			return false;
		}

		return isset( $this->custom_blocked[ $domain ] ) || $this->is_disposable( $email );
	}

	/**
	 * Whether an address's domain is allowed, by you or by the allowlist.
	 *
	 * @param string|object $email An address, a bare domain, or anything
	 *                             with a domain() method.
	 *
	 * @return bool
	 */
	public function is_allowed( string|object $email ): bool {
		$domain = self::domain_of( $email );

		return null !== $domain && ( isset( $this->custom_allowed[ $domain ] ) || isset( $this->allowlist()[ $domain ] ) );
	}

	/**
	 * How many disposable domains the list holds -- for an admin screen.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->disposable() );
	}

	/**
	 * Block domains for this request.
	 *
	 * @param string[]|string $domains One domain or several.
	 *
	 * @return self
	 */
	public function block( array|string $domains ): self {
		foreach ( (array) $domains as $domain ) {
			$domain = self::normalize( $domain );

			if ( '' !== $domain ) {
				$this->custom_blocked[ $domain ] = 1;
			}
		}

		return $this;
	}

	/**
	 * Allow domains for this request.
	 *
	 * @param string[]|string $domains One domain or several.
	 *
	 * @return self
	 */
	public function allow( array|string $domains ): self {
		foreach ( (array) $domains as $domain ) {
			$domain = self::normalize( $domain );

			if ( '' !== $domain ) {
				$this->custom_allowed[ $domain ] = 1;
			}
		}

		return $this;
	}

	/**
	 * Stop blocking a domain, for this request.
	 *
	 * @param string $domain The domain.
	 *
	 * @return self
	 */
	public function unblock( string $domain ): self {
		unset( $this->custom_blocked[ self::normalize( $domain ) ] );

		return $this;
	}

	/**
	 * Stop allowing a domain, for this request.
	 *
	 * @param string $domain The domain.
	 *
	 * @return self
	 */
	public function disallow( string $domain ): self {
		unset( $this->custom_allowed[ self::normalize( $domain ) ] );

		return $this;
	}

	/**
	 * Block a domain, and write the list to its file when one is named.
	 *
	 * @param string $domain  The domain.
	 * @param bool   $persist Write the file now.
	 *
	 * @return bool False when the domain is empty or the file could not be written.
	 */
	public function add_blocked( string $domain, bool $persist = true ): bool {
		return $this->add( $this->custom_blocked, $this->custom_blocked_file, $domain, $persist );
	}

	/**
	 * Stop blocking a domain, and write the list to its file when one is named.
	 *
	 * @param string $domain  The domain.
	 * @param bool   $persist Write the file now.
	 *
	 * @return bool False when it was not blocked or the file could not be written.
	 */
	public function remove_blocked( string $domain, bool $persist = true ): bool {
		return $this->remove( $this->custom_blocked, $this->custom_blocked_file, $domain, $persist );
	}

	/**
	 * Allow a domain, and write the list to its file when one is named.
	 *
	 * @param string $domain  The domain.
	 * @param bool   $persist Write the file now.
	 *
	 * @return bool False when the domain is empty or the file could not be written.
	 */
	public function add_allowed( string $domain, bool $persist = true ): bool {
		return $this->add( $this->custom_allowed, $this->custom_allowed_file, $domain, $persist );
	}

	/**
	 * Stop allowing a domain, and write the list to its file when one is named.
	 *
	 * @param string $domain  The domain.
	 * @param bool   $persist Write the file now.
	 *
	 * @return bool False when it was not allowed or the file could not be written.
	 */
	public function remove_allowed( string $domain, bool $persist = true ): bool {
		return $this->remove( $this->custom_allowed, $this->custom_allowed_file, $domain, $persist );
	}

	/**
	 * Whether you blocked a domain. Exact: a subdomain is not asked about.
	 *
	 * @param string $domain The domain.
	 *
	 * @return bool
	 */
	public function is_custom_blocked( string $domain ): bool {
		return isset( $this->custom_blocked[ self::normalize( $domain ) ] );
	}

	/**
	 * Whether you allowed a domain. Exact: a subdomain is not asked about.
	 *
	 * @param string $domain The domain.
	 *
	 * @return bool
	 */
	public function is_custom_allowed( string $domain ): bool {
		return isset( $this->custom_allowed[ self::normalize( $domain ) ] );
	}

	/**
	 * The domains you blocked.
	 *
	 * @return string[]
	 */
	public function get_custom_blocked(): array {
		return array_map( 'strval', array_keys( $this->custom_blocked ) );
	}

	/**
	 * The domains you allowed.
	 *
	 * @return string[]
	 */
	public function get_custom_allowed(): array {
		return array_map( 'strval', array_keys( $this->custom_allowed ) );
	}

	/**
	 * How many domains you blocked.
	 *
	 * @return int
	 */
	public function get_custom_blocked_count(): int {
		return count( $this->custom_blocked );
	}

	/**
	 * How many domains you allowed.
	 *
	 * @return int
	 */
	public function get_custom_allowed_count(): int {
		return count( $this->custom_allowed );
	}

	/**
	 * Read your blocked domains from a JSON list, and keep them there from
	 * now on. A missing file is an empty list.
	 *
	 * @param string $file The file.
	 *
	 * @return self
	 */
	public function load_custom_blocked( string $file ): self {
		$this->custom_blocked_file = $file;
		$this->custom_blocked      = self::read_json( $file );

		return $this;
	}

	/**
	 * Read your allowed domains from a JSON list, and keep them there from
	 * now on. A missing file is an empty list.
	 *
	 * @param string $file The file.
	 *
	 * @return self
	 */
	public function load_custom_allowed( string $file ): self {
		$this->custom_allowed_file = $file;
		$this->custom_allowed      = self::read_json( $file );

		return $this;
	}

	/**
	 * Write your blocked domains to a file.
	 *
	 * @param string|null $file The file; the loaded one when null.
	 *
	 * @return bool False when there is no file or it could not be written.
	 */
	public function save_custom_blocked( ?string $file = null ): bool {
		return self::write_json( $this->custom_blocked, $file ?? $this->custom_blocked_file );
	}

	/**
	 * Write your allowed domains to a file.
	 *
	 * @param string|null $file The file; the loaded one when null.
	 *
	 * @return bool False when there is no file or it could not be written.
	 */
	public function save_custom_allowed( ?string $file = null ): bool {
		return self::write_json( $this->custom_allowed, $file ?? $this->custom_allowed_file );
	}

	/**
	 * Forget your blocked and allowed domains.
	 *
	 * @param bool $persist Also empty their files, when named.
	 *
	 * @return self
	 */
	public function clear_custom( bool $persist = false ): self {
		$this->custom_blocked = [];
		$this->custom_allowed = [];

		if ( $persist ) {
			$this->save_custom_blocked();
			$this->save_custom_allowed();
		}

		return $this;
	}

	/**
	 * The domains in a list file's text: one a line, lower-cased, blank
	 * lines and `#` comments skipped. bin/refresh.php reads the upstream
	 * list with this, so what it writes is what is read back.
	 *
	 * @param string $text The file's text.
	 *
	 * @return string[]
	 */
	public static function parse( string $text ): array {
		$domains = [];

		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = strtolower( trim( $line ) );

			if ( '' !== $line && '#' !== $line[0] ) {
				$domains[] = $line;
			}
		}

		return $domains;
	}

	/**
	 * The disposable list, read on first use.
	 *
	 * @return array<string, int>
	 */
	private function disposable(): array {
		return $this->disposable ??= $this->read_list( 'disposable.txt' );
	}

	/**
	 * The shipped allowlist, read on first use.
	 *
	 * @return array<string, int>
	 */
	private function allowlist(): array {
		return $this->allowlist ??= $this->read_list( 'allowlist.txt' );
	}

	/**
	 * A list file in the data folder, as keys. Missing is empty.
	 *
	 * @param string $name The file's name.
	 *
	 * @return array<string, int>
	 */
	private function read_list( string $name ): array {
		$path = $this->data_path . '/' . $name;
		$text = is_readable( $path ) ? file_get_contents( $path ) : false;

		return false === $text ? [] : array_fill_keys( self::parse( $text ), 1 );
	}

	/**
	 * Whether a domain, or any domain it is under, is in a list. The bare
	 * top-level domain is not asked about.
	 *
	 * @param string             $domain  The domain.
	 * @param array<string, int> $domains The list, as keys.
	 *
	 * @return bool
	 */
	private static function listed( string $domain, array $domains ): bool {
		$parts = explode( '.', $domain );

		for ( $i = 0, $last = count( $parts ) - 1; $i < $last; $i++ ) {
			if ( isset( $domains[ implode( '.', array_slice( $parts, $i ) ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The lower-cased domain of an address, a bare domain, or an object
	 * with a domain() method; null when there is none.
	 *
	 * @param string|object $email What was given.
	 *
	 * @return string|null
	 */
	private static function domain_of( string|object $email ): ?string {
		if ( is_object( $email ) ) {
			$domain = method_exists( $email, 'domain' ) ? $email->domain() : '';
		} else {
			$at     = strrpos( $email, '@' );
			$domain = false === $at ? $email : substr( $email, $at + 1 );
		}

		$domain = is_string( $domain ) ? strtolower( trim( $domain ) ) : '';

		return '' === $domain ? null : $domain;
	}

	/**
	 * A domain as typed -- `@Spam.Example`, `pat@spam.example` -- as it is
	 * kept: `spam.example`.
	 *
	 * @param mixed $domain What was typed.
	 *
	 * @return string '' when it is not text.
	 */
	private static function normalize( mixed $domain ): string {
		if ( ! is_string( $domain ) ) {
			return '';
		}

		$domain = strtolower( trim( $domain ) );
		$at     = strrpos( $domain, '@' );

		return false === $at ? $domain : substr( $domain, $at + 1 );
	}

	/**
	 * Add to one of your lists.
	 *
	 * @param array<string, int> $entries The list.
	 * @param string|null        $file    Its file.
	 * @param string             $domain  The domain.
	 * @param bool               $persist Write the file now.
	 *
	 * @return bool
	 */
	private function add( array &$entries, ?string $file, string $domain, bool $persist ): bool {
		$domain = self::normalize( $domain );

		if ( '' === $domain ) {
			return false;
		}

		$entries[ $domain ] = 1;

		return ! $persist || null === $file || self::write_json( $entries, $file );
	}

	/**
	 * Remove from one of your lists.
	 *
	 * @param array<string, int> $entries The list.
	 * @param string|null        $file    Its file.
	 * @param string             $domain  The domain.
	 * @param bool               $persist Write the file now.
	 *
	 * @return bool
	 */
	private function remove( array &$entries, ?string $file, string $domain, bool $persist ): bool {
		$domain = self::normalize( $domain );

		if ( ! isset( $entries[ $domain ] ) ) {
			return false;
		}

		unset( $entries[ $domain ] );

		return ! $persist || null === $file || self::write_json( $entries, $file );
	}

	/**
	 * A JSON list of domains, as keys. Missing or not a list is empty.
	 *
	 * @param string $file The file.
	 *
	 * @return array<string, int>
	 */
	private static function read_json( string $file ): array {
		$text    = is_readable( $file ) ? file_get_contents( $file ) : false;
		$decoded = false === $text ? null : json_decode( $text, true );
		$entries = [];

		foreach ( is_array( $decoded ) ? $decoded : [] as $domain ) {
			$domain = self::normalize( $domain );

			if ( '' !== $domain ) {
				$entries[ $domain ] = 1;
			}
		}

		return $entries;
	}

	/**
	 * Write a list as a sorted JSON array.
	 *
	 * @param array<string, int> $entries The list, as keys.
	 * @param string|null        $file The file.
	 *
	 * @return bool
	 */
	private static function write_json( array $entries, ?string $file ): bool {
		if ( null === $file ) {
			return false;
		}

		$domains = array_map( 'strval', array_keys( $entries ) );
		sort( $domains );

		return false !== file_put_contents( $file, (string) json_encode( $domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}
}
