<?php

declare(strict_types=1);

namespace Digitalogic\Integrations\Paradox;

use Digitalogic\Pricing\Calculator;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname( __DIR__, 2 ) . '/pricing/Calculator.php';
require_once __DIR__ . '/ReportArithmetic.php';

/** Canonical snapshot reconciliation and Persian HTML reporting; pricing is delegated in-process. */
final class CanonicalProductReport {

	private const MAX_SNAPSHOT_BYTES         = 8388608;
	private const MAX_DECIMAL_INTEGER_DIGITS = 24;
	private const MAX_DECIMAL_SCALE          = 12;

	private const ISSUE_LABELS = array(
		'source_only_positive'                  => 'موجودی مثبت فقط در پاتریس',
		'source_only_nonpositive'               => 'فقط در پاتریس (بدون موجودی مثبت)',
		'woocommerce_only'                      => 'فقط در ووکامرس',
		'duplicate_woocommerce_match'           => 'چند تطبیق ووکامرس برای یک کد',
		'stock_drift'                           => 'مغایرت موجودی',
		'weight_drift'                          => 'مغایرت وزن',
		'unit_drift'                            => 'مغایرت واحد',
		'foreign_price_drift'                   => 'مغایرت قیمت CNY',
		'record_hash_drift'                     => 'مغایرت اثرانگشت رکورد',
		'woocommerce_price_drift'               => 'مغایرت قیمت فروش ووکامرس',
		'canonical_price_meta_drift'            => 'مغایرت متادیتای قیمت نهایی',
		'source_formula_price_drift'            => 'مغایرت قیمت منبع با فرمول',
		'woo_currency_unsupported'              => 'واحد پول فروشگاه پشتیبانی نمی‌شود',
		'woocommerce_missing_code'              => 'نبود کد کالا در ووکامرس',
		'woocommerce_ambiguous_code_meta'       => 'چند مقدار کد کالا برای یک ردیف ووکامرس',
		'woocommerce_publish_status_drift'      => 'وضعیت انتشار نامناسب ووکامرس',
		'woocommerce_stock_status_drift'        => 'وضعیت فروش‌پذیری نامناسب ووکامرس',
		'woocommerce_stock_management_disabled' => 'مدیریت موجودی ووکامرس غیرفعال است',
		'source_warning'                        => 'هشدار ثبت‌شده در منبع',
		'source_shipping_pair_incomplete'       => 'جفت ناقص نرخ و واحد پول حمل',
		'source_shipping_currency_invalid'      => 'واحد پول نامعتبر حمل',
		'source_foreign_currency_invalid'       => 'واحد پول خارجی نامعتبر',
	);

	private const FIELD_LABELS = array(
		'price_source_kind'              => 'مسیر قیمت',
		'price_source_amount'            => 'مقدار قیمت انتخاب‌شده',
		'price_source_currency'          => 'واحد قیمت انتخاب‌شده',
		'price_rounding_digits'          => 'رقم گردکردن',
		'price_rounding_mode'            => 'روش گردکردن',
		'foreign_price'                  => 'قیمت CNY',
		'weight_grams'                   => 'وزن',
		'shipping_price_per_kg'          => 'نرخ حمل هر کیلوگرم',
		'shipping_price_per_kg_currency' => 'واحد پول نرخ حمل',
		'markup_percent'                 => 'حاشیه سود',
		'irt_per_cny'                    => 'نرخ CNY به تومان',
		'unit'                           => 'واحد کالا',
		'record_hash'                    => 'اثر انگشت رکورد',
		'foreign_currency'               => 'واحد پول خارجی',
		'final_price'                    => 'قیمت نهایی',
		'total_stock'                    => 'موجودی کل',
		'shipping_method_id'             => 'روش حمل',
	);

	private const SOURCE_WARNING_LABELS = array(
		'final_price_unavailable' => 'قیمت نهایی به‌دلیل ناقص یا نامعتبر بودن ورودی‌های محاسبه در دسترس نیست.',
		'weight_missing'          => 'وزن کالا در منبع موجود نیست.',
		'negative_total_stock'    => 'موجودی کل منبع منفی است و نیاز به بررسی دارد.',
		'foreign_price_missing'   => 'قیمت خرید CNY در منبع موجود نیست.',
		'weight_ambiguous'        => 'وزن کالا در شرح منبع مبهم است.',
		'foreign_price_ambiguous' => 'قیمت CNY در داده منبع مبهم است.',
	);

	/**
	 * Read a private canonical snapshot after resolving and checking the file.
	 *
	 * @return array{document: array<string, mixed>, provenance: array<string, mixed>}
	 */
	public static function loadSnapshotFile(
		string $path,
		string $forbiddenRoot,
		string $allowedRuntimeRoot,
		string $expectedSourceId,
		string $expectedDataset,
		callable $identityVerifier
	): array {
		if ( ! self::isAbsolutePath( $path ) ) {
			throw new InvalidArgumentException( 'Snapshot path must be absolute.' );
		}

		$resolvedRoot        = realpath( $forbiddenRoot );
		$resolvedRuntimeRoot = realpath( $allowedRuntimeRoot );
		$resolvedPath        = realpath( $path );
		if ( $resolvedRoot === false || $resolvedRuntimeRoot === false || $resolvedPath === false ) {
			throw new RuntimeException( 'Snapshot path, private runtime root, or application root could not be resolved.' );
		}
		if ( self::pathIsInside( $resolvedRuntimeRoot, $resolvedRoot ) ) {
			throw new RuntimeException( 'Private runtime root must be outside the application web root.' );
		}
		if ( self::pathIsInside( $resolvedPath, $resolvedRoot ) ) {
			throw new RuntimeException( 'Snapshot must be stored outside the application web root.' );
		}
		if ( ! self::pathIsInside( $resolvedPath, $resolvedRuntimeRoot ) ) {
			throw new RuntimeException( 'Snapshot must be stored inside the explicit private runtime root.' );
		}
		if ( ! is_file( $resolvedPath ) || ! is_readable( $resolvedPath ) || is_link( $path ) ) {
			throw new RuntimeException( 'Snapshot must be a readable regular file, not a symbolic link.' );
		}

		clearstatcache( true, $resolvedPath );
		$preSize = filesize( $resolvedPath );
		if ( $preSize === false || $preSize < 2 || $preSize > self::MAX_SNAPSHOT_BYTES ) {
			throw new RuntimeException( 'Snapshot size is empty, unavailable, or exceeds the 8 MiB limit.' );
		}

		$handle = fopen( $resolvedPath, 'rb' );
		if ( $handle === false ) {
			throw new RuntimeException( 'Snapshot could not be opened.' );
		}

		try {
			if ( ! flock( $handle, LOCK_SH ) ) {
				throw new RuntimeException( 'Snapshot could not be locked for a consistent read.' );
			}
			$before = fstat( $handle );
			$raw    = stream_get_contents( $handle );
			$after  = fstat( $handle );
			flock( $handle, LOCK_UN );
		} finally {
			fclose( $handle );
		}

		if ( ! is_array( $before ) || ! is_array( $after ) || $raw === false ) {
			throw new RuntimeException( 'Snapshot read did not complete.' );
		}
		if ( (int) ( $before['size'] ?? -1 ) !== $preSize
			|| (int) ( $after['size'] ?? -1 ) !== $preSize
			|| strlen( $raw ) !== $preSize
			|| (int) ( $before['mtime'] ?? -1 ) !== (int) ( $after['mtime'] ?? -2 )
		) {
			throw new RuntimeException( 'Snapshot changed while it was being read.' );
		}

		try {
			$identity = $identityVerifier( $raw );
		} catch ( Throwable $error ) {
			throw new RuntimeException( 'Producer identity verification failed: ' . $error->getMessage(), 0, $error );
		}
		if ( ! is_array( $identity )
			|| ! is_string( $identity['identity_verifier'] ?? null )
			|| trim( $identity['identity_verifier'] ) === ''
		) {
			throw new RuntimeException( 'Producer identity verifier returned no usable provenance.' );
		}

		try {
			$document = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new RuntimeException( 'Snapshot JSON is invalid: ' . $error->getMessage(), 0, $error );
		}
		if ( ! is_array( $document ) ) {
			throw new RuntimeException( 'Snapshot root must be a JSON object.' );
		}
		self::validateSnapshot( $document );
		self::assertSnapshotScope( $document, $expectedSourceId, $expectedDataset );

		return array(
			'document'   => $document,
			'provenance' => array(
				'snapshot_file'         => basename( $resolvedPath ),
				'snapshot_bytes'        => $preSize,
				'snapshot_sha256'       => 'sha256:' . hash( 'sha256', $raw ),
				'identity_verifier'     => $identity['identity_verifier'],
				'identity_verification' => 'passed',
				...array_filter(
					array(
						'identity_verifier_sha256'  => $identity['identity_verifier_sha256'] ?? null,
						'identity_verifier_summary' => $identity['identity_verifier_summary'] ?? null,
					),
					static fn ( mixed $value ): bool => is_string( $value ) && $value !== ''
				),
			),
		);
	}

