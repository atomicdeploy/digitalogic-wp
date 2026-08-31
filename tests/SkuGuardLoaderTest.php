<?php

use PHPUnit\Framework\TestCase;

/** Isolated acceptance tests for the early canonical MU loader. */
final class SkuGuardLoaderTest extends TestCase {
	public function test_normal_loader_has_one_canonical_owner_and_no_legacy_class(): void {
		$result = $this->run_harness( 'normal' );

		$this->assertSame( '2.1.0', $result['version'] );
		$this->assertFalse( $result['legacy_class'] );
		$this->assertSame( 1, $result['filters']['wc_product_pre_has_unique_sku'] );
		$this->assertSame( 1, $result['filters']['add_post_metadata'] );
		$this->assertSame( 1, $result['filters']['update_post_metadata_by_mid'] );
		$this->assertSame( 2, $result['actions']['woocommerce_before_product_object_save'] );
	}

	public function test_missing_canonical_loader_fails_once_without_recursive_suffix_generation(): void {
		$result = $this->run_harness( 'missing' );

		$this->assertFalse( $result['legacy_class'] );
		$this->assertTrue( $result['unique_result'] );
		$this->assertFalse( $result['alias_add'] );
		$this->assertFalse( $result['alias_by_mid'] );
		$this->assertFalse( $result['rename_away_by_mid'] );
		$this->assertFalse( $result['alias_delete'] );
		$this->assertFalse( $result['alias_delete_by_mid'] );
		$this->assertTrue( $result['promotion_throws'] );
		$this->assertTrue( $result['throws']['existing_dirty'] );
		$this->assertTrue( $result['throws']['new_with_sku'] );
		$this->assertFalse( $result['throws']['existing_unchanged'] );
		$this->assertSame( 1, $result['filters']['wc_product_pre_has_unique_sku'] );
		$this->assertSame( 1, $result['actions']['woocommerce_before_product_object_save'] );
	}

	/** Run the loader in a clean PHP process so missing classes cannot be masked. */
	private function run_harness( $mode ) {
		$fixture = __DIR__ . '/fixtures/sku-guard-loader-harness.inc';
		$loader  = dirname( __DIR__ ) . '/includes/mu-plugins/digitalogic-woo-sku-guard.php';
		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- A clean PHP process is required to prove the missing-class loader boundary.
			array( PHP_BINARY, $fixture, (string) $mode, dirname( __DIR__ ), $loader ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exact subprocess pipe cleanup in a test.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exact subprocess pipe cleanup in a test.
		$exit = proc_close( $process );
		$this->assertSame( 0, $exit, $error );
		$result = json_decode( (string) $output, true );
		$this->assertIsArray( $result, $output . $error );

		return $result;
	}
}
