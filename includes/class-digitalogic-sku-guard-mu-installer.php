<?php
/**
 * Atomic installer for the canonical early SKU-guard MU loader.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Install or roll back the one allowlisted SKU-guard MU loader. */
final class Digitalogic_SKU_Guard_MU_Installer {

	public const TARGET_BASENAME    = 'digitalogic-woo-sku-guard.php';
	public const LIVE_V2_0_1_SHA256 = '02017e699efd51574da883eaee498b119a37b0ba70ca5ecb54b148967614e604';
	public const LOADER_V2_1_SHA256 = 'ec796fca70a98954815a9e96f9d6950790e794079f3fcf41195d0e57b1176a67';

	/**
	 * Replace the exact legacy file from an outside-MU same-volume stage.
	 *
	 * @param string $mu_directory           WordPress MU-plugin directory.
	 * @param string $backup_directory       Existing backup directory outside MU plugins.
	 * @param string $expected_current_hash  Required SHA-256 of the current target.
	 * @param string $source_file            Optional loader source override for testing.
	 * @return array|WP_Error
	 */
	public static function install( $mu_directory, $backup_directory, $expected_current_hash, $source_file = '' ) {
		$paths = self::validated_paths( $mu_directory, $backup_directory );
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}
		$source_file = '' !== (string) $source_file
			? (string) $source_file
			: DIGITALOGIC_PLUGIN_DIR . 'includes/mu-plugins/' . self::TARGET_BASENAME;
		if ( ! is_file( $source_file ) || ! is_readable( $source_file ) ) {
			return self::error( 'sku_guard_mu_source_missing', 'The canonical MU loader source is unavailable.' );
		}

		$target       = $paths['mu'] . DIRECTORY_SEPARATOR . self::TARGET_BASENAME;
		$current_hash = is_file( $target ) ? hash_file( 'sha256', $target ) : false;
		$expected     = strtolower( trim( (string) $expected_current_hash ) );
		if (
			! hash_equals( self::LIVE_V2_0_1_SHA256, $expected )
			|| false === $current_hash
			|| ! hash_equals( $expected, strtolower( $current_hash ) )
		) {
			return self::error( 'sku_guard_mu_precondition_failed', 'The current MU loader hash does not match the deployment manifest.' );
		}
		$source_hash = hash_file( 'sha256', $source_file );
		if ( false === $source_hash || ! hash_equals( self::LOADER_V2_1_SHA256, strtolower( $source_hash ) ) ) {
			return self::error( 'sku_guard_mu_source_hash_failed', 'The canonical MU loader could not be hashed.' );
		}

		$stage_directory = dirname( $paths['mu'] ) . DIRECTORY_SEPARATOR . '.digitalogic-sku-guard-stage';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Deployment staging must be outside MU plugins and on the target filesystem.
		if ( ! is_dir( $stage_directory ) && ! mkdir( $stage_directory, 0700, true ) && ! is_dir( $stage_directory ) ) {
			return self::error( 'sku_guard_mu_stage_failed', 'The outside-MU staging directory could not be created.' );
		}
		$stage = tempnam( $stage_directory, 'loader-' );
		if ( false === $stage || ! copy( $source_file, $stage ) || $source_hash !== hash_file( 'sha256', $stage ) ) {
			self::remove_file( $stage );
			return self::error( 'sku_guard_mu_stage_failed', 'The canonical MU loader could not be staged exactly.' );
		}

		$backup = $paths['backup'] . DIRECTORY_SEPARATOR . self::TARGET_BASENAME . '.before-' . substr( $current_hash, 0, 16 ) . '-' . gmdate( 'YmdHis' );
		if ( file_exists( $backup ) || ! copy( $target, $backup ) || $current_hash !== hash_file( 'sha256', $backup ) ) {
			self::remove_file( $stage );
			self::remove_file( $backup );
			return self::error( 'sku_guard_mu_backup_failed', 'The current MU loader could not be backed up exactly.' );
		}

		if ( ! rename( $stage, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the required atomic replacement primitive.
			self::remove_file( $stage );
			return self::error( 'sku_guard_mu_replace_failed', 'The staged MU loader could not atomically replace the current file.' );
		}
		if ( $source_hash !== hash_file( 'sha256', $target ) ) {
			$rollback = self::restore_exact( $target, $backup, $current_hash, $stage_directory );
			return is_wp_error( $rollback )
				? $rollback
				: self::error( 'sku_guard_mu_replace_verification_failed', 'The MU loader was restored after replacement verification failed.' );
		}

		return array(
			'ok'                     => true,
			'target'                 => $target,
			'backup'                 => $backup,
			'previous_sha256'        => $current_hash,
			'backup_sha256'          => $current_hash,
			'installed_sha256'       => $source_hash,
			'loader_version'         => Digitalogic_SKU_Guard::VERSION,
			'requires_fresh_request' => true,
		);
	}

