# Disposable Emails

Tell whether an email address belongs to a disposable provider, before you let
it sign up.

## What it does

Throwaway inboxes -- mailinator, guerrillamail and ten thousand like them --
exist to get past a signup form and never be read again. This checks an
address against the [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains)
list, kept by people who verify each entry so you do not have to.

The list ships as a plain text file and is read once, when first asked, into
memory -- not queried. Your own blocked and allowed domains sit alongside it,
and win. It lives in its own package so the list can be refreshed on its own
schedule.

## Features

* Reject a disposable address at signup, checkout or newsletter opt-in
* Catch a subdomain of a listed provider: `x.mailinator.com`
* Block a domain the shared list has not caught yet
* Allow one the shared list gets wrong, so a real customer is not turned away
* Keep your own blocked and allowed domains in a file between requests
* Pass an object rather than a string -- anything with a `domain()` method
* Count what is loaded, to show in an admin screen
* Refresh the list with one command, or let the weekly workflow do it

## Installation

```bash
composer require sugarcommerce/disposable-emails
```

## Quick start

Refuse a disposable address at registration:

```php
use SugarCommerce\DisposableEmails\Checker;

add_filter( 'registration_errors', function ( $errors, $login, $email ) {
	if ( Checker::shared()->is_disposable( $email ) ) {
		$errors->add( 'disposable_email', __( 'Please use a permanent email address.', 'my-plugin' ) );
	}

	return $errors;
}, 10, 3 );
```

`shared()` is one checker for the request, so the list is read once however
many addresses are asked about.

## Your own domains

For exceptions of your own, make your own checker:

```php
$checker = new Checker( blocked: [ 'throwaway.example' ], allowed: [ 'legitimate.example' ] );

$checker->is_blocked( $email );            // blocked by you, or disposable -- the signup question
$checker->is_disposable( $email );         // disposable by the list (or by you)
$checker->is_allowed( $email );            // allowed by you or by data/allowlist.txt
$checker->count();                         // 10,400 or so
```

`block()`, `allow()`, `unblock()` and `disallow()` last the request. To keep
them, name a JSON file -- `add_blocked()`, `remove_blocked()`, `add_allowed()`
and `remove_allowed()` then write it back:

```php
$checker = ( new Checker() )
	->load_custom_blocked( WP_CONTENT_DIR . '/uploads/my-plugin/blocked.json' )
	->load_custom_allowed( WP_CONTENT_DIR . '/uploads/my-plugin/allowed.json' );

$checker->add_blocked( '@Throwaway.Example' );   // kept as "throwaway.example"
```

The order answers are reached in: your allowed, then (for `is_disposable()`)
the shipped allowlist, then your blocked, then the list. Your blocked
domains and the list also match subdomains; your allowed domains are exact.

## Keeping the list current

```bash
composer refresh             # fetch upstream and rewrite data/disposable.txt
composer refresh -- --check  # say what would change, write nothing
php bin/refresh.php copy.conf  # from a file you already have
```

It refuses -- and leaves the file as it was -- when what came back is not a
list: a line that is not a domain (an error page), fewer than 5,000 domains,
or under half as many as are there now. `.github/workflows/refresh.yml` runs
it every Sunday and commits the file when it changed.

`data/allowlist.txt` is kept by hand: upstream dropped its allowlist in April
2026. Add a domain the list gets wrong there, one per line.

## Security

* Another spelling of a listed domain is the listed domain: upper case, a
  trailing dot, full-width letters, an ideographic full stop and other
  Unicode forms are looked up as their plain or xn-- form (Unicode needs the
  intl extension)
* A subdomain of a listed domain, or of one you block, is caught; a domain
  you allow lets in only itself
* A domain longer than DNS allows is not taken apart, so a huge address
  posted to a form costs nothing
* The refresh fetches from one fixed https host with TLS verified, follows
  no redirect, reads at most 16 MB, and refuses anything that is not a list
  of domains -- an error page or a truncated download never ships
* A custom list file that is not a JSON list is an empty list; nothing is
  unserialized

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* The intl extension, to look up a Unicode domain

## License

GPL-2.0-or-later. The list in `data/disposable.txt` is
[disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains), CC0.
