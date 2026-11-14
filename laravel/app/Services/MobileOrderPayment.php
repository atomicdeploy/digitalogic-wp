<?php
/**
 * Read canonical payment instructions for an already authorized Woo order.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 native adapter contracts.

/** Shared by initial checkout, recovery and the owner's order history. */
final class MobileOrderPayment {

	/**
	 * Project payment state without initiating or repeating a payment.
	 *
	 * @param object      $order Canonical Woo order.
	 * @param int         $owner Authenticated expected owner.
	 * @param object|null $gateway Already resolved canonical BACS gateway, if available.
	 * @return array Native payment presentation.
	 * @throws \UnexpectedValueException If the caller has not established ownership.
	 */
	public static function forOrder( object $order, int $owner, ?object $gateway = null ): array {
		if ( $owner <= 0 || (int) $order->get_customer_id() !== $owner ) {
			throw new \UnexpectedValueException( 'order_owner_mismatch' );
		}
		if ( $order->is_paid() ) {
			return array( 'status' => 'complete' );
		}
		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return array( 'status' => 'requires_review' );
		}
		if ( (float) $order->get_total() <= 0 ) {
			return array( 'status' => 'not_required' );
		}
		if ( 'bacs' !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) ) {
			return array( 'status' => 'requires_review' );
		}
		if ( null === $gateway ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = $gateways['bacs'] ?? null;
		}
		if ( ! $gateway || 'bacs' !== $gateway->id ) {
			return array( 'status' => 'requires_review' );
		}
		$accounts = apply_filters( 'woocommerce_bacs_accounts', (array) $gateway->account_details, $order->get_id() );
		$public   = array();
		foreach ( (array) $accounts as $account ) {
			$fields = array();
			foreach ( array( 'account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic' ) as $key ) {
				$value = is_array( $account ) ? ( $account[ $key ] ?? '' ) : '';
				if ( is_scalar( $value ) ) {
					$fields[ $key ] = wp_strip_all_tags( wp_unslash( (string) $value ) );
				}
			}
			if ( array_filter( $fields, static fn( $value ) => '' !== trim( $value ) ) ) {
				$public[] = $fields;
			}
		}
		return array(
			'status'        => 'awaiting_bank_transfer',
			'instructions'  => wp_strip_all_tags( (string) $gateway->get_option( 'instructions', '' ) ),
			'bank_accounts' => $public,
		);
	}
}
