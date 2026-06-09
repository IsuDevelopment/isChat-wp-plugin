<?php
/**
 * HTTP client for communicating with the Laravel SaaS API.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACS_API_Client {

	private string $api_url;
	private string $api_key;

	public function __construct( string $api_url, string $api_key ) {
		$this->api_url = trailingslashit( $api_url );
		$this->api_key = $api_key;
	}

	/**
	 * Test connection to the API.
	 *
	 * @return array{success: bool, message: string, data?: mixed}
	 */
	public function verify(): array {
		$response = $this->request( 'GET', 'v1/sites/verify' );
		return $response;
	}

	/**
	 * Upsert a document.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{success: bool, message: string, data?: mixed}
	 */
	public function upsert_document( array $payload ): array {
		return $this->request( 'POST', 'v1/documents/upsert', $payload );
	}

	/**
	 * Delete a document by source_id.
	 *
	 * @param string|int $source_id
	 * @param string     $source_type
	 * @return array{success: bool, message: string, data?: mixed}
	 */
	public function delete_document( $source_id, string $source_type ): array {
		return $this->request( 'POST', 'v1/documents/delete', [
			'source_id'   => (string) $source_id,
			'source_type' => $source_type,
		] );
	}

	/**
	 * Send HTTP request to the API.
	 *
	 * @param string               $method
	 * @param string               $endpoint
	 * @param array<string, mixed> $body
	 * @return array{success: bool, message: string, data?: mixed}
	 */
	private function request( string $method, string $endpoint, array $body = [] ): array {
		$url  = $this->api_url . 'api/' . $endpoint;
		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			],
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return [
				'success' => true,
				'message' => 'OK',
				'data'    => $body['data'] ?? $body,
			];
		}

		return [
			'success' => false,
			'message' => $body['message'] ?? "HTTP {$code}",
		];
	}
}
