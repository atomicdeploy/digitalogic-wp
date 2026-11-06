<?php
/**
 * Public login nonce boundaries using an isolated, in-memory security store.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileGoogleLoginChallenge as Challenge;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile -- PHPUnit class and its isolated fixture share the named test file.

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileGoogleLoginChallenge.php';

// phpcs:disable -- Isolated SQL test double; no production database or provider traffic.
final class Digitalogic_GoogleLoginChallenge_Fixture_DB {
	public string $prefix = 'fixture_';
	public string $options = 'fixture_options';
	public string $last_error = '';
	public array $rows = [], $rates = [], $tables = [], $queries = [];
	public bool $lock = true, $failRead = false, $failInsert = false, $failUpdate = false, $writeThenFail = false, $zeroUpdate = false;
	public int $acquired = 0, $released = 0;
	public function prepare($query, ...$args) { return ['query' => $query, 'args' => $args]; }
	public function esc_like($value) { return $value; }
	public function get_var($sql) {
		$this->last_error = '';
		if (str_contains($sql['query'], 'GET_LOCK')) { if ($this->lock) $this->acquired++; return $this->lock ? 1 : 0; }
		if (str_contains($sql['query'], 'RELEASE_LOCK')) { $this->released++; return 1; }
		throw new LogicException('Unexpected fixture query.');
	}
	public function get_row($sql, $format) {
		$this->last_error = '';
		if ($this->failRead) { $this->last_error = 'fixture read failure'; return null; }
		$field = str_contains($sql['query'], 'request_hash=') ? 'request_hash' : 'id';
		foreach ($this->rows as $row) { if ($row[$field] === $sql['args'][1]) return $row; }
		return null;
	}
	public function insert($table, $row) {
		$this->tables[] = $table;
		if ($this->failInsert) return false;
		$this->rows[$row['id']] = $row;
		return 1;
	}
	public function update($table, $values, $where) {
		$this->tables[] = $table;
		if ($this->failUpdate) return false;
		if ($this->zeroUpdate) return 0;
		$id = $where['id'];
		foreach ($where as $key => $value) { if (($this->rows[$id][$key] ?? null) !== $value) return 0; }
		$this->rows[$id] = array_replace($this->rows[$id], $values);
		return $this->writeThenFail ? false : 1;
	}
	public function query($sql) {
		$this->last_error = '';
		$this->queries[] = $sql;
		if (str_starts_with($sql['query'], 'INSERT IGNORE INTO fixture_options')) { $this->rates[$sql['args'][0]] ??= 0; return 1; }
		if (str_starts_with($sql['query'], 'UPDATE fixture_options')) {
			[$key, $limit] = $sql['args'];
			if (($this->rates[$key] ?? 0) >= $limit) return 0;
			$this->rates[$key] = ($this->rates[$key] ?? 0) + 1;
			return 1;
		}
		if (str_starts_with($sql['query'], 'DELETE FROM')) return 0;
		throw new LogicException('Unexpected fixture write.');
	}
}
// phpcs:enable
// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile -- PHPUnit class and isolated fixture intentionally share this test file.

/** No test sends tokens, creates users or grants a native session. */
final class MobileGoogleLoginChallengeTest extends TestCase {
	private const REQUEST = '079fdcbe-c45c-4aef-9b0b-587b8c6f68a0';
	private const SUBJECT = '123456789012345678901';
	/**
	 * Isolated metadata store.
	 *
	 * @var Digitalogic_GoogleLoginChallenge_Fixture_DB
	 */
	private Digitalogic_GoogleLoginChallenge_Fixture_DB $database;
	/**
	 * Previous test-suite database.
	 *
	 * @var mixed
	 */
	private mixed $previous_database;

	/** Install a database double confined to this test. */
	protected function setUp(): void {
		$this->previous_database = $GLOBALS['wpdb'];
		$this->database          = new Digitalogic_GoogleLoginChallenge_Fixture_DB();
		$GLOBALS['wpdb']         = $this->database;
	}

	/** Restore the suite database and assert every acquired lock was released. */
	protected function tearDown(): void {
		self::assertSame( $this->database->acquired, $this->database->released );
		$GLOBALS['wpdb'] = $this->previous_database;
	}

