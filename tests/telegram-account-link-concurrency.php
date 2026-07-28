<?php
/**
 * Static concurrency and security contract for the account-link integration.
 *
 * Run with:
 * php tests/telegram-account-link-concurrency.php
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	if ( ! headers_sent() ) {
		http_response_code( 404 );
	}
	exit;
}

$source_path    = dirname( __DIR__ ) . '/includes/integrations/class-telegram-account-link.php';
$bootstrap_path = dirname( __DIR__ ) . '/digitalogic.php';
$source         = file_get_contents( $source_path );
$bootstrap      = file_get_contents( $bootstrap_path );
if ( false === $source || false === $bootstrap ) {
	fwrite( STDERR, "Unable to read integration source.\n" );
	exit( 1 );
}

/**
 * Return a bounded method section.
 */
function method_section( string $source, string $method, string $next_method ): string {
	$start = strpos( $source, 'function ' . $method );
	$end   = strpos( $source, 'function ' . $next_method, false === $start ? 0 : $start + 1 );
	if ( '__never__' === $next_method && false !== $start ) {
		$end = strlen( $source );
	}
	if ( false === $start || false === $end || $end <= $start ) {
		throw new RuntimeException( 'Missing method boundary: ' . $method );
	}
	return substr( $source, $start, $end - $start );
}

/**
 * Fail one named contract.
 */
function contract( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "PASS: {$name}\n" );
}

/**
 * Confirm two source fragments exist in the required order.
 */
function ordered( string $source, string $first, string $second ): bool {
	$first_position  = strpos( $source, $first );
	$second_position = strpos( $source, $second );
	return false !== $first_position && false !== $second_position && $first_position < $second_position;
}

$consume       = method_section( $source, 'consume_link', 'account_status' );
$constructor   = method_section( $source, '__construct', 'install' );
$status        = method_section( $source, 'account_status', 'add_account_menu_item' );
$create        = method_section( $source, 'create_link_token', 'revoke_user_link' );
$revoke        = method_section( $source, 'revoke_user_link', 'user_link_state' );
$payload       = method_section( $source, 'eligibility_payload', 'persian_account_error' );
$signed_rate   = method_section( $source, 'enforce_signed_identity_rate', 'hit_rate_bucket' );
$server_secret = method_section( $source, 'server_secret', 'token_hash' );
$verify        = method_section( $source, 'verify_storage_table', '__never__' );

