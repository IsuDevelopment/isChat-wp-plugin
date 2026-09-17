<?php
/**
 * Authenticated, read-only WooCommerce offer verification for product cards.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ACS_Product_Offers {
	private const ROUTE_PATH = '/wp-json/acs/v1/product-offers';
	private const MAX_ITEMS  = 6;

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_register_connector' ], 20 );
	}

	public static function maybe_register_connector(): void {
		if ( ! current_user_can( 'manage_options' ) || ! ACS_Sync_Manager::is_configured() ) {
			return;
		}
		$secret      = self::secret();
		$fingerprint = hash( 'sha256', ACS_VERSION . ':' . $secret );
		if ( hash_equals( (string) get_option( 'acs_offer_connector_fingerprint', '' ), $fingerprint ) ) {
			return;
		}
		$result = ACS_Sync_Manager::make_client()->register_product_connector( $secret );
		if ( $result['success'] ) {
			update_option( 'acs_offer_connector_fingerprint', $fingerprint, false );
		}
	}

	public static function secret(): string {
		$secret = (string) get_option( 'acs_offer_verification_secret', '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( 'acs_offer_verification_secret', $secret, false );
		}
		return $secret;
	}

	public static function register_route(): void {
		register_rest_route( 'acs/v1', '/product-offers', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle' ],
			'permission_callback' => [ __CLASS__, 'authorize' ],
		] );
	}

	public static function authorize( WP_REST_Request $request ): bool|WP_Error {
		$timestamp = (string) $request->get_header( 'x-ischat-timestamp' );
		$nonce     = sanitize_text_field( (string) $request->get_header( 'x-ischat-nonce' ) );
		$signature = strtolower( sanitize_text_field( (string) $request->get_header( 'x-ischat-signature' ) ) );
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 60 || ! preg_match( '/^[A-Za-z0-9]{32}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'acs_offer_unauthorized', __( 'Invalid offer verification signature.', 'ai-ischat' ), [ 'status' => 401 ] );
		}

		$canonical = "POST\n" . self::ROUTE_PATH . "\n" . hash( 'sha256', $request->get_body() ) . "\n{$timestamp}\n{$nonce}";
		$expected  = hash_hmac( 'sha256', $canonical, self::secret() );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'acs_offer_unauthorized', __( 'Invalid offer verification signature.', 'ai-ischat' ), [ 'status' => 401 ] );
		}

		// add_option is a single INSERT protected by a unique option_name index,
		// providing atomic replay protection even without persistent object cache.
		$nonce_key = 'acs_offer_nonce_' . hash( 'sha256', $nonce );
		if ( ! add_option( $nonce_key, time(), '', false ) ) {
			return new WP_Error( 'acs_offer_replay', __( 'Offer verification request was already used.', 'ai-ischat' ), [ 'status' => 409 ] );
		}
		wp_schedule_single_event( time() + 180, 'acs_cleanup_offer_nonce', [ $nonce_key ] );

		return true;
	}

	public static function cleanup_nonce( string $option_name ): void {
		if ( str_starts_with( $option_name, 'acs_offer_nonce_' ) ) {
			delete_option( $option_name );
		}
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'acs_woocommerce_unavailable', __( 'WooCommerce is unavailable.', 'ai-ischat' ), [ 'status' => 503 ] );
		}
		$items = $request->get_json_params()['items'] ?? null;
		if ( ! is_array( $items ) || count( $items ) < 1 || count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error( 'acs_offer_invalid_items', __( 'Provide between one and six products.', 'ai-ischat' ), [ 'status' => 422 ] );
		}

		$result = [];
		foreach ( $items as $item ) {
			$result[] = self::resolve_offer( is_array( $item ) ? $item : [] );
		}

		return new WP_REST_Response( [ 'data' => [ 'items' => $result ] ] );
	}

	/** @param array<string, mixed> $item @return array<string, mixed> */
	private static function resolve_offer( array $item ): array {
		$product_id   = absint( $item['product_id'] ?? 0 );
		$variation_id = absint( $item['variation_id'] ?? 0 );
		$identity     = [ 'product_id' => $product_id, 'variation_id' => $variation_id ?: null ];
		$parent       = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $parent || 'publish' !== get_post_status( $product_id ) ) {
			return $identity + [ 'status' => 'not_found' ];
		}
		if ( '1' !== (string) get_post_meta( $product_id, '_acs_ai_index_enabled', true ) ) {
			return $identity + [ 'status' => 'not_eligible' ];
		}

		$product = $parent;
		if ( $variation_id ) {
			$product = wc_get_product( $variation_id );
			if ( ! $product || ! method_exists( $product, 'get_parent_id' ) || (int) $product->get_parent_id() !== $product_id || 'publish' !== $product->get_status() ) {
				return $identity + [ 'status' => 'not_found' ];
			}
		}

		$price         = wc_get_price_to_display( $product, [ 'price' => (float) $product->get_price() ] );
		$regular_price = wc_get_price_to_display( $product, [ 'price' => (float) $product->get_regular_price() ] );
		$image_id      = (int) $product->get_image_id() ?: (int) $parent->get_image_id();
		$attributes    = [];
		foreach ( (array) $product->get_attributes() as $name => $value ) {
			if ( is_object( $value ) ) {
				continue;
			}
			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( (string) $name, $parent ) : (string) $name;
			$term  = taxonomy_exists( (string) $name ) ? get_term_by( 'slug', (string) $value, (string) $name ) : false;
			$attributes[] = [ 'label' => sanitize_text_field( (string) $label ), 'value' => sanitize_text_field( $term && isset( $term->name ) ? (string) $term->name : (string) $value ) ];
		}

		return $identity + [
			'status'        => 'ok',
			'product_type'  => sanitize_key( (string) $parent->get_type() ),
			'price'         => wc_format_decimal( $price, wc_get_price_decimals() ),
			'regular_price' => wc_format_decimal( $regular_price, wc_get_price_decimals() ),
			'currency'      => get_woocommerce_currency(),
			'tax_display'   => get_option( 'woocommerce_tax_display_shop', 'incl' ) === 'excl' ? 'excl' : 'incl',
			'is_on_sale'    => (bool) $product->is_on_sale(),
			'is_purchasable'=> (bool) $product->is_purchasable(),
			'stock_status'  => sanitize_key( (string) $product->get_stock_status() ),
			'url'           => esc_url_raw( (string) $product->get_permalink() ),
			'image_url'     => $image_id ? esc_url_raw( (string) wp_get_attachment_image_url( $image_id, 'medium' ) ) : null,
			'attributes'    => array_slice( $attributes, 0, 3 ),
		];
	}
}

add_action( 'acs_cleanup_offer_nonce', [ 'ACS_Product_Offers', 'cleanup_nonce' ] );
