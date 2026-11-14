<?php
/**
 * Full public model counts, independent of detail pagination or attribute values.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Isolated canonical-product fixture and namespace lookup doubles.
namespace Digitalogic\Laravel\Http\Controllers {
	function wc_get_product($id) {
		if (isset($GLOBALS['variation_count_fixture'])) return $GLOBALS['variation_count_fixture'][$id] ?? false;
		return \wc_get_product($id);
	}
	function get_post_field($field, $id) {
		if (isset($GLOBALS['variation_count_fixture'])) return $GLOBALS['variation_count_fixture'][$id]->password ?? '';
		return function_exists('get_post_field') ? \get_post_field($field, $id) : '';
	}
}

namespace {
	use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
	use PHPUnit\Framework\TestCase;
	require_once dirname(__DIR__) . '/laravel/app/Http/Controllers/MobileCatalogController.php';

	final class Digitalogic_VariationCount_Fixture {
		public function __construct(public int $id, public string $type = 'variation', public int $parent = 9000,
			public string $status = 'publish', public string $visibility = 'visible', public string $password = '',
			public array $children = [], public bool $fails = false) {}
		public function get_id(): int { return $this->id; }
		public function is_type($type): bool { return $type === $this->type; }
		public function get_parent_id(): int { return $this->parent; }
		public function get_status(): string { return $this->status; }
		public function get_catalog_visibility(): string { return $this->visibility; }
		public function get_children(): array {
			if ($this->fails) throw new RuntimeException('Fixture inventory unavailable.');
			return $this->children;
		}
		public function is_in_stock(): never { throw new LogicException('Model counts must include out-of-stock models.'); }
		public function get_attributes(): never { throw new LogicException('Attribute combinations are not model records.'); }
	}

	final class MobileVariationCountTest extends TestCase {
		protected function tearDown(): void { unset($GLOBALS['variation_count_fixture']); }
		private function parent(array $children): Digitalogic_VariationCount_Fixture {
			$parent = new Digitalogic_VariationCount_Fixture(9000, 'variable', 0, children: $children);
			$GLOBALS['variation_count_fixture'] = [9000 => $parent];
			return $parent;
		}
		public function test_complete_count_exceeds_detail_limit_without_reading_stock_or_attributes(): void {
			$parent = $this->parent(range(1, 205));
			foreach ($parent->children as $id) $GLOBALS['variation_count_fixture'][$id] = new Digitalogic_VariationCount_Fixture($id);
			self::assertSame(205, (new MobileCatalogController())->variationCount($parent));
		}
		public function test_only_exact_public_children_are_counted_once(): void {
			$parent = $this->parent([1, 1, 2, 3, 4, 5, 6, 7, 8]);
			$GLOBALS['variation_count_fixture'] += [
				1 => new Digitalogic_VariationCount_Fixture(1),
				2 => new Digitalogic_VariationCount_Fixture(2, status: 'private'),
				3 => new Digitalogic_VariationCount_Fixture(3, status: 'draft'),
				4 => new Digitalogic_VariationCount_Fixture(4, visibility: 'hidden'),
				5 => new Digitalogic_VariationCount_Fixture(5, password: 'fixture-protected'),
				6 => new Digitalogic_VariationCount_Fixture(6, parent: 9999),
				7 => new Digitalogic_VariationCount_Fixture(7, type: 'simple'),
			];
			self::assertSame(1, (new MobileCatalogController())->variationCount($parent));
		}
		public function test_hidden_parent_does_not_expose_child_count(): void {
			$parent = $this->parent([1]);
			$parent->visibility = 'hidden';
			$GLOBALS['variation_count_fixture'][1] = new Digitalogic_VariationCount_Fixture(1);
			self::assertSame(0, (new MobileCatalogController())->variationCount($parent));
		}
		public function test_unavailable_inventory_is_unknown_not_a_fabricated_zero(): void {
			$parent = $this->parent([]);
			$parent->fails = true;
			self::assertNull((new MobileCatalogController())->variationCount($parent));
		}
		public function test_empty_variable_inventory_is_zero_and_nonvariable_is_not_applicable(): void {
			$parent = $this->parent([]);
			self::assertSame(0, (new MobileCatalogController())->variationCount($parent));
			self::assertNull((new MobileCatalogController())->variationCount(new Digitalogic_VariationCount_Fixture(5, 'simple')));
		}
		private function priced(int $id, string $type = 'variation', bool $stock = true, string $price = '200', array $children = [], string $status = 'publish'): object {
			return new class($id, $type, $stock, $price, $children, $status) {
				public string $password = '';
				public function __construct(private int $id, private string $type, private bool $stock, private string $price, private array $children, private string $status) {}
				public function get_id(): int { return $this->id; }
				public function get_parent_id(): int { return 9000; }
				public function is_type($type): bool { return $type === $this->type; }
				public function is_in_stock(): bool { return $this->stock; }
				public function get_status(): string { return $this->status; }
				public function get_catalog_visibility(): string { return 'visible'; }
				public function get_children(): array { return $this->children; }
				public function get_price(): string {
					if (!$this->stock) throw new LogicException('Unavailable saved prices must not be read for public display.');
					return $this->price;
				}
				public function get_regular_price(): string { return $this->get_price(); }
				public function get_sale_price(): string { return ''; }
				public function is_on_sale(): bool { return false; }
			};
		}
		public function test_unavailable_simple_or_variation_never_projects_saved_amounts(): void {
			$expected = ['price'=>'','regular_price'=>'','sale_price'=>'','price_range'=>null];
			foreach (['simple','variation','variable'] as $type) {
				self::assertSame($expected,(new MobileCatalogController())->publicPriceAmounts($this->priced(1,$type,false,'25'),0));
			}
		}
		public function test_mixed_parent_uses_only_available_public_child_prices(): void {
			$parent=$this->priced(9000,'variable',true,'5',[1,2,3,4]);
			$GLOBALS['variation_count_fixture']=[9000=>$parent,1=>$this->priced(1,'variation',false,'5'),2=>$this->priced(2,'variation',true,'200'),3=>$this->priced(3,'variation',true,'300'),4=>$this->priced(4,'variation',true,'1',[],'private')];
			self::assertSame(['price'=>'200','regular_price'=>'200','sale_price'=>'','price_range'=>['min_amount'=>'200','max_amount'=>'300']],(new MobileCatalogController())->publicPriceAmounts($parent,0));
		}
		public function test_available_missing_price_stays_absent_and_real_zero_is_preserved(): void {
			$controller=new MobileCatalogController();
			self::assertSame('',$controller->publicPriceAmounts($this->priced(1,'simple',true,''),2)['price']);
			self::assertSame('000',$controller->publicPriceAmounts($this->priced(1,'simple',true,'0'),2)['price']);
		}
	}
}