contract(
	str_contains( $source, 'UNIQUE KEY user_pending_slot (user_id,pending_slot)' ),
	'schema enforces one pending token slot per user'
);
contract(
	ordered(
		$create,
		'SELECT user_id FROM $links WHERE user_id = %d FOR UPDATE',
		"SET pending_slot = NULL, status = 'superseded'"
	),
	'issuance locks the stable user row before superseding pending tokens'
);
contract(
	ordered(
		$consume,
		'SELECT user_id FROM $links WHERE user_id = %d FOR UPDATE',
		'SELECT token_hash,user_id,pending_slot,status,expires_at'
	),
	'consume uses the same account-then-token lock order as revoke'
);
contract(
	str_contains( $status, 'START TRANSACTION' )
		&& str_contains( $status, 'telegram_id_hash = %s LIMIT 1 FOR UPDATE' )
		&& str_contains( $status, "if ( false === \$wpdb->query( 'COMMIT' ) )" ),
	'status is linearized on the identity row and fails closed on commit failure'
);
contract(
	ordered(
		$revoke,
		'SELECT telegram_id_hash FROM $links WHERE user_id = %d FOR UPDATE',
		"SET pending_slot = NULL, status = 'revoked'"
	),
	'revoke locks the user row before invalidating tokens'
);
contract(
	substr_count( $source, "'pending_slot' => null" ) >= 2
		&& str_contains( $source, "SET pending_slot = NULL, status = 'superseded'" )
		&& str_contains( $source, "SET pending_slot = NULL, status = 'revoked'" )
		&& str_contains( $source, "SET pending_slot = NULL, status = 'expired'" ),
	'every terminal token path releases the unique pending slot'
);
contract(
	str_contains( $verify, "'Non_unique'" )
		&& str_contains( $verify, "'Seq_in_index'" )
		&& str_contains( $verify, "'Sub_part'" )
		&& str_contains( $verify, "\$actual['Type']" )
		&& str_contains( $verify, "\$actual['Null']" )
		&& str_contains( $verify, "\$actual['Default']" )
		&& str_contains( $verify, "\$actual['Extra']" )
		&& str_contains( $verify, "array_values( \$required['columns'] )" ),
	'installer verifies exact column semantics plus full ordered, non-prefix indexes'
);
contract(
	str_contains( $source, 'wp_privacy_personal_data_exporters' )
		&& str_contains( $source, 'wp_privacy_personal_data_erasers' )
		&& str_contains( $source, "wp_schedule_event( time() + 300, 'hourly'" ),
	'privacy lifecycle and scheduled retention are registered'
);
contract(
	ordered( $constructor, 'add_action( self::CLEANUP_ACTION', 'if ( ! self::woocommerce_ready() )' )
		&& ordered( $constructor, 'if ( ! self::woocommerce_ready() )', "add_action( 'rest_api_init'" )
		&& ordered(
			$bootstrap,
			'Digitalogic_Telegram_Account_Link::instance();',
			"if (!class_exists('WooCommerce'))"
		),
	'lifecycle and privacy hooks survive WooCommerce outages while UI and REST stay gated'
);
contract(
	str_contains( $source, "if ( '1' !== \$installed_version )" )
		&& str_contains( $source, "SET pending_slot = NULL, status = 'superseded'" )
		&& str_contains( $source, "WHERE status = 'pending'" )
		&& str_contains( $consume, "status = 'pending' AND pending_slot = 1" )
		&& str_contains( $consume, "1 !== (int) \$token_row['pending_slot']" ),
	'schema-v1 pending codes are superseded and consume requires the active slot'
);
contract(
	str_contains( $source, 'private const STATUS_RATE_LIMIT    = 60;' )
		&& str_contains( $source, 'private const STATUS_RATE_WINDOW   = 60;' )
		&& str_contains( $source, 'private const CONSUME_RATE_LIMIT   = 10;' )
		&& str_contains( $source, 'private const CONSUME_RATE_WINDOW  = 600;' )
		&& ordered( $consume, "enforce_signed_identity_rate( 'consume'", 'SELECT user_id FROM $tokens' )
		&& ordered( $status, "enforce_signed_identity_rate( 'status'", 'START TRANSACTION' ),
	'signed status and consume limits run before protected-record lookup'
);
contract(
	str_contains( $signed_rate, "'digitalogic_assistant_signed_rate'" )
		&& str_contains( $signed_rate, 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.' )
		&& str_contains( $signed_rate, "array( 'status' => 429 )" )
		&& str_contains( $source, "private_hash( 'rate:signed-status', \$identity_hash )" )
		&& str_contains( $source, "private_hash( 'rate:signed-consume', \$identity_hash )" ),
	'signed rate rejection is Persian HTTP 429 and privacy erasure removes attributable buckets'
);
contract(
	str_contains( $source, 'return ! is_multisite();' ),
	'multisite fails closed until blog-scoped identities exist'
);
contract(
	str_contains( $source, 'private const CUSTOMER_ROLES' )
		&& str_contains( $source, 'private const STAFF_ROLES' )
		&& str_contains( $payload, "'scope'       => 'customer'" )
		&& ! str_contains( $payload, "'staff'" ),
	'eligible roles are explicit and the external projection grants customer scope only'
);
contract(
	! preg_match( '/user_email|user_login|phone|username|roles/', $payload ),
	'eligibility response contains no direct account or role fields'
);
contract(
	str_contains( $source, "private const SERVER_SECRET_FILE   = '/etc/digitalogic/assistant-hmac.secret';" )
		&& ordered( $server_secret, 'defined( self::SERVER_SECRET )', '$path = self::SERVER_SECRET_FILE' )
		&& str_contains( $server_secret, 'is_link( $path )' )
		&& str_contains( $server_secret, '! is_file( $path )' )
		&& str_contains( $server_secret, '! is_readable( $path )' )
		&& str_contains( $server_secret, '@file_get_contents( $path )' )
		&& str_contains( $server_secret, '$secret = trim( $contents )' )
		&& str_contains( $server_secret, "strlen( \$secret ) >= 32 ? \$secret : ''" )
		&& ! str_contains( $server_secret, 'get_option' ),
	'secret fallback is fixed, external, non-symlink, readable, and fail closed'
);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/** @var array<int,string> */
$registered_actions = array();
/** @var array<int,string> */
$registered_filters = array();

function add_action( string $hook ): void {
	global $registered_actions;
	$registered_actions[] = $hook;
}

function add_filter( string $hook ): void {
	global $registered_filters;
	$registered_filters[] = $hook;
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @param array<string,mixed> $data */
		public function __construct(
			private string $code = '',
			private string $message = '',
			private array $data = array()
		) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/** @return array<string,mixed> */
		public function get_error_data(): array {
			return $this->data;
		}
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function wp_salt( string $scheme = 'auth' ): string {
	unset( $scheme );
	return str_repeat( 'test-key-', 8 );
}

require_once $source_path;

Digitalogic_Telegram_Account_Link::instance();
contract(
	in_array( 'deleted_user', $registered_actions, true )
		&& in_array( 'digitalogic_assistant_account_cleanup', $registered_actions, true )
		&& in_array( 'wp_privacy_personal_data_exporters', $registered_filters, true )
		&& in_array( 'wp_privacy_personal_data_erasers', $registered_filters, true )
		&& ! in_array( 'rest_api_init', $registered_actions, true )
		&& ! in_array( 'woocommerce_account_menu_items', $registered_filters, true ),
	'WooCommerce-inactive runtime registers lifecycle/privacy hooks but no UI or REST hooks'
);

/**
 * Minimal database double for exact-index verification.
 */
final class Digitalogic_Assistant_Index_Test_DB {
	public string $prefix = 'wp_';

	/** @var array<int,array<string,mixed>> */
	public array $index_rows;

	/** @var array<int,array<string,mixed>> */
	public array $column_rows;

	/**
	 * @param array<int,array<string,mixed>> $column_rows Column rows.
	 * @param array<int,array<string,mixed>> $index_rows  Index rows.
	 */
	public function __construct( array $column_rows, array $index_rows ) {
		$this->column_rows = $column_rows;
		$this->index_rows  = $index_rows;
	}

	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );
		return $query;
	}

	/** @return array<string,string> */
	public function get_row( string $query, string $output ): array {
		unset( $query, $output );
		return array( 'Engine' => 'InnoDB' );
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		return str_contains( $query, 'SHOW FULL COLUMNS' ) ? $this->column_rows : $this->index_rows;
	}
}

