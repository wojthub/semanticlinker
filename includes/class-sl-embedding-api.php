<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper around the Google Gemini Embedding API.
 *
 * Supports batch requests: pass an array of strings, get back an
 * array of float-vectors in the same order.  All network I/O
 * goes through WordPress's wp_remote_post so timeouts and proxy
 * settings are respected.
 */
class SL_Embedding_API {

	private string $api_key;
	private string $model;

	/** @var array Accumulated API errors for user notification */
	private static array $api_errors = [];

	/** @var int Max errors to store */
	private const MAX_ERRORS = 10;

	/**
	 * @param string|null $api_key  Overrides the stored setting (useful for tests).
	 * @param string|null $model    Overrides the stored setting.
	 */
	public function __construct( $api_key = null, $model = null ) {
		// Use decrypted API key from settings
		$this->api_key = $api_key !== null ? $api_key : SL_Settings::get_api_key();
		$raw_model     = $model  !== null ? $model  : SL_Settings::get( 'embedding_model', 'gemini-embedding-001' );
		$this->model   = self::sanitize_model_name( $raw_model );
	}

	/**
	 * Sanitize model name to prevent URL injection.
	 * Only allows alphanumeric characters, hyphens, underscores, and dots.
	 *
	 * @param string $model  Raw model name.
	 * @return string        Sanitized model name.
	 */
	private static function sanitize_model_name( string $model ): string {
		// Remove any characters that are not alphanumeric, hyphen, underscore, or dot
		$sanitized = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', $model );

		// Ensure we have a valid model name
		if ( empty( $sanitized ) ) {
			return 'gemini-embedding-001';  // Fallback to default
		}

		return $sanitized;
	}

	/**
	 * Embed an array of strings using Google Gemini API.
	 *
	 * @param string[] $texts   Texts to embed.  Must be non-empty strings.
	 * @return array|false      Array of float[] vectors (same order as input),
	 *                          or false on any error.
	 */
	public function embed( array $texts ) {
		if ( empty( $this->api_key ) ) {
			SL_Debug::log( 'api', 'ERROR: API key is empty' );
			return false;
		}
		if ( empty( $texts ) ) {
			return [];
		}

		// Rate limiting protection
		if ( SL_Security::is_rate_limited( 'embedding' ) ) {
			SL_Debug::log( 'api', 'ERROR: Rate limit exceeded for embedding API' );
			SL_Security::log_security_event( 'rate_limit_exceeded', [ 'context' => 'embedding' ] );
			return false;
		}
		SL_Security::increment_rate_limit( 'embedding' );

		$texts = array_values( $texts );

		// Gemini has a limit of 100 texts per batch request
		// Process in batches if needed
		$batch_size = 100;
		$all_embeddings = [];

		for ( $i = 0; $i < count( $texts ); $i += $batch_size ) {
			$batch = array_slice( $texts, $i, $batch_size );
			$embeddings = $this->embed_batch( $batch );

			if ( $embeddings === false ) {
				return false;
			}

			$all_embeddings = array_merge( $all_embeddings, $embeddings );
		}

		return $all_embeddings;
	}

	/**
	 * Embed a single batch of texts (max 100).
	 *
	 * @param string[] $texts
	 * @return array|false
	 */
	private function embed_batch( array $texts ) {
		// Build requests array for batchEmbedContents
		$requests = [];
		foreach ( $texts as $text ) {
			$requests[] = [
				'model'   => 'models/' . $this->model,
				'content' => [
					'parts' => [
						[ 'text' => $text ]
					]
				]
			];
		}

		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents?key=%s',
			$this->model,
			$this->api_key
		);

