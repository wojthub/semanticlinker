<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security utilities for SemanticLinker AI plugin.
 *
 * Provides:
 *   - API key encryption/decryption
 *   - Rate limiting for API calls
 *   - Input validation helpers
 *   - Security headers
 */
class SL_Security {

	/** Rate limit: max API calls per minute. */
	private const RATE_LIMIT_PER_MINUTE = 30;

	/** Rate limit transient prefix. */
	private const RATE_LIMIT_KEY = 'sl_rate_limit_';

	/**
	 * Initialize security features.
	 */
	public function __construct() {
		// Add security headers on admin pages
		add_action( 'admin_init', [ $this, 'add_security_headers' ] );

		// Validate requests on our AJAX endpoints
		add_action( 'admin_init', [ $this, 'validate_admin_request' ] );
	}

	/* ── API Key Encryption ────────────────────────────────────────── */

	/** Encryption method markers - stored as prefix to identify how key was encrypted */
	private const ENC_OPENSSL = 'ossl:';
	private const ENC_XOR     = 'xor:';

	/**
	 * Encrypt the API key before storing.
	 *
	 * Uses WordPress AUTH_KEY as encryption key for simplicity.
	 * Stores a method prefix to ensure correct decryption.
	 *
	 * @param string $api_key  Plain text API key.
	 * @return string          Encrypted API key (method prefix + base64 encoded).
	 */
	public static function encrypt_api_key( string $api_key ): string {
		if ( empty( $api_key ) ) {
			return '';
		}

		$key = self::get_encryption_key();

		// Use OpenSSL if available (preferred)
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv = openssl_random_pseudo_bytes( 16 );
			$encrypted = openssl_encrypt( $api_key, 'AES-256-CBC', $key, 0, $iv );
			// Prefix with method marker so we know how to decrypt
			return self::ENC_OPENSSL . base64_encode( $iv . '::' . $encrypted );
		}

