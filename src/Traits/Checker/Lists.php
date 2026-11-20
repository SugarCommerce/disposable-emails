<?php
/**
 * The shipped lists: data/disposable.txt and data/allowlist.txt.
 *
 * @package SugarCommerce\DisposableEmails
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\DisposableEmails\Traits\Checker;

/**
 * The shipped lists: data/disposable.txt and data/allowlist.txt.
 *
 * @since 1.0.0
 */
trait Lists {

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
}
