<?php
/**
 * WP-CLI Commands
 * 
 * Command-line interface for Digitalogic plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Digitalogic WP-CLI Commands
 */
class Digitalogic_CLI_Commands {

	private const MAX_CURRENT_PATRIS_JSON_BYTES = 8388608;
    
    /**
     * Get currency rates
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic currency get
     * 
     * @when after_wp_load
     */
    public function currency_get($args, $assoc_args) {
        $currency = Digitalogic_Command_Dispatcher::instance()->get_currency();
        $base = $currency['woocommerce_base'];
        
		WP_CLI::line( __( 'Currency Rates:', 'digitalogic' ) );
		WP_CLI::line( sprintf( /* translators: %s: USD exchange rate. */ __( 'USD: %s', 'digitalogic' ), $currency['dollar_price'] ) );
		WP_CLI::line( sprintf( /* translators: %s: CNY exchange rate. */ __( 'CNY: %s', 'digitalogic' ), $currency['yuan_price'] ) );
		WP_CLI::line( sprintf( /* translators: %s: last currency update. */ __( 'Last Update: %s', 'digitalogic' ), $currency['update_date'] ) );
        $base_label = $base['code'];
        if (!empty($base['unit'])) {
			$unit_label  = 'toman' === $base['unit'] ? __( 'Toman', 'digitalogic' ) : $base['unit'];
			$base_label .= ' (' . $unit_label . ')';
        }
		$status_label = $base['compatible'] ? __( 'Ready', 'digitalogic' ) : __( 'Base currency mismatch', 'digitalogic' );
		WP_CLI::line( sprintf( /* translators: %s: WooCommerce base currency label. */ __( 'WooCommerce Base: %s', 'digitalogic' ), $base_label ) );
		WP_CLI::line( sprintf( /* translators: %s: Patris IRT compatibility status. */ __( 'Patris IRT Pricing: %s', 'digitalogic' ), $status_label ) );

        if (!$base['compatible']) {
            WP_CLI::warning(
				__( 'WooCommerce must use IRT (Toman) before transformed Patris prices can be applied.', 'digitalogic' )
            );
        }
    }
    
    /**
     * Update currency rates
     * 
     * ## OPTIONS
     * 
     * [--usd=<price>]
     * : USD price in local currency
     * 
     * [--cny=<price>]
     * : CNY price in local currency
     * 
     * [--recalculate]
     * : Recalculate all product prices
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic currency update --usd=42000 --cny=6000
     *     wp digitalogic currency update --usd=42000 --recalculate
     * 
     * @when after_wp_load
     */
    public function currency_update($args, $assoc_args) {
        $options = Digitalogic_Options::instance();
        
        $updated = false;
        
        if (isset($assoc_args['usd'])) {
            $options->set_dollar_price(floatval($assoc_args['usd']));
            WP_CLI::success('USD price updated to ' . $assoc_args['usd']);
            $updated = true;
        }
        
        if (isset($assoc_args['cny'])) {
            $options->set_yuan_price(floatval($assoc_args['cny']));
            WP_CLI::success('CNY price updated to ' . $assoc_args['cny']);
            $updated = true;
        }
        
        if (!$updated) {
            WP_CLI::error('No currency rates provided. Use --usd or --cny');
        }
        
        if (isset($assoc_args['recalculate'])) {
            WP_CLI::line('Recalculating product prices...');
            $pricing = Digitalogic_Pricing::instance();
            $results = $pricing->bulk_recalculate_prices();
            WP_CLI::success('Updated ' . $results['success'] . ' products, ' . $results['failed'] . ' failed');
        }
    }
    
    /**
     * List products
     * 
     * ## OPTIONS
     * 
     * [--limit=<number>]
     * : Number of products to list
     * ---
     * default: 10
     * ---
     * 
     * [--search=<term>]
     * : Search term
     * 
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic products list --limit=20
     *     wp digitalogic products list --search=arduino --format=json
     * 
     * @when after_wp_load
     */
    public function products_list($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 10;
        $search = isset($assoc_args['search']) ? $assoc_args['search'] : '';
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        
        $manager = Digitalogic_Product_Manager::instance();
        $products = $manager->get_products(array(
            'limit' => $limit,
            'search' => $search
        ));
        
        $items = array();
        foreach ($products as $product) {
            $items[] = array(
                'ID' => $product['id'],
                'Name' => $product['name'],
                'Product Code' => $product['sku'],
                'Price' => $product['price'],
                'Stock' => $product['stock_quantity']
            );
        }
        
        WP_CLI\Utils\format_items($format, $items, array('ID', 'Name', 'Product Code', 'Price', 'Stock'));
    }

	/**
	 * Get one product by exact WooCommerce ID or SKU.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<product_id>]
	 * : Exact WooCommerce product or variation ID.
	 *
	 * [--sku=<sku>]
	 * : Exact SKU. Numeric and leading-zero SKUs remain strings.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @when after_wp_load
	 */
	public function products_get( $args, $assoc_args ) {
		$identifiers = $this->product_identifiers( $args, $assoc_args );
		if ( is_wp_error( $identifiers ) ) {
			WP_CLI::error( $identifiers->get_error_message() );
			return;
		}

		$product = Digitalogic_Product_Manager::instance()->get_product_by_identifiers( $identifiers );
		if ( is_wp_error( $product ) ) {
			WP_CLI::error( $product->get_error_message() );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? sanitize_key( $assoc_args['format'] ) : 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		WP_CLI\Utils\format_items(
			'table',
			array( $product ),
			array(
				'id',
				'name',
				'sku',
				'type',
				'status',
				'regular_price',
				'sale_price',
				'stock_quantity',
				'stock_status',
			)
		);
	}

	/**
	 * Compare current product meta with its derived lookup row.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<product_id>]
	 * : Exact WooCommerce product or variation ID.
	 *
	 * [--sku=<sku>]
	 * : Exact SKU.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @when after_wp_load
	 */
	public function products_metadata( $args, $assoc_args ) {
		$identifiers = $this->product_identifiers( $args, $assoc_args );
		if ( is_wp_error( $identifiers ) ) {
			WP_CLI::error( $identifiers->get_error_message() );
			return;
		}

		$metadata = Digitalogic_Product_Manager::instance()->get_product_metadata( $identifiers );
		if ( is_wp_error( $metadata ) ) {
			WP_CLI::error( $metadata->get_error_message() );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? sanitize_key( $assoc_args['format'] ) : 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'product_id'      => $metadata['product_id'],
					'sku'             => $metadata['sku'],
					'patris_code'     => $metadata['patris_code'],
					'resolved_by'     => $metadata['resolved_by'],
					'consistent'      => $metadata['is_consistent'] ? 'yes' : 'no',
					'inconsistencies' => $metadata['inconsistency_count'],
				),
			),
			array( 'product_id', 'sku', 'patris_code', 'resolved_by', 'consistent', 'inconsistencies' )
		);

