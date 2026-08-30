<?php
/**
 * Provider-neutral pricing diagnostic factory and normalizer.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds one bounded diagnostic contract for pricing transports and clients.
 */
final class Digitalogic_Pricing_Diagnostic {

	private const MAX_CODE_LENGTH            = 96;
	private const MAX_REASON_LENGTH          = 512;
	private const MAX_RECOVERY_ACTION_LENGTH = 384;
	private const MAX_DETAIL_KEY_LENGTH      = 64;
	private const MAX_DETAIL_STRING_LENGTH   = 256;
	private const MAX_DETAIL_ITEMS           = 20;
	private const MAX_DETAIL_DEPTH           = 2;

	/**
	 * Build one complete diagnostic.
	 *
	 * @param string $code            Stable machine-readable code.
	 * @param string $severity        One of info, warning, or error.
	 * @param bool   $blocking        Whether the operation must stop.
	 * @param string $reason          Exact human-readable reason.
	 * @param bool   $retryable       Whether the unchanged operation may be retried.
	 * @param string $recovery_action Bounded action that resolves or advances the failure.
	 * @param array  $details         Safe diagnostic details.
	 * @return array{code:string,severity:string,blocking:bool,reason:string,retryable:bool,recovery_action:string,details:array}
	 */
	public static function make( $code, $severity, $blocking, $reason, $retryable, $recovery_action, $details = array() ) {
		return self::normalize(
			array(
				'code'            => $code,
				'severity'        => $severity,
				'blocking'        => $blocking,
				'reason'          => $reason,
				'retryable'       => $retryable,
				'recovery_action' => $recovery_action,
				'details'         => $details,
			)
		);
	}

	/**
	 * Normalize a diagnostic, transport envelope, or WP_Error.
	 *
	 * Unknown fields are ignored. Bounded, non-sensitive unknown fields inside
	 * detail containers are preserved so transport wrappers cannot erase useful
	 * evidence.
	 *
	 * @param mixed $value    Diagnostic, transport envelope, or WP_Error.
	 * @param array $defaults Optional field defaults.
	 * @return array{code:string,severity:string,blocking:bool,reason:string,retryable:bool,recovery_action:string,details:array}
	 */
	public static function normalize( $value, $defaults = array() ) {
		$defaults     = is_array( $defaults ) ? $defaults : array();
		$envelope     = self::envelope( $value );
		$data         = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$details      = isset( $envelope['details'] ) && is_array( $envelope['details'] ) ? $envelope['details'] : array();
		$data_details = isset( $data['details'] ) && is_array( $data['details'] ) ? $data['details'] : array();
		$nested       = self::nested_diagnostic( $envelope, $data, $details, $data_details );
		$layers       = array( $nested, $envelope, $data, $details, $data_details, $defaults );

		$blocking = self::boolean( self::pick( $layers, 'blocking', true ), true );
		$severity = strtolower( self::safe_text( self::pick( $layers, 'severity', $blocking ? 'error' : 'warning' ), 16 ) );
		if ( ! in_array( $severity, array( 'info', 'warning', 'error' ), true ) ) {
			$severity = $blocking ? 'error' : 'warning';
		}
		if ( $blocking ) {
			$severity = 'error';
		}

		$retryable = self::boolean( self::pick( $layers, 'retryable', false ), false );
		$reason    = self::pick_text(
			$layers,
			array( 'reason', 'message', 'message_fa' ),
			'The pricing operation could not be completed.',
			self::MAX_REASON_LENGTH
		);
		$recovery  = self::pick_text(
			$layers,
			array( 'recovery_action' ),
			$retryable
				? 'Retry the unchanged request after the reported condition clears.'
				: 'Resolve the reported condition before attempting the operation again.',
			self::MAX_RECOVERY_ACTION_LENGTH
		);

		return array(
			'code'            => self::code( self::pick( $layers, 'code', 'digitalogic_pricing_diagnostic' ) ),
			'severity'        => $severity,
			'blocking'        => $blocking,
			'reason'          => $reason,
			'retryable'       => $retryable,
			'recovery_action' => $recovery,
			'details'         => self::collect_details( $layers ),
		);
	}

	/**
	 * Create a WP_Error whose data carries the complete diagnostic contract.
	 *
	 * @param string $code            Stable machine-readable code.
	 * @param string $reason          Exact human-readable reason.
	 * @param int    $status          HTTP status.
	 * @param bool   $retryable       Whether the unchanged operation may be retried.
	 * @param string $recovery_action Bounded recovery action.
	 * @param array  $details         Safe diagnostic details.
	 * @param bool   $blocking        Whether the operation must stop.
	 * @param string $severity        One of info, warning, or error.
	 * @return WP_Error
	 */
	public static function error( $code, $reason, $status, $retryable, $recovery_action, $details = array(), $blocking = true, $severity = 'error' ) {
		$diagnostic           = self::make( $code, $severity, $blocking, $reason, $retryable, $recovery_action, $details );
		$diagnostic['status'] = max( 400, min( 599, (int) $status ) );

		return new WP_Error( $diagnostic['code'], $diagnostic['reason'], $diagnostic );
	}