	/**
	 * Resolve the Patris Export verifier and return a callback for the exact
	 * locked snapshot bytes. The executable must live in the same explicit
	 * private runtime root as the report inputs and outputs.
	 */
	public static function externalIdentityVerifier(
		string $path,
		string $forbiddenRoot,
		string $allowedRuntimeRoot
	): callable {
		if ( ! self::isAbsolutePath( $path ) ) {
			throw new InvalidArgumentException( 'Verifier path must be absolute.' );
		}

		$resolvedRoot        = realpath( $forbiddenRoot );
		$resolvedRuntimeRoot = realpath( $allowedRuntimeRoot );
		$resolvedPath        = realpath( $path );
		if ( $resolvedRoot === false || $resolvedRuntimeRoot === false || $resolvedPath === false ) {
			throw new RuntimeException( 'Verifier path, private runtime root, or application root could not be resolved.' );
		}
		if ( self::pathIsInside( $resolvedRuntimeRoot, $resolvedRoot ) ) {
			throw new RuntimeException( 'Private runtime root must be outside the application web root.' );
		}
		if ( self::pathIsInside( $resolvedPath, $resolvedRoot ) ) {
			throw new RuntimeException( 'Verifier must be stored outside the application web root.' );
		}
		if ( ! self::pathIsInside( $resolvedPath, $resolvedRuntimeRoot ) ) {
			throw new RuntimeException( 'Verifier must be stored inside the explicit private runtime root.' );
		}
		if ( ! is_file( $resolvedPath ) || ! is_readable( $resolvedPath ) || is_link( $path ) ) {
			throw new RuntimeException( 'Verifier must be a readable regular file, not a symbolic link.' );
		}
		if ( DIRECTORY_SEPARATOR !== '\\' && ! is_executable( $resolvedPath ) ) {
			throw new RuntimeException( 'Verifier file is not executable.' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			throw new RuntimeException( 'PHP proc_open is required for producer identity verification.' );
		}

		$hash = hash_file( 'sha256', $resolvedPath );
		if ( ! is_string( $hash ) || strlen( $hash ) !== 64 ) {
			throw new RuntimeException( 'Verifier executable could not be hashed.' );
		}
		$digest = 'sha256:' . $hash;
		return static function ( string $raw ) use ( $resolvedPath, $digest ): array {
			return self::runExternalIdentityVerifier( $resolvedPath, $digest, $raw );
		};
	}

	/** @return array{identity_verifier:string,identity_verifier_sha256:string,identity_verifier_summary:string} */
	private static function runExternalIdentityVerifier( string $executable, string $digest, string $raw ): array {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$process     = proc_open(
			array( $executable, 'verify', '-' ),
			$descriptors,
			$pipes,
			null,
			null,
			array(
				'bypass_shell'    => true,
				'binary_pipes'    => true,
				'suppress_errors' => true,
			)
		);
		if ( ! is_resource( $process ) || count( $pipes ) !== 3 ) {
			throw new RuntimeException( 'Patris Export verifier process could not be started.' );
		}

		try {
			$offset = 0;
			$length = strlen( $raw );
			while ( $offset < $length ) {
				$written = fwrite( $pipes[0], substr( $raw, $offset, 65536 ) );
				if ( $written === false || $written === 0 ) {
					throw new RuntimeException( 'Patris Export verifier stopped reading the snapshot.' );
				}
				$offset += $written;
			}
			fclose( $pipes[0] );
			unset( $pipes[0] );

			$stdout = stream_get_contents( $pipes[1], 32769 );
			$stderr = stream_get_contents( $pipes[2], 32769 );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			unset( $pipes[1], $pipes[2] );
			$exitCode = proc_close( $process );
			$process  = null;

			if ( $stdout === false || $stderr === false ) {
				throw new RuntimeException( 'Patris Export verifier output could not be read.' );
			}
			if ( strlen( $stdout ) > 32768 || strlen( $stderr ) > 32768 ) {
				throw new RuntimeException( 'Patris Export verifier output exceeded the safety limit.' );
			}
			if ( $exitCode !== 0 ) {
				$message = trim( $stderr ) !== '' ? trim( $stderr ) : trim( $stdout );
				throw new RuntimeException( 'Patris Export rejected the snapshot' . ( $message !== '' ? ': ' . $message : '.' ) );
			}
			$summary = trim( $stdout );
			if ( ! str_starts_with( $summary, 'valid snapshot:' ) ) {
				throw new RuntimeException( 'Patris Export verifier returned an unexpected success response.' );
			}

			return array(
				'identity_verifier'         => basename( $executable ) . ' verify -',
				'identity_verifier_sha256'  => $digest,
				'identity_verifier_summary' => $summary,
			);
		} finally {
			foreach ( $pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			if ( is_resource( $process ) ) {
				proc_terminate( $process );
				proc_close( $process );
			}
		}
	}

	/** @param array<string, mixed> $document */
	public static function validateSnapshot( array $document ): void {
		$allowedEnvelopeKeys = array(
			'schema',
			'event_type',
			'event_id',
			'local_currency',
			'formula_id',
			'source',
			'generated_at',
			'products',
			'categories',
			'excluded_codes',
			'deleted_codes',
			'quarantined_codes',
			'warnings',
		);
		foreach ( array_keys( $document ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowedEnvelopeKeys, true ) ) {
				throw new InvalidArgumentException( 'Snapshot contains an unsupported top-level selector.' );
			}
		}
		if ( ( $document['schema'] ?? null ) !== 'patris.product-sync' ) {
			throw new InvalidArgumentException( 'Snapshot schema must be patris.product-sync.' );
		}
		if ( ( $document['event_type'] ?? null ) !== 'snapshot' ) {
			throw new InvalidArgumentException( 'Report input must be a complete snapshot event.' );
		}
		if ( ! is_string( $document['event_id'] ?? null )
			|| preg_match( '/^sha256:[a-f0-9]{64}$/D', $document['event_id'] ) !== 1
		) {
			throw new InvalidArgumentException( 'Snapshot event_id must be a SHA-256 identity.' );
		}
		if ( ( $document['local_currency'] ?? null ) !== 'IRT' ) {
			throw new InvalidArgumentException( 'Digitalogic report input must use local_currency IRT.' );
		}
		if ( ( $document['formula_id'] ?? null ) !== 'landed_price' ) {
			throw new InvalidArgumentException( 'Digitalogic report input must use the living landed_price formula.' );
		}
		if ( ! is_array( $document['source'] ?? null )
			|| ! is_string( $document['source']['id'] ?? null )
			|| ! is_string( $document['source']['dataset'] ?? null )
			|| trim( $document['source']['id'] ) === ''
			|| trim( $document['source']['dataset'] ) === ''
			|| trim( $document['source']['id'] ) !== $document['source']['id']
			|| trim( $document['source']['dataset'] ) !== $document['source']['dataset']
			|| strlen( $document['source']['id'] ) > 191
			|| strlen( $document['source']['dataset'] ) > 191
			|| ! is_string( $document['source']['revision'] ?? null )
			|| preg_match( '/^sha256:[a-f0-9]{64}$/D', $document['source']['revision'] ) !== 1
		) {
			throw new InvalidArgumentException( 'Snapshot source id, dataset, and SHA-256 revision must be explicit and valid.' );
		}
		if ( ! is_string( $document['generated_at'] ?? null )
			|| preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $document['generated_at'] ) !== 1
		) {
			throw new InvalidArgumentException( 'Snapshot generated_at must be an explicit UTC RFC 3339 timestamp.' );
		}
		if ( ! array_key_exists( 'products', $document ) || ! is_array( $document['products'] ) || ! array_is_list( $document['products'] ) ) {
			throw new InvalidArgumentException( 'Snapshot products must be a JSON list.' );
		}

		$seenCodes = array();
		foreach ( $document['products'] as $index => $product ) {
			if ( ! is_array( $product ) ) {
				throw new InvalidArgumentException( 'Snapshot product at index ' . $index . ' must be an object.' );
			}
			$code = $product['product_code'] ?? null;
			if ( ! is_string( $code ) || $code === '' || trim( $code ) !== $code || strlen( $code ) > 191 ) {
				throw new InvalidArgumentException( 'Every product must have an exact, non-empty product_code string.' );
			}
			$identity = 'code:' . $code;
			if ( array_key_exists( $identity, $seenCodes ) ) {
				throw new InvalidArgumentException( 'Snapshot contains duplicate product_code: ' . $code . '.' );
			}
			$seenCodes[ $identity ] = true;
		}
	}

	/** @param array<string, mixed> $document */
	public static function assertSnapshotScope( array $document, string $expectedSourceId, string $expectedDataset ): void {
		if ( $expectedSourceId === '' || $expectedDataset === '' ) {
			throw new InvalidArgumentException( 'Expected source id and dataset must be explicit.' );
		}
		$source = is_array( $document['source'] ?? null ) ? $document['source'] : array();
		if ( ! isset( $source['id'], $source['dataset'] )
			|| ! is_string( $source['id'] )
			|| ! is_string( $source['dataset'] )
			|| ! hash_equals( $expectedSourceId, $source['id'] )
			|| ! hash_equals( $expectedDataset, $source['dataset'] )
		) {
			throw new InvalidArgumentException( 'Snapshot source scope does not match the explicitly expected site scope.' );
		}
	}

	/**
	 * Read only products carrying the exact Digitalogic Code metadata key.
	 * Variable parents are deliberately absent; their variations remain eligible.
	 *
	 * @return array{rows: list<array<string, mixed>>, store: array<string, string>}
	 */
	public static function fetchWooProducts( PDO $db, string $prefix ): array {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ) {
			throw new InvalidArgumentException( 'WordPress table prefix is invalid.' );
		}

		$posts             = $prefix . 'posts';
		$postmeta          = $prefix . 'postmeta';
		$terms             = $prefix . 'terms';
		$termTaxonomy      = $prefix . 'term_taxonomy';
		$termRelationships = $prefix . 'term_relationships';
		$options           = $prefix . 'options';

		$meta = array(
			'unit'                  => '_digitalogic_patris_unit',
			'foreign_price'         => '_digitalogic_patris_foreign_price',
			'weight_grams'          => '_digitalogic_patris_weight_grams',
			'source_total_stock'    => '_digitalogic_patris_total_stock',
			'canonical_final_price' => '_digitalogic_patris_final_price',
			'record_hash'           => '_digitalogic_patris_record_hash',
			'stock'                 => '_stock',
			'price'                 => '_price',
			'regular_price'         => '_regular_price',
			'sale_price'            => '_sale_price',
			'weight'                => '_weight',
			'manage_stock'          => '_manage_stock',
			'stock_status'          => '_stock_status',
		);

		$select     = array();
		$quotedKeys = array();
		foreach ( $meta as $alias => $key ) {
			$quoted       = $db->quote( $key );
			$quotedKeys[] = $quoted;
			$select[]     = 'MAX(CASE WHEN pm.meta_key = ' . $quoted . ' THEN pm.meta_value END) AS ' . $alias;
			$select[]     = 'MAX(CASE WHEN pm.meta_key = ' . $quoted . ' THEN 1 ELSE 0 END) AS ' . $alias . '_present';
		}

		$variableParent = "(p.post_type = 'product' AND EXISTS ("
			. 'SELECT 1 FROM ' . $termRelationships . ' tr_parent'
			. ' INNER JOIN ' . $termTaxonomy . ' tt_parent ON tt_parent.term_taxonomy_id = tr_parent.term_taxonomy_id'
			. ' INNER JOIN ' . $terms . ' t_parent ON t_parent.term_id = tt_parent.term_id'
			. " WHERE tr_parent.object_id = p.ID AND tt_parent.taxonomy = 'product_type' AND t_parent.slug = 'variable'))";

		$sql = 'SELECT p.ID AS woo_id, p.post_type, p.post_parent, p.post_status, p.post_title AS woo_name, '
			. 'CASE WHEN ' . $variableParent . ' THEN 1 ELSE 0 END AS is_variable_parent, '
			. "MAX(NULLIF(identity.meta_value, '')) AS product_code, "
			. "COUNT(DISTINCT CASE WHEN identity.meta_value <> '' THEN BINARY identity.meta_value END) AS product_code_value_count, "
			. implode( ', ', $select )
			. ' FROM ' . $posts . ' p'
			. ' LEFT JOIN ' . $postmeta . ' identity ON identity.post_id = p.ID'
			. " AND identity.meta_key = '_digitalogic_patris_product_code'"
			. ' LEFT JOIN ' . $postmeta . ' pm ON pm.post_id = p.ID AND pm.meta_key IN (' . implode( ', ', $quotedKeys ) . ')'
			. " WHERE p.post_type IN ('product', 'product_variation')"
			. " AND p.post_status NOT IN ('trash', 'auto-draft')"
			. ' GROUP BY p.ID, p.post_type, p.post_parent, p.post_status, p.post_title'
			. ' ORDER BY product_code, p.ID';

		$rows = $db->query( $sql )->fetchAll( PDO::FETCH_ASSOC );

		$optionStatement = $db->prepare(
			'SELECT option_name, option_value FROM ' . $options
			. " WHERE option_name IN ('woocommerce_currency', 'woocommerce_weight_unit')"
		);
		$optionStatement->execute();
		$settings = array(
			'currency'    => '',
			'weight_unit' => '',
		);
		foreach ( $optionStatement->fetchAll( PDO::FETCH_ASSOC ) as $option ) {
			if ( ( $option['option_name'] ?? '' ) === 'woocommerce_currency' ) {
				$settings['currency'] = strtoupper( trim( (string) ( $option['option_value'] ?? '' ) ) );
			} elseif ( ( $option['option_name'] ?? '' ) === 'woocommerce_weight_unit' ) {
				$settings['weight_unit'] = strtolower( trim( (string) ( $option['option_value'] ?? '' ) ) );
			}
		}