		foreach ( $metadata['inconsistencies'] as $inconsistency ) {
			WP_CLI::warning( wp_json_encode( $inconsistency, JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Update a product
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Backward-compatible positional WooCommerce product ID.
	 *
	 * [--id=<product_id>]
	 * : Exact WooCommerce product or variation ID.
	 *
	 * [--sku=<sku>]
	 * : Exact current SKU used to select the product. With a positional ID,
	 *   this remains the deprecated replacement-SKU option for compatibility.
	 *
	 * [--price=<price>]
	 * : Regular price
	 *
	 * [--sale-price=<price>]
	 * : Sale price
	 *
	 * [--stock=<quantity>]
	 * : Stock quantity
	 *
	 * [--set-sku=<sku>]
	 * : Replace the selected product's SKU.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic products update 123 --price=99.99 --stock=50
	 *     wp digitalogic products update --sku=00123 --set-sku=00124
	 *     wp digitalogic products update 123 --sku=00124
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @when after_wp_load
	 */
	public function products_update( $args, $assoc_args ) {
		$legacy_positional_sku_setter = isset( $args[0] )
			&& '' !== trim( (string) $args[0] )
			&& isset( $assoc_args['sku'] )
			&& ! isset( $assoc_args['id'] );

		if ( $legacy_positional_sku_setter && isset( $assoc_args['set-sku'] ) ) {
			WP_CLI::error( 'Use either the legacy positional-ID --sku setter or --set-sku, not both.' );
			return;
		}

		$identifiers = $this->product_identifiers( $args, $assoc_args, $legacy_positional_sku_setter );
		if ( is_wp_error( $identifiers ) ) {
			WP_CLI::error( $identifiers->get_error_message() );
			return;
		}

		$data = array();

		if ( isset( $assoc_args['price'] ) ) {
			$data['regular_price'] = sanitize_text_field( $assoc_args['price'] );
		}

		if ( isset( $assoc_args['sale-price'] ) ) {
			$data['sale_price'] = sanitize_text_field( $assoc_args['sale-price'] );
		}

		if ( isset( $assoc_args['stock'] ) ) {
			$data['stock_quantity'] = intval( $assoc_args['stock'] );
		}

		if ( $legacy_positional_sku_setter ) {
			$data['sku'] = sanitize_text_field( $assoc_args['sku'] );
			WP_CLI::warning( 'The positional-ID --sku setter is deprecated; use --id=<id> --set-sku=<sku>.' );
		} elseif ( isset( $assoc_args['set-sku'] ) ) {
			$data['sku'] = sanitize_text_field( $assoc_args['set-sku'] );
		}

		if ( empty( $data ) ) {
			WP_CLI::error( 'No update data provided' );
			return;
		}

		$manager = Digitalogic_Product_Manager::instance();
		$result  = $manager->update_product_by_identifiers( $identifiers, $data );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success( 'Product updated.' );
	}
    
    /**
     * Export products
     * 
     * ## OPTIONS
     * 
     * [--format=<format>]
     * : Export format
     * ---
     * default: csv
     * options:
     *   - csv
     *   - json
     * ---
     * 
     * [--output=<file>]
     * : Output file path
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic export --format=csv
     *     wp digitalogic export --format=json --output=/path/to/products.json
     *     wp digitalogic export --format=excel --output=/path/to/products.xlsx
     * 
     * @when after_wp_load
     */
    public function export($args, $assoc_args) {
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'csv';
        
        $import_export = Digitalogic_Import_Export::instance();
        
        if ($format === 'json') {
            $filepath = $import_export->export_json();
        } elseif ($format === 'excel') {
            $filepath = $import_export->export_excel();
        } else {
            $filepath = $import_export->export_csv();
        }
        
        if (is_wp_error($filepath)) {
            WP_CLI::error($filepath->get_error_message());
        }
        
        if (isset($assoc_args['output'])) {
            $output = $assoc_args['output'];
            if (copy($filepath, $output)) {
                WP_CLI::success('Products exported to: ' . $output);
            } else {
                WP_CLI::error('Failed to copy export file');
            }
        } else {
            WP_CLI::success('Products exported to: ' . $filepath);
        }
    }
    
    /**
     * Import products
     * 
     * ## OPTIONS
     * 
     * <file>
     * : Input file path (CSV, JSON, or Excel)
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic import /path/to/products.csv
     *     wp digitalogic import /path/to/products.json
     *     wp digitalogic import /path/to/products.xlsx
     * 
     * @when after_wp_load
     */
    public function import($args, $assoc_args) {
        $filepath = $args[0];
        
        if (!file_exists($filepath)) {
            WP_CLI::error('File not found: ' . $filepath);
        }
        
        $import_export = Digitalogic_Import_Export::instance();
        
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        
        WP_CLI::line('Importing products...');
        
        if ($extension === 'json') {
            $results = $import_export->import_json($filepath);
        } elseif ($extension === 'xlsx' || $extension === 'xls') {
            $results = $import_export->import_excel($filepath);
        } else {
            $results = $import_export->import_csv($filepath);
        }
        
        if (is_wp_error($results)) {
            WP_CLI::error($results->get_error_message());
        }
        
        WP_CLI::success('Import completed: ' . $results['success'] . ' success, ' . $results['failed'] . ' failed');
        
        if (!empty($results['errors'])) {
            WP_CLI::warning('Errors occurred:');
            foreach (array_slice($results['errors'], 0, 10) as $error) {
                WP_CLI::line('  - ' . $error);
            }
        }
    }
    
    /**
     * View activity logs
     * 
     * ## OPTIONS
     * 
     * [--limit=<number>]
     * : Number of logs to display
     * ---
     * default: 20
     * ---
     * 
     * [--action=<action>]
     * : Filter by action
     * 
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic logs --limit=50
     *     wp digitalogic logs --action=update_product --format=json
     * 
     * @when after_wp_load
     */
    public function logs($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 20;
        $action = isset($assoc_args['action']) ? $assoc_args['action'] : null;
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        
        $logger = Digitalogic_Logger::instance();
        $logs = $logger->get_logs(array(
            'limit' => $limit,
            'action' => $action
        ));
        
        $items = array();
        foreach ($logs as $log) {
            $items[] = array(
                'ID' => $log->id,
                'User' => $log->user_id,
                'Action' => $log->action,
                'Object' => $log->object_type . ' #' . $log->object_id,
                'Date' => $log->created_at
            );
        }
        
        WP_CLI\Utils\format_items($format, $items, array('ID', 'User', 'Action', 'Object', 'Date'));
    }

    /**
     * Pull the normalized Patris/API feed into WooCommerce.
     *
     * ## EXAMPLES
     *
     *     wp digitalogic patris sync
     *
     * @when after_wp_load
     */
    public function patris_sync($args, $assoc_args) {
        if (!class_exists('Digitalogic_Patris_Feed')) {
            WP_CLI::error('Patris feed service is not available.');
        }

        $result = Digitalogic_Patris_Feed::instance()->pull_sync();
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success(sprintf(
            'Patris sync complete: %d products imported, %d products updated, %d missing in WooCommerce, %d customers imported.',
            isset($result['total']) ? (int) $result['total'] : 0,
            isset($result['updated']) ? (int) $result['updated'] : 0,
            isset($result['missing_in_woocommerce']) ? (int) $result['missing_in_woocommerce'] : 0,
            isset($result['customers_imported']) ? (int) $result['customers_imported'] : 0
        ));
    }

    /**
     * Show the current Patris/WooCommerce report summary.
     *
     * ## OPTIONS
     *
	 * [--view=<view>]
	 * : Report view: warnings or price_list.
	 *
	 * [--category=<category>]
	 * : Optional warning category key.
	 *
	 * [--page=<page>]
	 * : One-based result page.
	 *
	 * [--per-page=<count>]
	 * : Rows per page, bounded by the report engine.
	 *
	 * [--source-id=<id>]
	 * : Optional exact source id; must be paired with --dataset.
	 *
	 * [--dataset=<dataset>]
	 * : Optional exact source dataset; must be paired with --source-id.
	 *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp digitalogic patris report
	 *     wp digitalogic patris report --view=warnings --page=1 --per-page=50
	 *     wp digitalogic patris report --view=price_list --format=json
     *
     * @when after_wp_load
     */
    public function patris_report($args, $assoc_args) {
        if (!class_exists('Digitalogic_Report_Engine')) {
            WP_CLI::error('Report engine is not available.');
			return;
        }

        $format = isset($assoc_args['format']) ? sanitize_key((string) $assoc_args['format']) : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Report format must be table, json, or csv.' );
			return;
		}
		$report_args = array(
			'view'      => $assoc_args['view'] ?? 'warnings',
			'category'  => $assoc_args['category'] ?? '',
			'page'      => $assoc_args['page'] ?? 1,
			'per_page'  => $assoc_args['per-page'] ?? 100,
			'source_id' => $assoc_args['source-id'] ?? '',
			'dataset'   => $assoc_args['dataset'] ?? '',
		);
		$report = Digitalogic_Report_Engine::instance()->get_report($report_args);

        if ($format === 'json') {
            WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }

		$this->output_current_patris_report( $report, $format );
    }

	/**
	 * Output warning summaries or actual price-list rows.
	 *
	 * @param array  $report Current report.
	 * @param string $format WP-CLI table or CSV format.
	 * @return void
	 */
	private function output_current_patris_report( $report, $format ) {
		$items = array();
		if ( 'price_list' === ( $report['view'] ?? '' ) ) {
			foreach ( $report['rows'] as $row ) {
				$source = is_array( $row['source'] ?? null ) ? $row['source'] : array();
				$woo    = is_array( $row['woocommerce'] ?? null ) ? $row['woocommerce'] : array();
				$items[] = array(
					'Code'             => (string) ( $row['product_code'] ?? '' ),
					'State'            => (string) ( $row['status'] ?? '' ),
					'Source price'     => $this->current_patris_sparse_value( $source, 'final_price' ),
					'Woo active price' => array_key_exists( 'active_price', $woo ) ? (string) $woo['active_price'] : '[missing]',
					'Source stock'     => $this->current_patris_sparse_value( $source, 'total_stock' ),
					'Woo stock'        => array_key_exists( 'stock_quantity', $woo ) ? ( null === $woo['stock_quantity'] ? 'null' : (string) $woo['stock_quantity'] ) : '[missing]',
					'CNY price'        => $this->current_patris_sparse_value( $source, 'foreign_price' ),
					'Weight (g)'       => $this->current_patris_sparse_value( $source, 'weight_grams' ),
					'Findings'         => implode( ',', array_map( 'strval', (array) ( $row['issues'] ?? array() ) ) ),
				);
			}
			WP_CLI\Utils\format_items(
				$format,
				$items,
				array( 'Code', 'State', 'Source price', 'Woo active price', 'Source stock', 'Woo stock', 'CNY price', 'Weight (g)', 'Findings' )
			);
			return;
		}

		foreach ( $report['categories'] as $category ) {
			$items[] = array(
				'Key'      => $category['key'],
				'Title'    => $category['title'],
				'Severity' => $category['severity'],
				'Count'    => $category['count'],
			);
		}
		WP_CLI\Utils\format_items( $format, $items, array( 'Key', 'Title', 'Severity', 'Count' ) );
	}

	/**
	 * Render one sparse value without conflating absence and explicit null.
	 *
	 * @param array  $record Sparse record.
	 * @param string $field Field name.
	 * @return string
	 */
	private function current_patris_sparse_value( $record, $field ) {
		if ( ! array_key_exists( $field, $record ) ) {
			return '[missing]';
		}
		if ( null === $record[ $field ] ) {
			return 'null';
		}
		if ( is_array( $record[ $field ] ) ) {
			return (string) wp_json_encode( $record[ $field ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return (string) $record[ $field ];
	}

	/**
	 * Validate and compare one static kala.json without changing receiver or WooCommerce state.
	 *
	 * The input must be a regular, nonsymlinked kala.json outside the WordPress
	 * webroot. The living receiver validates the exact sparse envelope, but this
	 * command does not acquire its write lock, persist the source, or update a
	 * WooCommerce product.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Absolute path to the transformed canonical kala.json outside the webroot.
	 *
	 * [--format=<format>]
	 * : Output format: table, json, or csv. Default: table.
	 *
	 * [--view=<view>]
	 * : warnings or price_list. Default: warnings.
	 *
	 * [--category=<category>]
	 * : Limit warning rows to one report category.
	 *
	 * [--page=<page>]
	 * : One-based report page. Default: 1.
	 *
	 * [--per-page=<count>]
	 * : Rows per page, from 1 to 100. Default: 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic patris inspect --file=/srv/digitalogic-private/kala.json --user=administrator
	 *     wp digitalogic patris inspect --file=/srv/digitalogic-private/kala.json --user=administrator --view=price_list --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function patris_inspect( $args, $assoc_args ) {
		unset( $args );
		if ( ! $this->require_current_patris_administrator() ) {
			return;
		}
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Report format must be table, json, or csv.' );
			return;
		}

		$json = $this->read_current_patris_json( $assoc_args['file'] ?? '' );
		if ( is_wp_error( $json ) ) {
			WP_CLI::error( $json->get_error_message() );
			return;
		}

		$envelope = Digitalogic_Product_Sync_Receiver::instance()->validate_json( $json );
		if ( is_wp_error( $envelope ) ) {
			WP_CLI::error( $envelope->get_error_message() );
			return;
		}

		$report = Digitalogic_Report_Engine::instance()->get_report_from_validated_envelope(
			$envelope,
			$this->current_patris_report_args( $assoc_args )
		);
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line(
				wp_json_encode(
					array(
						'inspection' => $this->validated_envelope_summary( $envelope ),
						'report'     => $report,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		WP_CLI::line(
			sprintf(
				'Static Patris snapshot is valid and was not applied; source products: %d; WooCommerce products: %d; selected rows: %d.',
				(int) $report['counts']['patris_products'],
				(int) $report['counts']['woocommerce_products'],
				(int) $report['pagination']['total']
			)
		);
		$this->output_current_patris_report( $report, $format );
	}

	/**
	 * Confirm and apply one canonical static kala.json through the living receiver.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Absolute path to the transformed canonical kala.json outside the webroot.
	 *
	 * --yes
	 * : Explicitly confirm receiver persistence and WooCommerce writes.
	 *
	 * [--format=<format>]
	 * : Output format: table, json, or csv. Default: table.
	 *
	 * [--view=<view>]
	 * : warnings or price_list. Default: warnings.
	 *
	 * [--category=<category>]
	 * : Limit warning rows to one report category.
	 *
	 * [--page=<page>]
	 * : One-based report page. Default: 1.
	 *
	 * [--per-page=<count>]
	 * : Rows per page, from 1 to 100. Default: 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic patris ingest --file=/srv/digitalogic-private/kala.json --user=administrator --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function patris_ingest( $args, $assoc_args ) {
		unset( $args );
		if ( ! $this->require_current_patris_administrator() ) {
			return;
		}
		if ( true !== ( $assoc_args['yes'] ?? false ) && '1' !== (string) ( $assoc_args['yes'] ?? '' ) ) {
			WP_CLI::error( 'This command writes receiver and WooCommerce state; pass --yes to continue.' );
			return;
		}
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Report format must be table, json, or csv.' );
			return;
		}

		$json = $this->read_current_patris_json( $assoc_args['file'] ?? '' );
		if ( is_wp_error( $json ) ) {
			WP_CLI::error( $json->get_error_message() );
			return;
		}
		$result = Digitalogic_Product_Sync_Receiver::instance()->receive_json( $json );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$source      = is_array( $result['source'] ?? null ) ? $result['source'] : array();
		$report_args = $this->current_patris_report_args( $assoc_args );
		$report_args['source_id'] = $source['id'] ?? '';
		$report_args['dataset']   = $source['dataset'] ?? '';
		$report = Digitalogic_Report_Engine::instance()->get_report( $report_args );
		if ( 'json' === $format ) {
			WP_CLI::line(
				wp_json_encode(
					array(
						'ingestion' => $result,
						'report'    => $report,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		WP_CLI::line(
			sprintf(
				'Current Patris state: %s; source products: %d; WooCommerce products: %d; selected rows: %d.',
				(string) ( $result['status'] ?? 'accepted' ),
				(int) $report['counts']['patris_products'],
				(int) $report['counts']['woocommerce_products'],
				(int) $report['pagination']['total']
			)
		);
		$this->output_current_patris_report( $report, $format );
	}

	/**
	 * Require both the administrator role and its expected capability.
	 *
	 * @return bool
	 */
	private function require_current_patris_administrator() {
		$user  = wp_get_current_user();
		$roles = is_object( $user ) && isset( $user->roles ) ? (array) $user->roles : array();
		if ( in_array( 'administrator', $roles, true ) && current_user_can( 'manage_options' ) ) {
			return true;
		}

		WP_CLI::error( 'Run this command with --user=<administrator>.' );
		return false;
	}

	/**
	 * Read one already path-validated, bounded static snapshot.
	 *
	 * @param mixed $requested_path Candidate path.
	 * @return string|WP_Error
	 */
	private function read_current_patris_json( $requested_path ) {
		$path = self::validate_current_patris_json_path( $requested_path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$json = file_get_contents( $path, false, null, 0, self::MAX_CURRENT_PATRIS_JSON_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A reviewed local CLI file is required and bounded.
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return new WP_Error( 'digitalogic_patris_static_file_read_failed', 'The canonical kala.json could not be read or is empty.' );
		}
		if ( strlen( $json ) > self::MAX_CURRENT_PATRIS_JSON_BYTES ) {
			return new WP_Error( 'digitalogic_patris_static_file_too_large', 'The canonical kala.json exceeds the 8 MiB inspection limit.' );
		}

		return $json;
	}

	/**
	 * Build bounded report arguments shared by static commands.
	 *
	 * @param array $assoc_args Named command arguments.
	 * @return array
	 */
	private function current_patris_report_args( $assoc_args ) {
		return array(
			'view'     => $assoc_args['view'] ?? 'warnings',
			'category' => $assoc_args['category'] ?? '',
			'page'     => $assoc_args['page'] ?? 1,
			'per_page' => $assoc_args['per-page'] ?? 100,
		);
	}

	/**
	 * Return a bounded nonproduct summary of a validated static envelope.
	 *
	 * @param array $envelope Validated envelope.
	 * @return array
	 */
	private function validated_envelope_summary( $envelope ) {
		return array(
			'status'     => 'valid',
			'event_id'   => (string) $envelope['event_id'],
			'event_type' => (string) $envelope['event_type'],
			'source'     => $envelope['source'],
			'generated_at' => (string) $envelope['generated_at'],
			'products'   => count( $envelope['products'] ),
			'categories' => count( $envelope['categories'] ),
		);
	}

	/**
	 * Validate a private static report input without following a webroot path.
	 *
	 * @param mixed $requested_path Candidate path.
	 * @return string|WP_Error
	 */
	public static function validate_current_patris_json_path( $requested_path ) {
		if ( ! is_string( $requested_path ) || '' === trim( $requested_path ) ) {
			return new WP_Error( 'digitalogic_patris_inspect_file_required', 'Specify --file=<absolute-path-to-kala.json>.' );
		}
		$requested_path = trim( $requested_path );
		$is_absolute    = str_starts_with( $requested_path, '/' )
			|| 1 === preg_match( '/^[A-Za-z]:[\\\\\/]/', $requested_path );
		if ( ! $is_absolute ) {
			return new WP_Error( 'digitalogic_patris_inspect_absolute_path_required', 'The canonical kala.json path must be absolute.' );
		}
		if ( is_link( $requested_path ) ) {
			return new WP_Error( 'digitalogic_patris_inspect_symlink_forbidden', 'The canonical kala.json must not be a symbolic link.' );
		}

		$path = realpath( $requested_path );
		if ( false === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'digitalogic_patris_inspect_file_unreadable', 'The canonical kala.json is not a readable regular file.' );
		}
		if ( 'kala.json' !== strtolower( basename( $path ) ) ) {
			return new WP_Error( 'digitalogic_patris_inspect_filename_invalid', 'The inspected file must be named kala.json.' );
		}

		$normalized_path      = self::normalize_cli_path( $path );
		$normalized_requested = self::normalize_cli_path( $requested_path );
		$webroot              = realpath( ABSPATH );
		$webroot              = false === $webroot ? ABSPATH : $webroot;
		$normalized_root      = rtrim( self::normalize_cli_path( $webroot ), '/' ) . '/';
		$compare_path         = DIRECTORY_SEPARATOR === '\\' ? strtolower( $normalized_path ) : $normalized_path;
		$compare_requested = DIRECTORY_SEPARATOR === '\\' ? strtolower( $normalized_requested ) : $normalized_requested;
		$compare_root         = DIRECTORY_SEPARATOR === '\\' ? strtolower( $normalized_root ) : $normalized_root;
		if (
			str_starts_with( $compare_path . '/', $compare_root )
			|| str_starts_with( $compare_requested . '/', $compare_root )
		) {
			return new WP_Error( 'digitalogic_patris_inspect_webroot_forbidden', 'The canonical kala.json must be stored outside the WordPress webroot.' );
		}

		clearstatcache( true, $path );
		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			return new WP_Error( 'digitalogic_patris_inspect_file_empty', 'The canonical kala.json is empty.' );
		}
		if ( $size > self::MAX_CURRENT_PATRIS_JSON_BYTES ) {
			return new WP_Error( 'digitalogic_patris_inspect_file_too_large', 'The canonical kala.json exceeds the 8 MiB inspection limit.' );
		}

		return $path;
	}

	/**
	 * Normalize a CLI filesystem path for containment checks.
	 *
	 * @param string $path Filesystem path.
	 * @return string
	 */
	private static function normalize_cli_path( $path ) {
		return str_replace( '\\', '/', rtrim( (string) $path, '/\\' ) );
	}

    /**
     * Show the Patris/API push token.
     *
     * @when after_wp_load
     */
    public function patris_token() {
        if (!class_exists('Digitalogic_Patris_Feed')) {
            WP_CLI::error('Patris feed service is not available.');
        }

        WP_CLI::line(Digitalogic_Patris_Feed::instance()->get_push_token());
    }

    /**
     * Run the Digitalogic WebSocket command server.
     *
     * ## OPTIONS
     *
     * [--host=<host>]
     * : Host/IP to bind.
     * ---
     * default: 127.0.0.1
     * ---
     *
     * [--port=<port>]
     * : Port to listen on.
     * ---
     * default: 8090
     * ---
     *
     * ## EXAMPLES
     *
     *     wp digitalogic websocket serve --host=127.0.0.1 --port=8090
     *
     * @when after_wp_load
     */
    public function websocket_serve($args, $assoc_args) {
        $host = isset($assoc_args['host']) ? (string) $assoc_args['host'] : '127.0.0.1';
        $port = isset($assoc_args['port']) ? intval($assoc_args['port']) : 8090;

        try {
            $server = new Digitalogic_WebSocket_Server();
            $server->run($host, $port);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Show the server-to-server WebSocket token.
     *
     * @when after_wp_load
     */
    public function websocket_token() {
        WP_CLI::line(Digitalogic_WebSocket::get_server_token());
    }

    /**
     * Broadcast a JSON toast/event message to open Digitalogic panels.
     *
     * ## OPTIONS
     *
     * [<json>]
     * : JSON object, for example '{"message":"Prices refreshed","level":"success"}'.
     *
     * [--message=<message>]
     * : Message text when not passing JSON.
     *
     * [--level=<level>]
     * : info, success, warning, or danger.
     *
     * ## EXAMPLES
     *
     *     wp digitalogic panel broadcast '{"message":"Sync finished","level":"success"}'
     *     wp digitalogic panel broadcast --message="Currency updated" --level=success
     *
     * @when after_wp_load
     */
    public function panel_broadcast($args, $assoc_args) {
        $payload = array();

        if (!empty($args[0])) {
            $decoded = json_decode((string) $args[0], true);
            if (!is_array($decoded)) {
                WP_CLI::error('The JSON argument must be an object.');
            }
            $payload = $decoded;
        }

        if (isset($assoc_args['message'])) {
            $payload['message'] = (string) $assoc_args['message'];
        }

        if (isset($assoc_args['level'])) {
            $payload['level'] = sanitize_key((string) $assoc_args['level']);
        }

        Digitalogic_Panel::broadcast_panel_message($payload);
        WP_CLI::success('Panel broadcast queued.');
    }

	/**
	 * Read exact import-pricing assignments for a bounded Code list.
	 *
	 * ## OPTIONS
	 *
	 * [<code>...]
	 * : One or more exact Patris Codes or SKU compatibility fallbacks.
	 *
	 * [--codes=<codes>]
	 * : Additional comma-separated Codes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic pricing assignments 113007045 113007046
	 *     wp digitalogic pricing assignments --codes=113007045,113007046
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional Codes.
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public function pricing_assignments( $args, $assoc_args ) {
		$codes = array_values( array_map( 'strval', $args ) );
		if ( isset( $assoc_args['codes'] ) ) {
			$codes = array_merge( $codes, explode( ',', (string) $assoc_args['codes'] ) );
		}

		$result = Digitalogic_Command_Dispatcher::instance()->get_product_import_pricing_batch(
			array(
				'codes' => $codes,
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::line(
			wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Audit canonical, regular, sale, and effective prices without mutating.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum products to inspect (1-500).
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--page=<number>]
	 * : One-based audit page.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format: table, csv, or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic pricing audit --limit=100 --format=table
	 *     wp digitalogic pricing audit --page=2 --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments (unused).
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public function pricing_audit( $args, $assoc_args ) {
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 100;
		$page   = isset( $assoc_args['page'] ) ? (int) $assoc_args['page'] : 1;
		$format = isset( $assoc_args['format'] ) ? sanitize_key( $assoc_args['format'] ) : 'table';

		if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
			WP_CLI::error( 'Format must be table, csv, or json.' );
			return;
		}

		$rows = Digitalogic_Patris_Price_Policy::instance()->audit( $limit, $page );
		WP_CLI\Utils\format_items(
			$format,
			$rows,
			array(
				'product_id',
				'product_type',
				'canonical_patris',
				'woo_regular',
				'woo_sale',
				'woo_effective',
				'sale_policy',
				'price_source',
				'audit_status',
				'needs_review',
			)
		);
	}

	/**
	 * Read or explicitly change the Patris promotion policy.
	 *
	 * Reading is non-mutating. Changing the policy requires an explicit
	 * administrator context; `preserve_sale` remains the safe default.
	 *
	 * ## OPTIONS
	 *
	 * [--set=<policy>]
	 * : Set `preserve_sale` or `replace_sale`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic pricing policy
	 *     wp digitalogic pricing policy --set=replace_sale --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments (unused).
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public function pricing_policy( $args, $assoc_args ) {
		$service = Digitalogic_Patris_Price_Policy::instance();
		if ( ! isset( $assoc_args['set'] ) ) {
			WP_CLI::line( $service->get_sale_policy() );
			return;
		}

		if ( ! $this->require_administrator() ) {
			return;
		}

		$policy = sanitize_key( (string) $assoc_args['set'] );
		if ( ! in_array( $policy, array( Digitalogic_Patris_Price_Policy::PRESERVE_SALE, Digitalogic_Patris_Price_Policy::REPLACE_SALE ), true ) ) {
			WP_CLI::error( 'Policy must be preserve_sale or replace_sale.' );
			return;
		}

		update_option( Digitalogic_Patris_Price_Policy::OPTION_NAME, $policy, false );
		WP_CLI::success( 'Patris promotion policy set to ' . $policy . '.' );
	}

	/**
	 * Create the route-scoped Patris pricing-input credential.
	 *
	 * The generated Bearer value is printed exactly once. Run this command with
	 * an explicit administrator context and move the value directly into the
	 * Patris environment secret configured by BearerTokenEnv.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic pricing-input-credential create --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function pricing_input_credential_create() {
		if ( ! $this->require_administrator() ) {
			return;
		}

		$this->output_issued_pricing_credential(
			Digitalogic_Pricing_Input_Credential::instance()->create()
		);
	}

	/**
	 * Rotate the pricing-input credential and invalidate its old value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic pricing-input-credential rotate --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function pricing_input_credential_rotate() {
		if ( ! $this->require_administrator() ) {
			return;
		}

		$this->output_issued_pricing_credential(
			Digitalogic_Pricing_Input_Credential::instance()->rotate()
		);
	}

	/**
	 * Revoke the pricing-input credential immediately.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic pricing-input-credential revoke --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function pricing_input_credential_revoke() {
		if ( ! $this->require_administrator() ) {
			return;
		}

		$result = Digitalogic_Pricing_Input_Credential::instance()->revoke();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Show nonsecret pricing-input credential metadata.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic pricing-input-credential status --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function pricing_input_credential_status() {
		if ( ! $this->require_administrator() ) {
			return;
		}

		$result = Digitalogic_Pricing_Input_Credential::instance()->status();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::line(
			wp_json_encode(
				$result,
				JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Show nonsecret product-sync delivery and reconciliation counts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic product-sync status
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function product_sync_status() {
		WP_CLI::line(
			wp_json_encode(
				Digitalogic_Product_Sync_Receiver::instance()->get_status(),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Retry deferred and transient product-sync work without changing ordering.
	 *
	 * ## OPTIONS
	 *
	 * [--source-id=<id>]
	 * : Exact source id. Must be paired with --dataset.
	 *
	 * [--dataset=<dataset>]
	 * : Exact source dataset. Must be paired with --source-id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic product-sync reconcile --user=<administrator>
	 *     wp digitalogic product-sync reconcile --source-id=patris-office --dataset=kala.db --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments (unused).
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public function product_sync_reconcile( $args, $assoc_args ) {
		if ( ! $this->require_administrator() ) {
			return;
		}

		$source_id = isset( $assoc_args['source-id'] ) ? (string) $assoc_args['source-id'] : null;
		$dataset   = isset( $assoc_args['dataset'] ) ? (string) $assoc_args['dataset'] : null;
		$result    = Digitalogic_Product_Sync_Receiver::instance()->reconcile( $source_id, $dataset );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::line(
			wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Plan or explicitly apply reviewed Patris catalog materialization.
	 *
	 * Dry-run is the default. --apply authorizes taxonomy/product writes, while
	 * --publish-ready additionally promotes only rows that pass every gate.
	 *
	 * ## OPTIONS
	 *
	 * --manifest=<path>
	 * : Strict living digitalogic.patris-catalog-enrichment JSON manifest.
	 *
	 * [--source-id=<id>]
	 * : Optional exact source-id assertion against the manifest.
	 *
	 * [--dataset=<dataset>]
	 * : Optional exact dataset assertion against the manifest.
	 *
	 * [--codes=<codes>]
	 * : Optional comma-separated exact Patris Codes.
	 *
	 * [--limit=<count>]
	 * : Optional positive batch limit after exact Code sorting.
	 *
	 * [--apply]
	 * : Apply the reviewed plan. Without this flag no writes occur.
	 *
	 * [--publish-ready]
	 * : Publish only fully priced, freight-currency-qualified, weighted,
	 * in-stock, SEO-enriched rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic product-sync materialize --manifest=/secure/reviewed.json --user=<administrator>
	 *     wp digitalogic product-sync materialize --manifest=/secure/reviewed.json --limit=25 --apply --user=<administrator>
	 *     wp digitalogic product-sync materialize --manifest=/secure/reviewed.json --apply --publish-ready --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments (unused).
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public function product_sync_materialize( $args, $assoc_args ) {
		if ( ! $this->require_administrator() ) {
			return;
		}
		$path = isset( $assoc_args['manifest'] ) ? (string) $assoc_args['manifest'] : '';
		if ( '' === trim( $path ) ) {
			WP_CLI::error( 'Specify --manifest=<reviewed-json-path>.' );
			return;
		}

		$service  = Digitalogic_Patris_Catalog_Materializer::instance();
		$manifest = $service->load_manifest_file( $path );
		if ( is_wp_error( $manifest ) ) {
			WP_CLI::error( $manifest->get_error_message() );
			return;
		}
		foreach ( array( 'source-id' => 'id', 'dataset' => 'dataset' ) as $argument => $field ) {
			if ( isset( $assoc_args[ $argument ] ) && (string) $assoc_args[ $argument ] !== (string) $manifest['source'][ $field ] ) {
				WP_CLI::error( 'The CLI source assertion does not match the reviewed manifest.' );
				return;
			}
		}

		$codes = array();
		if ( isset( $assoc_args['codes'] ) ) {
			foreach ( explode( ',', (string) $assoc_args['codes'] ) as $code ) {
				$code = trim( $code );
				if ( '' !== $code ) {
					$codes[] = $code;
				}
			}
		}
		$result = $service->run(
			$manifest,
			array(
				'apply'         => isset( $assoc_args['apply'] ),
				'publish_ready' => isset( $assoc_args['publish-ready'] ),
				'limit'         => isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : 0,
				'codes'         => $codes,
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Print one bounded Google Sheets catalog page as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--dataset=<dataset>]
	 * : products or categories. Default: products.
	 *
	 * [--page=<page>]
	 * : One-based page. Default: 1.
	 *
	 * [--limit=<limit>]
	 * : Page size from 1 to 100. Default: 100.
	 *
	 * [--locale=<locale>]
	 * : en, fa, or bilingual. Default: en.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic google-sheets catalog --dataset=products --locale=fa
	 *     wp digitalogic google-sheets catalog --dataset=categories --page=2
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 * @when after_wp_load
	 */
	public function google_sheets_catalog( $args, $assoc_args ) {
		$result = Digitalogic_Google_Sheets_Catalog::instance()->get_page( $assoc_args );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Require an explicit administrator user for mutating operational commands.
	 *
	 * @return bool
	 */
	private function require_administrator() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		WP_CLI::error( 'Run this command with --user=<administrator>.' );

		return false;
	}

	/**
	 * Build one unambiguous exact product selector for shared services.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @param bool  $legacy_positional_sku_setter Treat --sku as the legacy setter.
	 * @return array|WP_Error
	 */
	private function product_identifiers( $args, $assoc_args, $legacy_positional_sku_setter = false ) {
		$has_positional = isset( $args[0] ) && '' !== trim( (string) $args[0] );
		$has_id         = isset( $assoc_args['id'] ) && '' !== trim( (string) $assoc_args['id'] );
		$has_sku        = ! $legacy_positional_sku_setter
			&& isset( $assoc_args['sku'] )
			&& '' !== trim( (string) $assoc_args['sku'] );

		if ( 1 !== (int) $has_positional + (int) $has_id + (int) $has_sku ) {
			return new WP_Error(
				'digitalogic_cli_product_selector',
				'Specify exactly one product selector: positional ID, --id, or --sku.'
			);
		}

		if ( $has_sku ) {
			return array( 'sku' => (string) $assoc_args['sku'] );
		}

		return array( 'woocommerce_id' => (string) ( $has_id ? $assoc_args['id'] : $args[0] ) );
	}

	/**
	 * Print a newly issued secret once, followed by nonsecret metadata.
	 *
	 * @param array|WP_Error $result Credential issuance result.
	 * @return void
	 */
	private function output_issued_pricing_credential( $result ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::line( $result['secret'] );
		WP_CLI::line( wp_json_encode( $result['metadata'], JSON_UNESCAPED_SLASHES ) );
	}
}

// Register commands
// Note: Don't register 'digitalogic currency' alone as it conflicts with subcommands
WP_CLI::add_command('digitalogic currency get', array('Digitalogic_CLI_Commands', 'currency_get'));
WP_CLI::add_command('digitalogic currency update', array('Digitalogic_CLI_Commands', 'currency_update'));
WP_CLI::add_command('digitalogic products list', array('Digitalogic_CLI_Commands', 'products_list'));
WP_CLI::add_command( 'digitalogic products get', array( 'Digitalogic_CLI_Commands', 'products_get' ) );
WP_CLI::add_command( 'digitalogic products metadata', array( 'Digitalogic_CLI_Commands', 'products_metadata' ) );
WP_CLI::add_command('digitalogic products update', array('Digitalogic_CLI_Commands', 'products_update'));
WP_CLI::add_command('digitalogic export', array('Digitalogic_CLI_Commands', 'export'));
WP_CLI::add_command('digitalogic import', array('Digitalogic_CLI_Commands', 'import'));
WP_CLI::add_command('digitalogic logs', array('Digitalogic_CLI_Commands', 'logs'));
WP_CLI::add_command('digitalogic patris sync', array('Digitalogic_CLI_Commands', 'patris_sync'));
WP_CLI::add_command('digitalogic patris report', array('Digitalogic_CLI_Commands', 'patris_report'));
WP_CLI::add_command('digitalogic patris inspect', array('Digitalogic_CLI_Commands', 'patris_inspect'));
WP_CLI::add_command('digitalogic patris ingest', array('Digitalogic_CLI_Commands', 'patris_ingest'));
WP_CLI::add_command('digitalogic patris token', array('Digitalogic_CLI_Commands', 'patris_token'));
WP_CLI::add_command('digitalogic websocket serve', array('Digitalogic_CLI_Commands', 'websocket_serve'));
WP_CLI::add_command('digitalogic websocket token', array('Digitalogic_CLI_Commands', 'websocket_token'));
WP_CLI::add_command('digitalogic panel broadcast', array('Digitalogic_CLI_Commands', 'panel_broadcast'));
WP_CLI::add_command(
	'digitalogic pricing assignments',
	array( 'Digitalogic_CLI_Commands', 'pricing_assignments' )
);
WP_CLI::add_command(
	'digitalogic pricing audit',
	array( 'Digitalogic_CLI_Commands', 'pricing_audit' )
);
WP_CLI::add_command(
	'digitalogic pricing policy',
	array( 'Digitalogic_CLI_Commands', 'pricing_policy' )
);
WP_CLI::add_command(
	'digitalogic pricing-input-credential create',
	array( 'Digitalogic_CLI_Commands', 'pricing_input_credential_create' )
);
WP_CLI::add_command(
	'digitalogic pricing-input-credential rotate',
	array( 'Digitalogic_CLI_Commands', 'pricing_input_credential_rotate' )
);
WP_CLI::add_command(
	'digitalogic pricing-input-credential revoke',
	array( 'Digitalogic_CLI_Commands', 'pricing_input_credential_revoke' )
);
WP_CLI::add_command(
	'digitalogic pricing-input-credential status',
	array( 'Digitalogic_CLI_Commands', 'pricing_input_credential_status' )
);
WP_CLI::add_command(
	'digitalogic product-sync status',
	array( 'Digitalogic_CLI_Commands', 'product_sync_status' )
);
WP_CLI::add_command(
	'digitalogic product-sync reconcile',
	array( 'Digitalogic_CLI_Commands', 'product_sync_reconcile' )
);
WP_CLI::add_command(
	'digitalogic product-sync materialize',
	array( 'Digitalogic_CLI_Commands', 'product_sync_materialize' )
);
WP_CLI::add_command(
	'digitalogic google-sheets catalog',
	array( 'Digitalogic_CLI_Commands', 'google_sheets_catalog' )
);