	/**
	 * Convert an input value to one array envelope.
	 *
	 * @param mixed $value Input value.
	 * @return array
	 */
	private static function envelope( $value ) {
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) {
			$data           = $value->get_error_data();
			$data           = is_array( $data ) ? $data : array();
			$data['code']   = $value->get_error_code();
			$data['reason'] = $value->get_error_message();

			return $data;
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Find an explicitly nested diagnostic.
	 *
	 * @param array ...$containers Candidate containers.
	 * @return array
	 */
	private static function nested_diagnostic( ...$containers ) {
		foreach ( $containers as $container ) {
			if ( isset( $container['diagnostic'] ) && is_array( $container['diagnostic'] ) ) {
				return $container['diagnostic'];
			}
		}

		return array();
	}

	/**
	 * Return the first explicit field in priority order.
	 *
	 * @param array  $layers   Candidate layers.
	 * @param string $key      Field name.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	private static function pick( $layers, $key, $fallback ) {
		foreach ( $layers as $layer ) {
			if ( is_array( $layer ) && array_key_exists( $key, $layer ) ) {
				return $layer[ $key ];
			}
		}

		return $fallback;
	}

	/**
	 * Return the first non-empty text field in priority order.
	 *
	 * @param array $layers     Candidate layers.
	 * @param array $keys       Field names.
	 * @param mixed $fallback   Fallback value.
	 * @param int   $max_length Maximum text length.
	 * @return string
	 */
	private static function pick_text( $layers, $keys, $fallback, $max_length ) {
		foreach ( $layers as $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $layer ) ) {
					continue;
				}
				$value = self::safe_text( $layer[ $key ], $max_length );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return self::safe_text( $fallback, $max_length );
	}

	/**
	 * Normalize one bool without treating the string "false" as true.
	 *
	 * @param mixed $value    Candidate value.
	 * @param bool  $fallback Fallback value.
	 * @return bool
	 */
	private static function boolean( $value, $fallback ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return (bool) $fallback;
		}
		if ( 1 === $value || '1' === $value || 'true' === strtolower( (string) $value ) ) {
			return true;
		}
		if ( 0 === $value || '0' === $value || 'false' === strtolower( (string) $value ) ) {
			return false;
		}

		return (bool) $fallback;
	}

	/**
	 * Normalize a diagnostic code without imposing a provider dialect.
	 *
	 * @param mixed $value Candidate code.
	 * @return string
	 */
	private static function code( $value ) {
		$code = strtolower( trim( (string) $value ) );
		$code = preg_replace( '/[^a-z0-9._:-]+/', '_', $code );
		$code = trim( (string) $code, '._:-' );
		$code = substr( $code, 0, self::MAX_CODE_LENGTH );

		return '' !== $code ? $code : 'digitalogic_pricing_diagnostic';
	}

	/**
	 * Collect safe extra evidence from bounded detail containers.
	 *
	 * @param array $layers Candidate layers.
	 * @return array
	 */
	private static function collect_details( $layers ) {
		$collected = array();
		foreach ( array_reverse( $layers ) as $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}
			$candidates = isset( $layer['details'] ) && is_array( $layer['details'] )
				? $layer['details']
				: array();
			foreach ( $candidates as $key => $value ) {
				if ( self::contract_key( $key ) ) {
					continue;
				}
				$collected[ $key ] = $value;
			}
		}

		return self::safe_details( $collected );
	}

	/**
	 * Return whether a key is transport metadata rather than evidence.
	 *
	 * @param mixed $key Candidate key.
	 * @return bool
	 */
	private static function contract_key( $key ) {
		return in_array(
			(string) $key,
			array( 'code', 'severity', 'blocking', 'reason', 'message', 'message_fa', 'retryable', 'recovery_action', 'status', 'retry_after', 'diagnostic', 'details' ),
			true
		);
	}

	/**
	 * Recursively retain only bounded, non-sensitive diagnostic evidence.
	 *
	 * @param mixed $details Candidate details.
	 * @param int   $depth   Current recursion depth.
	 * @return array
	 */
	private static function safe_details( $details, $depth = 0 ) {
		if ( ! is_array( $details ) || $depth > self::MAX_DETAIL_DEPTH ) {
			return array();
		}

		$safe  = array();
		$count = 0;
		foreach ( $details as $key => $value ) {
			if ( $count >= self::MAX_DETAIL_ITEMS ) {
				break;
			}
			$is_list_key = is_int( $key );
			$safe_key    = $is_list_key ? $key : self::detail_key( $key );
			if ( ! $is_list_key && '' === $safe_key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = self::safe_details( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$value = self::safe_text( $value, self::MAX_DETAIL_STRING_LENGTH );
			} elseif ( ! is_null( $value ) && ! is_bool( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
				continue;
			}

			$safe[ $safe_key ] = $value;
			++$count;
		}

		return $safe;
	}

	/**
	 * Normalize and reject sensitive detail keys.
	 *
	 * @param mixed $value Candidate key.
	 * @return string
	 */
	private static function detail_key( $value ) {
		$key = strtolower( trim( (string) $value ) );
		$key = preg_replace( '/[^a-z0-9._:-]+/', '_', $key );
		$key = trim( (string) $key, '._:-' );
		$key = substr( $key, 0, self::MAX_DETAIL_KEY_LENGTH );
		if (
			'' === $key
			|| preg_match( '/(?:^|[._:-])(?:authorization|auth|cookie|credential|nonce|pass|password|private|secret|session|token|api_key)(?:$|[._:-])/', $key )
		) {
			return '';
		}

		return $key;
	}

	/**
	 * Convert one value to bounded, single-line plain text.
	 *
	 * @param mixed $value      Candidate text.
	 * @param int   $max_length Maximum text length.
	 * @return string
	 */
	private static function safe_text( $value, $max_length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = wp_strip_all_tags( (string) $value );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = preg_replace( '/\s+/u', ' ', trim( (string) $text ) );

		return function_exists( 'mb_substr' )
			? mb_substr( (string) $text, 0, $max_length )
			: substr( (string) $text, 0, $max_length );
	}
}