	/**
	 * Signed claims are a caller-verified fixture; the service checks nonce binding.
	 *
	 * @param array $challenge Public challenge fixture.
	 * @return array Verified-claims fixture.
	 */
	private function claims( array $challenge ): array {
		return array(
			'sub'   => self::SUBJECT,
			'nonce' => $challenge['nonce'],
			'iat'   => time(),
		);
	}

	/** Create one public challenge fixture. */
	private function create(): array {
		$result = Challenge::create( self::REQUEST, 'fixture-network', self::SUBJECT );
		self::assertIsArray( $result );
		return $result;
	}

	/**
	 * Assert a structured rejection.
	 *
	 * @param string $code Expected safe code.
	 * @param mixed  $result Actual service result.
	 */
	private function errorIs( string $code, mixed $result ): void {
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
	}

	/** Cached signed claims yield only a nonce; UUID/network replay cannot renew it. */
	public function test_create_replays_exact_nonce_and_keeps_subject_network_private(): void {
		$first = $this->create();
		self::assertSame( $first, $this->create() );
		self::assertCount( 1, $this->database->rows );
		self::assertSame( array( 'challenge_id', 'nonce', 'expires_at' ), array_keys( $first ) );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', $first['challenge_id'] );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $first['nonce'] );
		$row = $this->database->rows[ $first['challenge_id'] ];
		self::assertSame( 300, $row['expires_at'] - $row['created_at'] );
		self::assertSame( 0, $row['user_id'] );
		self::assertStringNotContainsString( self::SUBJECT, wp_json_encode( $row ) );
		self::assertStringNotContainsString( 'fixture-network', wp_json_encode( $row ) );
		self::assertSame( array( 1, 1 ), array_values( $this->database->rates ) );
	}

	/** Reusing an idempotency UUID cannot swap the proven Google subject. */
	public function test_same_network_uuid_changed_subject_conflicts_but_another_network_is_isolated(): void {
		$first = $this->create();
		$this->errorIs( 'google_login_challenge_conflict', Challenge::create( self::REQUEST, 'fixture-network', '999999999' ) );
		$other = Challenge::create( self::REQUEST, 'other-fixture-network', '999999999' );
		self::assertIsArray( $other );
		self::assertNotSame( $first['challenge_id'], $other['challenge_id'] );
		self::assertCount( 2, $this->database->rows );
	}

	/** Replay is available under quota; new requests obey both network and global limits. */
	public function test_new_request_rate_limits_do_not_break_existing_replay(): void {
		$first = $this->create();
		foreach ( array_keys( $this->database->rates ) as $key ) {
			$this->database->rates[ $key ] = 1000;
		}
		self::assertSame( $first, $this->create() );
		$this->errorIs( 'google_login_rate_limited', Challenge::create( '079fdcbe-c45c-4aef-9b0b-587b8c6f68a1', 'fixture-network', self::SUBJECT ) );
		// An independent network remains subject to the exhausted global counter.
		$this->errorIs( 'google_login_rate_limited', Challenge::create( self::REQUEST, 'new-fixture-network', self::SUBJECT ) );
		self::assertCount( 1, $this->database->rows );
	}

	/** One signed proof may retry safely, with the exact original evidence timestamps. */
	public function test_consume_preserves_original_time_and_stores_only_keyed_token_digest(): void {
		$challenge = $this->create();
		$first     = Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-signed-proof' );
		self::assertIsArray( $first );
		self::assertSame( array( 'verified_at', 'created_at', 'expires_at' ), array_keys( $first ) );
		self::assertSame( $first, Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-signed-proof' ) );
		self::assertStringNotContainsString( 'fixture-signed-proof', wp_json_encode( $this->database->rows ) );
		$this->errorIs( 'google_login_challenge_used', Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'different-fixture-proof' ) );
		self::assertSame( array( Challenge::table() ), array_values( array_unique( $this->database->tables ) ) );
	}

