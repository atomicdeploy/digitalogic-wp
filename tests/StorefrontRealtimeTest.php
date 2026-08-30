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
        foreach (array('user.updated', 'order.updated', 'panel.toast', 'workstation.notification', 'pricing.state.changed') as $name) {
            $this->assertNull(Digitalogic_Storefront_Realtime::project_public_event(array(
                'id' => 999,
                'name' => $name,
                'data' => array('secret' => 'no'),
            )), $name);
        }
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
