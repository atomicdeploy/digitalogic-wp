<?php
/**
 * Native RequestsContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileRequestSchema as Schema;
use Digitalogic\Laravel\Services\MobileRequestStore as Store;
use Digitalogic\Laravel\Http\Controllers\MobileRequestController as Controller;
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Conditional WordPress fixture helper lives with its only test consumer.
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRequestSchema.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRequestStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileRequestController.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityStore.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-order-forms.php';
if ( ! function_exists( 'wp_slash' ) ) {
	/**
	 * Supply WordPress slashing for this isolated form-validator fixture.
	 *
	 * @param mixed $value Fixture input.
	 * @return mixed Slashed fixture.
	 */
	function wp_slash( $value ) {
		return is_array( $value ) ? array_map( 'wp_slash', $value ) : addslashes( (string) $value );}}

/** Regression coverage for the native adapter. */
final class MobileRequestsContractTest extends TestCase {
	/**
	 * Verify schema and native validation agree on required and bounded fields.
	 *
	 * @return void Native adapter result.
	 */
	public function test_schema_and_native_validation_agree_on_required_and_bounded_fields(): void {
		$form   = Schema::form( 'pcb' );
		$fields = array(
			'contact_name'   => 'نمونه',
			'mobile'         => '09123456789',
			'consent'        => 'yes',
			'project_name'   => 'نمونه برد',
			'board_quantity' => 5,
			'board_length'   => 50,
			'board_width'    => 60,
		);
		self::assertNull( Controller::validateFields( $fields, $form['fields'] ) );
		self::assertSame( 'board_length', Controller::validateFields( array_merge( $fields, array( 'board_length' => 0.5 ) ), $form['fields'] )['field'] );
		self::assertSame( 'soldermask', Controller::validateFields( array_merge( $fields, array( 'soldermask' => 'unsupported' ) ), $form['fields'] )['field'] );
		self::assertSame( 'unknown_field', Controller::validateFields( array_merge( $fields, array( 'admin' => true ) ), $form['fields'] )['code'] );
		self::assertNull( Schema::form( 'internal' ) );
		self::assertSame( 20971520, $form['upload']['max_bytes'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $form['revision'] );
	}
	/**
	 * Verify reuses canonical validator and restores WordPress request globals.
	 *
	 * @return void Native adapter result.
	 */
	public function test_reuses_canonical_validator_and_restores_wordpress_request_globals(): void {
		$forms = ( new ReflectionClass( Digitalogic_Storefront_Order_Forms::class ) )->newInstanceWithoutConstructor();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deliberate local form-validation fixture, never a public request handler.
		$_POST  = array( 'preserved' => 'original' );
		$fields = array(
			'contact_name'   => 'نمونه',
			'mobile'         => '+989123456789',
			'consent'        => 'yes',
			'project_name'   => 'نمونه برد',
			'board_quantity' => 7,
			'board_length'   => 50,
			'board_width'    => 60,
		);
		self::assertTrue( is_wp_error( $forms->validate_native( 'pcb', $fields ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deliberate local form-validation fixture, never a public request handler.
		self::assertSame( array( 'preserved' => 'original' ), $_POST );
		$fields['board_quantity'] = 10;
		$result                   = $forms->validate_native( 'pcb', $fields );
		self::assertSame( 10, $result['payload']['quantity'] );
		self::assertSame( '1.6', $result['payload']['thickness_mm'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deliberate local form-validation fixture, never a public request handler.
		self::assertSame( array( 'preserved' => 'original' ), $_POST );
	}
	/**
	 * Verify file content must match allowed type not just the filename.
	 *
	 * @return void Native adapter result.
	 */
	public function test_file_content_must_match_allowed_type_not_just_the_filename(): void {
		$path = tempnam( sys_get_temp_dir(), 'dgl-mobile-test-' );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone build/test fixture; WordPress filesystem transport is not loaded.
			file_put_contents( $path, '<?php echo "not a zip";' );
			self::assertFalse( Store::fileTypeMatches( $path, 'zip' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone build/test fixture; WordPress filesystem transport is not loaded.
			file_put_contents( $path, "PK\x05\x06" . str_repeat( "\0", 18 ) );
			self::assertTrue( Store::fileTypeMatches( $path, 'zip' ) );
			self::assertFalse( Store::fileTypeMatches( $path, 'pdf' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone build/test fixture; WordPress filesystem transport is not loaded.
			file_put_contents( $path, "part,quantity\nR1,5\n" );
			self::assertTrue( Store::fileTypeMatches( $path, 'csv' ) );
			self::assertFalse( Store::fileTypeMatches( $path, 'php' ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone build/test fixture; WordPress filesystem transport is not loaded.
			unlink( $path );}
	}
}