		SL_Debug::log( 'api', 'Calling Gemini API', [
			'model'       => $this->model,
			'texts_count' => count( $texts ),
			'url'         => preg_replace( '/key=[^&]+/', 'key=***', $url ),
		] );

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => json_encode( [ 'requests' => $requests ] ),
				'timeout' => 120,
			]
		);

		if ( is_wp_error( $response ) ) {
			SL_Debug::log( 'api', 'ERROR: WP Error', [
				'error' => $response->get_error_message(),
			] );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		SL_Debug::log( 'api', 'Gemini API response', [
			'status_code' => $code,
			'has_embeddings' => isset( $data['embeddings'] ),
			'embeddings_count' => isset( $data['embeddings'] ) ? count( $data['embeddings'] ) : 0,
			'error' => $data['error'] ?? null,
		] );

		if ( $code !== 200 ) {
			SL_Debug::log( 'api', 'ERROR: Non-200 response', [
				'code' => $code,
				'body' => substr( $body, 0, 500 ),
			] );
			return false;
		}

		if ( ! isset( $data['embeddings'] ) || ! is_array( $data['embeddings'] ) ) {
			SL_Debug::log( 'api', 'ERROR: No embeddings in response', [
				'body' => substr( $body, 0, 500 ),
			] );
			return false;
		}

		// Check for empty embeddings array
		if ( empty( $data['embeddings'] ) ) {
			SL_Debug::log( 'api', 'ERROR: Empty embeddings array in response', [
				'texts_count' => count( $texts ),
				'body'        => substr( $body, 0, 500 ),
			] );
			return false;
		}

		// Extract vectors from Gemini response format
		$vectors = [];
		foreach ( $data['embeddings'] as $embedding ) {
			if ( isset( $embedding['values'] ) && is_array( $embedding['values'] ) ) {
				$vectors[] = $embedding['values'];
			} else {
				SL_Debug::log( 'api', 'ERROR: Invalid embedding format', [
					'embedding' => $embedding,
				] );
				return false;
			}
		}

		SL_Debug::log( 'api', 'Successfully got embeddings', [
			'count' => count( $vectors ),
			'vector_dim' => ! empty( $vectors ) ? count( $vectors[0] ) : 0,
		] );

		return $vectors;
	}

	/**
	 * Embed a single text string. Wrapper around embed() for convenience.
	 *
	 * @param string $text  Text to embed.
	 * @return array|false  Float[] vector or false on error.
	 */
	public function embed_single( string $text ) {
		$result = $this->embed( [ $text ] );
		return $result && isset( $result[0] ) ? $result[0] : false;
	}

	/**
	 * Evaluate if an anchor text is contextually appropriate for linking to a target title.
	 * Uses Gemini's text generation API to assess semantic relevance.
	 *
	 * @param string $anchor_text   The proposed anchor text.
	 * @param string $target_title  The title of the target article.
	 * @return bool                 True if the anchor is appropriate, false otherwise.
	 */
	public function evaluate_anchor_match( string $anchor_text, string $target_title ): bool {
		if ( empty( $this->api_key ) ) {
			SL_Debug::log( 'api', 'Anchor filter: API key is empty - skipping filter' );
			return true;  // Allow link if no API key (fail open)
		}

		$prompt = sprintf(
			'Jesteś starszym specjalistą ds. SEO i lingwistyki. Twoim zadaniem jest ocena spójności semantycznej pomiędzy tekstem zakotwiczenia (anchor text) a tytułem artykułu docelowego w ramach strategii linkowania wewnętrznego.

Przeanalizuj poniższe dane wejściowe:
Anchor text: "%s"
Tytuł artykułu docelowego: "%s"

Kryteria oceny:
1. Zgodność tematyczna: Czy anchor text odnosi się do głównego tematu, problemu lub słowa kluczowego zawartego w tytule?
2. Synonimy i hiperonimy: Traktuj synonimy, wyrazy bliskoznaczne oraz kategorie nadrzędne jako pasujące (np. "buty" pasuje do "Obuwie sportowe na lato").
3. Intencja użytkownika: Czy użytkownik klikający w ten anchor text spodziewałby się trafić na artykuł o podanym tytule?

Zasady wykluczenia:
- Jeśli anchor text jest mylący, całkowicie niezwiązany tematycznie lub sugeruje zupełnie inny rodzaj treści – uznaj to za błąd.

Format odpowiedzi:
Twoja odpowiedź musi składać się WYŁĄCZNIE z jednego słowa. Nie dodawaj żadnych wyjaśnień, znaków interpunkcyjnych ani wstępu.

Zwróć wynik:
- "TAK" (jeśli relacja jest logiczna i semantycznie poprawna)
- "NIE" (jeśli brak powiązania semantycznego)',
			$anchor_text,
			$target_title
		);

		$filter_model = self::sanitize_model_name( SL_Settings::get( 'filter_model', 'gemini-2.5-flash' ) );
		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
			$filter_model,
			$this->api_key
		);

		$body = [
			'contents' => [
				[
					'parts' => [
						[ 'text' => $prompt ]
					]
				]
			],
			'generationConfig' => [
				'temperature'     => 0.1,  // Low temperature for consistent responses
				'maxOutputTokens' => 256,  // Gemini 2.5+ needs room for thinking + TAK/NIE response
			]
		];

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => json_encode( $body ),
				'timeout' => 45,  // Increased from 30s for AI generation
			]
		);

		if ( is_wp_error( $response ) ) {
			SL_Debug::log( 'api', 'Anchor filter: WP Error', [
				'error'        => $response->get_error_message(),
				'anchor'       => $anchor_text,
				'target_title' => $target_title,
			] );
			self::track_error( 'connection', 'Błąd połączenia: ' . $response->get_error_message() );
			return true;  // Fail open - allow link on error
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$resp_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $resp_body, true );

		if ( $code !== 200 ) {
			SL_Debug::log( 'api', 'Anchor filter: Non-200 response', [
				'code' => $code,
				'body' => substr( $resp_body, 0, 300 ),
			] );
			self::track_error( 'api_error', 'Błąd API (kod ' . $code . ')' );
			return true;  // Fail open
		}

		// Check for MAX_TOKENS finish reason (indicates response was cut off)
		$finish_reason = $data['candidates'][0]['finishReason'] ?? null;
		if ( $finish_reason === 'MAX_TOKENS' ) {
			SL_Debug::log( 'api', 'Anchor filter: Response cut off (MAX_TOKENS)', [
				'anchor'       => $anchor_text,
				'target_title' => $target_title,
			] );
			self::track_error( 'max_tokens', 'Odpowiedź API została obcięta (MAX_TOKENS)' );
			return true;  // Fail-open
		}

		// Extract text from Gemini response - validate structure first
		if ( ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			// Unexpected response structure - log and fail-open
			SL_Debug::log( 'api', 'Anchor filter: Unexpected API response structure', [
				'anchor'       => $anchor_text,
				'target_title' => $target_title,
				'response_keys' => is_array( $data ) ? array_keys( $data ) : 'not_array',
				'raw_response' => substr( $resp_body, 0, 500 ),
			] );
			self::track_error( 'malformed', 'Nieprawidłowa struktura odpowiedzi API' );
			return true;  // Fail-open: allow link when API response is malformed
		}

		$text = $data['candidates'][0]['content']['parts'][0]['text'];
		$text = mb_strtoupper( trim( $text ), 'UTF-8' );

		// Check for empty response (another form of failure)
		if ( empty( $text ) ) {
			SL_Debug::log( 'api', 'Anchor filter: Empty text in response', [
				'anchor'       => $anchor_text,
				'target_title' => $target_title,
			] );
			self::track_error( 'empty', 'Pusta odpowiedź z API' );
			return true;  // Fail-open
		}

		$is_match = ( mb_strpos( $text, 'TAK', 0, 'UTF-8' ) !== false );

		SL_Debug::log( 'api', 'Anchor filter result', [
			'anchor'       => $anchor_text,
			'target_title' => $target_title,
			'response'     => $text,
			'is_match'     => $is_match,
		] );

		return $is_match;
	}

	/* ── API Error Tracking for User Notifications ──────────────── */

	/**
	 * Track an API error for user notification.
	 *
	 * @param string $error_type  Error type identifier.
	 * @param string $message     Human-readable error message.
	 */
	private static function track_error( string $error_type, string $message ): void {
		if ( count( self::$api_errors ) < self::MAX_ERRORS ) {
			self::$api_errors[] = [
				'type'    => $error_type,
				'message' => $message,
				'time'    => current_time( 'mysql' ),
			];
		}
	}

	/**
	 * Get all accumulated API errors.
	 *
	 * @return array
	 */
	public static function get_errors(): array {
		return self::$api_errors;
	}

	/**
	 * Check if there are any API errors.
	 *
	 * @return bool
	 */
	public static function has_errors(): bool {
		return ! empty( self::$api_errors );
	}

	/**
	 * Clear all accumulated API errors.
	 */
	public static function clear_errors(): void {
		self::$api_errors = [];
	}

	/**
	 * Get a user-friendly error summary.
	 *
	 * @return string|null
	 */
	public static function get_error_summary(): ?string {
		if ( empty( self::$api_errors ) ) {
			return null;
		}

		$count = count( self::$api_errors );
		$types = array_unique( array_column( self::$api_errors, 'type' ) );

		if ( in_array( 'max_tokens', $types, true ) ) {
			return sprintf(
				'Błąd API Gemini: %d odpowiedzi zostało obciętych (MAX_TOKENS). Sprawdź model filtra AI w ustawieniach.',
				$count
			);
		}

		if ( in_array( 'rate_limit', $types, true ) ) {
			return 'Błąd API Gemini: Przekroczono limit zapytań. Spróbuj ponownie za chwilę.';
		}

		if ( in_array( 'api_error', $types, true ) ) {
			return sprintf( 'Błąd API Gemini: %d zapytań zakończyło się błędem.', $count );
		}

		return sprintf( 'Wystąpiło %d problemów z API Gemini.', $count );
	}
}
