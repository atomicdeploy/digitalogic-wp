<?php
/**
 * Cooperative source-ingress transaction deadlines.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** The source deadline shares receiver checkpoints and the final commit fence. */
final class SourceDeliveryDeadlineTest extends TestCase {

	/**
	 * Injected monotonic seconds.
	 *
	 * @var float
	 */
	private $now = 100.0;

	/**
	 * Globals restored after each isolated transaction test.
	 *
	 * @var array
	 */
	private $saved_globals = array();

	/** Prepare the existing transactional database double. */
	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'wpdb', 'digitalogic_test_options', 'digitalogic_test_filters', 'digitalogic_test_transaction_failures', 'digitalogic_test_actions', 'digitalogic_test_action_callbacks' ) as $name ) {
			$this->saved_globals[ $name ] = $GLOBALS[ $name ] ?? null;
			$GLOBALS[ $name ]             = array();
		}
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		$GLOBALS['digitalogic_test_options']['deadline_test_value'] = 'before';
		$this->reset_services();
		$clock = new ReflectionProperty( Digitalogic_Pricing_Service::class, 'source_delivery_clock' );
		$clock->setValue( Digitalogic_Pricing_Service::instance(), fn() => $this->now );
	}

	/** Do not leak the injected clock or receiver guard into other tests. */
	protected function tearDown(): void {
		$this->reset_services();
		foreach ( $this->saved_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		parent::tearDown();
	}

	/** Clear request-local service ownership between cases. */
	private function reset_services(): void {
		foreach ( array( Digitalogic_Pricing_Service::class, Digitalogic_Product_Sync_Receiver::class ) as $class ) {
			$instance = new ReflectionProperty( $class, 'instance' );
			$instance->setValue( null, null );
		}
	}

	/** Time spent waiting for locks must not grant a fresh processing budget. */
	public function test_expired_budget_does_not_enter_source_callback(): void {
		$reads = 0;
		$clock = new ReflectionProperty( Digitalogic_Pricing_Service::class, 'source_delivery_clock' );
		$clock->setValue(
			Digitalogic_Pricing_Service::instance(),
			static function () use ( &$reads ) {
				return 0 === $reads++ ? 100.0 : 160.0;
			}
		);
		$calls  = 0;
		$result = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
			static function () use ( &$calls ) {
				++$calls;
				return array( 'status' => 'applied' );
			}
		);
		$this->assert_deadline_rollback( $result );
		$this->assertSame( 0, $calls );
	}

	/** The final check catches work that crossed the deadline without another batch. */
	public function test_deadline_at_precommit_rolls_back_without_retry(): void {
		$calls  = 0;
		$result = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
			function () use ( &$calls ) {
				++$calls;
				$GLOBALS['digitalogic_test_options']['deadline_test_value'] = 'changed';
				$this->now = 160.0;
				return array( 'status' => 'applied' );
			}
		);
		$this->assert_deadline_rollback( $result );
		$this->assertSame( 1, $calls );
	}

	/** The receiver's real checkpoint sees the same absolute budget. */
	public function test_receiver_checkpoint_rejects_expired_partial_work(): void {
		$checkpoint = new ReflectionMethod( Digitalogic_Product_Sync_Receiver::class, 'check_coordinated_actuation_guard' );
		$observed   = null;
		$result     = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
			function () use ( $checkpoint, &$observed ) {
				$GLOBALS['digitalogic_test_options']['deadline_test_value'] = 'partial';
				$this->now = 160.001;
				$observed  = $checkpoint->invoke( Digitalogic_Product_Sync_Receiver::instance() );
				return $observed;
			}
		);
		$this->assertSame( $observed, $result );
		$this->assert_deadline_rollback( $result );
		$this->assertTrue( $checkpoint->invoke( Digitalogic_Product_Sync_Receiver::instance() ) );
	}

	/** Work just below the boundary can commit normally. */
	public function test_success_before_deadline_commits_once(): void {
		$result = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
			function () {
				$GLOBALS['digitalogic_test_options']['deadline_test_value'] = 'committed';
				$this->now = 159.999;
				return array( 'status' => 'applied' );
			}
		);
		$this->assertSame( array( 'status' => 'applied' ), $result );
		$this->assertSame( 'committed', $GLOBALS['digitalogic_test_options']['deadline_test_value'] );
		$this->assertSame( 1, count( array_keys( $GLOBALS['wpdb']->queries, 'COMMIT', true ) ) );
		$this->assertNotContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** An enclosing ownership fence remains authoritative through commit and is restored. */
	public function test_enclosing_guard_is_composed_and_preserves_its_error(): void {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$error    = new WP_Error( 'outer_fence_lost', 'The existing owner lost its fence.' );
		$phases   = array();
		$outer    = static function ( $phase ) use ( $error, &$phases ) {
			$phases[] = $phase;
			return 'before_commit' === $phase ? $error : true;
		};
		$property = new ReflectionProperty( $receiver, 'coordinated_actuation_guard' );
		$result   = $receiver->with_coordinated_actuation_guard(
			$outer,
			function () use ( $receiver, $property ) {
				$before = $property->getValue( $receiver );
				$result = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
					static function () {
						$GLOBALS['digitalogic_test_options']['deadline_test_value'] = 'uncommitted';
						return array( 'status' => 'applied' );
					}
				);
				$this->assertSame( $before, $property->getValue( $receiver ) );
				return $result;
			}
		);
		$this->assertSame( $error, $result );
		$this->assertSame( array( 'before_write', 'before_commit' ), $phases );
		$this->assertSame( 'before', $GLOBALS['digitalogic_test_options']['deadline_test_value'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertNull( $property->getValue( $receiver ) );
	}

	/** Exceptions roll back partial work and restore the guard on both scope exits. */
	public function test_callback_exception_restores_enclosing_guard(): void {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$property = new ReflectionProperty( $receiver, 'coordinated_actuation_guard' );
		$result   = $receiver->with_coordinated_actuation_guard(
			static fn() => true,
			function () use ( $receiver, $property ) {
				$before = $property->getValue( $receiver );
				$result = Digitalogic_Pricing_Service::instance()->run_source_delivery_transaction(
					static function () {
						$GLOBALS['digitalogic_test_options']['deadline_test_value'] = 'partial';
						throw new RuntimeException( 'Injected source callback failure.' );
					}
				);
				$this->assertSame( $before, $property->getValue( $receiver ) );
				return $result;
			}
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_pricing_sync_transaction_exception', $result->get_error_code() );
		$this->assertSame( 'before', $GLOBALS['digitalogic_test_options']['deadline_test_value'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertNull( $property->getValue( $receiver ) );
	}

	/**
	 * Check rollback and released locks without treating a timeout as success.
	 *
	 * @param mixed $result Transaction outcome.
	 */
	private function assert_deadline_rollback( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_source_delivery_deadline_exceeded', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertSame( 'before', $GLOBALS['digitalogic_test_options']['deadline_test_value'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertFalse( Digitalogic_Pricing_Service::instance()->source_delivery_lock_is_owned() );
		$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
	}
}
