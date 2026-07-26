<?php

use PHPUnit\Framework\TestCase;

final class EventMeshTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']         = array();
		$GLOBALS['digitalogic_test_capabilities']    = array();
		$GLOBALS['digitalogic_test_current_user_id'] = 0;
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