	/** Purpose, principal, signed subject/nonce and temporal bounds all fail closed. */
	public function test_proof_boundary_mismatches_never_consume_the_challenge(): void {
		$challenge = $this->create();
		$row       = $this->database->rows[ $challenge['challenge_id'] ];
		$claims    = $this->claims( $challenge );
		$now       = time();
		self::assertTrue( Challenge::matches( $row, $claims, $now ) );
		foreach ( array( array( 'sub' => '999' ), array( 'nonce' => str_repeat( 'f', 64 ) ), array( 'iat' => $row['created_at'] - 31 ), array( 'iat' => $now + 31 ), array( 'iat' => (string) $now ) ) as $change ) {
			self::assertFalse( Challenge::matches( $row, array_replace( $claims, $change ), $now ) );
			$this->errorIs( 'google_login_invalid_challenge', Challenge::consume( $challenge['challenge_id'], array_replace( $claims, $change ), 'fixture-proof' ) );
		}
		foreach ( array( array( 'user_id' => 7 ), array( 'purpose' => 'reauth' ), array( 'created_at' => $now + 1 ), array( 'expires_at' => $now ), array( 'expires_at' => $row['created_at'] + 301 ) ) as $change ) {
			self::assertFalse( Challenge::matches( array_replace( $row, $change ), $claims, $now ) );
		}
		self::assertSame( 0, $this->database->rows[ $challenge['challenge_id'] ]['verified_at'] );
	}

	/** An authenticated-session row is rejected even if its nonce and subject match. */
	public function test_authenticated_challenge_cannot_be_consumed_as_public_login(): void {
		$challenge = $this->create();
		$this->database->rows[ $challenge['challenge_id'] ]['user_id'] = 7;
		$this->errorIs( 'google_login_invalid_challenge', Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-proof' ) );
		self::assertSame( 0, $this->database->rows[ $challenge['challenge_id'] ]['verified_at'] );
	}

	/** Expired consumed proofs never renew freshness through replay. */
	public function test_expiry_rejects_create_and_consumed_retry_without_extension(): void {
		$challenge = $this->create();
		Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-proof' );
		$this->database->rows[ $challenge['challenge_id'] ]['created_at'] = time() - 301;
		$this->database->rows[ $challenge['challenge_id'] ]['expires_at'] = time() - 1;
		$before = $this->database->rows;
		$this->errorIs( 'google_login_challenge_expired', Challenge::create( self::REQUEST, 'fixture-network', self::SUBJECT ) );
		$this->errorIs( 'google_login_invalid_challenge', Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-proof' ) );
		self::assertSame( $before, $this->database->rows );
	}

	/** Zero affected rows and unavailable storage never count as consumption success. */
	public function test_failed_and_zero_updates_never_report_a_verified_proof(): void {
		$challenge = $this->create();
		foreach ( array( 'failUpdate', 'zeroUpdate' ) as $flag ) {
			$this->database->$flag = true;
			$this->errorIs( 'google_login_not_saved', Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-proof' ) );
			$this->database->$flag = false;
		}
		self::assertSame( 0, $this->database->rows[ $challenge['challenge_id'] ]['verified_at'] );
	}

	/** Lost write acknowledgement is resolved by exact proof replay, never a new grant. */
	public function test_uncertain_consume_recovers_only_same_signed_proof(): void {
		$challenge                     = $this->create();
		$this->database->writeThenFail = true;
		$this->errorIs( 'google_login_not_saved', Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-proof' ) );
		$original                      = $this->database->rows[ $challenge['challenge_id'] ]['verified_at'];
		$this->database->writeThenFail = false;
		$recovered                     = Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'fixture-proof' );
		self::assertSame( $original, $recovered['verified_at'] );
		$this->errorIs( 'google_login_challenge_used', Challenge::consume( $challenge['challenge_id'], $this->claims( $challenge ), 'another-proof' ) );
	}

	/** Malformed requests, failed reads/inserts and lock contention cannot mint a nonce. */
	public function test_creation_failures_and_locks_are_fail_closed(): void {
		foreach ( array( array( 'bad', 'scope', self::SUBJECT ), array( self::REQUEST, '', self::SUBJECT ), array( self::REQUEST, 'scope', 'bad-subject' ) ) as $args ) {
			$this->errorIs( 'google_login_invalid_request', Challenge::create( ...$args ) );
		}
		self::assertSame( 0, $this->database->acquired );
		foreach ( array( 'failRead', 'failInsert' ) as $flag ) {
			$this->database->$flag = true;
			$this->errorIs( 'google_login_not_saved', Challenge::create( self::REQUEST, 'scope', self::SUBJECT ) );
			$this->database->$flag = false;
		}
		$this->database->lock = false;
		$this->errorIs( 'google_login_busy', Challenge::create( self::REQUEST, 'scope', self::SUBJECT ) );
		$this->errorIs( 'google_login_busy', Challenge::consume( str_repeat( 'a', 32 ), array(), 'fixture-proof' ) );
		self::assertSame( array(), $this->database->rows );
	}
}
