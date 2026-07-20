<?php
/**
 * Authenticated encryption for credentials at rest.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AEAD encryption (XChaCha20-Poly1305 via libsodium) for provider API keys
 * and other secrets.
 *
 * Keys are derived from the WordPress salts so they survive plugin updates but
 * never appear in the database. Ciphertext is versioned to allow future key
 * rotation without breaking stored values.
 */
final class Encryption {

	/**
	 * Prefix marking a value as MailPilot ciphertext (v1).
	 */
	private const PREFIX = 'mpx1:';

	/**
	 * Encrypt a plaintext secret. Empty strings pass through unchanged.
	 *
	 * @param string $plaintext Raw secret.
	 * @return string Versioned, base64-encoded ciphertext.
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
			$plaintext,
			'',
			$nonce,
			$this->key()
		);

		$payload = self::PREFIX . base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		sodium_memzero( $plaintext );

		return $payload;
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * Values without the MailPilot prefix are returned as-is, so plaintext
	 * settings migrated from older versions degrade gracefully.
	 *
	 * @param string $payload Stored value.
	 * @return string Plaintext, or empty string on failure.
	 */
	public function decrypt( string $payload ): string {
		if ( '' === $payload || ! str_starts_with( $payload, self::PREFIX ) ) {
			return $payload;
		}

		$decoded = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ) {
			return '';
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );

		$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			$ciphertext,
			'',
			$nonce,
			$this->key()
		);

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Whether a value is MailPilot ciphertext.
	 */
	public function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Derive a 256-bit encryption key from the site's salts.
	 *
	 * Uses AUTH_KEY + AUTH_SALT so the key is stable across requests but never
	 * stored. Filterable for sites that manage keys externally.
	 */
	private function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );

		/**
		 * Filter the raw key material used to derive the encryption key.
		 *
		 * @param string $material Concatenated salt material.
		 */
		$material = (string) apply_filters( 'mailpilot_encryption_key_material', $material );

		// Hash to a fixed 32-byte key regardless of salt length.
		return hash( 'sha256', 'mailpilot|' . $material, true );
	}
}
