<?php
/**
 * Your own blocked and allowed domains, kept in JSON files.
 *
 * @package SugarCommerce\DisposableEmails
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\DisposableEmails\Traits\Checker;

/**
 * Your own blocked and allowed domains, kept in JSON files.
 *
 * @since 1.0.0
 */
trait CustomFiles {

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
	 * A JSON list of domains, as keys. Missing or not a list is empty.
	 *
	 * @param string $file The file.
	 *
	 * @return array<string, int>
	 */
	private static function read_json( string $file ): array {
		$text    = is_readable( $file ) ? file_get_contents( $file ) : false;
		$decoded = false === $text || ! json_validate( $text ) ? null : json_decode( $text, true );
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
