<?php
/**
 * Your own blocked and allowed domains, for the request.
 *
 * @package SugarCommerce\DisposableEmails
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\DisposableEmails\Traits\Checker;

/**
 * Your own blocked and allowed domains, for the request.
 *
 * @since 1.0.0
 */
trait CustomDomains {

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
	 * Whether you blocked a domain or any domain it is under -- what
	 * is_disposable() and is_blocked() ask. A provider answering for every
	 * name under its domain is not let through by one made up on the spot.
	 *
	 * @param string $domain The domain, as domain_of() gives it.
	 *
	 * @return bool
	 */
	private function blocked_by_you( string $domain ): bool {
		return isset( $this->custom_blocked[ $domain ] ) || self::listed( $domain, $this->custom_blocked );
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
}
