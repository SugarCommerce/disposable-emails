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
}