		foreach ( $rows as &$row ) {
			$row['woo_id']                   = (int) $row['woo_id'];
			$row['is_variable_parent']       = (int) ( $row['is_variable_parent'] ?? 0 ) === 1;
			$row['product_code_value_count'] = (int) ( $row['product_code_value_count'] ?? 0 );
			if ( $row['product_code_value_count'] !== 1 || ! is_string( $row['product_code'] ) || $row['product_code'] === '' ) {
				unset( $row['product_code'] );
			}
			$row['store_currency']    = $settings['currency'];
			$row['store_weight_unit'] = $settings['weight_unit'];
			$actualPriceIrt           = self::wooPriceToIrt( $row['price'] ?? null, $settings['currency'] );
			if ( $actualPriceIrt === null ) {
				unset( $row['actual_price_irt'] );
			} else {
				$row['actual_price_irt'] = $actualPriceIrt;
			}
			if ( ( ! isset( $row['weight_grams'] ) || $row['weight_grams'] === '' ) && isset( $row['weight'] ) && $row['weight'] !== '' ) {
				$weightGrams = self::storeWeightToGrams( $row['weight'], $settings['weight_unit'] );
				if ( $weightGrams === null ) {
					unset( $row['weight_grams'] );
					$row['weight_grams_present'] = 0;
				} else {
					$row['weight_grams']         = $weightGrams;
					$row['weight_grams_present'] = 1;
				}
			}
		}
		unset( $row );

		if ( ! in_array( $settings['currency'], array( 'IRT', 'IRR' ), true ) ) {
			throw new RuntimeException( 'WooCommerce currency option is missing or unsupported; price comparison cannot proceed safely.' );
		}
		if ( ! in_array( $settings['weight_unit'], array( 'g', 'kg', 'lbs', 'oz' ), true ) ) {
			throw new RuntimeException( 'WooCommerce weight-unit option is missing or unsupported; weight comparison cannot proceed safely.' );
		}

