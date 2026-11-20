<?php
/**
 * An address down to its domain, and a domain against a list.
 *
 * @package SugarCommerce\DisposableEmails
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\DisposableEmails\Traits\Checker;

/**
 * An address down to its domain, and a domain against a list.
 *
 * @since 1.0.0
 */
trait Domains {

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
	 * The domain of an address, a bare domain, or an object with a domain()
	 * method, as it is looked up; null when there is none.
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

		$domain = is_string( $domain ) ? self::host( $domain ) : '';

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

		$at = strrpos( $domain, '@' );

		return self::host( false === $at ? $domain : substr( $domain, $at + 1 ) );
	}

	/**
	 * A host name in the one form the lists use, so another way of writing
	 * it cannot slip past: lower case, with no trailing dot
	 * (`mailinator.com.` is an absolute name a mail server delivers to all
	 * the same), and a Unicode name as its xn-- form, mapped the way IDNA
	 * maps it -- so full-width `ｍａｉｌｉｎａｔｏｒ.com` and `mailinator。com`
	 * are `mailinator.com`. Without the intl extension a Unicode name is
	 * left as it is, and matches nothing.
	 *
	 * @param string $domain The domain.
	 *
	 * @return string
	 */
	private static function host( string $domain ): string {
		$domain = trim( $domain );

		if ( 1 === preg_match( '/[^\x00-\x7F]/', $domain ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii  = idn_to_ascii( $domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
			$domain = false === $ascii ? $domain : $ascii;
		}

		return rtrim( strtolower( $domain ), '.' );
	}
}
