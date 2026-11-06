<?php
/**
 * Rewrites and checks references to the Stripe SDK for the scoped release build.
 *
 * Usage:
 *   php php-scoper/scope-source.php rewrite <path>...
 *   php php-scoper/scope-source.php check [--skip-tests] <root>
 *
 * `rewrite` turns `\Stripe\...` name tokens and `'Stripe\...'` string literals into the
 * prefixed `WCPOS\WooCommercePOS\StripeTerminal\Vendor\Stripe\...`. `check` exits 1 on any
 * reference the rewrite would still change and on a build tree that still ships the plain
 * SDK. Build-time only (PHP 8.0+ tokenizer); never shipped. See docs/dependency-scoping.md.
 *
 * Exit codes: 0 ok, 1 check failed or a literal could not be rewritten, 2 bad usage.
 *
 * @package WCPOS\WooCommercePOS\StripeTerminal
 */

const STWC_SCOPE_PREFIX = 'WCPOS\\WooCommercePOS\\StripeTerminal\\Vendor';

if ( PHP_VERSION_ID < 80000 ) {
	fwrite( STDERR, "scope-source.php needs PHP 8.0+ (T_NAME_* tokens).\n" );
	exit( 2 );
}

/**
 * Decodes the inside of a constant string literal far enough to test its start.
 *
 * Escapes that do not yield a backslash become a NUL, which can never match a class name.
 *
 * @param string $text The literal, quotes included.
 */
function stwc_literal_value( string $text ): string {
	$inner = substr( $text, 1, -1 );
	if ( "'" === $text[0] ) {
		return strtr( $inner, array( '\\\\' => '\\', "\\'" => "'" ) );
	}
	return preg_replace_callback(
		'/\\\\(?:([\\\\"$])|[nrtvef]|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
		function ( $m ) {
			return isset( $m[1] ) && '' !== $m[1] ? $m[1] : "\0";
		},
		$inner
	);
}

/**
 * Returns the rewritten token text, the same text when nothing matches, or null when the
 * token names Stripe in a form this tool cannot rewrite.
 *
 * @param int    $id   Token id.
 * @param string $text Token text.
 */
function stwc_rewrite_token( int $id, string $text ): ?string {
	if ( T_NAME_QUALIFIED === $id || T_NAME_FULLY_QUALIFIED === $id ) {
		$name = ltrim( $text, '\\' );
		if ( 0 !== stripos( $name, 'Stripe\\' ) ) {
			return $text;
		}
		return substr( $text, 0, strlen( $text ) - strlen( $name ) ) . STWC_SCOPE_PREFIX . '\\' . $name;
	}
	if ( T_CONSTANT_ENCAPSED_STRING !== $id || '"' !== $text[0] && "'" !== $text[0] ) {
		return $text; // Not a string literal (or a b'' binary string, which names no class).
	}
	if ( ! preg_match( '/^(?:overload:|alias:)?\\\\*Stripe\\\\/i', stwc_literal_value( $text ) ) ) {
		return $text;
	}
	// Raw form: optional mock prefix and leading backslashes, then Stripe and its separator as written.
	if ( ! preg_match( '/^(.)((?:overload:|alias:)?(?:\\\\\\\\|\\\\)*)(Stripe)(\\\\\\\\|\\\\)/i', $text, $m ) ) {
		return null;
	}
	$insert = str_replace( '\\', $m[4], STWC_SCOPE_PREFIX . '\\' );
	return $m[1] . $m[2] . $insert . substr( $text, strlen( $m[1] . $m[2] ) );
}

/**
 * Rewrites one PHP source string.
 *
 * @param string $code   Source.
 * @param array  $issues Receives `line:token` for every token that names Stripe and changed or could not change.
 */
function stwc_rewrite_source( string $code, array &$issues ): string {
	$out    = '';
	$tokens = token_get_all( $code );
	foreach ( $tokens as $i => $token ) {
		if ( ! is_array( $token ) ) {
			$out .= $token;
			continue;
		}
		$new = stwc_rewrite_token( $token[0], $token[1] );
		// A group use (`use Stripe\{A, B}`) splits the name into tokens; report it, never rewrite it.
		if ( ( T_STRING === $token[0] || T_NAME_FULLY_QUALIFIED === $token[0] ) && 0 === strcasecmp( ltrim( $token[1], '\\' ), 'Stripe' )
			&& isset( $tokens[ $i + 1 ][0] ) && T_NS_SEPARATOR === $tokens[ $i + 1 ][0] ) {
			$new = null;
		}
		if ( $new !== $token[1] ) {
			$issues[] = $token[2] . ':' . $token[1];
		}
		$out .= null === $new ? $token[1] : $new;
	}
	return $out;
}

