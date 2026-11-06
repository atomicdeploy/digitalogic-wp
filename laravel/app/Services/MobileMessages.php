<?php
/**
 * Native views and operations over canonical Fluent Support records.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

use FluentSupport\App\Models\Customer;
use FluentSupport\App\Models\Person;
use FluentSupport\App\Models\Ticket;
use FluentSupport\App\Models\Conversation;
use FluentSupport\App\Models\Product;
use FluentSupport\App\Services\Helper;
use FluentSupport\App\Services\CustomerPortalService;
use FluentSupport\App\Services\Tickets\ResponseService;
use FluentSupport\App\Services\Tickets\TicketService;

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Exact installed Fluent Support extension hooks must retain provider names.
// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and Laravel contracts.

/** Strict WordPress-owner adapter; email never transfers an existing Fluent identity. */
final class MobileMessages {
	/** Report whether the canonical provider and operation metadata are available. */
	public static function enabled(): bool {
		return class_exists( CustomerPortalService::class ) && class_exists( ResponseService::class )
			&& '1' === get_option( 'digitalogic_mobile_messages_schema' ) && (bool) get_option( 'digitalogic_mobile_native_messages', false );
	}

	/**
	 * Render plain paragraphs without exposing internal HTML or attachment URLs.
	 *
	 * @param string $html Existing provider content.
	 * @return string Plain customer-visible text.
	 */
	public static function text( string $html ): string {
		$html = preg_replace( '~<(?:br\s*/?|/p|/div|/li)>~i', "\n", $html );
		return trim( preg_replace( '/\n{3,}/', "\n\n", html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Preserve the canonical site's timezone in native timestamps.
	 *
	 * @param string $value Provider local date.
	 * @return string ISO8601 date with explicit offset.
	 */
	private static function date( string $value ): string {
		return ( new \DateTimeImmutable( $value, wp_timezone() ) )->format( DATE_ATOM );
	}

	/**
	 * Resolve exactly one canonical customer by verified WordPress ID.
	 *
	 * @param int $user_id Verified native owner.
	 * @return object|\WP_Error|null Customer, access failure, or no existing identity.
	 */
	public static function customer( int $user_id ) {
		$rows = Customer::where( 'user_id', $user_id )->limit( 2 )->get();
		if ( $rows->count() > 1 ) {
			return new \WP_Error( 'customer_identity_review_required' );
		}
		$customer = $rows->first();
		if ( ! $customer ) {
			return null;
		}
		$access = apply_filters( 'fluent_support/can_customer_access_portal', true, $customer );
		return 'active' !== $customer->status || ! $access || is_wp_error( $access ) ? new \WP_Error( 'messages_forbidden' ) : $customer;
	}

	/**
	 * Create the provider customer only during an explicit ticket operation.
	 *
	 * @param int $user_id Current WordPress owner.
	 * @return object|\WP_Error Canonical customer or safe rejection.
	 */
	public static function createCustomer( int $user_id ) {
		global $wpdb;
		$lock = 'dgl-fs-customer-' . $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize provider customer creation; no cached identity lookup is safe here.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) {
			return new \WP_Error( 'messages_busy' );
		}
		try {
			$customer = self::customer( $user_id );
			if ( $customer ) {
				return $customer;
			}
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				return new \WP_Error( 'messages_forbidden' );
			}
			// Existing email-only records require explicit identity review; never claim them.
			if ( '' !== $user->user_email && Person::where( 'email', $user->user_email )->exists() ) {
				return new \WP_Error( 'customer_identity_review_required' );
			}
			$customer = Customer::create(
				array(
					'user_id'    => $user_id,
					'email'      => '' === $user->user_email ? null : $user->user_email,
					'first_name' => $user->first_name,
					'last_name'  => $user->last_name,
					'status'     => 'active',
				)
			);
			do_action( 'fluent_support/customer_created', $customer );
			return self::customer( $user_id );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the exact uncached provider identity lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Resolve a ticket by exact owner, including tickets whose provider privacy is public.
	 *
	 * @param object $customer Verified provider customer.
	 * @param int    $id Canonical ticket ID.
	 * @param string $action Intended provider action.
	 * @return object|\WP_Error Owned ticket or denial.
	 */
	public static function ticket( $customer, int $id, string $action = '' ) {
		if ( ! $customer || is_wp_error( $customer ) ) {
			return new \WP_Error( 'ticket_not_found' );
		}
		$ticket = Ticket::where( 'customer_id', $customer->id )->where( 'id', $id )->first();
		if ( ! $ticket ) {
			return new \WP_Error( 'ticket_not_found' );
		}
		try {
			( new CustomerPortalService() )->checkCustomerTicketAccess( $customer, $ticket, $action );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'messages_forbidden' );
		}
		return $ticket;
	}

	/**
	 * Translate provider form requirements without inventing custom fields.
	 *
	 * @return array Schema understood by the native composer.
	 */
	public static function schema(): array {
		$rules    = apply_filters(
			'fluent_support/custom_field_required_before_ticket_create',
			array(
				'required_fields' => array(
					'title'   => 'required',
					'content' => 'required',
				),
				'error_messages'  => array(),
			)
		);
		$settings = Helper::getOption( '_ticket_form_settings', array() );
		$disabled = apply_filters( 'fluent_support/disabled_ticket_fields', array() );
		$products = Product::select( array( 'id', 'title' ) )->orderBy( 'id' )->limit( 200 )->get();
		$fields   = array(
			array(
				'key'        => 'subject',
				'label'      => 'موضوع',
				'type'       => 'text',
				'required'   => true,
				'max_length' => 180,
			),
			array(
				'key'        => 'body',
				'label'      => 'پیام',
				'type'       => 'textarea',
				'required'   => true,
				'max_length' => 10000,
			),
		);
		if ( ! in_array( 'product_services', $disabled, true ) && $products->count() > 0 ) {
			$fields[] = array(
				'key'      => 'product_id',
				'label'    => 'بخش پشتیبانی',
				'type'     => 'select',
				'required' => 'yes' === ( $settings['product_required_field'] ?? '' ),
				'options'  => array_map(
					static fn( $item ) => array(
						'value' => (string) $item['id'],
						'label' => $item['title'],
					),
					$products->toArray()
				),
			);
		}
		if ( ! in_array( 'priority', $disabled, true ) ) {
			$options = array();
			foreach ( Helper::customerTicketPriorities() as $value => $label ) {
				$options[] = array(
					'value' => $value,
					'label' => $label,
				);
			}
			$fields[] = array(
				'key'      => 'priority',
				'label'    => 'اولویت',
				'type'     => 'select',
				'required' => false,
				'options'  => $options,
			);
		}
		$unsupported = array_diff( array_keys( $rules['required_fields'] ?? array() ), array( 'title', 'content', 'product_id', 'client_priority' ) );
		return array(
			'can_create' => ! $unsupported,
			'fields'     => $fields,
			'statuses'   => array(
				array(
					'value' => 'new',
					'label' => 'جدید',
				),
				array(
					'value' => 'active',
					'label' => 'در حال پیگیری',
				),
				array(
					'value' => 'on-hold',
					'label' => 'در انتظار',
				),
				array(
					'value' => 'closed',
					'label' => 'بسته‌شده',
				),
			),
			'upload'     => array(
				'enabled'    => false,
				'max_bytes'  => 0,
				'extensions' => array(),
			),
			'blocker'    => $unsupported ? 'unsupported_required_fields' : null,
		);
	}

	/**
	 * Project only customer-visible canonical ticket fields.
	 *
	 * @param object $ticket Owned provider ticket.
	 * @return array Native ticket representation.
	 */
	public static function ticketData( $ticket ): array {
		return array(
			'id'         => (int) $ticket->id,
			'subject'    => self::text( (string) $ticket->title ),
			'status'     => (string) $ticket->status,
			'body'       => self::text( (string) $ticket->content ),
			'created_at' => self::date( (string) $ticket->created_at ),
			'updated_at' => self::date( (string) $ticket->updated_at ),
		);
	}

	/**
	 * Project a visible response, excluding attachment paths and internal notes.
	 *
	 * @param object $message Canonical visible response.
	 * @param int    $customer_id Ticket owner provider ID.
	 * @return array Native response representation.
	 */
	public static function messageData( $message, int $customer_id ): array {
		return array(
			'id'          => (int) $message->id,
			'body'        => self::text( (string) $message->content ),
			'created_at'  => self::date( (string) $message->created_at ),
			'sender_role' => (int) $message->person_id === $customer_id ? 'customer' : 'support',
			'attachments' => array(),
		);
	}

	/**
	 * List one bounded page, preserving canonical provider access filters.
	 *
	 * @param object|null $customer Existing verified provider identity.
	 * @param int         $page Positive bounded page.
	 * @param string      $search Plain bounded search.
	 * @return array Native tickets and pagination.
	 */
	public static function listing( $customer, int $page, string $search ): array {
		global $wpdb;
		$items = array();
		$more  = false;
		if ( $customer ) {
			$query = Ticket::where( 'customer_id', $customer->id );
			if ( '' !== $search ) {
				$query->where( 'title', 'LIKE', '%' . $wpdb->esc_like( $search ) . '%' );
			}
			$rows = $query->orderBy( 'updated_at', 'desc' )->orderBy( 'id', 'desc' )->offset( ( $page - 1 ) * 20 )->limit( 21 )->get();
			$more = $rows->count() > 20;
			foreach ( $rows->take( 20 ) as $row ) {
				if ( is_wp_error( self::ticket( $customer, (int) $row->id ) ) ) {
					continue;
				}
				$data = self::ticketData( $row );
				$last = Conversation::where( 'ticket_id', $row->id )->where( 'conversation_type', 'response' )->orderBy( 'id', 'desc' )->first();
				unset( $data['body'] );
				$data['last_message'] = mb_substr( self::text( (string) ( $last ? $last->content : $row->content ) ), 0, 180 );
				$data['unread_count'] = null;
				$items[]              = $data;
			}
		}
		return array(
			'items'      => $items,
			'pagination' => array(
				'page'      => $page,
				'has_more'  => $more,
				'next_page' => $more ? $page + 1 : null,
			),
		);
	}

	/**
	 * Read older visible messages by stable canonical ID, not offset.
	 *
	 * @param object $ticket Owned provider ticket.
	 * @param int    $before_id Exclusive message cursor, or zero for newest.
	 * @return array Native thread page, oldest first.
	 */
	public static function thread( $ticket, int $before_id ): array {
		$query = Conversation::where( 'ticket_id', $ticket->id )->where( 'conversation_type', 'response' );
		if ( $before_id > 0 ) {
			$query->where( 'id', '<', $before_id );
		}
		$rows     = $query->orderBy( 'id', 'desc' )->limit( 31 )->get();
		$more     = $rows->count() > 30;
		$messages = array();
		foreach ( $rows->take( 30 ) as $row ) {
			$messages[] = self::messageData( $row, (int) $ticket->customer_id );
		}
		$messages = array_reverse( $messages );
		return array(
			'ticket'     => self::ticketData( $ticket ),
			'messages'   => $messages,
			'pagination' => array(
				'has_more'       => $more,
				'next_before_id' => $more && $messages ? $messages[0]['id'] : null,
			),
		);
	}

	/**
	 * Validate the actual provider requirements before an operation enters its write phase.
	 *
	 * @param string $kind Native action.
	 * @param array  $input Immutable client payload.
	 * @return array|\WP_Error Provider data or definitive validation failure.
	 */
	public static function prepare( string $kind, array $input ) {
		if ( array_diff( array_keys( $input ), array( 'operation_id', 'subject', 'body', 'priority', 'product_id', 'attachments' ) ) || ! empty( $input['attachments'] ) ) {
			return new \WP_Error( 'unsupported_message_field' );
		}
		if ( in_array( $kind, array( 'close', 'reopen' ), true ) ) {
			return array();
		}
		$body = $input['body'] ?? null;
		if ( ! is_string( $body ) || '' === trim( $body ) || mb_strlen( $body ) > 10000 ) {
			return new \WP_Error( 'invalid_message_body' );
		}
		$data = array(
			'content'           => nl2br( esc_html( trim( $body ) ) ),
			'conversation_type' => 'response',
		);
		if ( 'reply' === $kind ) {
			return $data;
		}
		$subject = $input['subject'] ?? null;
		if ( ! is_string( $subject ) || '' === trim( $subject ) || mb_strlen( $subject ) > 180 ) {
			return new \WP_Error( 'invalid_message_subject' );
		}
		$data['title'] = sanitize_text_field( $subject );
		if ( '' === trim( $data['title'] ) ) {
			return new \WP_Error( 'invalid_message_subject' );
		}
		$data['privacy'] = 'private';
		$data['status']  = 'new';
		$schema          = self::schema();
		if ( ! $schema['can_create'] ) {
			return new \WP_Error( 'unsupported_required_fields' );
		}
		foreach ( array( 'product_id', 'priority' ) as $key ) {
			if ( isset( $input[ $key ] ) && ( ! is_scalar( $input[ $key ] ) || ( '' !== (string) $input[ $key ] && ! in_array( $key, array_column( $schema['fields'], 'key' ), true ) ) ) ) {
				return new \WP_Error( 'invalid_message_' . $key );
			}
		}
		foreach ( $schema['fields'] as $field ) {
			$key = $field['key'];
			if ( ! in_array( $key, array( 'product_id', 'priority' ), true ) ) {
				continue;
			}
			$value = (string) ( $input[ $key ] ?? '' );
			if ( ( $field['required'] || '' !== $value ) && ! in_array( $value, array_column( $field['options'], 'value' ), true ) ) {
				return new \WP_Error( 'invalid_message_' . $key );
			}
			if ( '' !== $value ) {
				$data[ 'priority' === $key ? 'client_priority' : $key ] = 'product_id' === $key ? (int) $value : $value;
			}
		}
		$base_rules = apply_filters(
			'fluent_support/custom_field_required_before_ticket_create',
			array(
				'required_fields' => array(
					'title'   => 'required',
					'content' => 'required',
				),
				'error_messages'  => array(),
			)
		);
		$rules      = apply_filters(
			'fluent_support/custom_field_required_by_conditions_before_ticket_create',
			array(
				'required_fields' => $base_rules['required_fields'] ?? array(),
				'error_messages'  => array(),
				'custom_data'     => array(),
				'default_data'    => array(
					'ticket_title'           => $data['title'],
					'ticket_content'         => $data['content'],
					'ticket_product_id'      => $data['product_id'] ?? null,
					'ticket_client_priority' => $data['client_priority'] ?? null,
				),
			)
		);
		if ( array_diff( array_keys( $rules['required_fields'] ?? array() ), array( 'title', 'content', 'product_id', 'client_priority' ) ) ) {
			return new \WP_Error( 'unsupported_required_fields' );
		}
		if ( \Illuminate\Support\Facades\Validator::make( $data, $rules['required_fields'] ?? array() )->fails() ) {
			return new \WP_Error( 'invalid_message_fields' );
		}
		return $data;
	}

	/**
	 * Invoke the existing provider engine once after durable reservation.
	 *
	 * @param string      $kind Native action.
	 * @param object      $customer Verified provider identity.
	 * @param object|null $ticket Owned target, absent when creating.
	 * @param array       $data Validated provider payload.
	 * @param string      $marker Stable canonical message ID.
	 * @return array|\WP_Error Canonical result identifiers.
	 */
	public static function perform( string $kind, $customer, $ticket, array $data, string $marker ) {
		$data['message_id'] = $marker;
		if ( 'create' === $kind ) {
			$allowed = apply_filters( 'fluent_support/can_customer_create_ticket', true, $customer, $data );
			if ( ! $allowed || is_wp_error( $allowed ) ) {
				return new \WP_Error( 'messages_forbidden' );
			}
			$ticket = ( new CustomerPortalService() )->createTicket( $customer, wp_slash( $data ), 0 );
			return array(
				'ticket_id'  => (int) $ticket->id,
				'message_id' => 0,
			);
		}
		if ( 'reply' === $kind ) {
			$allowed = apply_filters( 'fluent_support/can_customer_create_response', true, $customer, $ticket, $data );
			if ( ! $allowed || is_wp_error( $allowed ) ) {
				return new \WP_Error( 'messages_forbidden' );
			}
			$result = ( new ResponseService() )->createResponse( wp_slash( $data ), $customer, $ticket );
			return array(
				'ticket_id'  => (int) $ticket->id,
				'message_id' => (int) $result['response']->id,
			);
		}
		if ( 'close' === $kind ) {
			( new TicketService() )->close( $ticket, $customer );
		} else {
			( new TicketService() )->reopen( $ticket, $customer );
		}
		return array(
			'ticket_id'  => (int) $ticket->id,
			'message_id' => 0,
		);
	}

	/**
	 * Find only an exact canonical provider marker after an interrupted write.
	 *
	 * @param array  $operation Durable operation metadata.
	 * @param object $customer Verified provider identity.
	 * @return array|null Exact result identifiers, or unresolved state.
	 */
	public static function recover( array $operation, $customer ): ?array {
		$marker = MobileMessageStore::providerId( $operation['operation_key'] );
		if ( 'create' === $operation['kind'] ) {
			$ticket = Ticket::where( 'customer_id', $customer->id )->where( 'message_id', $marker )->first();
			return $ticket ? array(
				'ticket_id'  => (int) $ticket->id,
				'message_id' => 0,
			) : null;
		}
		if ( 'reply' === $operation['kind'] ) {
			$ticket = self::ticket( $customer, (int) $operation['ticket_id'] );
			if ( is_wp_error( $ticket ) ) {
				return null;
			}
			$message = Conversation::where( 'ticket_id', $ticket->id )->where( 'person_id', $customer->id )->where( 'conversation_type', 'response' )->where( 'message_id', $marker )->first();
			return $message ? array(
				'ticket_id'  => (int) $ticket->id,
				'message_id' => (int) $message->id,
			) : null;
		}
		// Status mutations without an acknowledged result remain unresolved; never replay provider hooks.
		return null;
	}
}