		return array(
			'rows'  => array_values( $rows ),
			'store' => $settings,
		);
	}

	/**
	 * @param array<string, mixed>       $snapshot
	 * @param list<array<string, mixed>> $wooRows
	 * @param array<string, mixed>       $provenance
	 * @param array<string, string>      $store
	 * @return array<string, mixed>
	 */
	public static function analyze( array $snapshot, array $wooRows, array $provenance = array(), array $store = array() ): array {
		self::validateSnapshot( $snapshot );
		$sourceProducts          = $snapshot['products'];
		$eligibleWoo             = array();
		$codedWoo                = array();
		$excludedVariableParents = 0;
		foreach ( $wooRows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ( $row['is_variable_parent'] ?? false ) === true ) {
				++$excludedVariableParents;
				continue;
			}
			if ( array_key_exists( 'product_code', $row ) && is_scalar( $row['product_code'] ) ) {
				$code = (string) $row['product_code'];
				if ( $code !== '' && (int) ( $row['product_code_value_count'] ?? 1 ) === 1 ) {
					$row['product_code'] = $code;
					$codedWoo[]          = $row;
				}
			}
			$eligibleWoo[] = $row;
		}

		$wooByCode = array();
		foreach ( $codedWoo as $row ) {
			$wooByCode[ $row['product_code'] ][] = $row;
		}
		$sourceByCode = array();
		foreach ( $sourceProducts as $product ) {
			$sourceByCode[ $product['product_code'] ][] = $product;
		}

		$reconciliation     = array();
		$warningRows        = array();
		$priceRows          = array();
		$sourceOnly         = array();
		$wooOnly            = array();
		$matchedSourceCount = 0;
		$duplicateCodes     = 0;
		$warningProductKeys = array();

		foreach ( $sourceProducts as $sourceIndex => $product ) {
			$code          = $product['product_code'];
			$matches       = $wooByCode[ $code ] ?? array();
			$sourceIssues  = self::sourceIssues( $product );
			$expectedPrice = self::expectedPrice( $product );

			foreach ( $sourceIssues as $issue ) {
				$warningRows[]                                  = self::warningRow( $code, null, $issue );
				$warningProductKeys[ 'source:' . $sourceIndex ] = true;
			}

			if ( $matches === array() ) {
				$positive         = self::numericGreaterThanZero( self::fieldState( $product, 'total_stock' ) );
				$issue            = array(
					'code'     => $positive ? 'source_only_positive' : 'source_only_nonpositive',
					'message'  => $positive
						? 'این کد با موجودی مثبت در منبع وجود دارد اما در ووکامرس تطبیق دقیقی ندارد.'
						: 'این کد فقط در منبع است و موجودی مثبت ندارد.',
					'severity' => $positive ? 'error' : 'info',
				);
				$issues           = array_merge( $sourceIssues, array( $issue ) );
				$row              = self::reconciliationRow( $product, null, $issues, $positive ? 'source-only-positive' : 'source-only' );
				$reconciliation[] = $row;
				$sourceOnly[]     = $row;
				$warningRows[]    = self::warningRow( $code, null, $issue );
				$warningProductKeys[ 'source:' . $sourceIndex ] = true;
			} else {
				++$matchedSourceCount;
				if ( count( $matches ) > 1 ) {
					++$duplicateCodes;
				}
				foreach ( $matches as $match ) {
					$matchIssues = array();
					if ( count( $matches ) > 1 ) {
						$matchIssues[] = array(
							'code'     => 'duplicate_woocommerce_match',
							'message'  => 'برای این کد ' . count( $matches ) . ' محصول/تنوع ووکامرس پیدا شد؛ هیچ تطبیقی حذف نشده است.',
							'severity' => 'error',
						);
					}
					$matchIssues      = array_merge( $matchIssues, self::driftIssues( $product, $match, $expectedPrice ) );
					$issues           = array_merge( $sourceIssues, $matchIssues );
					$status           = $issues === array() ? 'ok' : ( count( $matches ) > 1 ? 'duplicate' : 'warning' );
					$row              = self::reconciliationRow( $product, $match, $issues, $status );
					$reconciliation[] = $row;
					foreach ( $matchIssues as $issue ) {
						$warningRows[]                                  = self::warningRow( $code, $match['woo_id'] ?? null, $issue );
						$warningProductKeys[ 'source:' . $sourceIndex ] = true;
					}
				}
			}

			$priceHasDrift = false;
			foreach ( $matches as $match ) {
				if ( self::driftIssues( $product, $match, $expectedPrice ) !== array() ) {
					$priceHasDrift = true;
					break;
				}
			}

			$wooPrices = array();
			foreach ( $matches as $match ) {
				$wooPrices[] = self::wooPriceEntry( $match, $expectedPrice );
			}
			$priceRow = array(
				'product_code'                   => $code,
				'name'                           => self::valueOrBlank( $product, 'name' ),
				'price_source_kind'              => self::fieldState( $product, 'price_source_kind' ),
				'price_source_amount'            => self::fieldState( $product, 'price_source_amount' ),
				'price_source_currency'          => self::fieldState( $product, 'price_source_currency' ),
				'price_rounding_digits'          => self::fieldState( $product, 'price_rounding_digits' ),
				'foreign_price'                  => self::fieldState( $product, 'foreign_price' ),
				'weight_grams'                   => self::fieldState( $product, 'weight_grams' ),
				'shipping_price_per_kg'          => self::fieldState( $product, 'shipping_price_per_kg' ),
				'shipping_price_per_kg_currency' => self::fieldState( $product, 'shipping_price_per_kg_currency' ),
				'markup_percent'                 => self::fieldState( $product, 'markup_percent' ),
				'irt_per_cny'                    => self::fieldState( $product, 'irt_per_cny' ),
				'source_final_price'             => self::fieldState( $product, 'final_price' ),
				'woo_prices'                     => $wooPrices,
				'status'                         => $matches === array()
					? 'source-only'
					: ( ( $sourceIssues !== array() || count( $matches ) > 1 || $priceHasDrift ) ? 'warning' : 'matched' ),
			);
			if ( $expectedPrice !== null ) {
				$priceRow['expected_final_price'] = $expectedPrice;
			}
			$priceRows[] = $priceRow;
		}

		foreach ( $eligibleWoo as $row ) {
			$code = (string) ( $row['product_code'] ?? '' );
			if ( $code === '' ) {
				$ambiguous        = (int) ( $row['product_code_value_count'] ?? 0 ) > 1;
				$issue            = array(
					'code'     => $ambiguous ? 'woocommerce_ambiguous_code_meta' : 'woocommerce_missing_code',
					'message'  => $ambiguous
						? 'برای این محصول/تنوع بیش از یک مقدار متادیتای کد کالا ثبت شده و تطبیق قطعی ممکن نیست.'
						: 'این محصول/تنوع ووکامرس کد کالا ندارد و با منبع قابل تطبیق نیست.',
					'severity' => $ambiguous ? 'error' : 'warning',
				);
				$reportRow        = self::reconciliationRow( null, $row, array( $issue ), 'woo-only' );
				$reconciliation[] = $reportRow;
				$wooOnly[]        = $reportRow;
				$warningRows[]    = self::warningRow( '', $row['woo_id'] ?? null, $issue );
				$warningProductKeys[ 'woo:' . (string) ( $row['woo_id'] ?? 'missing-code' ) ] = true;
				$priceRows[] = self::wooOnlyPriceRow( $row );
				continue;
			}
			if ( array_key_exists( $code, $sourceByCode ) ) {
				continue;
			}
			$issue            = array(
				'code'     => 'woocommerce_only',
				'message'  => 'کد کالای ووکامرس در snapshot منبع وجود ندارد.',
				'severity' => 'warning',
			);
			$reportRow        = self::reconciliationRow( null, $row, array( $issue ), 'woo-only' );
			$reconciliation[] = $reportRow;
			$wooOnly[]        = $reportRow;
			$warningRows[]    = self::warningRow( $code, $row['woo_id'] ?? null, $issue );
			$warningProductKeys[ 'woo:' . (string) ( $row['woo_id'] ?? $code ) ] = true;
			$priceRows[] = self::wooOnlyPriceRow( $row );
		}

		usort(
			$reconciliation,
			static function ( array $left, array $right ): int {
				$code = strcmp( (string) $left['product_code'], (string) $right['product_code'] );
				return $code !== 0 ? $code : ( (int) ( $left['woo_id'] ?? 0 ) <=> (int) ( $right['woo_id'] ?? 0 ) );
			}
		);
		usort(
			$warningRows,
			static fn ( array $left, array $right ): int => strcmp(
				(string) $left['product_code'] . ':' . (string) $left['issue_code'],
				(string) $right['product_code'] . ':' . (string) $right['issue_code']
			)
		);

		$positiveSourceOnly = count(
			array_filter(
				$sourceOnly,
				static fn ( array $row ): bool => $row['status'] === 'source-only-positive'
			)
		);
		$driftRows          = count(
			array_filter(
				$reconciliation,
				static function ( array $row ): bool {
					foreach ( $row['issues'] as $issue ) {
						if ( str_ends_with( (string) $issue['code'], '_drift' ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);

		$source           = is_array( $snapshot['source'] ?? null ) ? $snapshot['source'] : array();
		$sourceWarnings   = is_array( $snapshot['warnings'] ?? null ) ? array_values( $snapshot['warnings'] ) : array();
		$excludedCodes    = is_array( $snapshot['excluded_codes'] ?? null ) ? array_values( $snapshot['excluded_codes'] ) : array();
		$quarantinedCodes = is_array( $snapshot['quarantined_codes'] ?? null ) ? array_values( $snapshot['quarantined_codes'] ) : array();
		$reportProvenance = array_merge(
			$provenance,
			array(
				'source_id'           => self::scalarOrBlank( $source['id'] ?? '' ),
				'source_dataset'      => self::scalarOrBlank( $source['dataset'] ?? '' ),
				'source_revision'     => self::scalarOrBlank( $source['revision'] ?? '' ),
				'event_id'            => self::scalarOrBlank( $snapshot['event_id'] ?? '' ),
				'source_generated_at' => self::scalarOrBlank( $snapshot['generated_at'] ?? '' ),
				'report_generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'store_currency'      => $store['currency'] ?? self::firstRowValue( $eligibleWoo, 'store_currency', '' ),
				'store_weight_unit'   => $store['weight_unit'] ?? self::firstRowValue( $eligibleWoo, 'store_weight_unit', '' ),
				'local_currency'      => self::scalarOrBlank( $snapshot['local_currency'] ?? '' ),
				'formula_id'          => self::scalarOrBlank( $snapshot['formula_id'] ?? '' ),
			)
		);

		return array(
			'summary'          => array(
				'source_products'           => count( $sourceProducts ),
				'woocommerce_products'      => count( $eligibleWoo ),
				'matched_source_products'   => $matchedSourceCount,
				'source_only'               => count( $sourceOnly ),
				'source_only_positive'      => $positiveSourceOnly,
				'woocommerce_only'          => count( $wooOnly ),
				'duplicate_match_codes'     => $duplicateCodes,
				'warning_products'          => count( $warningProductKeys ),
				'warning_entries'           => count( $warningRows ),
				'drift_rows'                => $driftRows,
				'excluded_variable_parents' => $excludedVariableParents,
				'source_envelope_warnings'  => count( $sourceWarnings ),
				'excluded_source_codes'     => count( $excludedCodes ),
				'quarantined_source_codes'  => count( $quarantinedCodes ),
			),
			'provenance'       => $reportProvenance,
			'source_outcomes'  => array(
				'warnings'          => $sourceWarnings,
				'excluded_codes'    => $excludedCodes,
				'quarantined_codes' => $quarantinedCodes,
			),
			'warnings'         => $warningRows,
			'reconciliation'   => $reconciliation,
			'price_list'       => $priceRows,
			'source_only'      => $sourceOnly,
			'woocommerce_only' => $wooOnly,
		);
	}

	/** @return array{kind: string, value?: mixed} */
	public static function fieldState( array $row, string $field ): array {
		if ( ! array_key_exists( $field, $row ) ) {
			return array( 'kind' => 'missing' );
		}
		if ( $row[ $field ] === null ) {
			return array(
				'kind'  => 'null',
				'value' => null,
			);
		}
		return array(
			'kind'  => 'value',
			'value' => $row[ $field ],
		);
	}

	/** Evaluate an explicit canonical row. Missing or invalid inputs are never filled by report metadata. */
	public static function evaluatePrice( array $product ): array {

		return ( new Calculator() )->evaluate( $product );
	}

	/**
	 * Render the existing living report without reinterpreting it as a signed snapshot.
	 * Identity, filters, source selection and warning counts remain owned by Report_Engine.
	 */
	public static function renderCurrentReport( array $report ): string {

		if ( ! is_array( $report['rows'] ?? null ) || ! is_array( $report['counts'] ?? null ) ) {
			throw new InvalidArgumentException( 'Current report rows and counts are required.' );
		}
		$source = is_array( $report['source'] ?? null ) ? $report['source'] : array();
		$html   = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">' . '<meta name="viewport" content="width=device-width,initial-scale=1">' . '<title>گزارش محصولات دیجیتالاجیک</title>' . self::reportStyles() . '</head><body><main class="shell"><header class="hero"><div><h1>گزارش محصولات و قیمت‌ها</h1>' . '<p>تطبیق جاری پاتریس و ووکامرس با قیمت مرجع مشترک</p></div></header>';
		$html  .= '<section class="panel"><div class="panel-title"><h2>منشأ گزارش</h2></div><dl class="provenance">';
		foreach ( array(
			'وضعیت'             => $report['status'] ?? '',
			'نمای گزارش'        => $report['view'] ?? '',
			'دسته انتخاب‌شده'   => $report['filters']['category'] ?? '',
			'شناسه گزارش'       => $report['snapshot_revision'] ?? '',
			'زمان گزارش'        => $report['generated_at'] ?? '',
			'شناسه منبع'        => $source['id'] ?? '',
			'مجموعه‌داده'       => $source['dataset'] ?? '',
			'بازبینی منبع'      => $source['revision'] ?? '',
			'آخرین رویداد منبع' => $source['last_event_id'] ?? '',
			'نوع آخرین رویداد'  => $source['last_event_type'] ?? '',
		) as $label => $value ) {
			$html .= '<div><dt>' . self::h( $label ) . '</dt><dd>' . self::h( $value ) . '</dd></div>';
		}
		$html .= '</dl></section><section class="cards">';
		foreach ( array(
			'patris_products'        => 'محصولات پاتریس',
			'woocommerce_products'   => 'ردیف‌های ووکامرس',
			'matched_products'       => 'تطبیق‌شده',
			'warning_products'       => 'ردیف‌های دارای هشدار',
			'drift_products'         => 'ردیف‌های دارای مغایرت',
			'unsafe_identity_groups' => 'گروه‌های هویتی نیازمند بررسی',
		) as $key => $label ) {
			$html .= '<article class="card"><span>' . self::h( $label ) . '</span><strong>' . self::h( self::displayScalar( $report['counts'][ $key ] ?? null ) ) . '</strong></article>';
		}
		$html .= '</section>';
		if ( ! empty( $report['refresh_deferred'] ) || ! empty( $report['limits']['source_truncated'] ) || ! empty( $report['limits']['woocommerce_truncated'] ) || ( $report['integrity']['status'] ?? 'current' ) !== 'current' ) {
			$html .= '<section class="panel"><div class="panel-title"><p>این گزارش محدودیت تازگی، کامل‌بودن یا یکپارچگی دارد؛ نتیجه کامل و تأییدشده نیست.</p></div></section>';
		}
		foreach ( $report['integrity']['warnings'] ?? array() as $warning ) {
			$detail = is_array( $warning ) ? implode( ' — ', array_filter( array( $warning['code'] ?? '', $warning['message'] ?? '' ), 'is_string' ) ) : ( is_scalar( $warning ) ? (string) $warning : '' );
			$html  .= '<section class="panel"><div class="panel-title"><p>' . self::h( $detail ) . '</p></div></section>';
		}
		$labels = array();
		foreach ( $report['categories'] ?? array() as $category ) {
			$labels[ $category['key'] ] = $category['title'];
		}
		$html .= '<section class="panel"><div class="panel-title"><h2>ردیف‌های نمای انتخاب‌شده</h2><span class="count">' . count( $report['rows'] ) . '</span></div><div class="table-wrap"><table><thead><tr>' . '<th>کد کالا</th><th>نام</th><th>وضعیت</th><th>مسیر قیمت</th><th>قیمت مرجع (تومان)</th>' . '<th>قیمت منبع (تومان)</th><th>قیمت فعال ووکامرس (ارز فروشگاه)</th><th>یافته‌های گزارش</th></tr></thead><tbody>';
		foreach ( $report['rows'] as $row ) {
			$product   = is_array( $row['source'] ?? null ) ? $row['source'] : array();
			$woo       = is_array( $row['woocommerce'] ?? null ) ? $row['woocommerce'] : array();
			$reference = '—';
			if ( $product !== array() ) {
				try {
					$evaluation = self::evaluatePrice( $product );
					$reference  = $evaluation['available'] ? self::displayScalar( $evaluation['value'] ) : 'ورودی قیمت ناقص است';
				} catch ( InvalidArgumentException ) {
					$reference = 'ورودی قیمت نامعتبر است';
				}
			}
			$issues = array_map( static fn ( $issue ): string => (string) ( $labels[ $issue ] ?? $issue ), $row['issues'] ?? array() );
			$cells  = array( $row['product_code'] ?? '', $row['name'] ?? $row['woo_name'] ?? '', $row['status'] ?? '', self::stateText( self::fieldState( $product, 'price_source_kind' ) ), $reference, self::stateText( self::fieldState( $product, 'final_price' ) ), self::stateText( self::fieldState( $woo, 'active_price' ) ), implode( '؛ ', $issues ) );
			$html  .= '<tr>' . implode( '', array_map( static fn ( $cell ): string => '<td>' . self::h( $cell ) . '</td>', $cells ) ) . '</tr>';
		}
		return $html . '</tbody></table></div></section></main></body></html>';
	}
	/** @param array<string, mixed> $report */
	public static function renderHtml( array $report ): string {
		$summary    = $report['summary'];
		$provenance = $report['provenance'];
		$html       = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>گزارش تطبیق محصولات دیجیتالوجیک</title>'
			. self::reportStyles()
			. '</head><body><main class="shell">'
			. '<header class="hero"><div><span class="eyebrow">DIGITALOGIC · PATRIS</span>'
			. '<h1>گزارش تطبیق محصولات و قیمت‌ها</h1>'
			. '<p>بازبینی خواندنیِ snapshot پاتریس در برابر مقادیر واقعی ووکامرس، با تطبیق دقیق کد کالا</p></div>'
			. '<div class="hero-mark">DL</div></header>';

		$cards = array(
			array( 'محصولات منبع', $summary['source_products'], 'blue' ),
			array( 'ردیف‌های ووکامرس', $summary['woocommerce_products'], 'blue' ),
			array( 'تطبیق‌شده', $summary['matched_source_products'], 'green' ),
			array( 'فقط منبع با موجودی مثبت', $summary['source_only_positive'], 'red' ),
			array( 'فقط ووکامرس', $summary['woocommerce_only'], 'amber' ),
			array( 'کدهای چندتطبیقی', $summary['duplicate_match_codes'], 'red' ),
			array( 'ردیف‌های دارای مغایرت', $summary['drift_rows'], 'amber' ),
			array( 'کل هشدارها', $summary['warning_entries'], 'red' ),
			array( 'کدهای حذف‌شده منبع', $summary['excluded_source_codes'], 'amber' ),
			array( 'کدهای قرنطینه منبع', $summary['quarantined_source_codes'], 'red' ),
			array( 'هشدارهای سراسری منبع', $summary['source_envelope_warnings'], 'amber' ),
		);
		$html .= '<section class="cards">';
		foreach ( $cards as [$label, $value, $tone] ) {
			$html .= '<article class="card ' . self::h( $tone ) . '"><span>' . self::h( $label ) . '</span><strong>'
				. self::h( self::formatNumber( $value ) ) . '</strong></article>';
		}
		$html .= '</section>';

		$provenanceLabels = array(
			'snapshot_file'       => 'فایل ورودی',
			'snapshot_sha256'     => 'اثر انگشت فایل',
			'snapshot_bytes'      => 'اندازه فایل (بایت)',
			'source_id'           => 'شناسه منبع',
			'source_dataset'      => 'مجموعه‌داده',
			'source_revision'     => 'بازبینی منبع',
			'event_id'            => 'شناسه رویداد',
			'source_generated_at' => 'زمان تولید منبع',
			'report_generated_at' => 'زمان تولید گزارش (UTC)',
			'store_currency'      => 'واحد پول فروشگاه',
			'store_weight_unit'   => 'واحد وزن فروشگاه',
			'local_currency'      => 'واحد پول محلی منبع',
			'formula_id'          => 'شناسه فرمول جاری',
		);
		$html            .= '<section class="panel"><div class="panel-title"><h2>منشأ و قابلیت ردیابی</h2></div><dl class="provenance">';
		foreach ( $provenanceLabels as $key => $label ) {
			$html .= '<div><dt>' . self::h( $label ) . '</dt><dd>' . self::h( self::displayScalar( $provenance[ $key ] ?? '' ) ) . '</dd></div>';
		}
		$html .= '</dl></section>';

		$html .= self::renderSourceOutcomes( $report['source_outcomes'] );

		$html .= '<section class="toolbar"><label>جست‌وجو <input id="report-search" type="search" placeholder="کد، نام، شناسه یا هشدار…"></label>'
			. '<label>وضعیت <select id="report-status"><option value="">همه</option><option value="ok">بدون مغایرت</option>'
			. '<option value="warning">هشدار</option><option value="duplicate">چندتطبیقی</option>'
			. '<option value="source-only-positive">فقط منبع با موجودی</option><option value="source-only">فقط منبع</option>'
			. '<option value="woo-only">فقط ووکامرس</option></select></label>'
			. '<span id="visible-count" class="result-count"></span></section>';

		$html .= self::renderWarnings( $report['warnings'] );
		$html .= self::renderReconciliation( $report['reconciliation'] );
		$html .= self::renderPriceList( $report['price_list'] );
		$html .= '<footer>گزارش فقط‌خواندنی دیجیتالوجیک · تطبیق بر اساس متادیتای canonical کد کالا · هیچ داده‌ای در وردپرس تغییر نکرده است.</footer>';
		$html .= self::reportScript() . '</main></body></html>';
		return $html;
	}

	public static function writeHtml(
		string $path,
		string $html,
		string $forbiddenRoot,
		string $allowedRuntimeRoot,
		bool $overwrite = false
	): string {
		if ( ! self::isAbsolutePath( $path ) ) {
			throw new InvalidArgumentException( 'Output path must be absolute.' );
		}
		if ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== 'html' ) {
			throw new InvalidArgumentException( 'Output file must use the .html extension.' );
		}
		$resolvedRoot        = realpath( $forbiddenRoot );
		$resolvedRuntimeRoot = realpath( $allowedRuntimeRoot );
		$resolvedDirectory   = realpath( dirname( $path ) );
		if ( $resolvedRoot === false || $resolvedRuntimeRoot === false || $resolvedDirectory === false || ! is_dir( $resolvedDirectory ) ) {
			throw new RuntimeException( 'Output directory, private runtime root, or application root could not be resolved.' );
		}
		if ( self::pathIsInside( $resolvedRuntimeRoot, $resolvedRoot ) ) {
			throw new RuntimeException( 'Private runtime root must be outside the application web root.' );
		}
		if ( self::pathIsInside( $resolvedDirectory, $resolvedRoot ) ) {
			throw new RuntimeException( 'Report output must be stored outside the application web root.' );
		}
		if ( ! self::pathIsInside( $resolvedDirectory, $resolvedRuntimeRoot ) ) {
			throw new RuntimeException( 'Report output must be stored inside the explicit private runtime root.' );
		}
		$target = $resolvedDirectory . DIRECTORY_SEPARATOR . basename( $path );
		if ( file_exists( $target ) && ( ! $overwrite || is_link( $target ) || ! is_file( $target ) ) ) {
			throw new RuntimeException( 'Output already exists or is unsafe; choose a new path or pass --overwrite.' );
		}

		$temporary = tempnam( $resolvedDirectory, '.digitalogic-report-' );
		if ( $temporary === false ) {
			throw new RuntimeException( 'Could not create a temporary report file.' );
		}
		try {
			if ( file_put_contents( $temporary, $html, LOCK_EX ) !== strlen( $html ) ) {
				throw new RuntimeException( 'Report output was incomplete.' );
			}
			@chmod( $temporary, 0600 );
			if ( file_exists( $target ) && DIRECTORY_SEPARATOR === '\\' ) {
				if ( ! unlink( $target ) ) {
					throw new RuntimeException( 'Existing report could not be replaced.' );
				}
			}
			if ( ! rename( $temporary, $target ) ) {
				throw new RuntimeException( 'Report could not be moved into place.' );
			}
		} finally {
			if ( is_file( $temporary ) ) {
				unlink( $temporary );
			}
		}
		return $target;
	}

	/** @return list<array{code: string, message: string, severity: string}> */
	private static function sourceIssues( array $product ): array {
		$issues        = array();
		$positiveStock = self::numericGreaterThanZero( self::fieldState( $product, 'total_stock' ) );
		$required      = array(
			'total_stock' => 'موجودی کل',
			'unit'        => 'واحد کالا',
			'record_hash' => 'اثر انگشت رکورد',
		);
		foreach ( $required as $field => $label ) {
			$state = self::fieldState( $product, $field );
			if ( $state['kind'] !== 'value' ) {
				$issues[] = array(
					'code'     => 'source_' . $state['kind'] . '_' . $field,
					'message'  => 'مقدار «' . $label . '» در منبع ' . ( $state['kind'] === 'null' ? 'null صریح است.' : 'موجود نیست.' ),
					'severity' => 'warning',
				);
			}
		}
		try {
			$evaluation = self::evaluatePrice( $product );
			foreach ( $evaluation['missing'] as $field ) {
				$state    = self::fieldState( $product, $field );
				$kind     = $state['kind'] === 'value' ? 'invalid' : $state['kind'];
				$issues[] = array(
					'code'     => 'source_' . $kind . '_' . $field,
					'message'  => 'ورودی «' . ( self::FIELD_LABELS[ $field ] ?? $field ) . '» برای مسیر قیمت انتخاب‌شده کامل و معتبر نیست.',
					'severity' => $positiveStock ? 'error' : 'warning',
				);
			}
		} catch ( InvalidArgumentException $error ) {
			$issues[] = array(
				'code'     => 'source_invalid_pricing_input',
				'message'  => 'ورودی محاسبه قیمت انتخاب‌شده نامعتبر است: ' . $error->getMessage(),
				'severity' => 'error',
			);
		}

		$stockState = self::fieldState( $product, 'total_stock' );
		if ( $stockState['kind'] === 'value' && ! self::signedNumeric( $stockState['value'] ) ) {
			$issues[] = array(
				'code'     => 'source_invalid_total_stock',
				'message'  => 'موجودی کل باید یک عدد ده‌دهی محدود و معتبر باشد.',
				'severity' => 'error',
			);
		}
		foreach ( array(
			'unit'               => 'واحد کالا',
			'shipping_method_id' => 'روش حمل',
		) as $field => $label ) {
			$state = self::fieldState( $product, $field );
			if ( $state['kind'] === 'value' && ( ! is_string( $state['value'] ) || trim( $state['value'] ) === '' ) ) {
				$issues[] = array(
					'code'     => 'source_invalid_' . $field,
					'message'  => 'مقدار «' . $label . '» نباید خالی باشد.',
					'severity' => 'error',
				);
			}
		}

		$shippingAmount   = self::fieldState( $product, 'shipping_price_per_kg' );
		$shippingCurrency = self::fieldState( $product, 'shipping_price_per_kg_currency' );
		if ( ( $shippingAmount['kind'] === 'value' ) xor ( $shippingCurrency['kind'] === 'value' ) ) {
			$issues[] = array(
				'code'     => 'source_shipping_pair_incomplete',
				'message'  => 'مقدار نرخ حمل و واحد پول آن باید به‌صورت یک جفت موجود باشند.',
				'severity' => 'error',
			);
		}
		if ( $shippingCurrency['kind'] === 'value' && ! in_array( $shippingCurrency['value'], array( 'CNY', 'IRR' ), true ) ) {
			$issues[] = array(
				'code'     => 'source_shipping_currency_invalid',
				'message'  => 'واحد پول حمل باید دقیقاً CNY یا IRR باشد.',
				'severity' => 'error',
			);
		}
		$foreignCurrency = self::fieldState( $product, 'foreign_currency' );
		if ( ( $product['price_source_kind'] ?? '' ) === 'foreign_price'
			&& $foreignCurrency['kind'] === 'value' && $foreignCurrency['value'] !== 'CNY'
		) {
			$issues[] = array(
				'code'     => 'source_foreign_currency_invalid',
				'message'  => 'واحد قیمت خارجی ثبت‌شده باید CNY باشد.',
				'severity' => 'error',
			);
		}

		$expected   = self::expectedPrice( $product );
		$finalState = self::fieldState( $product, 'final_price' );
		if ( $expected !== null ) {
			if ( $finalState['kind'] === 'missing' ) {
				$issues[] = array(
					'code'     => 'source_missing_final_price',
					'message'  => 'با وجود کامل بودن ورودی‌های فرمول، کلید قیمت نهایی موجود نیست.',
					'severity' => 'error',
				);
			} elseif ( $finalState['kind'] === 'null' ) {
				$issues[] = array(
					'code'     => 'source_null_final_price',
					'message'  => 'با وجود کامل بودن ورودی‌های فرمول، قیمت نهایی null صریح است.',
					'severity' => 'error',
				);
			} elseif ( ! self::numericEqual( $expected, $finalState['value'] ) ) {
				$issues[] = array(
					'code'     => 'source_formula_price_drift',
					'message'  => 'قیمت نهایی منبع با محاسبه مرجع قیمت یکسان نیست.',
					'severity' => 'error',
				);
			}
		}

		if ( isset( $product['warnings'] ) && is_array( $product['warnings'] ) ) {
			foreach ( $product['warnings'] as $warning ) {
				if ( is_scalar( $warning ) && (string) $warning !== '' ) {
					$technicalCode = (string) $warning;
					$issues[]      = array(
						'code'           => 'source_warning',
						'technical_code' => $technicalCode,
						'message'        => self::SOURCE_WARNING_LABELS[ $technicalCode ] ?? ( 'هشدار فنی منبع با کد «' . $technicalCode . '» ثبت شده است.' ),
						'severity'       => 'warning',
					);
				}
			}
		}
		return $issues;
	}

	private static function expectedPrice( array $product ): ?int {
		try {
			$result = self::evaluatePrice( $product );
			return $result['available'] ? $result['value'] : null;
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/** @return array<string, mixed> */
	private static function wooPriceEntry( array $woo, ?int $expectedPrice ): array {
		$entry = array( 'woo_id' => $woo['woo_id'] );
		if ( array_key_exists( 'actual_price_irt', $woo ) ) {
			$entry['active_price_irt'] = $woo['actual_price_irt'];
			if ( $expectedPrice !== null ) {
				$difference = self::numericDifference( $woo['actual_price_irt'], $expectedPrice );
				if ( $difference !== null ) {
					$entry['difference_irt'] = $difference;
				}
			}
		}
		foreach ( array(
			'regular_price' => 'regular_price_irt',
			'sale_price'    => 'sale_price_irt',
		) as $sourceField => $targetField ) {
			if ( (int) ( $woo[ $sourceField . '_present' ] ?? 0 ) !== 1 ) {
				continue;
			}
			$price = self::wooPriceToIrt( $woo[ $sourceField ] ?? null, (string) ( $woo['store_currency'] ?? '' ) );
			if ( $price !== null ) {
				$entry[ $targetField ] = $price;
			}
		}
		return $entry;
	}

	/** @return array<string, mixed> */
	private static function wooOnlyPriceRow( array $woo ): array {
		return array(
			'product_code'                   => (string) ( $woo['product_code'] ?? '' ),
			'name'                           => self::scalarOrBlank( $woo['woo_name'] ?? '' ),
			'foreign_price'                  => array( 'kind' => 'missing' ),
			'weight_grams'                   => array( 'kind' => 'missing' ),
			'shipping_price_per_kg'          => array( 'kind' => 'missing' ),
			'shipping_price_per_kg_currency' => array( 'kind' => 'missing' ),
			'markup_percent'                 => array( 'kind' => 'missing' ),
			'irt_per_cny'                    => array( 'kind' => 'missing' ),
			'source_final_price'             => array( 'kind' => 'missing' ),
			'woo_prices'                     => array( self::wooPriceEntry( $woo, null ) ),
			'status'                         => 'woo-only',
		);
	}

	/** @return list<array{code: string, message: string, severity: string}> */
	private static function driftIssues( array $source, array $woo, ?int $expectedPrice ): array {
		$issues      = array();
		$comparisons = array(
			array( 'total_stock', 'stock', 'stock_drift', true ),
			array( 'weight_grams', 'weight_grams', 'weight_drift', true ),
			array( 'unit', 'unit', 'unit_drift', false ),
			array( 'foreign_price', 'foreign_price', 'foreign_price_drift', true ),
			array( 'record_hash', 'record_hash', 'record_hash_drift', false ),
		);
		foreach ( $comparisons as [$sourceField, $wooField, $issueCode, $numeric] ) {
			$sourceState = self::fieldState( $source, $sourceField );
			if ( $sourceState['kind'] !== 'value' ) {
				continue;
			}
			$wooState = self::wooFieldState( $woo, $wooField );
			$equal    = $wooState['kind'] === 'value'
				&& ( $numeric
					? self::numericEqual( $sourceState['value'], $wooState['value'] )
					: hash_equals( (string) $sourceState['value'], (string) $wooState['value'] ) );
			if ( ! $equal ) {
				$issues[] = array(
					'code'     => $issueCode,
					'message'  => self::ISSUE_LABELS[ $issueCode ] . ': مقدار منبع «'
						. self::displayScalar( $sourceState['value'] ) . '» و مقدار ووکامرس «'
						. self::stateText( $wooState ) . '» است.',
					'severity' => 'warning',
				);
			}
		}

		if ( $expectedPrice !== null ) {
			$actual = $woo['actual_price_irt'] ?? null;
			if ( $actual === null && isset( $woo['store_currency'] ) && ! in_array( $woo['store_currency'], array( 'IRT', 'IRR' ), true ) ) {
				$issues[] = array(
					'code'     => 'woo_currency_unsupported',
					'message'  => 'قیمت ووکامرس با واحد پول ' . (string) $woo['store_currency'] . ' قابل تبدیل مطمئن به تومان نیست.',
					'severity' => 'error',
				);
			} elseif ( ! self::numericEqual( $expectedPrice, $actual ) ) {
				$issues[] = array(
					'code'     => 'woocommerce_price_drift',
					'message'  => 'قیمت محاسبه‌شده ' . self::formatNumber( $expectedPrice ) . ' تومان و قیمت فروش ووکامرس ' . self::displayScalar( $actual ) . ' تومان است.',
					'severity' => 'error',
				);
			}
			$canonicalPrice = self::wooFieldState( $woo, 'canonical_final_price' );
			if ( $canonicalPrice['kind'] !== 'value' || ! self::numericEqual( $expectedPrice, $canonicalPrice['value'] ) ) {
				$issues[] = array(
					'code'     => 'canonical_price_meta_drift',
					'message'  => 'متادیتای canonical قیمت نهایی ووکامرس با محاسبه مستقل یکسان نیست.',
					'severity' => 'warning',
				);
			}
		}

		$sourceStock = self::fieldState( $source, 'total_stock' );
		if ( $sourceStock['kind'] === 'value' && self::signedNumeric( $sourceStock['value'] ) ) {
			$positiveStock = self::signedNumericGreaterThanZero( $sourceStock['value'] );
			$postStatus    = (string) ( $woo['post_status'] ?? '' );
			if ( $positiveStock && $postStatus !== 'publish' ) {
				$issues[] = array(
					'code'     => 'woocommerce_publish_status_drift',
					'message'  => 'موجودی منبع مثبت است اما وضعیت انتشار ووکامرس «' . self::displayScalar( $postStatus ) . '» است.',
					'severity' => 'warning',
				);
			}
			$stockStatus         = self::wooFieldState( $woo, 'stock_status' );
			$expectedStockStatus = $positiveStock ? 'instock' : 'outofstock';
			if ( $stockStatus['kind'] !== 'value' || $stockStatus['value'] !== $expectedStockStatus ) {
				$issues[] = array(
					'code'     => 'woocommerce_stock_status_drift',
					'message'  => 'وضعیت مورد انتظار فروش‌پذیری «' . $expectedStockStatus . '» و وضعیت ووکامرس «' . self::stateText( $stockStatus ) . '» است.',
					'severity' => 'warning',
				);
			}
			$manageStock = self::wooFieldState( $woo, 'manage_stock' );
			if ( $positiveStock && ( $manageStock['kind'] !== 'value' || $manageStock['value'] !== 'yes' ) ) {
				$issues[] = array(
					'code'     => 'woocommerce_stock_management_disabled',
					'message'  => 'موجودی منبع مثبت است اما مدیریت موجودی ووکامرس فعال نیست.',
					'severity' => 'warning',
				);
			}
		}
		return $issues;
	}

	/** @return array<string, mixed> */
	private static function reconciliationRow( ?array $source, ?array $woo, array $issues, string $status ): array {
		$code = $source['product_code'] ?? $woo['product_code'] ?? '';
		$row  = array(
			'product_code'     => (string) $code,
			'source_name'      => $source === null ? '' : self::valueOrBlank( $source, 'name' ),
			'woo_name'         => $woo === null ? '' : self::scalarOrBlank( $woo['woo_name'] ?? '' ),
			'post_type'        => $woo === null ? '' : self::scalarOrBlank( $woo['post_type'] ?? '' ),
			'post_parent'      => $woo === null ? '' : self::scalarOrBlank( $woo['post_parent'] ?? '' ),
			'post_status'      => $woo === null ? '' : self::scalarOrBlank( $woo['post_status'] ?? '' ),
			'source_stock'     => $source === null ? array( 'kind' => 'missing' ) : self::fieldState( $source, 'total_stock' ),
			'woo_stock'        => $woo === null ? array( 'kind' => 'missing' ) : self::wooFieldState( $woo, 'stock' ),
			'source_weight'    => $source === null ? array( 'kind' => 'missing' ) : self::fieldState( $source, 'weight_grams' ),
			'woo_weight'       => $woo === null ? array( 'kind' => 'missing' ) : self::wooFieldState( $woo, 'weight_grams' ),
			'source_unit'      => $source === null ? array( 'kind' => 'missing' ) : self::fieldState( $source, 'unit' ),
			'woo_unit'         => $woo === null ? array( 'kind' => 'missing' ) : self::wooFieldState( $woo, 'unit' ),
			'source_price'     => $source === null ? array( 'kind' => 'missing' ) : self::fieldState( $source, 'final_price' ),
			'source_hash'      => $source === null ? array( 'kind' => 'missing' ) : self::fieldState( $source, 'record_hash' ),
			'woo_hash'         => $woo === null ? array( 'kind' => 'missing' ) : self::wooFieldState( $woo, 'record_hash' ),
			'woo_manage_stock' => $woo === null ? array( 'kind' => 'missing' ) : self::wooFieldState( $woo, 'manage_stock' ),
			'woo_stock_status' => $woo === null ? array( 'kind' => 'missing' ) : self::wooFieldState( $woo, 'stock_status' ),
			'status'           => $status,
			'issues'           => $issues,
		);
		if ( $woo !== null && array_key_exists( 'woo_id', $woo ) ) {
			$row['woo_id'] = $woo['woo_id'];
		}
		if ( $woo !== null && array_key_exists( 'actual_price_irt', $woo ) ) {
			$row['woo_price_irt'] = $woo['actual_price_irt'];
		}
		return $row;
	}

	/** @return array<string, mixed> */
	private static function warningRow( string $code, mixed $wooId, array $issue ): array {
		$issueCode = (string) ( $issue['code'] ?? 'warning' );
		$label     = self::issueLabel( $issueCode );
		$row       = array(
			'product_code' => $code,
			'issue_code'   => $issueCode,
			'label'        => $label,
			'message'      => (string) ( $issue['message'] ?? '' ),
			'severity'     => (string) ( $issue['severity'] ?? 'warning' ),
		);
		if ( $wooId !== null ) {
			$row['woo_id'] = $wooId;
		}
		if ( isset( $issue['technical_code'] ) && is_scalar( $issue['technical_code'] ) ) {
			$row['technical_code'] = (string) $issue['technical_code'];
		}
		return $row;
	}

	/** @return array{kind: string, value?: mixed} */
	private static function wooFieldState( array $row, string $field ): array {
		$presentKey = $field . '_present';
		if ( array_key_exists( $presentKey, $row ) && (int) $row[ $presentKey ] !== 1 ) {
			return array( 'kind' => 'missing' );
		}
		return self::fieldState( $row, $field );
	}

	private static function renderSourceOutcomes( array $outcomes ): string {
		$groups = array(
			'excluded_codes'    => array( 'کدهای کنارگذاشته‌شده منبع', 'این کدها طبق طبقه‌بندی منبع محصول قابل عرضه محسوب نشده‌اند.' ),
			'quarantined_codes' => array( 'کدهای قرنطینه‌شده منبع', 'رکوردهای مبهم تا رفع ابهام وارد فهرست محصولات نشده‌اند.' ),
			'warnings'          => array( 'هشدارهای سراسری منبع', 'هشدارهایی که به کل snapshot یا یک کد کنارگذاشته‌شده مربوط‌اند.' ),
		);
		$html   = '<section class="panel"><div class="panel-title"><div><h2>خروجی‌های طبقه‌بندی منبع</h2><p>حذف و قرنطینه نیز بخشی از حسابرسی هستند و پنهان نمی‌شوند.</p></div></div><div class="outcome-grid">';
		foreach ( $groups as $key => [$title, $description] ) {
			$values = is_array( $outcomes[ $key ] ?? null ) ? $outcomes[ $key ] : array();
			$html  .= '<article><h3>' . self::h( $title ) . ' <span class="count">' . self::h( self::formatNumber( count( $values ) ) ) . '</span></h3><p>' . self::h( $description ) . '</p>';
			if ( $values === array() ) {
				$html .= '<div class="muted">موردی ثبت نشده است.</div>';
			} else {
				$html .= '<div class="chips">';
				foreach ( $values as $value ) {
					$text  = is_scalar( $value )
						? (string) $value
						: json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
					$html .= '<code>' . self::h( $text ) . '</code>';
				}
				$html .= '</div>';
			}
			$html .= '</article>';
		}
		return $html . '</div></section>';
	}

	private static function renderWarnings( array $rows ): string {
		$html = '<section class="panel"><div class="panel-title"><div><h2>هشدارها و موارد نیازمند توجه</h2><p>نبود کلید با null صریح یکی نیست و جداگانه گزارش می‌شود.</p></div><span class="count">' . self::formatNumber( count( $rows ) ) . '</span></div>';
		if ( $rows === array() ) {
			return $html . '<div class="empty">هشداری پیدا نشد.</div></section>';
		}
		$html .= '<div class="table-wrap"><table><thead><tr><th>کد کالا</th><th>شناسه ووکامرس</th><th>نوع / کد فنی</th><th>شرح</th><th>شدت</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$search = implode( ' ', array_map( array( self::class, 'displayScalar' ), $row ) );
			$html  .= '<tr data-status="warning" data-search="' . self::h( $search ) . '"><td class="code">' . self::h( self::displayScalar( $row['product_code'] ) ) . '</td>'
				. '<td>' . self::h( self::displayScalar( $row['woo_id'] ?? null ) ) . '</td><td>' . self::h( $row['label'] )
				. ( isset( $row['technical_code'] ) ? '<br><code>' . self::h( $row['technical_code'] ) . '</code>' : '' ) . '</td>'
				. '<td>' . self::h( $row['message'] ) . '</td><td><span class="severity ' . self::h( $row['severity'] ) . '">' . self::h( self::severityLabel( $row['severity'] ) ) . '</span></td></tr>';
		}
		return $html . '</tbody></table></div></section>';
	}

	private static function renderReconciliation( array $rows ): string {
		$html = '<section class="panel"><div class="panel-title"><div><h2>تطبیق کامل محصولات</h2><p>والدهای متغیر کنار گذاشته شده‌اند؛ هر تنوع و هر تطبیق تکراری در ردیف مستقل حفظ شده است.</p></div><span class="count">' . self::formatNumber( count( $rows ) ) . '</span></div>'
			. '<div class="table-wrap"><table><thead><tr><th>وضعیت</th><th>کد کالا</th><th>محصول منبع</th><th>شناسه / محصول ووکامرس</th><th>انتشار / فروش‌پذیری / کنترل موجودی</th><th>موجودی منبع / وو</th><th>وزن منبع / وو (g)</th><th>واحد منبع / وو</th><th>قیمت منبع / وو (تومان)</th><th>موارد</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$issueLabels = array_map( static fn ( array $issue ): string => self::issueLabel( (string) $issue['code'] ), $row['issues'] );
			$search      = implode( ' ', array( $row['product_code'], $row['source_name'], $row['woo_id'] ?? '', $row['woo_name'], implode( ' ', $issueLabels ) ) );
			$html       .= '<tr data-status="' . self::h( $row['status'] ) . '" data-search="' . self::h( $search ) . '">'
				. '<td><span class="status ' . self::h( $row['status'] ) . '">' . self::h( self::statusLabel( $row['status'] ) ) . '</span></td>'
				. '<td class="code">' . self::h( self::displayScalar( $row['product_code'] ) ) . '</td><td>' . self::h( $row['source_name'] ) . '</td>'
				. '<td><b>' . self::h( self::displayScalar( $row['woo_id'] ?? null ) ) . '</b><br><span class="muted">' . self::h( $row['woo_name'] ) . '</span><br><span class="muted">' . self::h( $row['post_type'] . ( $row['post_parent'] !== '' ? ' / parent #' . $row['post_parent'] : '' ) ) . '</span></td>'
				. '<td>' . self::h( self::displayScalar( $row['post_status'] ) ) . '<br><span class="muted">' . self::h( self::stateText( $row['woo_stock_status'] ) ) . ' / ' . self::h( self::stateText( $row['woo_manage_stock'] ) ) . '</span></td>'
				. '<td>' . self::h( self::stateText( $row['source_stock'] ) ) . '<br><span class="muted">' . self::h( self::stateText( $row['woo_stock'] ) ) . '</span></td>'
				. '<td>' . self::h( self::stateText( $row['source_weight'] ) ) . '<br><span class="muted">' . self::h( self::stateText( $row['woo_weight'] ) ) . '</span></td>'
				. '<td>' . self::h( self::stateText( $row['source_unit'] ) ) . '<br><span class="muted">' . self::h( self::stateText( $row['woo_unit'] ) ) . '</span></td>'
				. '<td>' . self::h( self::stateText( $row['source_price'] ) ) . '<br><span class="muted">' . self::h( self::displayScalar( $row['woo_price_irt'] ?? null ) ) . '</span></td>'
				. '<td>' . self::h( $issueLabels === array() ? '—' : implode( '، ', $issueLabels ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></div></section>';
	}

	private static function renderPriceList( array $rows ): string {
		$html = '<section class="panel"><div class="panel-title"><div><h2>فهرست قیمت و ورودی‌های محاسبه</h2><p>قیمت نهایی بر اساس مسیر انتخاب‌شده و سیاست گردکردن همان ردیف به تومان محاسبه می‌شود.</p></div><span class="count">' . self::formatNumber( count( $rows ) ) . '</span></div>'
			. '<div class="formula"><b>روش محاسبه:</b> قیمت خارجی شامل بهای کالا و حمل با واحد پول مشخص است. قیمت همکار از ریال به تومان تبدیل و سود اعمال می‌شود. این دو مسیر فقط یک‌بار در پایان، طبق رقم گردکردن ردیف، نیم‌به‌بالا گرد می‌شوند. فروش مستقیم فقط با تبدیل دقیق به تومان صحیح پذیرفته می‌شود.</div>'
			. '<div class="table-wrap"><table><thead><tr><th>کد کالا</th><th>نام</th><th>منبع قیمت / مقدار / ارز</th><th>وزن g</th><th>حمل / ارز</th><th>سود %</th><th>نرخ CNY (تومان)</th><th>قیمت محاسبه‌شده</th><th>قیمت منبع</th><th>قیمت‌های ووکامرس</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$wooPrices = array();
			foreach ( $row['woo_prices'] as $price ) {
				$parts = array( '#' . self::displayScalar( $price['woo_id'] ) );
				foreach ( array(
					'active_price_irt'  => 'فعال',
					'regular_price_irt' => 'عادی',
					'sale_price_irt'    => 'ویژه',
					'difference_irt'    => 'اختلاف فعال−محاسبه',
				) as $key => $label ) {
					if ( array_key_exists( $key, $price ) ) {
						$parts[] = $label . ': ' . self::displayScalar( $price[ $key ] );
					}
				}
				$wooPrices[] = implode( '؛ ', $parts );
			}
			$shipping = self::stateText( $row['shipping_price_per_kg'] ) . ' / ' . self::stateText( $row['shipping_price_per_kg_currency'] );
			$search   = implode( ' ', array( $row['product_code'], $row['name'], $shipping, implode( ' ', $wooPrices ) ) );
			$html    .= '<tr data-status="' . self::h( $row['status'] === 'matched' ? 'ok' : $row['status'] ) . '" data-search="' . self::h( $search ) . '">'
				. '<td class="code">' . self::h( $row['product_code'] ) . '</td><td>' . self::h( $row['name'] ) . '</td>'
				. '<td>' . self::h( implode( ' / ', array_map( static fn ( string $field ): string => self::stateText( $row[ $field ] ?? array( 'kind' => 'missing' ) ), array( 'price_source_kind', 'price_source_amount', 'price_source_currency' ) ) ) ) . '</td><td>' . self::h( self::stateText( $row['weight_grams'] ) ) . '</td>'
				. '<td>' . self::h( $shipping ) . '</td><td>' . self::h( self::stateText( $row['markup_percent'] ) ) . '</td>'
				. '<td>' . self::h( self::stateText( $row['irt_per_cny'] ) ) . '</td><td><b>' . self::h( self::displayScalar( $row['expected_final_price'] ?? null ) ) . '</b></td>'
				. '<td>' . self::h( self::stateText( $row['source_final_price'] ) ) . '</td><td>' . self::h( $wooPrices === array() ? '—' : implode( ' | ', $wooPrices ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></div></section>';
	}

	private static function reportStyles(): string {
		$fontStyles = '<style>';
		foreach ( array(
			500 => 'Vazir-Medium-FD.woff2',
			700 => 'Vazir-Bold-FD.woff2',
		) as $weight => $file ) {
			$path = __DIR__ . '/fonts/' . $file;
			if ( is_file( $path ) && is_readable( $path ) && filesize( $path ) <= 131072 ) {
				$fontStyles .= "@font-face{font-family:'Vazir Embedded';font-style:normal;font-weight:{$weight};font-display:swap;src:url(data:font/woff2;base64,"
					. base64_encode( (string) file_get_contents( $path ) ) . ') format(\'woff2\')}';
			}
		}
		$fontStyles .= '</style>';
		return $fontStyles . <<<'HTML'
<style>
:root{--ink:#15233b;--muted:#64748b;--line:#dbe4ef;--paper:#fff;--bg:#f2f6fb;--blue:#0069a8;--blue2:#00a4c7;--green:#087f5b;--amber:#b65b00;--red:#b42318;--shadow:0 14px 38px rgba(20,48,82,.09)}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top right,#dff5fb 0,transparent 28rem),var(--bg);color:var(--ink);font-family:'Vazir Embedded',Vazirmatn,Vazir,Yekan Bakh,Tahoma,Arial,sans-serif;line-height:1.75}.shell{max-width:1540px;margin:auto;padding:30px}.hero{display:flex;align-items:center;justify-content:space-between;gap:30px;padding:42px;border-radius:28px;background:linear-gradient(135deg,#052f54,#007ea7 65%,#00a8c6);color:#fff;box-shadow:var(--shadow)}.hero h1{font-size:clamp(28px,4vw,48px);line-height:1.3;margin:8px 0}.hero p{margin:0;color:#dcf7ff}.eyebrow{font:700 12px/1.2 Arial;letter-spacing:2px;color:#a8edff}.hero-mark{width:96px;height:96px;border:1px solid rgba(255,255,255,.4);border-radius:28px;display:grid;place-items:center;font:bold 28px Arial;background:rgba(255,255,255,.12)}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:20px 0}.card{background:var(--paper);border:1px solid var(--line);border-radius:18px;padding:19px 22px;box-shadow:0 6px 18px rgba(20,48,82,.04);border-top:4px solid var(--blue)}.card span{display:block;color:var(--muted);font-size:13px}.card strong{display:block;margin-top:7px;font-size:30px;font-variant-numeric:tabular-nums}.card.green{border-top-color:var(--green)}.card.amber{border-top-color:var(--amber)}.card.red{border-top-color:var(--red)}.panel{background:var(--paper);border:1px solid var(--line);border-radius:22px;margin:18px 0;box-shadow:var(--shadow);overflow:hidden}.panel-title{padding:22px 25px 15px;display:flex;align-items:center;justify-content:space-between;gap:20px}.panel-title h2{margin:0;font-size:20px}.panel-title p{margin:2px 0 0;color:var(--muted);font-size:13px}.count{min-width:46px;padding:4px 12px;border-radius:999px;background:#e5f4fa;color:var(--blue);text-align:center;font-weight:800}.provenance{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:var(--line);border-top:1px solid var(--line);margin:0}.provenance div{background:#fff;padding:14px 20px;min-width:0}.provenance dt{color:var(--muted);font-size:12px}.provenance dd{direction:ltr;text-align:right;margin:2px 0 0;font:13px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.toolbar{position:sticky;top:10px;z-index:4;display:flex;align-items:end;gap:14px;padding:14px 18px;margin:22px 0;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}.toolbar label{display:grid;gap:4px;color:var(--muted);font-size:12px}.toolbar input,.toolbar select{font:inherit;color:var(--ink);background:#fff;border:1px solid #c7d5e5;border-radius:11px;padding:9px 12px;min-width:250px;outline:none}.toolbar input:focus,.toolbar select:focus{border-color:var(--blue2);box-shadow:0 0 0 3px rgba(0,164,199,.12)}.result-count{margin-right:auto;color:var(--muted)}.table-wrap{overflow:auto;border-top:1px solid var(--line)}table{width:100%;border-collapse:collapse;min-width:1080px;font-size:13px}th,td{text-align:right;vertical-align:top;padding:11px 13px;border-bottom:1px solid #e8eef5}th{position:sticky;top:0;background:#edf5fa;color:#25425c;white-space:nowrap;z-index:2}tbody tr:hover{background:#f8fbfd}.code{direction:ltr;text-align:left;font:600 12px/1.8 ui-monospace,SFMono-Regular,Consolas,monospace}.muted{color:var(--muted)}.status,.severity{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}.status.ok{background:#dff6ec;color:var(--green)}.status.warning,.status.duplicate{background:#fff0db;color:var(--amber)}.status.source-only-positive,.severity.error{background:#ffe3e0;color:var(--red)}.status.source-only,.status.woo-only,.severity.warning{background:#fff0db;color:var(--amber)}.severity.info{background:#e6f1fb;color:var(--blue)}.formula{margin:0 25px 18px;padding:12px 15px;border-radius:13px;background:#eef9fc;border-right:4px solid var(--blue2);color:#32516c}.empty{padding:40px;text-align:center;color:var(--muted);border-top:1px solid var(--line)}footer{text-align:center;color:var(--muted);padding:25px;font-size:12px}tr[hidden]{display:none}@media(max-width:950px){.shell{padding:14px}.hero{padding:28px}.hero-mark{display:none}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.provenance{grid-template-columns:1fr}.toolbar{position:static;align-items:stretch;flex-direction:column}.toolbar input,.toolbar select{width:100%;min-width:0}.result-count{margin:0}}@media print{body{background:#fff}.shell{max-width:none;padding:0}.toolbar{display:none}.hero,.panel,.card{box-shadow:none}.panel{break-inside:avoid}.table-wrap{overflow:visible}th{position:static}table{font-size:9px}.cards{grid-template-columns:repeat(4,1fr)}}
.outcome-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:0 25px 25px}.outcome-grid article{border:1px solid var(--line);border-radius:15px;padding:15px;min-width:0}.outcome-grid h3{margin:0 0 5px;font-size:15px}.outcome-grid p{margin:0 0 10px;color:var(--muted);font-size:12px}.chips{display:flex;flex-wrap:wrap;gap:6px;max-height:180px;overflow:auto}.chips code{direction:ltr;background:#eef4f8;border-radius:7px;padding:3px 7px;overflow-wrap:anywhere}@media(max-width:950px){.outcome-grid{grid-template-columns:1fr}}
</style>
HTML;
	}

	private static function reportScript(): string {
		return <<<'HTML'
<script>
(()=>{const q=document.getElementById('report-search'),s=document.getElementById('report-status'),c=document.getElementById('visible-count');const apply=()=>{const query=q.value.trim().toLocaleLowerCase('fa'),status=s.value;let shown=0;document.querySelectorAll('tbody tr[data-status]').forEach(row=>{const okText=!query||(row.dataset.search||'').toLocaleLowerCase('fa').includes(query);const okStatus=!status||row.dataset.status===status;row.hidden=!(okText&&okStatus);if(!row.hidden)shown++;});c.textContent=`${shown.toLocaleString('fa-IR')} ردیف قابل مشاهده`;};q.addEventListener('input',apply);s.addEventListener('change',apply);apply();})();
</script>
HTML;
	}

	private static function stateText( array $state ): string {
		if ( ( $state['kind'] ?? '' ) === 'missing' ) {
			return 'کلید موجود نیست';
		}
		if ( ( $state['kind'] ?? '' ) === 'null' ) {
			return 'null صریح';
		}
		return self::displayScalar( $state['value'] ?? null );
	}

	private static function statusLabel( string $status ): string {
		return match ( $status ) {
			'ok' => 'بدون مغایرت',
			'duplicate' => 'چندتطبیقی',
			'source-only-positive' => 'فقط منبع؛ موجود',
			'source-only' => 'فقط منبع',
			'woo-only' => 'فقط ووکامرس',
			default => 'نیازمند توجه',
		};
	}

	private static function severityLabel( string $severity ): string {
		return match ( $severity ) {
			'error' => 'بحرانی',
			'info' => 'اطلاع',
			default => 'هشدار',
		};
	}

	private static function issueLabel( string $issueCode ): string {
		if ( array_key_exists( $issueCode, self::ISSUE_LABELS ) ) {
			return self::ISSUE_LABELS[ $issueCode ];
		}
		foreach ( array(
			'source_missing_' => 'نبود کلید منبع: ',
			'source_null_'    => 'null صریح منبع: ',
			'source_invalid_' => 'مقدار نامعتبر منبع: ',
		) as $prefix => $labelPrefix ) {
			if ( str_starts_with( $issueCode, $prefix ) ) {
				$field = substr( $issueCode, strlen( $prefix ) );
				return $labelPrefix . ( self::FIELD_LABELS[ $field ] ?? $field );
			}
		}
		return 'هشدار فنی: ' . str_replace( '_', ' ', $issueCode );
	}

	private static function displayScalar( mixed $value ): string {
		if ( $value === null || $value === '' ) {
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'بله' : 'خیر';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return self::formatNumber( $value );
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '[داده ساختاریافته]';
	}

	private static function formatNumber( mixed $value ): string {
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?[0-9]+$/D', $value ) ) ) {
			return number_format( (int) $value, 0, '.', ',' );
		}
		if ( is_float( $value ) ) {
			return rtrim( rtrim( number_format( $value, 8, '.', ',' ), '0' ), '.' );
		}
		return (string) $value;
	}

	private static function h( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	private static function valueOrBlank( array $row, string $field ): string {
		$state = self::fieldState( $row, $field );
		return $state['kind'] === 'value' && is_scalar( $state['value'] ) ? (string) $state['value'] : '';
	}

	private static function scalarOrBlank( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function firstRowValue( array $rows, string $field, string $default ): string {
		foreach ( $rows as $row ) {
			if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ) {
				return (string) $row[ $field ];
			}
		}
		return $default;
	}

	private static function numericGreaterThanZero( array $state ): bool {
		if ( ( $state['kind'] ?? '' ) !== 'value' ) {
			return false;
		}
		try {
			return self::decimalCompare( self::decimalParts( $state['value'], 'number' ), self::decimalParts( '0', 'zero' ) ) > 0;
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function numericNonnegative( array $state ): bool {
		if ( ( $state['kind'] ?? '' ) !== 'value' ) {
			return false;
		}
		try {
			return self::decimalCompare( self::decimalParts( $state['value'], 'number' ), self::decimalParts( '0', 'zero' ) ) >= 0;
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function numericEqual( mixed $left, mixed $right ): bool {
		if ( $left === null || $right === null || $left === '' || $right === '' ) {
			return false;
		}
		try {
			return self::decimalCompare( self::decimalParts( $left, 'left' ), self::decimalParts( $right, 'right' ) ) === 0;
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	private static function numericDifference( mixed $left, mixed $right ): ?string {
		try {
			$leftParts  = self::decimalParts( $left, 'left' );
			$rightParts = self::decimalParts( $right, 'right' );
		} catch ( InvalidArgumentException ) {
			return null;
		}
		$scale       = max( $leftParts['scale'], $rightParts['scale'] );
		$leftDigits  = $leftParts['digits'] . str_repeat( '0', $scale - $leftParts['scale'] );
		$rightDigits = $rightParts['digits'] . str_repeat( '0', $scale - $rightParts['scale'] );
		$comparison  = self::bigIntegerCompare( $leftDigits, $rightDigits );
		if ( $comparison === 0 ) {
			return '0';
		}
		$difference = $comparison > 0
			? self::bigIntegerSubtract( $leftDigits, $rightDigits )
			: self::bigIntegerSubtract( $rightDigits, $leftDigits );
		$text       = self::decimalToString(
			array(
				'digits' => $difference,
				'scale'  => $scale,
			)
		);
		return $comparison < 0 ? '-' . $text : $text;
	}

	private static function signedNumeric( mixed $value ): bool {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) && is_finite( $value ) ) {
			$text = json_encode( $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
		} elseif ( is_string( $value ) ) {
			$text = $value;
		} else {
			return false;
		}
		if ( ! preg_match( '/^-?(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $text, $matches ) ) {
			return false;
		}
		return strlen( ltrim( $matches[1], '0' ) ) <= self::MAX_DECIMAL_INTEGER_DIGITS
			&& strlen( $matches[2] ?? '' ) <= self::MAX_DECIMAL_SCALE;
	}

	private static function signedNumericGreaterThanZero( mixed $value ): bool {
		if ( ! self::signedNumeric( $value ) ) {
			return false;
		}
		$text = (string) $value;
		return ! str_starts_with( $text, '-' ) && preg_replace( '/[.0]/', '', $text ) !== '';
	}

	private static function wooPriceToIrt( mixed $price, string $currency ): ?string {
		if ( $price === null || $price === '' ) {
			return null;
		}
		try {
			$parts = self::decimalParts( $price, 'woocommerce_price' );
		} catch ( InvalidArgumentException ) {
			return null;
		}
		if ( $currency === 'IRT' ) {
			return self::decimalToString( $parts );
		}
		if ( $currency === 'IRR' ) {
			++$parts['scale'];
			return self::decimalToString( $parts );
		}
		return null;
	}

	private static function storeWeightToGrams( mixed $weight, string $unit ): ?float {
		if ( ! is_numeric( $weight ) ) {
			return null;
		}
		$value = (float) $weight;
		if ( ! is_finite( $value ) || $value < 0 ) {
			return null;
		}
		return match ( $unit ) {
			'g' => $value,
			'kg' => $value * 1000,
			'lbs' => $value * 453.59237,
			'oz' => $value * 28.349523125,
			default => null,
		};
	}

	private static function isAbsolutePath( string $path ): bool {
		return str_starts_with( $path, '/' )
			|| str_starts_with( $path, '\\\\' )
			|| preg_match( '/^[A-Za-z]:[\\\\\/]/D', $path ) === 1;
	}

	private static function pathIsInside( string $path, string $root ): bool {
		$normalize = static function ( string $value ): string {
			$value = rtrim( str_replace( '\\', '/', $value ), '/' );
			return DIRECTORY_SEPARATOR === '\\' ? strtolower( $value ) : $value;
		};
		$path      = $normalize( $path );
		$root      = $normalize( $root );
		return $path === $root || str_starts_with( $path . '/', $root . '/' );
	}

	/** @return array{digits: string, scale: int} */
	private static function decimalParts( mixed $value, string $field ): array {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				throw new InvalidArgumentException( $field . ' must be finite.' );
			}
			$text = json_encode( $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
		} elseif ( is_string( $value ) ) {
			$text = $value;
		} else {
			throw new InvalidArgumentException( $field . ' must be a base-10 number.' );
		}
		if ( ! preg_match( '/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $text, $matches ) ) {
			throw new InvalidArgumentException( $field . ' must be a non-negative base-10 number without exponent notation.' );
		}
		$integer  = $matches[1];
		$fraction = $matches[2] ?? '';
		if ( strlen( ltrim( $integer, '0' ) ) > self::MAX_DECIMAL_INTEGER_DIGITS || strlen( $fraction ) > self::MAX_DECIMAL_SCALE ) {
			throw new InvalidArgumentException( $field . ' exceeds supported decimal bounds.' );
		}
		$scale  = strlen( $fraction );
		$digits = ltrim( $integer . $fraction, '0' );
		$digits = $digits === '' ? '0' : $digits;
		while ( $scale > 0 && str_ends_with( $digits, '0' ) ) {
			$digits = substr( $digits, 0, -1 );
			--$scale;
		}
		return array(
			'digits' => $digits === '' ? '0' : $digits,
			'scale'  => $scale,
		);
	}

	/** @param array{digits: string, scale: int} $left @param array{digits: string, scale: int} $right */
	private static function decimalCompare( array $left, array $right ): int {
		return ( new ReportArithmetic() )->compare( $left, $right );
	}

	/** @param array{digits: string, scale: int} $decimal */
	private static function decimalToString( array $decimal ): string {
		$digits = self::normalizeBigInteger( $decimal['digits'] );
		if ( $decimal['scale'] <= 0 ) {
			return $digits . str_repeat( '0', -$decimal['scale'] );
		}
		if ( strlen( $digits ) <= $decimal['scale'] ) {
			return '0.' . str_repeat( '0', $decimal['scale'] - strlen( $digits ) ) . $digits;
		}
		return substr( $digits, 0, -$decimal['scale'] ) . '.' . substr( $digits, -$decimal['scale'] );
	}
	private static function bigIntegerSubtract( string $larger, string $smaller ): string {
		return ( new ReportArithmetic() )->subtract( $larger, $smaller );
	}

	private static function bigIntegerCompare( string $left, string $right ): int {
		return ( new ReportArithmetic() )->integerCompare( $left, $right );
	}

	private static function normalizeBigInteger( string $value ): string {
		return ( new ReportArithmetic() )->normalize( $value );
	}
}
