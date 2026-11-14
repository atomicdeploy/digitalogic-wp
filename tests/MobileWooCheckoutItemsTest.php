<?php
/**
 * Canonical prepared checkout lines, with no orders or live product lookups.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Test-only namespace precision override and literal prepared-line fixtures.
namespace Digitalogic\Laravel\Services {
	function wc_get_price_decimals() {
		return $GLOBALS['checkout_items_fixture_decimals'] ?? \wc_get_price_decimals();
	}
}

namespace {
	use Digitalogic\Laravel\Services\MobileWooCheckout;
	use PHPUnit\Framework\TestCase;

	require_once dirname(__DIR__) . '/laravel/app/Services/MobileWooCheckout.php';
	require_once dirname(__DIR__) . '/laravel/app/Http/Controllers/MobileCatalogController.php';

	final class MobileWooCheckoutItemsTest extends TestCase {
		protected function tearDown(): void {
			unset($GLOBALS['checkout_items_fixture_decimals']);
		}

		private function line(array $changes = [], string $name = 'Canonical board', string $sku = 'EXACT-SKU', int $image = 0): array {
			$product = new class($name, $sku, $image) {
				public function __construct(private string $name, private string $sku, private int $image) {}
				public function get_name(): string { return $this->name; }
				public function get_sku(): string { return $this->sku; }
				public function get_image_id(): int { return $this->image; }
				public function get_price(): never { throw new LogicException('Catalog price must not replace prepared checkout amount.'); }
			};
			return array_replace([
				'product_id' => 42, 'variation_id' => 0, 'data' => $product,
				'quantity' => 3, 'line_subtotal' => '1500', 'line_total' => '1200',
			], $changes);
		}

		public function test_prepared_amounts_and_quantity_are_used_instead_of_catalog_price(): void {
			$rows = MobileWooCheckout::presentItems([$this->line()]);
			self::assertSame([
				'product_id' => 42, 'variation_id' => 0, 'name' => 'Canonical board',
				'sku' => 'EXACT-SKU', 'quantity' => 3, 'unit_price' => '500',
				'line_subtotal' => '1500', 'line_total' => '1200', 'image' => null,
			], $rows[0]);
		}

		public function test_exact_variation_identity_image_and_sku_remain_together(): void {
			$rows = MobileWooCheckout::presentItems([
				$this->line(['variation_id' => 51, 'quantity' => 1, 'line_subtotal' => '175', 'line_total' => '160'], '<b>Board</b> — 3.3V', 'CHILD-3V3', 812),
			]);
			self::assertSame(42, $rows[0]['product_id']);
			self::assertSame(51, $rows[0]['variation_id']);
			self::assertSame('Board — 3.3V', $rows[0]['name']);
			self::assertSame('CHILD-3V3', $rows[0]['sku']);
			self::assertSame('https://digitalogic.test/media/812', $rows[0]['image']);
			self::assertSame('175', $rows[0]['unit_price']);
		}

		public function test_current_server_cart_changes_are_reflected_without_old_client_lines(): void {
			$before = MobileWooCheckout::presentItems([$this->line(['quantity' => 1, 'line_subtotal' => '500', 'line_total' => '500'])]);
			$after = MobileWooCheckout::presentItems([
				$this->line(),
				$this->line(['product_id' => 99, 'quantity' => 2, 'line_subtotal' => '200', 'line_total' => '200'], 'Added on website', ''),
			]);
			self::assertCount(1, $before);
			self::assertCount(2, $after);
			self::assertSame(3, $after[0]['quantity']);
			self::assertSame(99, $after[1]['product_id']);
			self::assertSame('', $after[1]['sku']);
			self::assertNull($after[1]['image']);
		}

		public function test_currency_minor_units_and_complete_discount_preserve_actual_zero(): void {
			$GLOBALS['checkout_items_fixture_decimals'] = 2;
			$rows = MobileWooCheckout::presentItems([$this->line(['line_subtotal' => '30.75', 'line_total' => '0'])]);
			self::assertSame('1025', $rows[0]['unit_price']);
			self::assertSame('3075', $rows[0]['line_subtotal']);
			self::assertSame('000', $rows[0]['line_total']);
		}

		public function test_invalid_prepared_amounts_cannot_be_silently_rendered_as_zero(): void {
			foreach ([['quantity' => 0], ['quantity' => 1.5], ['line_total' => null], ['line_subtotal' => 'not-a-price'], ['line_total' => -1], ['line_total' => INF], ['data' => null]] as $changes) {
				try {
					MobileWooCheckout::presentItems([$this->line($changes)]);
					self::fail('Invalid prepared line must prevent an incomplete review.');
				} catch (RuntimeException $failure) {
					self::assertSame('Prepared checkout line unavailable.', $failure->getMessage());
				}
			}
		}

		public function test_empty_canonical_cart_stays_empty(): void {
			self::assertSame([], MobileWooCheckout::presentItems([]));
		}
	}
}