$exact_columns    = array(
	array(
		'Field'   => 'user_id',
		'Type'    => 'bigint(20) unsigned',
		'Null'    => 'NO',
		'Default' => null,
		'Extra'   => '',
	),
	array(
		'Field'   => 'user_id_hash',
		'Type'    => 'char(64)',
		'Null'    => 'NO',
		'Default' => null,
		'Extra'   => '',
	),
);
$exact_rows       = array(
	array(
		'Key_name'     => 'PRIMARY',
		'Column_name'  => 'user_id',
		'Seq_in_index' => 1,
		'Non_unique'   => 0,
		'Sub_part'     => null,
	),
	array(
		'Key_name'     => 'user_identity',
		'Column_name'  => 'user_id_hash',
		'Seq_in_index' => 1,
		'Non_unique'   => 0,
		'Sub_part'     => null,
	),
);
$required_columns = array(
	'user_id'      => array('type' => 'bigint(20) unsigned', 'nullable' => false, 'default' => null, 'extra' => ''),
	'user_id_hash' => array('type' => 'char(64)', 'nullable' => false, 'default' => null, 'extra' => ''),
);
$required_indexes = array(
	'PRIMARY'       => array('unique' => true, 'columns' => array('user_id')),
	'user_identity' => array('unique' => true, 'columns' => array('user_id_hash')),
);
$verify_method    = ( new ReflectionClass( 'Digitalogic_Telegram_Account_Link' ) )->getMethod( 'verify_storage_table' );

$wpdb = new Digitalogic_Assistant_Index_Test_DB( $exact_columns, $exact_rows );
contract(
	true === $verify_method->invoke( null, 'wp_test', $required_columns, $required_indexes ),
	'exact columns and full-length unique indexes pass runtime verification'
);

$non_unique_rows                  = $exact_rows;
$non_unique_rows[1]['Non_unique'] = 1;
$wpdb                             = new Digitalogic_Assistant_Index_Test_DB( $exact_columns, $non_unique_rows );
contract(
	false === $verify_method->invoke( null, 'wp_test', $required_columns, $required_indexes ),
	'a non-unique replacement fails runtime verification'
);

$prefix_rows                = $exact_rows;
$prefix_rows[1]['Sub_part'] = 16;
$wpdb                       = new Digitalogic_Assistant_Index_Test_DB( $exact_columns, $prefix_rows );
contract(
	false === $verify_method->invoke( null, 'wp_test', $required_columns, $required_indexes ),
	'a prefix-only unique index fails runtime verification'
);

$wrong_type            = $exact_columns;
$wrong_type[1]['Type'] = 'char(63)';
$wpdb                  = new Digitalogic_Assistant_Index_Test_DB( $wrong_type, $exact_rows );
contract(
	false === $verify_method->invoke( null, 'wp_test', $required_columns, $required_indexes ),
	'a wrong column length fails runtime verification'
);

$wrong_null            = $exact_columns;
$wrong_null[1]['Null'] = 'YES';
$wpdb                  = new Digitalogic_Assistant_Index_Test_DB( $wrong_null, $exact_rows );
contract(
	false === $verify_method->invoke( null, 'wp_test', $required_columns, $required_indexes ),
	'a wrong column nullability fails runtime verification'
);

