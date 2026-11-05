<?php
use Digitalogic\Laravel\Services\MobileRequestSchema as Schema;
use Digitalogic\Laravel\Services\MobileRequestStore as Store;
use Digitalogic\Laravel\Http\Controllers\MobileRequestController as Controller;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRequestSchema.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRequestStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileRequestController.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityStore.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-order-forms.php';
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return is_array( $value ) ? array_map( 'wp_slash', $value ) : addslashes( (string) $value );}}

final class MobileRequestsContractTest extends TestCase {
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
	public function test_reuses_canonical_validator_and_restores_wordpress_request_globals(): void {
		$forms  = ( new ReflectionClass( Digitalogic_Storefront_Order_Forms::class ) )->newInstanceWithoutConstructor();
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
		self::assertSame( array( 'preserved' => 'original' ), $_POST );
		$fields['board_quantity'] = 10;
		$result                   = $forms->validate_native( 'pcb', $fields );
		self::assertSame( 10, $result['payload']['quantity'] );
		self::assertSame( '1.6', $result['payload']['thickness_mm'] );
		self::assertSame( array( 'preserved' => 'original' ), $_POST );
	}
	public function test_file_content_must_match_allowed_type_not_just_the_filename(): void {
		$path = tempnam( sys_get_temp_dir(), 'dgl-mobile-test-' );
		try {
			file_put_contents( $path, '<?php echo "not a zip";' );
			self::assertFalse( Store::fileTypeMatches( $path, 'zip' ) );
			file_put_contents( $path, "PK\x05\x06" . str_repeat( "\0", 18 ) );
			self::assertTrue( Store::fileTypeMatches( $path, 'zip' ) );
			self::assertFalse( Store::fileTypeMatches( $path, 'pdf' ) );
			file_put_contents( $path, "part,quantity\nR1,5\n" );
			self::assertTrue( Store::fileTypeMatches( $path, 'csv' ) );
			self::assertFalse( Store::fileTypeMatches( $path, 'php' ) );
		} finally {
			unlink( $path );}
	}
}