/**
 * Lists the .php files under a path.
 *
 * @param string   $path Root file or directory.
 * @param string[] $skip Top-level directory names (relative to $path) not to enter.
 * @return string[]
 */
function stwc_php_files( string $path, array $skip = array() ): array {
	if ( is_file( $path ) ) {
		return array( $path );
	}
	$root  = rtrim( $path, '/' );
	$files = array();
	$iter  = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			function ( SplFileInfo $file ) use ( $root, $skip ) {
				if ( ! $file->isDir() ) {
					return true;
				}
				$rel = substr( $file->getPathname(), strlen( $root ) + 1 );
				return ! in_array( $rel, $skip, true ) && '.git' !== $file->getFilename() && 'node_modules' !== $file->getFilename();
			}
		)
	);
	foreach ( $iter as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
	sort( $files );
	return $files;
}

/**
 * The `rewrite` command.
 *
 * @param string[] $paths Files or directories.
 */
function stwc_cmd_rewrite( array $paths ): int {
	$status = 0;
	foreach ( $paths as $path ) {
		if ( ! file_exists( $path ) ) {
			fwrite( STDERR, "No such path: $path\n" );
			return 2;
		}
		foreach ( stwc_php_files( $path ) as $file ) {
			$code   = file_get_contents( $file );
			$issues = array();
			$new    = stwc_rewrite_source( $code, $issues );
			if ( $new !== $code ) {
				file_put_contents( $file, $new );
			}
			// A name that matched but could not be rewritten is left as is; recheck to list it.
			$left = array();
			stwc_rewrite_source( $new, $left );
			foreach ( $left as $issue ) {
				fwrite( STDERR, "Cannot rewrite $file:$issue\n" );
				$status = 1;
			}
		}
	}
	return $status;
}

/**
 * The `check` command.
 *
 * @param string $root       Build tree root.
 * @param bool   $skip_tests Do not read tests/.
 */
function stwc_cmd_check( string $root, bool $skip_tests ): int {
	$root     = rtrim( $root, '/' );
	$failures = array();
	$skip     = array( 'vendor', 'vendor_prefixed', 'php-scoper' );
	if ( $skip_tests ) {
		$skip[] = 'tests';
	}
	foreach ( stwc_php_files( $root, $skip ) as $file ) {
		$issues = array();
		stwc_rewrite_source( file_get_contents( $file ), $issues );
		foreach ( $issues as $issue ) {
			$failures[] = substr( $file, strlen( $root ) + 1 ) . ':' . $issue;
		}
	}
	if ( is_dir( "$root/vendor/stripe" ) ) {
		$failures[] = 'vendor/stripe exists: the plain SDK is still in the build tree';
	}
	foreach ( glob( "$root/vendor/composer/autoload_*.php" ) as $file ) {
		if ( preg_match( "/'Stripe\\\\\\\\[^']*'\\s*=>/i", file_get_contents( $file ) ) ) {
			$failures[] = substr( $file, strlen( $root ) + 1 ) . ': has a Stripe\\\\ autoload key';
		}
	}
	if ( ! is_file( "$root/vendor_prefixed/autoload.php" ) ) {
		$failures[] = 'vendor_prefixed/autoload.php is missing';
	}
	if ( ! is_file( "$root/vendor_prefixed/stripe-php/data/ca-certificates.crt" ) ) {
		$failures[] = 'vendor_prefixed/stripe-php/data/ca-certificates.crt is missing';
	}
	foreach ( $failures as $failure ) {
		fwrite( STDOUT, $failure . "\n" );
	}
	fwrite( STDOUT, $failures ? 'scope check FAILED: ' . count( $failures ) . " problem(s)\n" : "scope check ok\n" );
	return $failures ? 1 : 0;
}

$args    = array_slice( $argv, 1 );
$command = array_shift( $args );
if ( 'rewrite' === $command && $args ) {
	exit( stwc_cmd_rewrite( $args ) );
}
if ( 'check' === $command ) {
	$skip_tests = in_array( '--skip-tests', $args, true );
	$args       = array_values( array_diff( $args, array( '--skip-tests' ) ) );
	if ( 1 === count( $args ) && is_dir( $args[0] ) ) {
		exit( stwc_cmd_check( $args[0], $skip_tests ) );
	}
}
fwrite( STDERR, "Usage: php scope-source.php rewrite <path>...\n       php scope-source.php check [--skip-tests] <root>\n" );
exit( 2 );