		// Fallback: simple XOR obfuscation (not true encryption, but better than plain text)
		return self::ENC_XOR . base64_encode( self::xor_string( $api_key, $key ) );
	}

	/**
	 * Decrypt the API key for use.
	 *
	 * Detects encryption method from prefix and decrypts accordingly.
	 * Returns empty string with error log if method is unavailable.
	 *
	 * @param string $encrypted  Encrypted API key.
	 * @return string            Plain text API key or empty on failure.
	 */
	public static function decrypt_api_key( string $encrypted ): string {
		if ( empty( $encrypted ) ) {
			return '';
		}

		// Check if already plain text (for backward compatibility with very old data)
		if ( strpos( $encrypted, 'AIza' ) === 0 ) {
			return $encrypted;
		}

		$key = self::get_encryption_key();

		// Check for OpenSSL-encrypted data (new format with prefix)
		if ( strpos( $encrypted, self::ENC_OPENSSL ) === 0 ) {
			$data = substr( $encrypted, strlen( self::ENC_OPENSSL ) );

			if ( ! function_exists( 'openssl_decrypt' ) ) {
				// OpenSSL was used to encrypt but is now unavailable
				SL_Debug::log( 'security', 'ERROR: Cannot decrypt API key - OpenSSL extension required but not available' );
				return '';
			}

			$decoded = base64_decode( $data );
			if ( $decoded !== false && strpos( $decoded, '::' ) !== false ) {
				list( $iv, $cipher ) = explode( '::', $decoded, 2 );
				$decrypted = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
				if ( $decrypted !== false ) {
					return $decrypted;
				}
			}

			SL_Debug::log( 'security', 'ERROR: OpenSSL decryption failed - corrupted data?' );
			return '';
		}

		// Check for XOR-encrypted data (new format with prefix)
		if ( strpos( $encrypted, self::ENC_XOR ) === 0 ) {
			$data = substr( $encrypted, strlen( self::ENC_XOR ) );
			$decoded = base64_decode( $data );
			if ( $decoded !== false ) {
				return self::xor_string( $decoded, $key );
			}
			return '';
		}

		// Legacy format (no prefix) - try to detect and decrypt
		// This handles data encrypted before the prefix system was added
		$decoded = base64_decode( $encrypted );
		if ( $decoded === false ) {
			return '';
		}

		// Legacy OpenSSL format detection (contains '::' separator)
		if ( strpos( $decoded, '::' ) !== false && function_exists( 'openssl_decrypt' ) ) {
			list( $iv, $cipher ) = explode( '::', $decoded, 2 );
			$decrypted = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
			if ( $decrypted !== false ) {
				return $decrypted;
			}
		}

		// Legacy XOR format (no '::' separator)
		if ( strpos( $decoded, '::' ) === false ) {
			return self::xor_string( $decoded, $key );
		}

		// If we have '::' but OpenSSL is unavailable, we cannot decrypt
		if ( strpos( $decoded, '::' ) !== false && ! function_exists( 'openssl_decrypt' ) ) {
			SL_Debug::log( 'security', 'ERROR: Legacy OpenSSL-encrypted key cannot be decrypted - OpenSSL unavailable' );
			return '';
		}

		return '';
	}

	/**
	 * Get encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_encryption_key(): string {
		// Use multiple WordPress keys for better entropy
		$key = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$key .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$key .= SECURE_AUTH_KEY;
		}

		// Ensure minimum key length
		if ( strlen( $key ) < 32 ) {
			$key = str_pad( $key, 32, 'sl_default_key_' );
		}

		return hash( 'sha256', $key, true );
	}

	/**
	 * XOR string obfuscation (fallback when OpenSSL unavailable).
	 *
	 * @param string $string
	 * @param string $key
	 * @return string
	 */
	private static function xor_string( string $string, string $key ): string {
		$result = '';
		$key_len = strlen( $key );

		for ( $i = 0; $i < strlen( $string ); $i++ ) {
			$result .= $string[ $i ] ^ $key[ $i % $key_len ];
		}

		return $result;
	}

	/* ── Rate Limiting ─────────────────────────────────────────────── */

	/**
	 * Check if rate limit is exceeded.
	 *
	 * @param string $context  Context identifier (e.g., 'embedding', 'gemini').
	 * @return bool            True if rate limit exceeded.
	 */
	public static function is_rate_limited( string $context = 'api' ): bool {
		$user_id = get_current_user_id();
		$key = self::RATE_LIMIT_KEY . $context . '_' . $user_id;

		$count = self::get_rate_limit_count( $key );

		return $count >= self::RATE_LIMIT_PER_MINUTE;
	}

	/**
	 * Get current rate limit count from cache or transient.
	 *
	 * @param string $key  Rate limit key.
	 * @return int         Current count.
	 */
	private static function get_rate_limit_count( string $key ): int {
		if ( wp_using_ext_object_cache() ) {
			$cache_key = 'sl_rate_' . $key;
			$count = wp_cache_get( $cache_key, 'semanticlinker' );
			return $count !== false ? (int) $count : 0;
		}

		return (int) get_transient( $key );
	}

	/**
	 * Increment the rate limit counter.
	 *
	 * Uses object cache atomic increment when available (Redis, Memcached),
	 * falls back to transient-based approach for basic setups.
	 * Note: Transient fallback has a small race window which is acceptable
	 * for rate limiting purposes (may allow a few extra requests through).
	 *
	 * @param string $context  Context identifier.
	 */
	public static function increment_rate_limit( string $context = 'api' ): void {
		$user_id = get_current_user_id();
		$key = self::RATE_LIMIT_KEY . $context . '_' . $user_id;

		// Use object cache atomic increment if available (Redis, Memcached)
		if ( wp_using_ext_object_cache() ) {
			$cache_key = 'sl_rate_' . $key;
			$cache_group = 'semanticlinker';

			// Try atomic increment first
			$result = wp_cache_incr( $cache_key, 1, $cache_group );

			if ( $result === false ) {
				// Key doesn't exist - initialize with 1 and 60s expiry
				wp_cache_set( $cache_key, 1, $cache_group, 60 );
			}
			return;
		}

		// Fallback: transient-based (has small race window, acceptable for rate limiting)
		$current = get_transient( $key );

		if ( $current === false ) {
			set_transient( $key, 1, 60 );
		} else {
			set_transient( $key, (int) $current + 1, 60 );
		}
	}

	/**
	 * Get remaining API calls before rate limit.
	 *
	 * @param string $context
	 * @return int
	 */
	public static function get_remaining_calls( string $context = 'api' ): int {
		$user_id = get_current_user_id();
		$key = self::RATE_LIMIT_KEY . $context . '_' . $user_id;

		$count = self::get_rate_limit_count( $key );
		return max( 0, self::RATE_LIMIT_PER_MINUTE - $count );
	}

	/* ── Input Validation ──────────────────────────────────────────── */

	/**
	 * Validate and sanitize a post ID.
	 *
	 * @param mixed $post_id
	 * @return int|false  Valid post ID or false.
	 */
	public static function validate_post_id( $post_id ) {
		$id = absint( $post_id );

		if ( $id < 1 ) {
			return false;
		}

		// Check if post exists
		if ( ! get_post( $id ) ) {
			return false;
		}

		return $id;
	}

	/**
	 * Validate a URL is safe and belongs to this site.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function validate_internal_url( string $url ): bool {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return false;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );

		return $site_host === $url_host;
	}

	/**
	 * Sanitize anchor text - remove dangerous characters.
	 *
	 * @param string $anchor
	 * @return string
	 */
	public static function sanitize_anchor( string $anchor ): string {
		// Remove HTML tags
		$anchor = wp_strip_all_tags( $anchor );

		// Remove script/style content that might have slipped through
		$anchor = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $anchor );
		$anchor = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $anchor );

		// Remove special characters that could be used for XSS
		$anchor = preg_replace( '/[<>"\']/', '', $anchor );

		// Normalize whitespace
		$anchor = preg_replace( '/\s+/', ' ', trim( $anchor ) );

		return $anchor;
	}

	/* ── Security Headers ──────────────────────────────────────────── */

	/**
	 * Add security headers on SemanticLinker admin pages.
	 */
	public function add_security_headers(): void {
		// Only add on our pages
		if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'semanticlinker' ) === false ) {
			return;
		}

		// Prevent clickjacking
		if ( ! headers_sent() ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-XSS-Protection: 1; mode=block' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		}
	}

	/**
	 * Validate admin request integrity.
	 */
	public function validate_admin_request(): void {
		// Only on our pages
		if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'semanticlinker' ) === false ) {
			return;
		}

		// Ensure user has proper capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				__( 'Nie masz uprawnień do tej strony.', 'semanticlinker-ai' ),
				__( 'Brak uprawnień', 'semanticlinker-ai' ),
				[ 'response' => 403 ]
			);
		}
	}

	/* ── Logging Security Events ───────────────────────────────────── */

	/**
	 * Log a security-related event.
	 *
	 * @param string $event    Event type.
	 * @param array  $context  Additional context.
	 */
	public static function log_security_event( string $event, array $context = [] ): void {
		$context['user_id'] = get_current_user_id();
		$context['ip'] = self::get_client_ip();
		$context['timestamp'] = current_time( 'mysql' );

		SL_Debug::log( 'security', $event, $context );
	}

	/**
	 * Get client IP address (with proxy consideration).
	 *
	 * @return string
	 */
	private static function get_client_ip(): string {
		$headers = [
			'HTTP_CF_CONNECTING_IP',  // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = $_SERVER[ $header ];

				// Handle comma-separated list (X-Forwarded-For)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}

				// Validate IP
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}

	/* ── CSRF Token Helpers ────────────────────────────────────────── */

	/**
	 * Generate a CSRF token for forms.
	 *
	 * @param string $action
	 * @return string
	 */
	public static function generate_token( string $action ): string {
		return wp_create_nonce( 'sl_' . $action );
	}

	/**
	 * Verify a CSRF token.
	 *
	 * @param string $token
	 * @param string $action
	 * @return bool
	 */
	public static function verify_token( string $token, string $action ): bool {
		return wp_verify_nonce( $token, 'sl_' . $action ) !== false;
	}
}
