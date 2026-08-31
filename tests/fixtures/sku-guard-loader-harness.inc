<?php

$mode        = (string) ( $argv[1] ?? 'missing' );
$plugin_root = (string) ( $argv[2] ?? '' );
$loader      = (string) ( $argv[3] ?? '' );
$normal_plugins = sys_get_temp_dir() . '/digitalogic-sku-loader-' . getmypid();
if ( 'normal' === $mode ) {
	$normal_includes = $normal_plugins . '/digitalogic-wp/includes';
	mkdir( $normal_includes, 0700, true );
	copy( $plugin_root . '/includes/class-digitalogic-product-write-lock.php', $normal_includes . '/class-digitalogic-product-write-lock.php' );
	copy( $plugin_root . '/includes/class-digitalogic-sku-guard.php', $normal_includes . '/class-digitalogic-sku-guard.php' );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', 'normal' === $mode ? $normal_plugins : __DIR__ . '/missing-plugins' );
define( 'WPMU_PLUGIN_DIR', __DIR__ . '/missing-mu-plugins' );

$GLOBALS['loader_filters'] = array();
$GLOBALS['loader_actions'] = array();
$GLOBALS['loader_meta']    = array(
	77 => (object) array(
		'post_id'  => 7,
		'meta_key' => '_SkU',
	),
);
$GLOBALS['loader_posts'] = array(
	7 => array( 'post_type' => 'product', 'meta' => array( '_SkU' => 'OLD-7' ) ),
	8 => array( 'post_type' => 'product', 'meta' => array() ),
	9 => array( 'post_type' => 'post', 'meta' => array( '_SKU' => 'STAGED-9' ) ),
);

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['loader_filters'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['loader_actions'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function get_metadata_by_mid( $type, $meta_id ) {
	return 'post' === $type ? ( $GLOBALS['loader_meta'][ (int) $meta_id ] ?? false ) : false;
}

function absint( $value ) {
	return abs( (int) $value );
}

function __( $message ) {
	return (string) $message;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function get_post_type( $post_id = 0 ) {
	return (string) ( $GLOBALS['loader_posts'][ (int) $post_id ]['post_type'] ?? 'product' );
}

function get_post_meta( $post_id ) {
	return (array) ( $GLOBALS['loader_posts'][ (int) $post_id ]['meta'] ?? array() );
}

function is_admin() {
	return false;
}

function esc_html( $value ) {
	return (string) $value;
}

function do_action() {}

class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}

final class Digitalogic_Loader_Test_Product {
	private $id;
	private $sku;
	private $changes;
	public function __construct( $id, $sku, $changes ) {
		$this->id      = (int) $id;
		$this->sku     = (string) $sku;
		$this->changes = (array) $changes;
	}
	public function get_id() { return $this->id; }
	public function get_sku() { return $this->sku; }
	public function get_changes() { return $this->changes; }
}

require $loader;

$result = array(
	'mode'         => $mode,
	'filters'      => array_map( 'count', $GLOBALS['loader_filters'] ),
	'actions'      => array_map( 'count', $GLOBALS['loader_actions'] ),
	'legacy_class' => class_exists( 'Digitalogic_Woo_SKU_Guard', false ),
);

if ( 'normal' === $mode ) {
	Digitalogic_Product_Write_Lock::instance();
	Digitalogic_SKU_Guard::instance();
	$result['version'] = Digitalogic_SKU_Guard::VERSION;
} else {
	$unique = $GLOBALS['loader_filters']['wc_product_pre_has_unique_sku'][0]['callback'];
	$save   = $GLOBALS['loader_actions']['woocommerce_before_product_object_save'][0]['callback'];
	$add    = $GLOBALS['loader_filters']['add_post_metadata'][0]['callback'];
	$by_mid = $GLOBALS['loader_filters']['update_post_metadata_by_mid'][0]['callback'];
	$delete = $GLOBALS['loader_filters']['delete_post_metadata'][0]['callback'];
	$delete_mid = $GLOBALS['loader_filters']['delete_post_metadata_by_mid'][0]['callback'];
	$promotion = $GLOBALS['loader_filters']['wp_insert_post_data'][0]['callback'];
	$result['unique_result'] = $unique( false, 8, 'BAD?' );
	$result['alias_add']     = $add( null, 8, '_SkU' );
	$result['alias_by_mid']  = $by_mid( null, 77, 'ANY', false );
	$result['rename_away_by_mid'] = $by_mid( null, 77, 'ANY', 'legacy_key' );
	$result['alias_delete']        = $delete( null, 7, '_SKU' );
	$result['alias_delete_by_mid'] = $delete_mid( null, 77 );
	try {
		$promotion(
			array( 'post_type' => 'product' ),
			array( 'ID' => 9 ),
			array( 'ID' => 9 ),
			true
		);
		$result['promotion_throws'] = false;
	} catch ( RuntimeException $exception ) {
		$result['promotion_throws'] = true;
	}
	$result['throws']        = array();
	foreach (
		array(
			'existing_dirty'   => new Digitalogic_Loader_Test_Product( 8, 'DIRTY-8', array( 'sku' => 'DIRTY-8' ) ),
			'new_with_sku'     => new Digitalogic_Loader_Test_Product( 0, 'NEW-0', array() ),
			'existing_unchanged'=> new Digitalogic_Loader_Test_Product( 8, 'UNCHANGED-8', array() ),
		) as $case => $product
	) {
		try {
			$save( $product );
			$result['throws'][ $case ] = false;
		} catch ( RuntimeException $exception ) {
			$result['throws'][ $case ] = true;
		}
	}
}

echo json_encode( $result, JSON_UNESCAPED_SLASHES );

if ( 'normal' === $mode ) {
	unlink( $normal_plugins . '/digitalogic-wp/includes/class-digitalogic-product-write-lock.php' );
	unlink( $normal_plugins . '/digitalogic-wp/includes/class-digitalogic-sku-guard.php' );
	rmdir( $normal_plugins . '/digitalogic-wp/includes' );
	rmdir( $normal_plugins . '/digitalogic-wp' );
	rmdir( $normal_plugins );
}
