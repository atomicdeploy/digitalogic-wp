<?php
/**
 * Native RequestController adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use Digitalogic\Laravel\Services\MobileRequestStore as Store;
use Digitalogic\Laravel\Services\MobileRequestSchema as Schema;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Native adapter over canonical WordPress and WooCommerce authority. */
final class MobileRequestController {
	public const CHUNK_SIZE = 524288;
	/**
	 * Report whether the deployed adapter and its required provider are available.
	 *
	 * @return bool Native adapter result.
	 */
	public static function enabled(): bool {
		return get_option( 'digitalogic_mobile_requests_schema' ) === '1' && (bool) get_option( 'digitalogic_mobile_forms', false ) && MobileIdentityController::enabled();}
	/**
	 * Expose the currently supported canonical service-form schemas.
	 *
	 * @return Response Native adapter result.
	 */
	public function forms(): Response {
		return $this->json( array( 'forms' => Schema::forms() ) );}

	/**
	 * Reserve an owner-bound, quota-limited resumable upload.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function createUpload( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->error( 'unauthorized', 'ابتدا وارد حساب شوید.', 401 );
		}$user       = (int) $session['user_id'];
		$input       = $request->json()->all();
		$form        = Schema::form( is_string( $input['form_type'] ?? null ) ? $input['form_type'] : '' );
		$name        = is_string( $input['filename'] ?? null ) ? sanitize_file_name( $input['filename'] ) : '';
		$size        = $input['size'] ?? 0;
		$sha         = $input['sha256'] ?? '';
		$idempotency = $input['request_id'] ?? '';
		if ( ! $form || ! Store::validRequestId( $idempotency ) || '' === $name || strlen( $name ) > 255 || ! is_int( $size ) || $size < 1 || $size > 20971520 || ! is_string( $sha ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $sha ) || ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), $form['upload']['extensions'], true ) ) {
			return $this->error( 'invalid_upload', 'نام، اندازه یا نوع فایل معتبر نیست.', 400 );}
		$table    = Store::table( 'uploads' );
		$key      = Store::requestKey( $user, 'upload:' . $idempotency );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_hash=%s AND user_id=%d', $table, $key, $user ), ARRAY_A );
		if ( $existing ) {
			if ( $existing['sha256'] !== $sha || (int) $existing['size'] !== $size || $existing['form_type'] !== $form['id'] || $existing['filename'] !== $name ) {
				return $this->error( 'request_id_conflict', 'این شناسه برای فایل دیگری استفاده شده است.', 409 );
			}return (int) $existing['expires_at'] > time() ? $this->json( $this->progress( $existing ) ) : $this->error( 'upload_expired', 'مهلت این بارگذاری تمام شده است.', 410 );}
		if ( ! Identity::admit( 'uploads-user:' . $user, 12, 3600 ) || ! Identity::admit( 'uploads-global', 60, 60 ) ) {
			return $this->error( 'rate_limited', 'برای بارگذاری بعدی کمی صبر کنید.', 429 );}
		if ( (int) $wpdb->get_var( "SELECT GET_LOCK('digitalogic_mobile_upload_quota',2)" ) !== 1 ) {
			return $this->error( 'upload_busy', 'بارگذاری‌های دیگر در حال آماده‌سازی‌اند.', 409 );
		}
		try {
			Store::cleanupUploads();
			$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id=%d AND state<>'consumed'", $table, $user ) );
			$bytes  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(size),0) FROM %i WHERE state<>'consumed'", $table ) );
			if ( $active >= 5 || $bytes + $size > 314572800 ) {
				return $this->error( 'upload_quota', 'بارگذاری‌های قبلی را تکمیل یا حذف کنید.', 429 );}
			$id   = bin2hex( random_bytes( 16 ) );
			$path = Store::privateDirectory() . '/' . $id . '.part';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
			$file = fopen( $path, 'xb' );
			if ( ! $file ) {
				return $this->error( 'storage_unavailable', 'فضای ذخیره‌سازی در دسترس نیست.', 503 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
			}fclose( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
			chmod( $path, 0640 );
			$row = array(
				'id'           => $id,
				'request_hash' => $key,
				'user_id'      => $user,
				'form_type'    => $form['id'],
				'filename'     => $name,
				'size'         => $size,
				'sha256'       => $sha,
				'path'         => $path,
				'state'        => 'uploading',
				'expires_at'   => time() + DAY_IN_SECONDS,
			);
			if ( $wpdb->insert( $table, $row ) !== 1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
				unlink( $path );
				return $this->error( 'request_conflict', 'درخواست هم‌زمان در حال بررسی است.', 409 );}
			return $this->json( $this->progress( $row ), 201 );
		} finally {
			$wpdb->get_var( "SELECT RELEASE_LOCK('digitalogic_mobile_upload_quota')" ); }
	}

	/**
	 * Authenticate and dispatch operations for one owned upload.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @param string  $id Exact scoped identifier.
	 * @return Response Native adapter result.
	 */
	public function upload( Request $request, string $id ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->error( 'unauthorized', 'ابتدا وارد حساب شوید.', 401 );}
		$write = true; // Resume reads can recover a final chunk written before a process exit.
		if ( $write ) {
			$wpdb->query( 'START TRANSACTION' );}
		try {
			return $this->uploadOwned( $request, $id, (int) $session['user_id'], $write );
		} finally {
			if ( $write ) {
				$wpdb->query( 'COMMIT' );}
		}
	}

	/**
	 * Apply bounded chunks and offset checks under the upload owner’s lock.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @param string  $id Exact scoped identifier.
	 * @param int     $user User.
	 * @param bool    $write Write.
	 * @return Response Native adapter result.
	 */
	private function uploadOwned( Request $request, string $id, int $user, bool $write ): Response {
		global $wpdb;
		$table = Store::table( 'uploads' );
		if ( $write ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND user_id=%d FOR UPDATE', $table, $id, $user ), ARRAY_A );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND user_id=%d', $table, $id, $user ), ARRAY_A );
		}
		if ( ! $row ) {
			return $this->error( 'upload_not_found', 'فایل پیدا نشد.', 404 );
		}
		if ( $request->isMethod( 'DELETE' ) ) {
			if ( 'consumed' === $row['state'] ) {
				return $this->error( 'file_already_submitted', 'فایل به درخواست ثبت‌شده تعلق دارد.', 409 );
			}if ( is_file( $row['path'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
				unlink( $row['path'] );
			}$wpdb->delete(
				$table,
				array(
					'id'      => $id,
					'user_id' => $user,
				)
			);
			return $this->json( array( 'deleted' => true ) );}
		if ( (int) $row['expires_at'] <= time() && 'consumed' !== $row['state'] ) {
			return $this->error( 'upload_expired', 'مهلت بارگذاری تمام شده است.', 410 );
		}
		if ( ! $request->isMethod( 'PUT' ) ) {
			$error = $this->finalizeUpload( $row );
			if ( $error ) {
				return $error;}
			return $this->json( $this->progress( $row ) );}
		$offset  = $request->headers->get( 'Upload-Offset', '' );
		$content = $request->getContent();
		$length  = strlen( $content );
		if ( ! preg_match( '/\A[0-9]{1,9}\z/D', $offset ) || $length < 1 || $length > self::CHUNK_SIZE || (int) $offset + $length > (int) $row['size'] || ! in_array( $row['state'], array( 'uploading', 'complete' ), true ) ) {
			return $this->error( 'invalid_chunk', 'بخش فایل معتبر نیست.', 400 );}
		$range = $request->headers->get( 'Content-Range', '' );
		if ( '' !== $range && 'bytes ' !== $range . $offset . '-' . ( (int) $offset + $length - 1 ) . '/' . $row['size'] ) {
			return $this->error( 'invalid_content_range', 'بازه فایل معتبر نیست.', 400 );}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
		$stream = fopen( $row['path'], 'c+b' );
		if ( ! $stream || ! flock( $stream, LOCK_EX ) ) {
			if ( $stream ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
				fclose( $stream );
			}return $this->error( 'upload_busy', 'فایل در حال ذخیره است.', 409 );}
		try {
			$current = (int) fstat( $stream )['size'];
			if ( (int) $offset < $current && (int) $offset + $length <= $current ) {
				fseek( $stream, (int) $offset );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
				$same = hash_equals( hash( 'sha256', $content ), hash( 'sha256', fread( $stream, $length ) ) );
				if ( $same ) {
					$error = $this->finalizeUpload( $row, $stream );
					if ( $error ) {
						return $error;}
				}
				return $same ? $this->json( $this->progress( $row, $current ) ) : $this->json(
					array(
						'code'    => 'offset_conflict',
						'message' => 'این بخش قبلاً با داده دیگری ذخیره شده است.',
						'offset'  => $current,
					),
					409
				);}
			if ( (int) $offset !== $current ) {
				return $this->json(
					array(
						'code'    => 'offset_conflict',
						'message' => 'ادامه بارگذاری را از اندازه ذخیره‌شده آغاز کنید.',
						'offset'  => $current,
					),
					409
				);}
			fseek( $stream, 0, SEEK_END );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
			$written = fwrite( $stream, $content );
			fflush( $stream );
			if ( $written !== $length ) {
				ftruncate( $stream, $current );
				return $this->error( 'storage_unavailable', 'ذخیره این بخش کامل نشد.', 503 );}
			$current += $length;
			$error    = $this->finalizeUpload( $row, $stream );
			if ( $error ) {
				return $error;}
			return $this->json( $this->progress( $row, $current ) );
		} finally {
			flock( $stream, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
			fclose( $stream );}
	}

	/**
	 * Recover durable bytes after a process exit, and never acknowledge an unsaved final state.
	 *
	 * @param array $row Supporting durable metadata row.
	 * @param mixed $stream Stream.
	 * @return Response|null Native adapter result.
	 */
	private function finalizeUpload( array &$row, $stream = null ): ?Response {
		global $wpdb;
		clearstatcache( true, $row['path'] );
		if ( 'uploading' !== $row['state'] || ! is_file( $row['path'] ) || (int) filesize( $row['path'] ) !== (int) $row['size'] ) {
			return null;}
		$owned = null === $stream;
		if ( $owned ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
			$stream = fopen( $row['path'], 'c+b' );
			if ( ! $stream || ! flock( $stream, LOCK_EX ) ) {
				if ( $stream ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
					fclose( $stream );
				}return $this->error( 'upload_busy', 'فایل در حال ذخیره است.', 409 );}
		}
		try {
			if ( ! hash_equals( $row['sha256'], hash_file( 'sha256', $row['path'] ) ) || ! Store::fileTypeMatches( $row['path'], strtolower( pathinfo( $row['filename'], PATHINFO_EXTENSION ) ) ) ) {
				ftruncate( $stream, 0 );
				fflush( $stream );
				$wpdb->update( Store::table( 'uploads' ), array( 'state' => 'failed' ), array( 'id' => $row['id'] ) );
				return $this->error( 'file_integrity_failed', 'محتوای فایل با اطلاعات بارگذاری سازگار نیست.', 422 );
			}
			if ( $wpdb->update(
				Store::table( 'uploads' ),
				array( 'state' => 'complete' ),
				array(
					'id'    => $row['id'],
					'state' => 'uploading',
				)
			) !== 1 ) {
				return $this->error( 'storage_unavailable', 'وضعیت فایل ذخیره نشد؛ دوباره بررسی کنید.', 503 );}
			$row['state'] = 'complete';
			return null;
		} finally {
			if ( $owned ) {
				flock( $stream, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
				fclose( $stream );}
		}
	}

	/**
	 * Submit an explicitly confirmed immutable operation with durable recovery state.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public function submit( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->error( 'unauthorized', 'ابتدا وارد حساب شوید.', 401 );
		}$user  = (int) $session['user_id'];
		$input  = $request->json()->all();
		$id     = $input['request_id'] ?? '';
		$type   = $input['form_type'] ?? '';
		$fields = $input['fields'] ?? null;
		$upload = $input['upload_id'] ?? '';
		$form   = is_string( $type ) ? Schema::form( $type ) : null;
		if ( ! Store::validRequestId( $id ) || ! $form || ! is_array( $fields ) || ! is_string( $upload ) || ( '' !== $upload && ! preg_match( '/\A[a-f0-9]{32}\z/D', $upload ) ) ) {
			return $this->error( 'invalid_request', 'اطلاعات درخواست معتبر نیست.', 400 );}
		if ( isset( $input['schema_revision'] ) && $input['schema_revision'] !== $form['revision'] ) {
			return $this->error( 'schema_changed', 'گزینه‌های فرم به‌روز شده‌اند؛ ابتدا فرم را تازه کنید.', 409 );}
		$error = self::validateFields( $fields, $form['fields'] );
		if ( $error ) {
			return $this->json( $error, 422 );}
		$phone = Identity::phone( (string) ( $fields['mobile'] ?? '' ) );
		if ( ! $phone ) {
			return $this->error( 'invalid_mobile', 'شماره موبایل معتبر وارد کنید.', 422 );
		}$fields['mobile'] = $phone;
		$forms             = \Digitalogic_Storefront_Order_Forms::instance();
		$data              = $forms->validate_native( $type, $fields );
		if ( is_wp_error( $data ) ) {
			return $this->error( $data->get_error_code(), 'بخش‌های ضروری فرم را کامل کنید.', 422 );}
		if ( 'pcb' === $type && '' === $upload ) {
			return $this->error( 'file_required', 'فایل Gerber را بارگذاری کنید.', 422 );
		}if ( 'foreign' === $type && empty( $data['payload']['items'] ) && '' === $upload ) {
			return $this->error( 'no_items', 'حداقل یک قطعه یا فایل BOM لازم است.', 422 );}
		$key      = Store::requestKey( $user, $id );
		$hash     = hash( 'sha256', wp_json_encode( array( $type, $data, $upload ) ) );
		$table    = Store::table( 'requests' );
		$uploads  = Store::table( 'uploads' );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_hash=%s AND user_id=%d', $table, $key, $user ), ARRAY_A );
		if ( $existing ) {
			return $existing['payload_hash'] === $hash ? $this->json( array( 'request' => json_decode( $existing['result'], true ) ) ) : $this->error( 'request_id_conflict', 'این شناسه برای درخواست دیگری استفاده شده است.', 409 );}
		if ( ! Identity::admit( 'request-user:' . $user, 10, 3600 ) ) {
			return $this->error( 'rate_limited', 'برای درخواست بعدی کمی صبر کنید.', 429 );}
		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( $wpdb->insert(
				$table,
				array(
					'request_hash' => $key,
					'user_id'      => $user,
					'payload_hash' => $hash,
					'result'       => '{}',
					'created_at'   => time(),
				)
			) !== 1 ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->error( 'request_in_progress', 'درخواست در حال ذخیره است؛ وضعیت همین شناسه را بررسی کنید.', 409 );}
			$file = array();
			if ( '' !== $upload ) {
				$file_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND user_id=%d FOR UPDATE', $uploads, $upload, $user ), ARRAY_A );
				if ( ! $file_row || 'complete' !== $file_row['state'] || $file_row['form_type'] !== $type || (int) $file_row['expires_at'] <= time() || ! is_file( $file_row['path'] ) ) {
					throw new \RuntimeException( 'invalid_upload' );
				}$file = array(
					'path'          => $file_row['path'],
					'original_name' => $file_row['filename'],
					'extension'     => strtolower( pathinfo( $file_row['filename'], PATHINFO_EXTENSION ) ),
					'size'          => (int) $file_row['size'],
				);}
			$result = $forms->persist_native( $type, $data, $user, $file );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( 'save_failed' );}
			if ( $wpdb->update( $table, array( 'result' => wp_json_encode( $result ) ), array( 'request_hash' => $key ) ) !== 1 ) {
				throw new \RuntimeException( 'save_failed' );}
			if ( '' !== $upload && $wpdb->update(
				$uploads,
				array( 'state' => 'consumed' ),
				array(
					'id'      => $upload,
					'user_id' => $user,
				)
			) !== 1 ) {
				throw new \RuntimeException( 'save_failed' );}
			if ( $wpdb->query( 'COMMIT' ) === false ) {
				throw new \RuntimeException( 'save_failed' );}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->error( $error->getMessage() === 'invalid_upload' ? 'invalid_upload' : 'save_failed', 'درخواست ثبت نشد؛ فایل و اطلاعات را بررسی کنید.', 409 );}
		try {
			$forms->notify_native( $result, $data );} catch ( \Throwable $error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure signal contains no token, phone, customer data or exception details.
			error_log( 'Digitalogic native request notification was not enqueued; durable request remains available.' );}
			return $this->json( array( 'request' => $result ), 201 );
	}

	/**
	 * Recover an owner-scoped operation without automatically repeating its side effects.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @param string  $id Exact scoped identifier.
	 * @return Response Native adapter result.
	 */
	public function lookup( Request $request, string $id ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->error( 'unauthorized', 'ابتدا وارد حساب شوید.', 401 );
		}$table = Store::table( 'requests' );
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT result FROM %i WHERE request_hash=%s AND user_id=%d', $table, Store::requestKey( (int) $session['user_id'], $id ), $session['user_id'] ) );
		if ( ! $result ) {
			return $this->error( 'request_not_found', 'درخواست پیدا نشد.', 404 );
		}$data          = json_decode( $result, true );
		$data['status'] = (string) get_post_meta( (int) $data['id'], '_dgl_request_status', true );
		return $this->json( array( 'request' => $data ) );
	}

	/**
	 * List the owner’s existing private service-request records.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function requests( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->error( 'unauthorized', 'ابتدا وارد حساب شوید.', 401 );
		}$page = max( 1, min( 500, (int) $request->query( 'page', 1 ) ) );
		$query = new \WP_Query(
			array(
				'post_type'      => 'digitalogic_request',
				'post_status'    => 'private',
				'author'         => (int) $session['user_id'],
				'posts_per_page' => 20,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'         => (int) $post->ID,
				'code'       => get_post_meta( $post->ID, '_dgl_request_code', true ),
				'type'       => get_post_meta( $post->ID, '_dgl_request_type', true ),
				'status'     => get_post_meta( $post->ID, '_dgl_request_status', true ),
				'created_at' => get_post_time( 'c', true, $post ),
				'title'      => get_post_meta( $post->ID, '_dgl_request_payload', true )['project_name'] ?? '',
			);}
		$more = $page < $query->max_num_pages;
		return $this->json(
			array(
				'items'      => $items,
				'pagination' => array(
					'page'      => $page,
					'has_more'  => $more,
					'next_page' => $more ? $page + 1 : null,
				),
			)
		);
	}

	/**
	 * Apply the canonical service-form validator to supported input.
	 *
	 * @param array $input Validated or pending native input.
	 * @param array $fields Canonical form or address fields.
	 * @return array|null Native adapter result.
	 */
	public static function validateFields( array $input, array $fields ): ?array {
		$allowed = array_column( $fields, 'key' );
		if ( array_diff( array_keys( $input ), $allowed ) ) {
			return array(
				'code'    => 'unknown_field',
				'message' => 'فیلد نامعتبر در فرم وجود دارد.',
			);}
		foreach ( $fields as $field ) {
			$key     = $field['key'];
			$value   = $input[ $key ] ?? $field['default'] ?? '';
			$invalid = false;
			if ( 'items' === $field['type'] ) {
				$invalid = ! is_array( $value ) && '' !== $value;
				if ( is_array( $value ) ) {
					if ( count( $value ) > 10 || ! array_is_list( $value ) ) {
						$invalid = true;
					} else {
						foreach ( $value as $item ) {
							if ( ! is_array( $item ) || self::validateFields( $item, $field['fields'] ) ) {
								$invalid = true;
								break;}
						}
					}
				}
			} elseif ( ! is_scalar( $value ) ) {
				$invalid = true;
			} elseif ( ! empty( $field['required'] ) && trim( (string) $value ) === '' ) {
				$invalid = true;
			} elseif ( isset( $field['max_length'] ) && mb_strlen( (string) $value ) > $field['max_length'] ) {
				$invalid = true;
			} elseif ( 'select' === $field['type'] && ! in_array( (string) $value, array_column( $field['options'], 'value' ), true ) ) {
				$invalid = true;
			} elseif ( 'number' === $field['type'] && '' !== $value ) {
				$number          = Identity::digits( (string) $value );
						$invalid = ! is_numeric( $number ) || ( isset( $field['min'] ) && (float) $number < $field['min'] ) || ( isset( $field['max'] ) && (float) $number > $field['max'] );}
			if ( $invalid ) {
				return array(
					'code'    => 'invalid_field',
					'message' => 'مقدار «' . $field['label'] . '» معتبر نیست.',
					'field'   => $key,
				);}
		}
		return null;
	}
	/**
	 * Authenticate the supplied native bearer only when native authentication is enabled.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return array|null Native adapter result.
	 */
	private function session( Request $request ): ?array {
		return self::enabled() ? Identity::authenticate( $request->headers->get( 'Authorization', '' ) ) : null;}
	/**
	 * Project resumable-upload state without exposing storage paths.
	 *
	 * @param array    $row Supporting durable metadata row.
	 * @param int|null $offset Offset.
	 * @return array Native adapter result.
	 */
	private function progress( array $row, ?int $offset = null ): array {
		clearstatcache( true, $row['path'] );
		return array(
			'upload_id'  => $row['id'],
			'offset'     => $offset ?? ( is_file( $row['path'] ) ? (int) filesize( $row['path'] ) : 0 ),
			'size'       => (int) $row['size'],
			'chunk_size' => self::CHUNK_SIZE,
			'state'      => $row['state'],
			'expires_at' => gmdate( 'c', (int) $row['expires_at'] ),
		);}
	/**
	 * Return a bounded error code and customer-facing message.
	 *
	 * @param string $code Bounded error or challenge code.
	 * @param string $message Message.
	 * @param int    $status HTTP or durable operation status.
	 * @return Response Native adapter result.
	 */
	private function error( string $code, string $message, int $status ): Response {
		return $this->json(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);}
	/**
	 * Return a native JSON response with the appropriate privacy headers.
	 *
	 * @param array $data Canonical or validated native data.
	 * @param int   $status HTTP or durable operation status.
	 * @return Response Native adapter result.
	 */
	private function json( array $data, int $status = 200 ): Response {
		return response()->json(
			$data,
			$status,
			array(
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);}
}
