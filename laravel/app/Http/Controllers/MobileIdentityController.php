<?php

namespace Digitalogic\Laravel\Http\Controllers;

use Digitalogic\Laravel\Services\MobileIdentityStore as Store;
use Digitalogic\Laravel\Services\MobileGoogleIdentity as Google;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MobileIdentityController {
	public function requestOtp( Request $request ): Response {
		global $wpdb;
		if ( ! self::otpEnabled() ) {
			return $this->error( 'native_auth_unavailable', 'فعلاً از ورود وب‌سایت استفاده کنید.', 503 );
		}
		$phone = Store::phone( (string) $request->json( 'phone', '' ) );
		$request_id = (string) $request->json( 'request_id', '' );
		if ( $phone === null || ! preg_match( '/\A[A-Za-z0-9-]{16,64}\z/D', $request_id ) ) {
			return $this->error( 'invalid_request', 'شماره موبایل معتبر وارد کنید.', 400 );
		}
		$table = Store::table( 'otp' );
		$phone_hash = Store::hash( $phone );
		$request_hash = Store::hash( $phone . ':' . $request_id );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_hash=%s", $request_hash ), ARRAY_A );
		if ( $existing ) {
			return (int) $existing['expires_at'] > time() && $existing['state'] !== 'used' ? $this->challengeResponse( $existing ) : $this->error( 'challenge_expired', 'درخواست تازه‌ای برای کد ورود ثبت کنید.', 410 );
		}
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		if ( ! Store::admit( 'otp-global', 60, 60 ) || ! Store::admit( 'otp-ip:' . $ip, 8, 60 ) || ! Store::admit( 'otp-phone:' . $phone_hash, 1, 60 ) || ! Store::admit( 'otp-phone-hour:' . $phone_hash, 5, 3600 ) ) {
			return $this->json( array( 'code'=>'rate_limited','message'=>'برای دریافت دوباره کد کمی صبر کنید.','retry_after'=>60 ), 429 );
		}
		$id = bin2hex( random_bytes( 16 ) );
		$code = (string) random_int( 100000, 999999 );
		$row = array( 'id'=>$id,'request_hash'=>$request_hash,'phone_hash'=>$phone_hash,'user_id'=>Store::ownerOfPhone( $phone ),'code_hash'=>Store::hash( $id . ':' . $code . ':' . $phone_hash ),'attempts'=>0,'state'=>'pending','created_at'=>time(),'expires_at'=>time()+300 );
		if ( $wpdb->insert( $table, $row ) !== 1 ) {
			return $this->error( 'request_conflict', 'درخواست در حال بررسی است؛ چند لحظه صبر کنید.', 409 );
		}
		// No retry: a transport timeout may still have delivered the message.
		$result = \Digitalogic_Smsir_Integration::send_native_login_otp( $phone, $code );
		unset( $code );
		$row['state'] = is_wp_error( $result ) ? 'pending' : 'accepted';
		$wpdb->update( $table, array( 'state'=>$row['state'] ), array( 'id'=>$id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at<%d LIMIT 100", time()-DAY_IN_SECONDS ) );
		return $this->challengeResponse( $row );
	}

	public function verifyOtp( Request $request ): Response {
		global $wpdb;
		if ( ! self::otpEnabled() ) {
			return $this->error( 'native_auth_unavailable', 'فعلاً از ورود وب‌سایت استفاده کنید.', 503 );
		}
		$id = (string) $request->json( 'challenge_id', '' );
		$code = Store::digits( trim( (string) $request->json( 'code', '' ) ) );
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) || ! preg_match( '/\A[0-9]{6}\z/D', $code ) ) {
			return $this->error( 'invalid_code', 'کد شش‌رقمی معتبر وارد کنید.', 400 );
		}
		$table = Store::table( 'otp' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%s", $id ), ARRAY_A );
		$attempt = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts=attempts+1 WHERE id=%s AND attempts<5 AND expires_at>%d AND state IN ('pending','accepted')", $id, time() ) );
		if ( ! $row || $attempt !== 1 || ! hash_equals( $row['code_hash'], Store::hash( $id . ':' . $code . ':' . $row['phone_hash'] ) ) ) {
			return $this->error( 'invalid_or_expired_code', 'کد نادرست یا منقضی شده است.', 401 );
		}
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state='used' WHERE id=%s AND state IN ('pending','accepted') AND expires_at>%d", $id, time() ) );
		if ( $claimed !== 1 ) {
			return $this->error( 'code_already_used', 'این کد قبلاً استفاده شده است.', 409 );
		}
		$user_id = (int) $row['user_id'];
		if ( $user_id === 0 ) {
			return $this->json( array( 'registered'=>false,'signup_url'=>wp_registration_url() ) );
		}
		$verified_phone = $user_id > 0 ? Store::phone( (string) get_user_meta( $user_id, 'digits_phone', true ) ) : null;
		if ( $verified_phone === null || ! hash_equals( $row['phone_hash'], Store::hash( $verified_phone ) ) || Store::ownerOfPhone( $verified_phone ) !== $user_id ) {
			return $this->error( 'account_identity_changed', 'برای بررسی حساب از ورود وب‌سایت استفاده کنید.', 409 );
		}
		$session = Store::issueSession( $user_id, (string) $request->json( 'device_name', 'Android' ) );
		return $this->json( array_merge( array( 'registered'=>true,'profile'=>$this->profileData( $user_id ) ), $session ) );
	}

	public function profile( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$user_id = (int) $session['user_id'];
		if ( $request->isMethod( 'PUT' ) ) {
			$input = $request->json()->all();
			if ( array_diff( array_keys( $input ), array( 'first_name','last_name','display_name','billing' ) ) ) {
				return $this->error( 'unsupported_profile_field', 'تغییر این بخش حساب از وب‌سایت انجام می‌شود.', 400 );
			}
			$user = array( 'ID'=>$user_id );
			foreach ( array( 'first_name','last_name','display_name' ) as $key ) {
				if ( isset( $input[$key] ) && is_string( $input[$key] ) ) {
					$user[$key] = mb_substr( sanitize_text_field( $input[$key] ), 0, 80 );
				}
			}
			$billing = is_array( $input['billing'] ?? null ) ? $input['billing'] : array();
			if ( isset( $billing['email'] ) && $billing['email'] !== '' && ( ! is_string( $billing['email'] ) || ! is_email( $billing['email'] ) ) ) {
				return $this->error( 'invalid_email', 'نشانی ایمیل معتبر وارد کنید.', 400 );
			}
			$result = wp_update_user( $user );
			if ( is_wp_error( $result ) ) {
				return $this->error( 'profile_not_saved', 'اطلاعات ذخیره نشد؛ دوباره تلاش کنید.', 409 );
			}
			foreach ( self::billingFields() as $key ) {
				if ( isset( $billing[$key] ) && is_string( $billing[$key] ) ) {
					update_user_meta( $user_id, 'billing_' . $key, mb_substr( sanitize_text_field( $billing[$key] ), 0, 250 ) );
				}
			}
		}
		return $this->json( array( 'profile'=>$this->profileData( $user_id ) ) );
	}

	public function google( Request $request ): Response {
		if ( ! self::googleEnabled() ) { return $this->error( 'google_unavailable', 'ورود گوگل در دسترس نیست.', 503 ); }
		$token = $request->json( 'id_token', '' );
		if ( ! is_string( $token ) || strlen( $token ) > 6000 || substr_count( $token, '.' ) !== 2 ) { return $this->error( 'invalid_google_token', 'ورود گوگل تأیید نشد.', 401 ); }
		if ( ! Store::admit( 'google-global', 120, 60 ) || ! Store::admit( 'google-ip:' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), 20, 60 ) ) { return $this->error( 'rate_limited', 'کمی صبر کنید و دوباره تلاش کنید.', 429 ); }
		try { $keys = Google::keys(); } catch ( \Throwable $error ) { return $this->error( 'google_temporarily_unavailable', 'ارتباط با گوگل موقتاً برقرار نیست.', 503 ); }
		try { $claims = Google::verify( $token, $keys, Google::audience(), Google::presenter() ); } catch ( \Throwable $error ) { return $this->error( 'invalid_google_token', 'ورود گوگل تأیید نشد.', 401 ); }
		$user_id = Google::owner( $claims['sub'] );
		if ( $user_id === 0 ) { return $this->json( array( 'registered'=>false, 'signup_url'=>wp_registration_url() ) ); }
		// A Google token can create one native session; values never enter logs.
		if ( ! Store::claimGoogleToken( $token, $claims['exp'] ) ) { return $this->error( 'identity_token_used', 'ورود گوگل را دوباره آغاز کنید.', 409 ); }
		return $this->json( array_merge( array( 'registered'=>true, 'profile'=>$this->profileData( $user_id ) ), Store::issueSession( $user_id, (string)$request->json( 'device_name', 'Android' ) ) ) );
	}

	public function orders( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$page = max( 1, min( 500, (int) $request->query( 'page', 1 ) ) );
		$result = wc_get_orders( array( 'customer_id'=>(int)$session['user_id'],'limit'=>20,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC' ) );
		$items = array();
		foreach ( $result->orders as $order ) {
			$lines = array();
			foreach ( $order->get_items() as $item ) {
				$lines[] = array( 'id'=>$item->get_id(),'product_id'=>$item->get_product_id(),'variation_id'=>$item->get_variation_id(),'name'=>$item->get_name(),'quantity'=>$item->get_quantity(),'total'=>$item->get_total() );
			}
			$items[] = array( 'id'=>$order->get_id(),'number'=>$order->get_order_number(),'status'=>$order->get_status(),'date_created'=>$order->get_date_created()?->date('c'),'total'=>$order->get_total(),'currency'=>$order->get_currency(),'line_items'=>$lines,'payment_method_title'=>$order->get_payment_method_title() );
		}
		$more = $page < $result->max_num_pages;
		return $this->json( array( 'items'=>$items,'pagination'=>array('page'=>$page,'has_more'=>$more,'next_page'=>$more?$page+1:null) ) );
	}

	public function sessions( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$table = Store::table( 'sessions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,device_name,created_at,last_seen_at,expires_at FROM {$table} WHERE user_id=%d AND expires_at>%d ORDER BY last_seen_at DESC LIMIT 100", $session['user_id'], time() ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['current'] = $row['id'] === $session['id'];
			foreach ( array('created_at','last_seen_at','expires_at') as $key ) {
				$row[$key] = gmdate( 'c', (int)$row[$key] );
			}
		}
		return $this->json( array('items'=>$rows) );
	}

	public function logout( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$id = (string) $request->json( 'session_id', $session['id'] );
		$wpdb->delete( Store::table('sessions'), array('id'=>$id,'user_id'=>(int)$session['user_id']), array('%s','%d') );
		return $this->json( array('revoked'=>true) );
	}

	public function favorites( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$id = (int) $session['user_id'];
		$table = Store::table('favorites');
		$empty = hash('sha256','[]');
		$wpdb->query( $wpdb->prepare("INSERT IGNORE INTO {$table} (user_id,items,revision,updated_at) VALUES (%d,'[]',%s,%d)",$id,$empty,time()) );
		if ( $request->isMethod('PUT') ) {
			$items = $request->json('items');
			$base = (string)$request->json('base_revision','');
			if ( !is_array($items) || !array_is_list($items) || count($items)>500 || count(array_filter($items,static fn($value)=>is_int($value)&&$value>0))!==count($items) || !preg_match('/\A[a-f0-9]{64}\z/D',$base) ) {
				return $this->error('invalid_favorites','فهرست علاقه‌مندی معتبر نیست.',400);
			}
			$items=array_values(array_unique($items)); sort($items,SORT_NUMERIC);
			$encoded=wp_json_encode($items); $revision=hash('sha256',$encoded);
			$updated=$wpdb->query($wpdb->prepare("UPDATE {$table} SET items=%s,revision=%s,updated_at=%d WHERE user_id=%d AND revision=%s",$encoded,$revision,time(),$id,$base));
			$current=$wpdb->get_row($wpdb->prepare("SELECT items,revision FROM {$table} WHERE user_id=%d",$id),ARRAY_A);
			if($updated!==1 && $current['revision']!==$revision){return $this->json(array('code'=>'revision_conflict','items'=>json_decode($current['items'],true),'revision'=>$current['revision']),409);}
		} else {
			$current=$wpdb->get_row($wpdb->prepare("SELECT items,revision FROM {$table} WHERE user_id=%d",$id),ARRAY_A);
		}
		return $this->json(array('items'=>json_decode($current['items'],true),'revision'=>$current['revision']));
	}

	public static function enabled(): bool {
		return get_option('digitalogic_mobile_identity_schema')==='1' && (bool)get_option('digitalogic_mobile_native_auth',false);
	}
	public static function otpEnabled(): bool { return self::enabled() && \Digitalogic_Smsir_Integration::native_otp_configured(); }
	public static function googleEnabled(): bool { return self::enabled() && Google::configured(); }

	private function session( Request $request ): ?array {
		return self::enabled() ? Store::authenticate($request->headers->get('Authorization','')) : null;
	}

	private function profileData( int $id ): array {
		$user=get_user_by('id',$id); $billing=array();
		foreach(self::billingFields() as $field){$billing[$field]=(string)get_user_meta($id,'billing_'.$field,true);}
		return array('id'=>$id,'display_name'=>$user->display_name,'email'=>$user->user_email,'first_name'=>(string)get_user_meta($id,'first_name',true),'last_name'=>(string)get_user_meta($id,'last_name',true),'billing'=>$billing);
	}

	private static function billingFields(): array {
		return array('first_name','last_name','email','phone','address_1','address_2','city','state','postcode','country');
	}

	private function challengeResponse( array $row ): Response {
		return $this->json(array('challenge_id'=>$row['id'],'retry_after'=>max(0,60-(time()-(int)$row['created_at'])),'expires_in'=>max(0,(int)$row['expires_at']-time()),'expires_at'=>gmdate('c',(int)$row['expires_at']),'delivery_status'=>$row['state']==='accepted'?'accepted':'pending'),202);
	}

	private function unauthorized(): Response {return $this->error('unauthorized','دوباره وارد حساب شوید.',401);}
	private function error(string $code,string $message,int $status): Response {return $this->json(array('code'=>$code,'message'=>$message),$status);}
	private function json(array $data,int $status=200): Response {return response()->json($data,$status,array('Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
