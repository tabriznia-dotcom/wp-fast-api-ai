<?php
/**
 * Encryption at rest for API keys.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets with libsodium (bundled with WordPress via sodium_compat).
 *
 * The key is derived from the site's authentication salts, so a database dump
 * alone does not reveal stored API keys. Keys can also be supplied through
 * constants in wp-config.php, in which case nothing is stored in the database.
 */
final class Secrets {

	const PREFIX = 'aipd1:';

	/**
	 * Encrypts a plaintext secret.
	 *
	 * @param string $plaintext Secret value.
	 * @return string Encoded ciphertext, or empty string for empty input.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
			return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext encoding, not obfuscation.
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Decrypts a value produced by encrypt().
	 *
	 * @param string $encoded Encoded ciphertext.
	 * @return string Plaintext, or empty string when decryption fails.
	 */
	public static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		if ( 0 !== strpos( $encoded, self::PREFIX ) ) {
			return '';
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext decoding, not obfuscation.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		try {
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
			return false === $plain ? '' : $plain;
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Derives the 32-byte encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		$material = defined( 'AIPD_ENCRYPTION_KEY' ) && AIPD_ENCRYPTION_KEY ? (string) AIPD_ENCRYPTION_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( 'ai-page-designer|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
