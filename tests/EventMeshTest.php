<?php

use PHPUnit\Framework\TestCase;

final class EventMeshTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']         = array();
		$GLOBALS['digitalogic_test_capabilities']    = array();
		$GLOBALS['digitalogic_test_current_user_id'] = 0;
		$GLOBALS['digitalogic_test_current_user']    = (object) array(
			'ID'           => 0,
			'user_login'   => '',
			'display_name' => '',
			'roles'        => array(),
		);
		$GLOBALS['digitalogic_test_user_meta']       = array();
		$GLOBALS['wpdb']                             = new Digitalogic_Test_WPDB();
	}

	public function test_notification_requires_an_explicit_audience(): void {
		$result = Digitalogic_Event_Mesh::sanitize_notification(
			array(
				'title' => 'Approval required',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_notification_audience', $result->get_error_code() );
	}

	public function test_notification_schema_is_bounded_and_drops_executable_targets(): void {
		$actions = array();
		$fields  = array();
		for ( $index = 0; $index < 10; ++$index ) {
			$actions[] = array(
				'id'      => 'action-' . $index,
				'label'   => 'Action ' . $index,
				'style'   => 'primary',
				'command' => 'powershell.exe',
				'url'     => 'file:///unsafe',
			);
			$fields[]  = array(
				'id'       => 'field-' . $index,
				'label'    => 'Field ' . $index,
				'type'     => 'text',
				'required' => true,
			);
		}

		$result = Digitalogic_Event_Mesh::sanitize_notification(
			array(
				'title'      => 'Review',
				'message'    => 'Choose a bounded response.',
				'audience'   => array( 'devices' => array( 'workstation-12345678' ) ),
				'actions'    => $actions,
				'fields'     => $fields,
				'expires_at' => '2026-07-27T12:00:00Z',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 4, $result['actions'] );
		$this->assertCount( 4, $result['fields'] );
		$this->assertArrayNotHasKey( 'command', $result['actions'][0] );
		$this->assertArrayNotHasKey( 'url', $result['actions'][0] );
		$this->assertSame( array( 'workstation-12345678' ), $result['audience']['devices'] );
	}

	public function test_target_visibility_matches_broadcast_user_and_device(): void {
		$event = array(
			'name' => 'workstation.notification',
			'data' => array(
				'audience' => array(
					'broadcast' => false,
					'users'     => array( 8 ),
					'devices'   => array( 'workstation-12345678' ),
					'operators' => array(),
				),
			),
		);

		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $event, 8, '' ) );
		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $event, 9, 'workstation-12345678' ) );
		$this->assertFalse( Digitalogic_Event_Mesh::event_visible_to( $event, 9, '' ) );

		$event['data']['audience']['broadcast'] = true;
		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $event, 0, '' ) );
	}

	public function test_storefront_presentation_and_role_attribute_audience_are_bounded(): void {
		$result = Digitalogic_Event_Mesh::sanitize_notification(
			array(
				'title'       => 'Customer notice',
				'message'     => 'Updated delivery information.',
				'display'     => 'both',
				'duration_ms' => 90000,
				'dismissible' => false,
				'link'        => array(
					'href'  => '/shop/',
					'label' => 'Shop',
				),
				'audience'    => array(
					'roles'      => array( 'customer' ),
					'attributes' => array(
						'billing_country' => array( 'IR' ),
						'session_tokens'  => array( 'must-not-be-used' ),
					),
					'match'      => 'all',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'both', $result['display'] );
		$this->assertSame( 60000, $result['duration_ms'] );
		$this->assertFalse( $result['dismissible'] );
		$this->assertSame( array( 'href' => '/shop/', 'label' => 'Shop' ), $result['link'] );
		$this->assertSame( array( 'customer' ), $result['audience']['roles'] );
		$this->assertSame( array( 'billing_country' => array( 'IR' ) ), $result['audience']['attributes'] );
		$this->assertSame( 'all', $result['audience']['match'] );
	}

	public function test_role_and_attribute_targeting_is_server_side_and_supports_all_or_any(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 42;
		$GLOBALS['digitalogic_test_current_user']    = (object) array(
			'ID'           => 42,
			'user_login'   => 'customer-42',
			'display_name' => 'Customer',
			'roles'        => array( 'customer' ),
		);
		$GLOBALS['digitalogic_test_user_meta'][42]   = array(
			'billing_country'   => 'IR',
			'preferred_language' => 'fa_IR',
		);
		$event = array(
			'name' => 'workstation.notification',
			'data' => array(
				'audience' => array(
					'roles'           => array( 'customer' ),
					'attributes'      => array(
						'billing_country'   => array( 'IR' ),
						'preferred_language' => array( 'fa_IR' ),
					),
					'match'           => 'all',
					'attribute_match' => 'all',
				),
			),
		);

		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $event, 42 ) );
		$event['data']['audience']['attributes']['billing_country'] = array( 'AE' );
		$this->assertFalse( Digitalogic_Event_Mesh::event_visible_to( $event, 42 ) );
		$event['data']['audience']['match'] = 'any';
		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $event, 42 ) );
		$this->assertFalse( Digitalogic_Event_Mesh::event_visible_to( $event, 0 ) );
	}

	public function test_expired_notification_is_not_visible(): void {
		$event = array(
			'name' => 'workstation.notification',
			'data' => array(
				'expires_at' => '2000-01-01T00:00:00Z',
				'audience'   => array( 'broadcast' => true ),
			),
		);

		$this->assertFalse( Digitalogic_Event_Mesh::event_visible_to( $event, 8, 'workstation-12345678' ) );
	}

	public function test_audience_filter_applies_to_presence_events_too(): void {
		$event = array(
			'name' => 'operator.presence.changed',
			'data' => array(
				'audience' => array( 'users' => array( 8 ) ),
			),
		);

		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $event, 8, '' ) );
		$this->assertFalse( Digitalogic_Event_Mesh::event_visible_to( $event, 9, '' ) );
	}

	/** A safe source-scoped event survives provider representation differences. */
	public function test_pricing_source_authorization_is_independent_from_optional_representation(): void {
		$source = array( 'id' => 'patris-office', 'dataset' => 'kala.db' );
		$event  = array(
			'id'   => 101,
			'name' => 'pricing.state.changed',
			'data' => array(
				'source'             => $source + array( 'provider_label' => 'Office feed' ),
				'provider_extension' => array( 'page_size' => 75 ),
				'audience'           => array( 'services' => array( 'patris_pricing' ) ),
			),
		);

		$decision = Digitalogic_Event_Mesh::pricing_event_delivery_decision( $event, 'patris_pricing', $source );
		$this->assertTrue( $decision['visible'] );
		$this->assertTrue( $decision['authorized'] );
		$this->assertFalse( $decision['blocking'] );
		$this->assertSame( 'conditional_refresh', $decision['recovery']['action'] );
		$this->assertSame( 3, $decision['recovery']['max_attempts'] );
		$this->assertSame( 30, $decision['recovery']['timeout_seconds'] );
		$this->assertContains( 'metadata_warning', array_column( $decision['diagnostics'], 'code' ) );
		$this->assertContains( 'provider_capability_missing', array_column( $decision['diagnostics'], 'code' ) );
		$this->assertSame( $event['data']['provider_extension'], $decision['data']['provider_extension'] );
		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $event, 0, '', 'patris_pricing', $source ) );

		$other_source = array( 'id' => $source['id'], 'dataset' => 'other.db' );
		$unrelated    = Digitalogic_Event_Mesh::pricing_event_delivery_decision( $event, 'patris_pricing', $other_source );
		$this->assertFalse( $unrelated['visible'] );
		$this->assertFalse( $unrelated['blocking'] );
	}

	/** Canonical revision is independent from an optional negotiated digest. */
	public function test_terminal_event_uses_canonical_revision_and_optional_distinct_digest(): void {
		$source = array(
			'id'       => 'patris-office',
			'dataset'  => 'kala.db',
			'revision' => 'sha256:' . str_repeat( '1', 64 ),
		);
		$event  = array(
			'id'   => 102,
			'name' => 'pricing.snapshot.build.terminal',
			'data' => array(
				'schema'                 => 'provider.snapshot.ready',
				'schema_version'         => 42,
				'projection'             => 'canonical',
				'build_id'               => 'build_' . str_repeat( '2', 32 ),
				'request_id'             => 'request-canonical-0001',
				'status'                 => 'ready',
				'source'                 => $source,
				'state_revision'         => 'sha256:' . str_repeat( '3', 64 ),
				'etag'                   => '"sha256:' . str_repeat( '3', 64 ) . '"',
				'pricing_state_revision' => 'sha256:' . str_repeat( '4', 64 ),
				'pricing_policy_revision' => 'sha256:' . str_repeat( '7', 64 ),
				'catalog_revision'       => 'sha256:' . str_repeat( '5', 64 ),
				'snapshot_token'         => 'snapshot-canonical-0001',
				'revision'               => 'sha256:' . str_repeat( '6', 64 ),
				'row_count'              => 757,
				'snapshot_path'          => '/provider/snapshot/current',
				'revision_path'          => '/provider/revision/current',
				'audience'               => array( 'services' => array( 'patris_pricing' ) ),
			),
		);

		$without_digest = Digitalogic_Event_Mesh::pricing_event_delivery_decision( $event, 'patris_pricing', $source );
		$this->assertTrue( $without_digest['visible'] );
		$this->assertFalse( $without_digest['blocking'] );
		$this->assertArrayNotHasKey( 'snapshot_revision', $without_digest['data'] );
		$this->assertSame( $event['data']['revision'], $without_digest['data']['revision'] );
		$this->assertContains( 'provider_capability_missing', array_column( $without_digest['diagnostics'], 'code' ) );
		$this->assertSame( 'consume_event', $without_digest['recovery']['action'] );

		$event['data']['snapshot_revision'] = $event['data']['revision'];
		$event['data']['digest']            = $event['data']['revision'];
		$without_duplicates                 = Digitalogic_Event_Mesh::pricing_event_delivery_decision( $event, 'patris_pricing', $source );
		$this->assertTrue( $without_duplicates['visible'] );
		$this->assertArrayNotHasKey( 'snapshot_revision', $without_duplicates['data'] );
		$this->assertArrayNotHasKey( 'digest', $without_duplicates['data'] );
		$this->assertContains( 'metadata_warning', array_column( $without_duplicates['diagnostics'], 'code' ) );

		unset( $event['data']['snapshot_revision'] );
		$event['data']['digest'] = 'sha256:' . str_repeat( 'a', 64 );
		$with_distinct_digest    = Digitalogic_Event_Mesh::pricing_event_delivery_decision( $event, 'patris_pricing', $source );
		$this->assertTrue( $with_distinct_digest['visible'] );
		$this->assertSame( $event['data']['digest'], $with_distinct_digest['data']['digest'] );
		$this->assertNotContains( 'optional_digest_mismatch', array_column( $with_distinct_digest['diagnostics'], 'code' ) );
	}

	/** Unsafe identity or credential metadata remains a blocking secret-free result. */
	public function test_pricing_unsafe_or_ambiguous_identity_is_blocking_without_leaking_values(): void {
		$source = array( 'id' => 'patris-office', 'dataset' => 'kala.db' );
		$event  = array(
			'id'   => 103,
			'name' => 'pricing.state.changed',
			'data' => array(
				'source'   => array( 'id' => $source['id'] ),
				'audience' => array( 'services' => array( 'patris_pricing' ) ),
			),
		);

		$ambiguous = Digitalogic_Event_Mesh::pricing_event_delivery_decision( $event, 'patris_pricing', $source );
		$this->assertFalse( $ambiguous['visible'] );
		$this->assertTrue( $ambiguous['blocking'] );
		$this->assertSame( 'unsafe_source_identity', $ambiguous['diagnostics'][0]['code'] );
		$this->assertSame( 'ERROR', $ambiguous['diagnostics'][0]['severity'] );
		$this->assertTrue( $ambiguous['diagnostics'][0]['blocking'] );
		$this->assertSame( 'stop_and_reauthorize', $ambiguous['recovery']['action'] );
		$this->assertSame( array(), $ambiguous['data'] );

		$event['data']['source'] = $source + array( 'secret' => 'must-not-pass' );
		$sensitive               = Digitalogic_Event_Mesh::pricing_event_delivery_decision( $event, 'patris_pricing', $source );
		$this->assertTrue( $sensitive['blocking'] );
		$this->assertSame( 'unsafe_event_metadata', $sensitive['diagnostics'][0]['code'] );
		$this->assertStringNotContainsString( 'must-not-pass', wp_json_encode( $sensitive ) );
	}

	public function test_presence_requires_combined_fresh_evidence_before_marking_away(): void {
		$now         = strtotime( '2026-07-27T12:00:00Z' );
		$locked_only = Digitalogic_Event_Mesh::resolve_presence(
			array(
				'windows_session' => array(
					'state'       => 'locked',
					'observed_at' => '2026-07-27T11:59:30Z',
					'confidence'  => 'high',
				),
			),
			$now
		);
		$this->assertSame( 'unknown', $locked_only['state'] );

		$away = Digitalogic_Event_Mesh::resolve_presence(
			array(
				'windows_session' => array(
					'state'       => 'locked',
					'observed_at' => '2026-07-27T11:59:30Z',
					'confidence'  => 'high',
				),
				'routeros_dhcp'   => array(
					'state'       => 'unbound',
					'observed_at' => '2026-07-27T11:59:20Z',
					'confidence'  => 'medium',
				),
			),
			$now
		);
		$this->assertSame( 'away', $away['state'] );
		$this->assertSame( 'session_locked_and_device_away', $away['reason'] );
	}

	public function test_unlocked_session_is_high_confidence_presence(): void {
		$presence = Digitalogic_Event_Mesh::resolve_presence(
			array(
				'windows_session' => array(
					'state'       => 'unlocked',
					'observed_at' => '2026-07-27T11:59:45Z',
					'confidence'  => 'high',
				),
			),
			strtotime( '2026-07-27T12:00:00Z' )
		);

		$this->assertSame( 'present', $presence['state'] );
		$this->assertSame( 'high', $presence['confidence'] );
	}
}
