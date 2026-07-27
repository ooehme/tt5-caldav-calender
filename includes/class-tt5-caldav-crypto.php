<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TT5_CalDAV_Crypto {
	private function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}

	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$raw = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );

			if ( false === $raw ) {
				throw new RuntimeException( 'OpenSSL encryption failed.' );
			}

			return 'o1:' . base64_encode( $iv . $tag . $raw );
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$raw   = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );

			return 's1:' . base64_encode( $nonce . $raw );
		}

		throw new RuntimeException( 'No supported encryption extension available.' );
	}

	public function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}

		if ( str_starts_with( $ciphertext, 'o1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$decoded = base64_decode( substr( $ciphertext, 3 ), true );
			if ( false === $decoded || strlen( $decoded ) < 29 ) {
				throw new RuntimeException( 'Invalid encrypted value.' );
			}

			$iv  = substr( $decoded, 0, 12 );
			$tag = substr( $decoded, 12, 16 );
			$raw = substr( $decoded, 28 );
			$out = openssl_decrypt( $raw, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );

			if ( false === $out ) {
				throw new RuntimeException( 'The stored password could not be decrypted. WordPress salts may have changed.' );
			}

			return $out;
		}

		if ( str_starts_with( $ciphertext, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$decoded = base64_decode( substr( $ciphertext, 3 ), true );
			if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				throw new RuntimeException( 'Invalid encrypted value.' );
			}

			$nonce = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$raw   = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$out   = sodium_crypto_secretbox_open( $raw, $nonce, $this->key() );

			if ( false === $out ) {
				throw new RuntimeException( 'The stored password could not be decrypted. WordPress salts may have changed.' );
			}

			return $out;
		}

		throw new RuntimeException( 'Unsupported encrypted value.' );
	}
}
