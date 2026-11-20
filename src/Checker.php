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

use SugarCommerce\DisposableEmails\Traits\Checker\CustomDomains;
use SugarCommerce\DisposableEmails\Traits\Checker\CustomFiles;
use SugarCommerce\DisposableEmails\Traits\Checker\Domains;
use SugarCommerce\DisposableEmails\Traits\Checker\Lists;

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

	use CustomDomains;
	use CustomFiles;
	use Domains;
	use Lists;

	/**
	 * What a line of a list must look like: a host name, lower case.
	 */
	public const string DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/';

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
}
