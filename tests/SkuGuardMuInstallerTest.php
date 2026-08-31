<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-digitalogic-product-write-lock.php';
require_once dirname( __DIR__ ) . '/includes/class-digitalogic-sku-guard.php';
require_once dirname( __DIR__ ) . '/includes/class-digitalogic-sku-guard-mu-installer.php';

/** Filesystem acceptance for the exact same-basename MU-loader replacement. */
final class SkuGuardMuInstallerTest extends TestCase {
	/** @var string */
	private $root;

	/** @var string */
	private $mu;

	/** @var string */
	private $backup;

	/** @var string */
	private $target;

	protected function setUp(): void {
		$this->root   = sys_get_temp_dir() . '/digitalogic-sku-installer-' . bin2hex( random_bytes( 6 ) );
		$this->mu     = $this->root . '/mu-plugins';
		$this->backup = $this->root . '/private-backups';
		mkdir( $this->mu, 0700, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated test fixture outside WordPress.
		mkdir( $this->backup, 0700, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated test fixture outside WordPress.
		$this->target = $this->mu . '/' . Digitalogic_SKU_Guard_MU_Installer::TARGET_BASENAME;
		copy( __DIR__ . '/fixtures/digitalogic-woo-sku-guard-v2.0.1.php', $this->target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Exact live-loader fixture.
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->root );
	}

	public function test_exact_live_loader_hash_manifest_is_pinned(): void {
		$this->assertSame(
			'02017e699efd51574da883eaee498b119a37b0ba70ca5ecb54b148967614e604',
			Digitalogic_SKU_Guard_MU_Installer::LIVE_V2_0_1_SHA256
		);
		$this->assertSame(
			Digitalogic_SKU_Guard_MU_Installer::LIVE_V2_0_1_SHA256,
			hash_file( 'sha256', __DIR__ . '/fixtures/digitalogic-woo-sku-guard-v2.0.1.php' )
		);
		$this->assertSame(
			Digitalogic_SKU_Guard_MU_Installer::LOADER_V2_1_SHA256,
			hash_file( 'sha256', dirname( __DIR__ ) . '/includes/mu-plugins/' . Digitalogic_SKU_Guard_MU_Installer::TARGET_BASENAME )
		);
	}

	public function test_installs_same_basename_from_outside_mu_stage_and_rolls_back_exactly(): void {
		$source        = dirname( __DIR__ ) . '/includes/mu-plugins/' . Digitalogic_SKU_Guard_MU_Installer::TARGET_BASENAME;
		$previous_hash = hash_file( 'sha256', $this->target );
		$source_hash   = hash_file( 'sha256', $source );

		$before_boot = $this->boot_loader_in_fresh_request( $this->target );
		$this->assertSame( 1, $before_boot['filters']['wc_product_pre_has_unique_sku'] );
		$this->assertSame( 2, $before_boot['actions']['woocommerce_before_product_object_save'] );

		$result = Digitalogic_SKU_Guard_MU_Installer::install( $this->mu, $this->backup, $previous_hash, $source );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['requires_fresh_request'] );
		$this->assertSame( '2.1.0', $result['loader_version'] );
		$this->assertSame( $source_hash, hash_file( 'sha256', $this->target ) );
		$this->assertSame( $previous_hash, hash_file( 'sha256', $result['backup'] ) );
		$this->assertSame( $previous_hash, $result['backup_sha256'] );
		$this->assertSame( realpath( $this->backup ), dirname( $result['backup'] ) );
		$this->assertSame( array( Digitalogic_SKU_Guard_MU_Installer::TARGET_BASENAME ), $this->php_basenames( $this->mu ) );
		$installed_boot = $this->boot_loader_in_fresh_request( $this->target );
		$this->assertSame( 1, $installed_boot['filters']['wc_product_pre_has_unique_sku'] );
		$this->assertSame( 2, $installed_boot['actions']['woocommerce_before_product_object_save'] );

		$rollback = Digitalogic_SKU_Guard_MU_Installer::rollback(
			$this->mu,
			$this->backup,
			$result['backup'],
			$source_hash,
			$result['backup_sha256']
		);
		$this->assertIsArray( $rollback );
		$this->assertTrue( $rollback['ok'] );
		$this->assertSame( $previous_hash, hash_file( 'sha256', $this->target ) );
		$this->assertSame( array( Digitalogic_SKU_Guard_MU_Installer::TARGET_BASENAME ), $this->php_basenames( $this->mu ) );
		$rollback_boot = $this->boot_loader_in_fresh_request( $this->target );
		$this->assertSame( 1, $rollback_boot['filters']['wc_product_pre_has_unique_sku'] );
		$this->assertSame( 2, $rollback_boot['actions']['woocommerce_before_product_object_save'] );
	}

	public function test_hash_or_backup_path_mismatch_refuses_without_mutation(): void {
		$before = hash_file( 'sha256', $this->target );
		$source = dirname( __DIR__ ) . '/includes/mu-plugins/' . Digitalogic_SKU_Guard_MU_Installer::TARGET_BASENAME;

		$result = Digitalogic_SKU_Guard_MU_Installer::install( $this->mu, $this->backup, str_repeat( '0', 64 ), $source );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'sku_guard_mu_precondition_failed', $result->get_error_code() );
		$this->assertSame( $before, hash_file( 'sha256', $this->target ) );

		$result = Digitalogic_SKU_Guard_MU_Installer::install( $this->mu, $this->mu, $before, $source );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'sku_guard_mu_backup_exposed', $result->get_error_code() );
		$this->assertSame( $before, hash_file( 'sha256', $this->target ) );
	}

	/** Boot one loader in a separate request-shaped PHP process. */
	private function boot_loader_in_fresh_request( $loader ) {
		$fixture = __DIR__ . '/fixtures/sku-guard-loader-harness.inc';
		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Fresh-process loader ownership is the deployment acceptance boundary.
			array( PHP_BINARY, $fixture, 'normal', dirname( __DIR__ ), (string) $loader ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exact test pipe cleanup.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exact test pipe cleanup.
		$this->assertSame( 0, proc_close( $process ), $error );
		$result = json_decode( (string) $output, true );
		$this->assertIsArray( $result, $output . $error );

		return $result;
	}

	/** Return direct PHP basenames; nested stage/backup artifacts are forbidden. */
	private function php_basenames( $directory ) {
		$files = glob( rtrim( $directory, '/\\' ) . '/*.php' );
		return array_map( 'basename', false === $files ? array() : $files );
	}

	/** Remove only this test's unique temporary tree. */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the unique test fixture tree.
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the unique test fixture root.
	}
}
