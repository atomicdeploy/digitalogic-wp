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
			'source' => $payload['source'],
		);
		$request = new WP_REST_Request(array(), $probe, $auth, json_encode($probe));
		$this->assertTrue($api->check_patris_product_sync_permission($request));
		$response = $api->get_patris_product_sync_receipt($request);
		$this->assertSame(200, $response->get_status());
		$data = $response->get_data()['data'];
		$this->assertSame('digitalogic.product-sync-receipt.v1', $data['schema']);
		$this->assertSame('applied', $data['status']);
		$this->assertSame($payload['event_id'], $data['event_id']);
		$this->assertSame($payload['source'], $data['source']);
		$this->assertSame(0, $data['pending_products']);
		$this->assertSame(0, $data['deferred_products']);
		$this->assertArrayHasKey('observed_at', $data);
		$this->assertStringNotContainsString('receiver-secret', json_encode($response->get_data()));
		$this->assertArrayNotHasKey('products', $data);

		$unknown = $probe;
		$unknown['event_id'] = 'sha256:' . str_repeat('a', 64);
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $unknown, $auth, json_encode($unknown))
		);
		$this->assertSame(200, $response->get_status());
		$this->assertSame('not_found', $response->get_data()['data']['status']);
		$this->assertSame($unknown['event_id'], $response->get_data()['data']['event_id']);

		$invalid = $probe;
		$invalid['event_id'] = 'not-a-hash';
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $invalid, $auth, json_encode($invalid))
		);
		$this->assertSame(400, $response->get_status());
		$this->assertSame('digitalogic_product_sync_receipt_identity_invalid', $response->get_data()['code']);
	}

	public function test_product_sync_receipt_distinguishes_pending_and_superseded_events(): void {
		$source_id = 'patris-export';
		$dataset = 'ALLANBAR';
		$revision = 'sha256:' . str_repeat('1', 64);
		$event_id = 'sha256:' . str_repeat('2', 64);
		$later_event_id = 'sha256:' . str_repeat('3', 64);
		$key = hash('sha256', $source_id . "\n" . $dataset);
		$GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION] = array(
			'sources' => array(
				$key => array(
					'source' => array('id' => $source_id, 'dataset' => $dataset, 'revision' => $revision),
					'last_event_id' => $event_id,
					'recent_events' => array(
						$event_id => array('source_revision' => $revision),
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
		$state['sources'][$key]['last_event_id'] = $later_event_id;
		$state['sources'][$key]['recent_events'][$later_event_id] = array('source_revision' => 'sha256:' . str_repeat('4', 64));
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
			);
		}
		$GLOBALS['digitalogic_test_options'][Digitalogic_Product_Sync_Receiver::STATE_OPTION] = $state;
		unset($GLOBALS['digitalogic_test_option_cache'][Digitalogic_Product_Sync_Receiver::STATE_OPTION]);
		$response = $api->get_patris_product_sync_receipt(
			new WP_REST_Request(array(), $probe, $auth, json_encode($probe))
		);
		$this->assertSame(503, $response->get_status());
		$this->assertSame('digitalogic_product_sync_receipt_history_inconclusive', $response->get_data()['code']);
	}
	// phpcs:enable

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
