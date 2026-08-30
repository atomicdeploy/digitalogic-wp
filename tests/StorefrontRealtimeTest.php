<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

final class StorefrontRealtimeTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array(
            'options_dollar_price' => 187891,
            'options_yuan_price' => 29500,
            'options_update_date' => '260830',
        );
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_locale'] = 'en_US';
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_current_user_id'] = 0;
        $GLOBALS['digitalogic_test_current_user'] = (object) array(
            'ID' => 0,
            'user_login' => '',
            'display_name' => '',
            'roles' => array(),
        );
        $GLOBALS['digitalogic_test_user_meta'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
    }

    public function test_sse_poll_forces_fresh_durable_event_reads(): void {
        $fresh = array(
            array(
                'id' => 222,
                'name' => 'currency.updated',
                'data' => array(),
            ),
        );
        $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] = $fresh;
        $GLOBALS['digitalogic_test_option_cache']['digitalogic_panel_events'] = array();

        $this->assertSame($fresh, Digitalogic_Panel::get_events_since(0, true));
        $this->assertContains(
            array('digitalogic_panel_events', 'options'),
            $GLOBALS['digitalogic_test_cache_deletes']
        );

        $source = file_get_contents((new ReflectionClass(Digitalogic_Storefront_Realtime::class))->getFileName());
        $this->assertMatchesRegularExpression(
            '/get_events_since\(\s*\$cursor,\s*true\s*\)/',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/get_latest_event_id\(\s*true\s*\)/',
            $source
        );
    }

    public function test_signed_in_event_source_authenticates_rest_audience_with_nonce(): void {
        $source = file_get_contents((new ReflectionClass(Digitalogic_Storefront_Realtime::class))->getFileName());

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$user_id\s*>\s*0\s*\).*?add_query_arg\(\s*[\'\"]_wpnonce[\'\"]\s*,\s*wp_create_nonce\(\s*[\'\"]wp_rest[\'\"]\s*\)/s',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/[\'\"]streamUrl[\'\"]\s*=>\s*\$stream_url/',
            $source
        );
    }

    public function test_sse_discards_inherited_compression_buffer_before_headers(): void {
        $source = file_get_contents((new ReflectionClass(Digitalogic_Storefront_Realtime::class))->getFileName());

        $this->assertMatchesRegularExpression(
            '/serve_stream\(\s*\$request\s*\).*?disable_output_buffering\(\);.*?Content-Type: text\/event-stream/s',
            $source
        );
        $this->assertMatchesRegularExpression('/ob_end_clean\(\)/', $source);
    }

    public function test_public_projection_exposes_currency_snapshot_without_internal_event_data(): void {
        $event = Digitalogic_Storefront_Realtime::project_public_event(array(
            'id' => 123,
            'name' => 'currency.updated',
            'time' => '2026-08-30 12:00:00',
            'data' => array('option' => 'dollar_price', 'internal' => 'must-not-leak'),
        ));

        $this->assertSame(123, $event['id']);
        $this->assertSame('currency.updated', $event['name']);
        $this->assertSame('general', $event['data']['scope']);
        $this->assertSame(187891, $event['data']['currency']['dollar_price']);
        $this->assertSame(29500, $event['data']['currency']['yuan_price']);
        $this->assertArrayNotHasKey('internal', $event['data']);
    }

    public function test_public_projection_reduces_product_events_to_identity_and_change(): void {
        $event = Digitalogic_Storefront_Realtime::project_public_event(array(
            'id' => 456,
            'name' => 'product.updated',
            'time' => '2026-08-30 12:01:00',
            'data' => array(
                'id' => 9002,
                'product_id' => 9001,
                'parent_id' => 9001,
                'title' => 'Private draft title',
                'cost' => '123',
            ),
        ));

        $this->assertSame(array(
            'scope' => 'product',
            'product_id' => 9001,
            'object_id' => 9002,
            'parent_id' => 9001,
            'change' => 'updated',
        ), $event['data']);
    }

    public function test_private_and_operational_events_are_never_projected(): void {
        foreach (array('user.updated', 'order.updated', 'panel.toast', 'pricing.state.changed') as $name) {
            $this->assertNull(Digitalogic_Storefront_Realtime::project_public_event(array(
                'id' => 999,
                'name' => $name,
                'data' => array('secret' => 'no'),
            )), $name);
        }
    }

    public function test_targeted_notification_projection_hides_private_audience_and_actions(): void {
        $GLOBALS['digitalogic_test_current_user_id'] = 42;
        $GLOBALS['digitalogic_test_current_user'] = (object) array(
            'ID' => 42,
            'user_login' => 'customer-42',
            'display_name' => 'Customer',
            'roles' => array('customer'),
        );
        $GLOBALS['digitalogic_test_user_meta'][42] = array('billing_country' => 'IR');
        $notification = Digitalogic_Event_Mesh::sanitize_notification(array(
            'notification_id' => 'notice-42',
            'title' => 'Customer notice',
            'message' => 'A safe public message.',
            'display' => 'both',
            'level' => 'warning',
            'expires_at' => '2099-01-01T00:00:00Z',
            'audience' => array(
                'roles' => array('customer'),
                'attributes' => array('billing_country' => array('IR')),
                'match' => 'all',
            ),
            'actions' => array(array('id' => 'approve', 'label' => 'Approve')),
            'source' => 'n8n-private-source',
        ));
        $notification['link'] = array('href' => 'https://example.com/private', 'label' => 'Unsafe');
        $event = array(
            'id' => 777,
            'name' => 'workstation.notification',
            'time' => '2026-08-30 12:02:00',
            'data' => $notification,
        );

        $public = Digitalogic_Storefront_Realtime::project_public_event($event, 42);

        $this->assertSame('notification', $public['data']['scope']);
        $this->assertSame('notice-42', $public['data']['notification']['id']);
        $this->assertSame('both', $public['data']['notification']['display']);
        $this->assertSame(array(), $public['data']['notification']['link']);
        $this->assertArrayNotHasKey('audience', $public['data']['notification']);
        $this->assertArrayNotHasKey('actions', $public['data']['notification']);
        $this->assertArrayNotHasKey('source', $public['data']['notification']);
        $this->assertNull(Digitalogic_Storefront_Realtime::project_public_event($event, 0));
    }

    public function test_invalid_product_identity_is_rejected(): void {
        $this->assertNull(Digitalogic_Storefront_Realtime::project_public_event(array(
            'id' => 1000,
            'name' => 'product.updated',
            'data' => array('id' => 0),
        )));
    }

    public function test_variation_updates_target_the_open_parent_page_and_dedupe_per_request(): void {
        $GLOBALS['digitalogic_test_posts'][9001] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_parent' => 0,
            'meta' => array(),
        );
        $GLOBALS['digitalogic_test_posts'][9002] = array(
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'post_parent' => 9001,
            'meta' => array(),
        );
        unset($GLOBALS['digitalogic_test_wc_products'][9001], $GLOBALS['digitalogic_test_wc_products'][9002]);
        $panel = (new ReflectionClass(Digitalogic_Panel::class))->newInstanceWithoutConstructor();

        $panel->record_product_event(9002);
        $panel->record_product_event(9002);

        $events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
        $this->assertCount(1, $events);
        $this->assertSame('product.updated', $events[0]['name']);
        $this->assertSame(9002, $events[0]['data']['id']);
        $this->assertSame(9001, $events[0]['data']['product_id']);
        $this->assertSame(9001, $events[0]['data']['parent_id']);
    }
}