$wrong_default               = $exact_columns;
$wrong_default[1]['Default'] = '';
$wpdb                        = new Digitalogic_Assistant_Index_Test_DB( $wrong_default, $exact_rows );
contract(
	false === $verify_method->invoke( null, 'wp_test', $required_columns, $required_indexes ),
	'a wrong column default fails runtime verification'
);

$wrong_extra             = $exact_columns;
$wrong_extra[0]['Extra'] = 'auto_increment';
$wpdb                    = new Digitalogic_Assistant_Index_Test_DB( $wrong_extra, $exact_rows );
contract(
	false === $verify_method->invoke( null, 'wp_test', $required_columns, $required_indexes ),
	'a wrong column extra attribute fails runtime verification'
);

/**
 * Database double for the bounded schema-v1 migration.
 */
final class Digitalogic_Assistant_Migration_Test_DB {
	/** @var array<int,string> */
	public array $queries = array();

	public function __construct( private bool $fail_update = false ) {
	}

	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );
		return $query;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( $this->fail_update && str_contains( $query, 'UPDATE ' ) ) {
			return false;
		}
		return 1;
	}
}

$migration_method = ( new ReflectionClass( 'Digitalogic_Telegram_Account_Link' ) )->getMethod( 'migrate_legacy_tokens' );
$wpdb             = new Digitalogic_Assistant_Migration_Test_DB();
contract(
	true === $migration_method->invoke( null, '1', 'wp_tokens' )
		&& str_contains( implode( "\n", $wpdb->queries ), "SET pending_slot = NULL, status = 'superseded'" )
		&& str_contains( implode( "\n", $wpdb->queries ), "WHERE status = 'pending'" ),
	'schema-v1 migration transaction supersedes every legacy pending token'
);

$wpdb = new Digitalogic_Assistant_Migration_Test_DB();
contract(
	true === $migration_method->invoke( null, '2', 'wp_tokens' ) && array() === $wpdb->queries,
	'current schema skips the legacy migration without a write'
);

$wpdb = new Digitalogic_Assistant_Migration_Test_DB( true );
contract(
	false === $migration_method->invoke( null, '1', 'wp_tokens' )
		&& in_array( 'ROLLBACK', $wpdb->queries, true )
		&& ! in_array( 'COMMIT', $wpdb->queries, true ),
	'a failed legacy migration rolls back and cannot mark schema ready'
);

/**
 * Database double for signed-boundary rate behavior.
 */
final class Digitalogic_Assistant_Rate_Test_DB {
	public string $prefix = 'wp_';

	/** @var array<int,array<int,mixed>> */
	public array $prepared_values = array();

	public function __construct( private int $request_count ) {
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared_values[] = $args;
		return $query;
	}

	public function query( string $query ): int {
		unset( $query );
		return 1;
	}

	/** @return array<string,mixed> */
	public function get_row( string $query, string $output ): array {
		unset( $query, $output );
		return array(
			'window_started' => gmdate( 'Y-m-d H:i:s' ),
			'request_count'  => $this->request_count,
			'blocked_until'  => null,
		);
	}

	/** @param array<string,mixed> $data */
	public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
		unset( $table, $data, $where, $format, $where_format );
		return 1;
	}
}

$rate_method   = ( new ReflectionClass( 'Digitalogic_Telegram_Account_Link' ) )->getMethod( 'enforce_signed_identity_rate' );
$service       = Digitalogic_Telegram_Account_Link::instance();
$identity_hash = hash( 'sha256', 'test-external-identity' );

$wpdb         = new Digitalogic_Assistant_Rate_Test_DB( 61 );
$status_limit = $rate_method->invoke( $service, 'status', $identity_hash );
$prepared     = array_merge( ...$wpdb->prepared_values );
contract(
	$status_limit instanceof WP_Error
		&& 'digitalogic_assistant_signed_rate' === $status_limit->get_error_code()
		&& 429 === ( $status_limit->get_error_data()['status'] ?? 0 )
		&& 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.' === $status_limit->get_error_message()
		&& ! in_array( $identity_hash, $prepared, true ),
	'status limit returns Persian 429 and stores only a re-keyed fingerprint'
);

$wpdb          = new Digitalogic_Assistant_Rate_Test_DB( 11 );
$consume_limit = $rate_method->invoke( $service, 'consume', $identity_hash );
contract(
	$consume_limit instanceof WP_Error
		&& 'digitalogic_assistant_signed_rate' === $consume_limit->get_error_code()
		&& 429 === ( $consume_limit->get_error_data()['status'] ?? 0 ),
	'consume limit returns bounded HTTP 429'
);

$wpdb           = new Digitalogic_Assistant_Rate_Test_DB( 60 );
$status_allowed = $rate_method->invoke( $service, 'status', $identity_hash );
contract(
	true === $status_allowed,
	'status request at the configured limit remains allowed'
);

fwrite( STDOUT, "All account-link concurrency and security contracts passed.\n" );
