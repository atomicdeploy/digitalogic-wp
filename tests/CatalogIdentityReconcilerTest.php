<?php
/**
 * Catalog identity quarantine tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Covers normalization, precedence, and fail-closed quarantine behavior. */
final class CatalogIdentityReconcilerTest extends TestCase {

	/** Persian/Arabic characters and digits share one diagnostic normal form. */
	public function test_normalizes_persian_arabic_digits_letters_and_separators(): void {
		$this->assertSame(
			'XY-S100H123',
			Digitalogic_Catalog_Identity_Reconciler::normalize_identifier( " XY‐S100H۱۲٣\u{200C} " )
		);
		$this->assertSame(
			'ماژول کی 123',
			Digitalogic_Catalog_Identity_Reconciler::normalize_name( 'ماژول كی-۱۲٣' )
		);
	}

	/** Exact names diagnose a split without becoming an automatic match. */
	public function test_unique_exact_name_pair_is_blocking_quarantine_not_auto_match(): void {
		$result = Digitalogic_Catalog_Identity_Reconciler::instance()->annotate_rows(
			array(
				$this->source_row( '113006024', 'XY-S100H' ),
				$this->woo_row( 11160, 'XY-S100H' ),
			)
		);

		$this->assertSame( 1, $result['counts']['quarantined_identity_groups'] );
		$this->assertSame( 1, $result['counts']['one_to_one_split_candidates'] );
		$this->assertSame( 'exact_name', $result['rows'][0]['identity_resolution']['candidate_reasons'][0] );
		$this->assertFalse( $result['rows'][0]['identity_resolution']['safe_auto_match'] );
		$this->assertSame(
			$result['rows'][0]['identity_resolution']['quarantine_id'],
			$result['rows'][1]['identity_resolution']['quarantine_id']
		);
		$this->assertSame( 'projection_integrity_identity_quarantine', $result['integrity_warnings'][0]['code'] );
	}

	/** Higher-precedence SKU evidence suppresses lower-precedence name aliases. */
	public function test_exact_sku_precedence_is_deterministic_and_still_quarantined(): void {
		$result = Digitalogic_Catalog_Identity_Reconciler::instance()->annotate_rows(
			array(
				$this->source_row( 'CODE-1', 'Shared name' ),
				$this->woo_row( 10, 'Different name', 'CODE-1' ),
				$this->woo_row( 11, 'Shared name' ),
			)
		);

		$this->assertSame( array( 10 ), $result['rows'][0]['identity_resolution']['woocommerce_ids'] );
		$this->assertSame( array( 'exact_sku' ), $result['rows'][0]['identity_resolution']['candidate_reasons'] );
		$this->assertArrayNotHasKey( 'identity_resolution', $result['rows'][2] );
	}

	/** A normalized Product Code wins over SKU and display-name evidence. */
	public function test_normalized_product_code_precedence_is_deterministic(): void {
		$result = Digitalogic_Catalog_Identity_Reconciler::instance()->annotate_rows(
			array(
				$this->source_row( 'XY-S100H-۱۲۳', 'Shared name' ),
				$this->woo_row( 12, 'Different name', '', 'XY‐S100H-123' ),
				$this->woo_row( 13, 'Shared name', 'XY-S100H-۱۲۳' ),
			)
		);

		$this->assertSame( array( 12 ), $result['rows'][0]['identity_resolution']['woocommerce_ids'] );
		$this->assertSame( array( 'normalized_product_code' ), $result['rows'][0]['identity_resolution']['candidate_reasons'] );
		$this->assertArrayNotHasKey( 'identity_resolution', $result['rows'][2] );
	}

	/** Variation identity and parent are retained in exact quarantine diagnostics. */
	public function test_variation_parent_identity_is_preserved_without_parent_child_merge(): void {
		$result = Digitalogic_Catalog_Identity_Reconciler::instance()->annotate_rows(
			array(
				$this->source_row( 'VAR-RED', 'Board red' ),
				$this->woo_row( 201, 'Board red', '', '', 'variation', 200 ),
			)
		);

		$this->assertSame( array( 200 ), $result['rows'][0]['identity_resolution']['woocommerce_parent_ids'] );
		$this->assertSame( array( 'variation' ), $result['rows'][0]['identity_resolution']['woocommerce_types'] );
		$this->assertFalse( $result['rows'][0]['identity_resolution']['safe_auto_match'] );

		$identity = new Digitalogic_Canonical_Catalog_Identity(
			'woocommerce',
			'woo:201',
			'',
			'',
			'Board red',
			'variation',
			200
		);
		$this->assertTrue( $identity->is_variation() );
	}

	/** Multiple normalized aliases form one collision group and never guess. */
	public function test_many_to_one_name_alias_is_one_collision_quarantine(): void {
		$result = Digitalogic_Catalog_Identity_Reconciler::instance()->annotate_rows(
			array(
				$this->source_row( 'A', 'ماژول كی-۱۲۳' ),
				$this->source_row( 'B', 'ماژول کی 123' ),
				$this->woo_row( 20, 'ماژول کی ۱۲۳' ),
			)
		);

		$this->assertSame( 1, $result['counts']['identity_collision_groups'] );
		$this->assertSame( 2, $result['counts']['quarantined_source_rows'] );
		$this->assertSame( 1, $result['counts']['quarantined_woo_rows'] );
		foreach ( $result['rows'] as $row ) {
			$this->assertContains( 'normalized_identity_collision', $row['issues'] );
		}
	}

	/**
	 * Build one provider-neutral Patris-only row.
	 *
	 * @param string $code Product Code.
	 * @param string $name Product name.
	 */
	private function source_row( string $code, string $name ): array {
		return array(
			'product_code' => $code,
			'status'       => 'source_only',
			'source'       => array(
				'product_code' => $code,
				'name'         => $name,
			),
			'issues'       => array(),
		);
	}

	/**
	 * Build one provider-neutral Woo-only row.
	 *
	 * @param int    $id WooCommerce ID.
	 * @param string $name Product name.
	 * @param string $sku Optional SKU.
	 * @param string $product_code Optional Product Code.
	 * @param string $type Product or variation type.
	 * @param int    $parent_id Optional parent ID.
	 */
	private function woo_row(
		int $id,
		string $name,
		string $sku = '',
		string $product_code = '',
		string $type = 'simple',
		int $parent_id = 0
	): array {
		return array(
			'product_code' => 'woo:' . $id,
			'status'       => 'woocommerce_only',
			'woocommerce'  => array(
				'id'           => $id,
				'name'         => $name,
				'sku'          => $sku,
				'product_code' => $product_code,
				'type'         => $type,
				'parent_id'    => $parent_id,
			),
			'issues'       => array(),
		);
	}
}
