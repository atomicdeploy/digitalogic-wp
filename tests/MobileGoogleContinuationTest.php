<?php
/**
 * Google-to-phone continuation boundaries without provider calls or real identities.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Isolated namespace/database fixture intentionally mirrors only the exercised protocol.
namespace Digitalogic\Laravel\Services {
	function wc_terms_and_conditions_page_id() { return 0; }
	function wc_terms_and_conditions_checkbox_enabled() { return true; }
	function wc_get_terms_and_conditions_checkbox_text() { return 'Fixture terms'; }
	function clean_user_cache($id) { if(!isset($GLOBALS['continuation_fixture_users'])) \clean_user_cache($id); }
	function get_user_by($field, $id) {
		if (isset($GLOBALS['continuation_fixture_users'])) return $GLOBALS['continuation_fixture_users'][$id] ?? false;
		return \get_user_by($field, $id);
	}
}

namespace {
	use Digitalogic\Laravel\Services\MobileGoogleContinuation as Continuation;
	use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
	use Digitalogic\Laravel\Services\MobileRegistrationStore as Registration;
	use Digitalogic\Laravel\Http\Controllers\MobileIdentityController as Controller;
	use Illuminate\Http\Request;
	use PHPUnit\Framework\TestCase;

	foreach (['MobileIdentityStore','MobileIdentityResolver','MobileRegistrationStore','MobileGoogleContinuation','MobileRequestStore'] as $service) require_once dirname(__DIR__).'/laravel/app/Services/'.$service.'.php';
	require_once dirname(__DIR__).'/laravel/app/Http/Controllers/MobileIdentityController.php';
	require_once dirname(__DIR__).'/includes/integrations/class-smsir-integration.php';

	final class Digitalogic_GoogleContinuation_Fixture_DB {
		public string $prefix='fixture_', $users='fixture_users', $usermeta='fixture_usermeta', $last_error='';
		public array $rows=[], $emails=[], $phones=[], $writes=[], $queries=[];
		public int $acquired=0, $released=0;
		private ?array $snapshot=null;
		public function prepare($sql,...$args) { return ['query'=>$sql,'args'=>$args]; }
		public function get_row($sql,$format=null) {
			$this->last_error=''; $this->queries[]=$sql;
			$args=$sql['args']; $table=$args[0]; $key=$args[1];
			if(str_contains($table,'sessions')) {
				foreach($this->rows[$table]??[] as $row) if($row['token_hash']===$key && $row['expires_at']>time()) return $row;
				return null;
			}
			$row=$this->rows[$table][$key]??null;
			if($row && str_contains($sql['query'],'expires_at>') && $row['expires_at']<=($args[2]??time())) return null;
			return $row;
		}
		public function get_results($sql,$format=null) {
			$this->last_error=''; $this->queries[]=$sql;
			$text=is_array($sql)?$sql['query']:$sql;
			if(str_contains($text,'auth_google_id')) return [];
			return str_contains($text,'user_email')?$this->emails:$this->phones;
		}
		public function get_var($sql) {
			$this->last_error=''; $text=is_array($sql)?$sql['query']:$sql;
			if(str_contains($text,'GET_LOCK')) { $this->acquired++; return 1; }
			if(str_contains($text,'RELEASE_LOCK')) { $this->released++; return 1; }
			throw new LogicException('Unmodelled fixture scalar lookup.');
		}
		public function query($sql) {
			$this->last_error=''; $this->queries[]=$sql; $text=is_array($sql)?$sql['query']:$sql;
			if($text==='START TRANSACTION') { $this->snapshot=$this->rows; return 1; }
			if($text==='ROLLBACK') { if($this->snapshot!==null) $this->rows=$this->snapshot; $this->snapshot=null; return 1; }
			if($text==='COMMIT') { $this->snapshot=null; return 1; }
			throw new LogicException('Unexpected fixture mutation; no OTP/provider writes allowed.');
		}
		public function insert($table,$row) {
			$this->writes[]=['insert',$table];
			if($table!==Continuation::table()) throw new LogicException('No user/session/OTP creation permitted in fixtures.');
			$this->rows[$table][$row['token_hash']]=$row; return 1;
		}
		public function update($table,$data,$where,...$formats) {
			$this->writes[]=['update',$table];
			$key=$where['token_hash']??$where['id']??null;
			if(!isset($this->rows[$table][$key])) return 0;
			foreach($where as $field=>$value) if(($this->rows[$table][$key][$field]??null)!==$value) return 0;
			$this->rows[$table][$key]=array_replace($this->rows[$table][$key],$data); return 1;
		}
	}

	final class MobileGoogleContinuationTest extends TestCase {
		private const PHONE='+989123456789';
		private const UUID='079fdcbe-c45c-4aef-9b0b-587b8c6f68a0';
		private Digitalogic_GoogleContinuation_Fixture_DB $database;
		private array $saved;
		private \Illuminate\Container\Container $previousContainer;
		protected function setUp(): void {
			$this->saved=[];
			foreach(['wpdb','wp_object_cache','digitalogic_test_options','digitalogic_test_option_cache','digitalogic_test_user_meta','digitalogic_test_remote_posts'] as $key) $this->saved[$key]=$GLOBALS[$key]??null;
			$this->previousContainer=\Illuminate\Container\Container::getInstance();
			$container=new \Illuminate\Container\Container();
			$factory=$this->createStub(\Illuminate\Contracts\Routing\ResponseFactory::class);
			$factory->method('json')->willReturnCallback(static fn($data,$status=200,$headers=[],$options=0)=>new \Illuminate\Http\JsonResponse($data,$status,$headers,$options));
			$container->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,$factory);
			\Illuminate\Container\Container::setInstance($container);
			$GLOBALS['wp_object_cache']=new \Digitalogic\Laravel\Services\MobileMergeObjectCache(1);
			$this->database=new Digitalogic_GoogleContinuation_Fixture_DB(); $GLOBALS['wpdb']=$this->database;
			$GLOBALS['digitalogic_test_option_cache']=[]; $GLOBALS['digitalogic_test_user_meta']=[]; $GLOBALS['digitalogic_test_remote_posts']=[];
			$GLOBALS['digitalogic_test_options']=[
				'digitalogic_mobile_identity_schema'=>'2','digitalogic_mobile_native_auth'=>true,
				'digitalogic_mobile_registration_schema'=>'1','dig_enable_registration'=>'1','dig_mobilein_uname'=>'1',
				'dig_reg_fields'=>['dig_reg_mobilenumber'=>'2','dig_reg_email'=>'0','dig_reg_password'=>'0'],
				'digit_smsir2'=>['apiKey'=>'fixture-not-a-provider-credential','template'=>'1','variables'=>'OTP:%OTP%'],
			];
			$GLOBALS['continuation_fixture_users']=[];
		}
		protected function tearDown(): void {
			self::assertSame($this->database->acquired,$this->database->released);
			self::assertSame([],$GLOBALS['digitalogic_test_remote_posts']);
			foreach($this->saved as $key=>$value) $GLOBALS[$key]=$value;
			\Illuminate\Container\Container::setInstance($this->previousContainer);
			unset($GLOBALS['continuation_fixture_users']);
		}
		private function claims(): array { return ['sub'=>'123456789012345678901','email'=>'fixture.person@gmail.com','iat'=>time(),'exp'=>time()+3600]; }
		private function begin(): array { return Continuation::begin($this->claims(),'fixture-verified-google-token',null); }
		private function challenge(string $token): array {
			return ['id'=>str_repeat('a',32),'purpose'=>'login','state'=>'used','created_at'=>time(),'expires_at'=>time()+300,
				'continuation_hash'=>hash('sha256',$token),'phone_hash'=>Identity::hash(self::PHONE),
				'phone_ciphertext'=>Registration::seal(self::PHONE,'phone'),'code_hash'=>Identity::hash(str_repeat('a',32).':123456:'.Identity::hash(self::PHONE))];
		}
		private function errorIs(string $code,$value): void { self::assertInstanceOf(WP_Error::class,$value); self::assertSame($code,$value->get_error_code()); }
		public function test_stale_google_proof_stops_before_creating_continuation(): void {
			$claims=$this->claims(); $claims['iat']=time()-601;
			try { Continuation::begin($claims,'fixture-token',null); self::fail('Fresh proof required.'); }
			catch(UnexpectedValueException $error) { self::assertSame('fresh_google_required',$error->getMessage()); }
			self::assertSame([],$this->database->writes);
		}
		public function test_fresh_nonce_can_continue_cached_jwt_but_does_not_extend_original_replay(): void {
			$claims=$this->claims(); $claims['iat']=time()-1200;
			$fresh=['verified_at'=>time()-5,'created_at'=>time()-6,'expires_at'=>time()+294];
			$first=Continuation::begin($claims,'fixture-token',$fresh);
			$second=Continuation::begin($claims,'fixture-token',$fresh);
			self::assertSame($first,$second); self::assertCount(1,$this->database->writes);
			$row=Continuation::read($first['continuation_token']);
			self::assertSame($fresh['verified_at'],$row['proof']['proof_at']);
			self::assertLessThanOrEqual($fresh['verified_at']+600,$row['expires_at']);
			self::assertStringNotContainsString('fixture.person@gmail.com',$row['claims_ciphertext']);
		}
		public function test_ambiguous_normalized_email_stops_continuation_without_new_identity(): void {
			$this->database->emails=[['ID'=>7,'user_email'=>'fixtureperson@gmail.com'],['ID'=>8,'user_email'=>'fixture.person+shop@googlemail.com']];
			$this->expectException(UnexpectedValueException::class);
			$this->expectExceptionMessage('identity_discovery_conflict');
			Continuation::begin($this->claims(),'fixture-token',null);
		}
		public function test_expired_continuation_blocks_before_otp_insert_or_sms(): void {
			$continuation=$this->begin(); $hash=hash('sha256',$continuation['continuation_token']);
			$this->database->rows[Continuation::table()][$hash]['expires_at']=time()-1;
			$before=$this->database->writes;
			$request=Request::create('/auth/otp/request','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],wp_json_encode(['phone'=>self::PHONE,'request_id'=>self::UUID,'continuation_token'=>$continuation['continuation_token']]));
			$response=(new Controller())->requestOtp($request);
			self::assertSame(410,$response->getStatusCode());
			self::assertSame('continuation_expired',json_decode($response->getContent(),true)['code']);
			self::assertSame($before,$this->database->writes);
		}
		public function test_link_consent_is_required_before_consuming_bound_otp(): void {
			$continuation=$this->begin(); $challenge=$this->challenge($continuation['continuation_token']);
			$this->database->rows[Identity::table('otp')][$challenge['id']]=$challenge;
			$before=$this->database->writes;
			$request=Request::create('/auth/otp/verify','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],wp_json_encode(['challenge_id'=>$challenge['id'],'code'=>'123456','continuation_token'=>$continuation['continuation_token'],'link_consent'=>false]));
			$response=(new Controller())->verifyOtp($request);
			self::assertSame(422,$response->getStatusCode());
			self::assertSame('identity_link_consent_required',json_decode($response->getContent(),true)['code']);
			self::assertSame($before,$this->database->writes);
		}
		public function test_continuation_and_phone_hash_mismatch_never_create_or_bind(): void {
			$continuation=$this->begin(); $challenge=$this->challenge($continuation['continuation_token']);
			$before=$this->database->writes;
			$wrong=$challenge; $wrong['continuation_hash']=str_repeat('b',64);
			$this->errorIs('identity_continuation_mismatch',Continuation::finishPhone($continuation['continuation_token'],$wrong,'fixture'));
			$wrong=$challenge; $wrong['phone_hash']=str_repeat('b',64);
			$this->errorIs('identity_phone_changed',Continuation::finishPhone($continuation['continuation_token'],$wrong,'fixture'));
			self::assertSame($before,$this->database->writes);
		}
		public function test_known_normalized_email_and_other_phone_owner_never_create_duplicate(): void {
			$this->database->emails=[['ID'=>7,'user_email'=>'fixtureperson+existing@gmail.com']];
			$continuation=$this->begin(); $challenge=$this->challenge($continuation['continuation_token']);
			$this->database->phones=[['user_id'=>8,'meta_key'=>'digits_phone','meta_value'=>self::PHONE]];
			$before=$this->database->writes;
			$this->errorIs('identity_merge_required',Continuation::finishPhone($continuation['continuation_token'],$challenge,'fixture'));
			self::assertSame($before,$this->database->writes);
		}
		public function test_unknown_phone_with_existing_normalized_email_cannot_enroll(): void {
			$this->database->emails=[['ID'=>7,'user_email'=>'fixtureperson@gmail.com']];
			$continuation=$this->begin(); $before=$this->database->writes;
			$this->errorIs('identity_merge_required',Continuation::finishPhone($continuation['continuation_token'],$this->challenge($continuation['continuation_token']),'fixture'));
			self::assertSame($before,$this->database->writes);
		}
		public function test_completed_registration_same_uuid_recovers_after_google_proof_expiry(): void {
			$continuation=$this->begin(); $hash=hash('sha256',$continuation['continuation_token']);
			$this->database->rows[Continuation::table()][$hash]['expires_at']=time()-1;
			$registration=str_repeat('c',64); $bearer=str_repeat('d',64); $password='fixture-password-epoch';
			$session=['registered'=>true,'user_id'=>8,'access_token'=>$bearer,'session_id'=>str_repeat('e',32),'expires_at'=>gmdate('c',time()+600)];
			$this->database->rows[Registration::table()][hash('sha256',$registration)]=[
				'token_hash'=>hash('sha256',$registration),'phone_ciphertext'=>Registration::seal(self::PHONE,'phone'),'continuation_hash'=>$hash,
				'state'=>'registered','user_id'=>8,'request_hash'=>Identity::hash(self::UUID.':fixture'),'expires_at'=>time()+300,
				'session_ciphertext'=>Registration::seal(wp_json_encode($session),'session')];
			$GLOBALS['continuation_fixture_users'][8]=(object)['ID'=>8,'user_pass'=>$password];
			$this->database->rows[Identity::table('sessions')][$session['session_id']]=[
				'id'=>$session['session_id'],'token_hash'=>hash('sha256',$bearer),'user_id'=>8,'auth_epoch'=>Identity::hash($password),'expires_at'=>time()+600,'last_seen_at'=>time()];
			$result=Registration::complete($registration,self::UUID,'fixture',true,$continuation['continuation_token']);
			self::assertIsArray($result); self::assertSame($bearer,$result['access_token']); self::assertTrue($result['replayed']);
			self::assertInstanceOf(WP_Error::class,Registration::complete($registration,self::UUID,'fixture',true,str_repeat('f',64)));
			self::assertInstanceOf(WP_Error::class,Registration::complete($registration,self::UUID,'fixture',true,null));
			$this->errorIs('request_id_conflict',Registration::complete($registration,'079fdcbe-c45c-4aef-9b0b-587b8c6f68a1','fixture',true,$continuation['continuation_token']));
			$this->database->rows[Identity::table('sessions')]=[];
			$result=Registration::complete($registration,self::UUID,'fixture',true,$continuation['continuation_token']);
			self::assertInstanceOf(WP_Error::class,$result);
			self::assertStringNotContainsString($bearer,wp_json_encode($result->get_error_data()));
		}
	}
}
