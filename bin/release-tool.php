<?php
/**
 * Release signing tool (runs locally; the secret key never goes to GitHub).
 *
 * Usage:
 *   php bin/release-tool.php keygen <secret-key-file>
 *   php bin/release-tool.php sign <zip> <secret-key-file>      Writes <zip>.sig
 *   php bin/release-tool.php verify <zip> [<sig-file>]          Checks against includes/release-keys.php
 *   php bin/release-tool.php manifest <version> <changelog-file> <out.json>
 *
 * Signatures are base64 Ed25519 over the zip's SHA-384 hash, the scheme
 * WP_AI_Gatekeeper_Updater::verify() (and WordPress core) checks.
 *
 * @package WP_AI_Gatekeeper
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- CLI tool, not WordPress runtime.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' ); // Lets us include release-keys.php.
$root = dirname( __DIR__ );

/**
 * Print an error and exit.
 *
 * @param string $message Message.
 */
function wpai_release_fail( $message ) {
	fwrite( STDERR, "Error: {$message}\n" );
	exit( 1 );
}

/**
 * Trusted public keys shipped in the plugin.
 *
 * @param string $root Repository root.
 * @return string[]
 */
function wpai_release_trusted_keys( $root ) {
	$keys = include $root . '/includes/release-keys.php';
	return is_array( $keys ) ? $keys : array();
}

/**
 * Whether a signature verifies with any trusted key.
 *
 * @param string   $file             Zip path.
 * @param string   $signature_base64 Signature.
 * @param string[] $keys             Trusted keys.
 * @return bool
 */
function wpai_release_verify( $file, $signature_base64, array $keys ) {
	$signature = base64_decode( trim( $signature_base64 ), true );
	if ( false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
		return false;
	}
	$hash = hash_file( 'sha384', $file, true );
	foreach ( $keys as $key ) {
		$raw = base64_decode( $key, true );
		if ( false !== $raw && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) && sodium_crypto_sign_verify_detached( $signature, $hash, $raw ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Read a plugin header value.
 *
 * @param string $root   Repository root.
 * @param string $header Header name.
 * @return string
 */
function wpai_release_header( $root, $header ) {
	$source = file_get_contents( $root . '/wp-ai-gatekeeper.php' );
	return preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.+)$/m', $source, $m ) ? trim( $m[1] ) : '';
}

if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
	wpai_release_fail( 'PHP sodium extension is required.' );
}

$command = $argv[1] ?? '';

switch ( $command ) {
	case 'keygen':
		$path = $argv[2] ?? wpai_release_fail( 'keygen <secret-key-file>' );
		if ( file_exists( $path ) ) {
			wpai_release_fail( "{$path} already exists; refusing to overwrite a signing key." );
		}
		if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0700, true ) ) {
			wpai_release_fail( 'Cannot create ' . dirname( $path ) );
		}
		$pair = sodium_crypto_sign_keypair();
		umask( 077 );
		file_put_contents( $path, base64_encode( sodium_crypto_sign_secretkey( $pair ) ) . "\n" );
		chmod( $path, 0600 );
		echo "Secret key written to {$path} (keep it private and backed up).\n";
		echo "Public key (add to includes/release-keys.php):\n" . base64_encode( sodium_crypto_sign_publickey( $pair ) ) . "\n";
		break;

	case 'sign':
		$zip  = $argv[2] ?? wpai_release_fail( 'sign <zip> <secret-key-file>' );
		$path = $argv[3] ?? wpai_release_fail( 'sign <zip> <secret-key-file>' );
		if ( ! is_readable( $path ) ) {
			wpai_release_fail( "Signing key {$path} not found. Create one with: make signing-key" );
		}
		$secret = base64_decode( trim( file_get_contents( $path ) ), true );
		if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
			wpai_release_fail( "{$path} is not a valid signing key." );
		}
		$signature = base64_encode( sodium_crypto_sign_detached( hash_file( 'sha384', $zip, true ), $secret ) );
		if ( ! wpai_release_verify( $zip, $signature, wpai_release_trusted_keys( $root ) ) ) {
			wpai_release_fail( 'This key is not in includes/release-keys.php; installed sites would reject the update.' );
		}
		file_put_contents( $zip . '.sig', $signature . "\n" );
		echo "Signed {$zip} -> {$zip}.sig\n";
		break;

	case 'verify':
		$zip = $argv[2] ?? wpai_release_fail( 'verify <zip> [<sig-file>]' );
		$sig = $argv[3] ?? $zip . '.sig';
		if ( ! is_readable( $zip ) || ! is_readable( $sig ) ) {
			wpai_release_fail( "Missing {$zip} or {$sig}." );
		}
		if ( ! wpai_release_verify( $zip, file_get_contents( $sig ), wpai_release_trusted_keys( $root ) ) ) {
			wpai_release_fail( "Signature of {$zip} does NOT verify with any trusted key." );
		}
		echo "OK: {$zip} is signed with a trusted release key.\n";
		break;

	case 'manifest':
		$version   = $argv[2] ?? wpai_release_fail( 'manifest <version> <changelog-file> <out.json>' );
		$changelog = $argv[3] ?? wpai_release_fail( 'manifest <version> <changelog-file> <out.json>' );
		$out       = $argv[4] ?? wpai_release_fail( 'manifest <version> <changelog-file> <out.json>' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			wpai_release_fail( "Version must be x.y.z, got {$version}." );
		}
		$manifest = array(
			'version'      => $version,
			'requires'     => wpai_release_header( $root, 'Requires at least' ),
			'requires_php' => wpai_release_header( $root, 'Requires PHP' ),
			'changelog'    => is_readable( $changelog ) ? trim( file_get_contents( $changelog ) ) : '',
		);
		file_put_contents( $out, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		echo "Wrote {$out}\n";
		break;

	default:
		fwrite( STDERR, "Usage: php bin/release-tool.php keygen|sign|verify|manifest ...\n" );
		exit( 2 );
}