	/** Restore one exact backup through the same outside-MU atomic stage. */
	public static function rollback( $mu_directory, $backup_directory, $backup_file, $expected_current_hash, $expected_backup_hash ) {
		$paths = self::validated_paths( $mu_directory, $backup_directory );
		if ( is_wp_error( $paths ) ) {
			return $paths;
		}
		$backup_file     = realpath( (string) $backup_file );
		$expected_backup = strtolower( trim( (string) $expected_backup_hash ) );
		$backup_pattern  = '/^' . preg_quote( self::TARGET_BASENAME, '/' ) . '\.before-02017e699efd5157-[0-9]{14}$/';
		if (
			false === $backup_file
			|| dirname( $backup_file ) !== $paths['backup']
			|| 1 !== preg_match( $backup_pattern, basename( $backup_file ) )
			|| ! hash_equals( self::LIVE_V2_0_1_SHA256, $expected_backup )
		) {
			return self::error( 'sku_guard_mu_rollback_backup_invalid', 'The rollback backup is outside the allowlisted backup directory.' );
		}
		$target       = $paths['mu'] . DIRECTORY_SEPARATOR . self::TARGET_BASENAME;
		$current_hash = is_file( $target ) ? hash_file( 'sha256', $target ) : false;
		$expected     = strtolower( trim( (string) $expected_current_hash ) );
		if ( false === $current_hash || 64 !== strlen( $expected ) || ! hash_equals( $expected, strtolower( $current_hash ) ) ) {
			return self::error( 'sku_guard_mu_rollback_precondition_failed', 'The installed MU loader hash changed before rollback.' );
		}
		$backup_hash = hash_file( 'sha256', $backup_file );
		if ( false === $backup_hash || ! hash_equals( $expected_backup, strtolower( $backup_hash ) ) ) {
			return self::error( 'sku_guard_mu_rollback_backup_hash_failed', 'The rollback backup does not match the install receipt.' );
		}
		$stage_directory = dirname( $paths['mu'] ) . DIRECTORY_SEPARATOR . '.digitalogic-sku-guard-stage';
		$restored        = self::restore_exact( $target, $backup_file, $backup_hash, $stage_directory );
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		return array(
			'ok'              => true,
			'target'          => $target,
			'restored_sha256' => $backup_hash,
		);
	}

	/** Validate exact directories and require backups outside the autoloaded MU path. */
	private static function validated_paths( $mu_directory, $backup_directory ) {
		$mu     = realpath( (string) $mu_directory );
		$backup = realpath( (string) $backup_directory );
		if ( false === $mu || false === $backup || ! is_dir( $mu ) || ! is_dir( $backup ) ) {
			return self::error( 'sku_guard_mu_directory_invalid', 'The MU and backup directories must already exist.' );
		}
		$mu_prefix = rtrim( $mu, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( $backup === $mu || str_starts_with( $backup . DIRECTORY_SEPARATOR, $mu_prefix ) ) {
			return self::error( 'sku_guard_mu_backup_exposed', 'The backup directory must be outside the MU-plugin request path.' );
		}

		return array(
			'mu'     => $mu,
			'backup' => $backup,
		);
	}

	/** Copy one backup to an outside-MU stage and atomically rename it over target. */
	private static function restore_exact( $target, $backup, $expected_hash, $stage_directory ) {
		if ( false === $expected_hash || ! is_dir( $stage_directory ) ) {
			return self::error( 'sku_guard_mu_rollback_failed', 'The rollback stage or backup hash is unavailable.' );
		}
		$stage = tempnam( $stage_directory, 'rollback-' );
		if ( false === $stage || ! copy( $backup, $stage ) || $expected_hash !== hash_file( 'sha256', $stage ) || ! rename( $stage, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Rollback uses the same atomic same-filesystem replacement.
			self::remove_file( $stage );
			return self::error( 'sku_guard_mu_rollback_failed', 'The exact MU-loader backup could not be restored atomically.' );
		}
		if ( $expected_hash !== hash_file( 'sha256', $target ) ) {
			return self::error( 'sku_guard_mu_rollback_verification_failed', 'The restored MU loader hash does not match its backup.' );
		}

		return true;
	}

	/** Remove only one exact temporary file when it exists. */
	private static function remove_file( $path ) {
		if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only an exact installer-created staging file.
		}
	}

	/** Build one typed deployment error. */
	private static function error( $code, $message ) {
		return new WP_Error( $code, __( $message, 'digitalogic' ), array( 'status' => 500 ) );
	}
}
