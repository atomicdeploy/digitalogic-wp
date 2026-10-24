<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncRestTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_options'] = array(
            Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION => 'go',
            Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION => 'receiver-secret',
        );
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $this->resetSingleton(Digitalogic_REST_API::class);
        $this->resetSingleton(Digitalogic_Product_Sync_Receiver::class);
    }

    public function test_registers_only_exact_patris_machine_routes(): void {
        Digitalogic_REST_API::instance()->register_routes();
        $routes = array();
        foreach ($GLOBALS['digitalogic_test_routes'] as $route) {
            $routes[$route['namespace'] . $route['route']] = $route['args'];
        }

        $this->assertArrayHasKey('digitalogic/patris/product-sync', $routes);
		// phpcs:disable -- One additive assertion follows this legacy test method's compact style.
		$this->assertArrayHasKey('digitalogic/patris/product-sync/receipt', $routes);
		// phpcs:enable
        $this->assertArrayHasKey('digitalogic/integration/catalog', $routes);
        $this->assertArrayHasKey('digitalogic/integration/pricing-assignments/batch', $routes);
        $this->assertArrayHasKey('digitalogic/integration/products/by-code/(?P<code>[^/]+)/pricing', $routes);
		$this->assertCount(5, array_filter(
            array_keys($routes),
            static fn($route) => str_starts_with($route, 'digitalogic/patris/')
                || str_starts_with($route, 'digitalogic/integration/')
        ));
    }

    public function test_product_sync_uses_header_secret_and_optional_identity_headers(): void {
        $api = Digitalogic_REST_API::instance();
        $payload = json_decode(
            file_get_contents(__DIR__ . '/fixtures/patris-product-sync-golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $request = new WP_REST_Request(
            array(),
            $payload,
            array(
                'X-Patris-Product-Sync-Secret' => 'receiver-secret',
                'X-Patris-Contract' => $payload['schema'],
                'X-Patris-Event-ID' => $payload['event_id'],
            ),
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );

        $this->assertTrue($api->check_patris_product_sync_permission($request));
        $response = $api->receive_patris_product_sync($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('accepted', $response->get_data()['data']['status']);
        $timings = $response->get_data()['data']['receiver_timing_ms'];
        foreach (array('handler_total', 'json_decode', 'validation', 'transaction_total', 'transaction_entry', 'transaction_work', 'projection', 'persistence', 'destination_drain', 'event_emit', 'receiver_total') as $key) {
            $this->assertIsNumeric($timings[$key]);
            $this->assertGreaterThanOrEqual(0, $timings[$key]);
        }
        $this->assertStringNotContainsString('receiver_timing_ms', json_encode(Digitalogic_Product_Sync_Receiver::instance()->get_state()));

        $bad = new WP_REST_Request(
            array(),
            $payload,
            array('X-Patris-Event-ID' => 'sha256:' . str_repeat('f', 64)),
            json_encode($payload)
        );
        $response = $api->receive_patris_product_sync($bad);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('digitalogic_product_sync_header_mismatch', $response->get_data()['code']);
    }

	// phpcs:disable -- Receipt contract tests follow this legacy test class' compact fixture style.
	public function test_product_sync_receipt_is_authenticated_exact_and_nonsecret(): void {
		$api = Digitalogic_REST_API::instance();
		$payload = json_decode(
			file_get_contents(__DIR__ . '/fixtures/patris-product-sync-golden.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$auth = array('X-Patris-Product-Sync-Secret' => 'receiver-secret');
		$receive = new WP_REST_Request(array(), $payload, $auth, json_encode($payload));
		$this->assertSame(200, $api->receive_patris_product_sync($receive)->get_status());

		$probe = array(
			'event_id' => $payload['event_id'],
			'generated_at' => $payload['generated_at'],
			'source' => $payload['source'],
		);
		$request = new WP_REST_Request(array(), $probe, $auth, json_encode($probe));
		$this->assertTrue($api->check_patris_product_sync_permission($request));
		$response = $api->get_patris_product_sync_receipt($request);
		$this->assertSame(200, $response->get_status());
		$data = $response->get_data()['data'];
		$this->assertSame('digitalogic.product-sync-receipt.v2', $data['schema']);
		$this->assertSame('applied', $data['status']);
		$this->assertSame($payload['event_id'], $data['event_id']);
		$this->assertSame($payload['generated_at'], $data['generated_at']);
		$this->assertSame($payload['source'], $data['source']);
		$this->assertSame(0, $data['pending_products']);
		$this->assertSame(0, $data['deferred_products']);
		$this->assertArrayHasKey('observed_at', $data);
		$this->assertStringNotContainsString('receiver-secret', json_encode($response->get_data()));
		$this->assertArrayNotHasKey('products', $data);

		$mismatch = $probe;
		$mismatch['source']['revision'] = 'sha256:' . str_repeat('f', 64);
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $mismatch, $auth, json_encode($mismatch))
		);
		$this->assertSame(409, $response->get_status());
		$this->assertSame('digitalogic_product_sync_receipt_event_identity_mismatch', $response->get_data()['code']);

		$unknown = $probe;
		$unknown['event_id'] = 'sha256:' . str_repeat('a', 64);
		$unknown['generated_at'] = '2026-07-20T00:00:01Z';
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $unknown, $auth, json_encode($unknown))
		);
		$this->assertSame(200, $response->get_status());
		$this->assertSame('not_found', $response->get_data()['data']['status']);
		$this->assertSame($unknown['event_id'], $response->get_data()['data']['event_id']);
		$this->assertSame($unknown['generated_at'], $response->get_data()['data']['generated_at']);

		$invalid = $probe;
		$invalid['event_id'] = 'not-a-hash';
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $invalid, $auth, json_encode($invalid))
		);
		$this->assertSame(400, $response->get_status());
		$this->assertSame('digitalogic_product_sync_receipt_identity_invalid', $response->get_data()['code']);

		$malformed = $probe;
		$malformed['generated_at'] = 'not-rfc3339';
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $malformed, $auth, json_encode($malformed))
		);
		$this->assertSame(400, $response->get_status());
		$this->assertSame('digitalogic_product_sync_receipt_generated_at_invalid', $response->get_data()['code']);
		$this->assertSame(
			$payload['generated_at'],
			Digitalogic_Product_Sync_Receiver::instance()->get_source_state('patris-export', 'ALLANBAR')['last_accepted_generated_at']
		);
	}

	public function test_product_sync_receipt_distinguishes_pending_and_superseded_events(): void {
		$source_id = 'patris-export';
		$dataset = 'ALLANBAR';
		$revision = 'sha256:' . str_repeat('1', 64);
		$event_id = 'sha256:' . str_repeat('2', 64);
		$later_event_id = 'sha256:' . str_repeat('3', 64);
		$generated_at = '2026-07-20T00:00:00Z';
		$later_generated_at = '2026-07-20T00:01:00Z';
		$key = hash('sha256', $source_id . "\n" . $dataset);
		$GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION] = array(
			'sources' => array(
				$key => array(
					'source' => array('id' => $source_id, 'dataset' => $dataset, 'revision' => $revision),
					'generated_at' => $generated_at,
					'last_accepted_generated_at' => $generated_at,
					'last_event_id' => $event_id,
					'recent_events' => array(
						$event_id => array('source_revision' => $revision, 'generated_at' => $generated_at),
					),
					'pending_products' => array(
						'116038' => array('queued_event_id' => $event_id),
					),
					'deferred_products' => array(
						'116039' => array('queued_event_id' => $event_id),
					),
				),
			),
		);
		$probe = array(
			'event_id' => $event_id,
			'generated_at' => $generated_at,
			'source' => array('id' => $source_id, 'dataset' => $dataset, 'revision' => $revision),
		);
		$api = Digitalogic_REST_API::instance();
		$auth = array('X-Patris-Product-Sync-Secret' => 'receiver-secret');
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $probe, $auth, json_encode($probe))
		);
		$this->assertSame('pending', $response->get_data()['data']['status']);
		$this->assertSame(1, $response->get_data()['data']['pending_products']);
		$this->assertSame(1, $response->get_data()['data']['deferred_products']);

		$state = $GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION];
		$state['sources'][$key]['pending_products'] = array();
		$GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION] = $state;
		unset($GLOBALS['digitalogic_test_option_cache'][Digitalogic_Product_Sync_Receiver::STATE_OPTION]);
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $probe, $auth, json_encode($probe))
		);
		$this->assertSame('pending', $response->get_data()['data']['status']);
		$this->assertSame(0, $response->get_data()['data']['pending_products']);
		$this->assertSame(1, $response->get_data()['data']['deferred_products']);

		$state = $GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION];
		$state['sources'][$key]['last_event_id'] = $later_event_id;
		$state['sources'][$key]['generated_at'] = $later_generated_at;
		$state['sources'][$key]['last_accepted_generated_at'] = $later_generated_at;
		$state['sources'][$key]['recent_events'][$later_event_id] = array(
			'source_revision' => 'sha256:' . str_repeat('4', 64),
			'generated_at' => $later_generated_at,
		);
		$GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION] = $state;
		unset($GLOBALS['digitalogic_test_option_cache'][Digitalogic_Product_Sync_Receiver::STATE_OPTION]);
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $probe, $auth, json_encode($probe))
		);
		$this->assertSame(200, $response->get_status());
		$this->assertSame('superseded', $response->get_data()['data']['status']);
		$this->assertSame(0, $response->get_data()['data']['pending_products']);
		$this->assertSame(0, $response->get_data()['data']['deferred_products']);

		$state = $GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION];
		$state['sources'][$key]['recent_events'] = array();
		for ($i = 0; $i < 128; ++$i) {
			$state['sources'][$key]['recent_events']['sha256:' . hash('sha256', (string) $i)] = array(
				'source_revision' => 'sha256:' . hash('sha256', 'revision-' . $i),
				'generated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-07-19T00:00:00Z') + $i),
			);
		}
		array_pop($state['sources'][$key]['recent_events']);
		$state['sources'][$key]['recent_events'][$later_event_id] = array(
			'source_revision' => 'sha256:' . str_repeat('4', 64),
			'generated_at' => $later_generated_at,
		);
		unset(
			$state['sources'][$key]['generated_at'],
			$state['sources'][$key]['last_accepted_generated_at']
		);
		$GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION] = $state;
		unset($GLOBALS['digitalogic_test_option_cache'][Digitalogic_Product_Sync_Receiver::STATE_OPTION]);
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $probe, $auth, json_encode($probe))
		);
		$this->assertSame(503, $response->get_status());
		$this->assertSame('digitalogic_product_sync_receipt_history_inconclusive', $response->get_data()['code']);

		$newer = $probe;
		$newer['event_id'] = 'sha256:' . str_repeat('5', 64);
		$newer['generated_at'] = '2026-07-20T00:02:00Z';
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $newer, $auth, json_encode($newer))
		);
		$this->assertSame(200, $response->get_status());
		$this->assertSame('not_found', $response->get_data()['data']['status']);
		$this->assertSame($newer['generated_at'], $response->get_data()['data']['generated_at']);

		$future = $newer;
		$future['event_id'] = 'sha256:' . str_repeat('6', 64);
		$future['generated_at'] = '2999-01-01T00:00:00Z';
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $future, $auth, json_encode($future))
		);
		$this->assertSame(503, $response->get_status());
		$this->assertSame('digitalogic_product_sync_receipt_chronology_inconclusive', $response->get_data()['code']);
	}
	// phpcs:enable

	/** A complete later snapshot retires an unknown attempt without claiming it applied. */
	public function test_absent_receipt_requires_exact_complete_snapshot_supersession_proof(): void {
		$event_id = 'sha256:' . str_repeat( 'a', 64 );
		$last_id  = 'sha256:' . str_repeat( 'b', 64 );
		$revision = 'sha256:' . str_repeat( 'c', 64 );
		$time     = '2026-10-12T08:05:48.7105679Z';
		$source   = array(
			'input_source'       => array(
				'id'       => 'patris-office',
				'dataset'  => 'kala.db',
				'revision' => $revision,
			),
			'input_products'     => array(),
			'pending_products'   => array(),
			'deferred_products'  => array(),
			'generated_at'       => $time,
			'generated_at_order' => array( 1791792348, 710567900 ),
			'last_event_id'      => $last_id,
			'last_event_type'    => 'snapshot',
			'recent_events'      => array(
				$last_id => array(
					'generated_at'    => $time,
					'source_revision' => $revision,
					'event_type'      => 'snapshot',
				),
			),
		);
		$key      = hash( 'sha256', "patris-office\nkala.db" );
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$probe    = function ( $stored, $generated_at = '2026-10-11T14:49:44.5635371Z' ) use ( $key, $receiver, $event_id ) {
			$state = array( 'sources' => array( $key => $stored ) );
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = $state;
			unset( $GLOBALS['digitalogic_test_option_cache'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] );
			$result = $receiver->get_event_delivery_receipt( $event_id, 'patris-office', 'kala.db', 'sha256:' . str_repeat( 'd', 64 ), $generated_at );
			$this->assertSame( $state, $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] );
			return $result;
		};
		$result   = $probe( $source );
		$this->assertSame( 'superseded', $result['status'] );
		$this->assertSame( $event_id, $result['event_id'] );
		$this->assertSame( 0, $result['pending_products'] );
		$this->assertArrayNotHasKey( 'products', $result );
		foreach ( array( 'last_event_id', 'input_products', 'input_source', 'generated_at_order', 'recent_events' ) as $field ) {
			$broken = $source;
			unset( $broken[ $field ] );
			$this->assertInstanceOf( WP_Error::class, $probe( $broken ), $field );
		}
		$mutations = array(
			array( 'last_event_type' => 'update' ),
			array( 'quarantined_codes' => array( '109055' ) ),
			array( 'pending_products' => array( '109055' => array( 'queued_event_id' => $event_id ) ) ),
			array( 'deferred_products' => array( '109055' => array( 'queued_event_id' => $last_id ) ) ),
			array( 'generated_at_order' => array( 1791792348, 710567901 ) ),
			array(
				'input_source' => array(
					'id'       => 'other',
					'dataset'  => 'kala.db',
					'revision' => $revision,
				),
			),
			array(
				'input_source' => array(
					'id'       => 'patris-office',
					'dataset'  => 'other',
					'revision' => $revision,
				),
			),
			array(
				'input_source' => array(
					'id'       => 'patris-office',
					'dataset'  => 'kala.db',
					'revision' => $event_id,
				),
			),
		);
		foreach ( $mutations as $mutation ) {
			$this->assertInstanceOf( WP_Error::class, $probe( array_replace( $source, $mutation ) ) );
		}
		$this->assertInstanceOf( WP_Error::class, $probe( $source, $time ) );
		$this->assertSame( 'not_found', $probe( $source, '2026-10-12T08:05:48.710567901Z' )['status'] );
		$this->assertInstanceOf( WP_Error::class, $probe( $source, '2999-01-01T00:00:00Z' ) );
	}

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
