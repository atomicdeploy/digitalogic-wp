<?php

namespace Digitalogic\Laravel\Http\Controllers;

use Digitalogic\Laravel\Services\MobileCartStore as Store;
use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use Digitalogic\Laravel\Services\MobileRequestStore;
use Digitalogic\Laravel\Services\MobileWooCart;
use Digitalogic\Laravel\Services\MobileCheckoutStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MobileCartController
{
    public static function enabled(): bool
    {
        return get_option('digitalogic_mobile_cart_schema') === '2' && (bool)get_option('digitalogic_mobile_cloud_cart', false);
    }

    public function cart( Request $request ): Response
    {
        if (! self::enabled() ) { return $this->error('cart_unavailable', 'سبد ابری موقتاً در دسترس نیست.', 503); 
        }
        $context = $this->context($request, $request->isMethod('POST'));
        if (is_wp_error($context) ) { return $this->contextError($context); 
        }
        if ($request->isMethod('DELETE') ) { return $this->discardGuest($context); 
        }
        if ($request->isMethod('PATCH') ) { return $this->patch($request, $context); 
        }
        return $this->json($this->snapshot($context['cart'], $context['token']), $request->isMethod('POST') ? 201 : 200);
    }

    public function context( Request $request, bool $create = false )
    {
        $authorization = $request->headers->get('Authorization', '');
        $session = $authorization !== '' && MobileIdentityController::enabled() ? Identity::authenticate($authorization) : null;
        if ($authorization !== '' && ! $session ) { return new \WP_Error('unauthorized', 'دوباره وارد حساب شوید.', array( 'status' => 401 )); 
        }
        $token = $request->headers->get('X-Digitalogic-Cart', '');
        if ($token !== '' ) {
            $cart = Store::byToken($token);
            if (! $cart ) { return new \WP_Error('cart_token_invalid', 'دسترسی به سبد منقضی شده است.', array( 'status' => 401 )); 
            }
            if (! empty($cart['user_id']) && ( ! $session || (int)$cart['user_id'] !== (int)$session['user_id'] ) ) {
                return new \WP_Error('cart_owner_required', 'این سبد به حساب دیگری تعلق دارد.', array( 'status' => 403 ));
            }
        } elseif ($session ) {
            $cart = Store::create((int)$session['user_id']);
            $token = Store::token($cart['id']);
        } elseif ($create ) {
            if (! Identity::admit('cart-create-ip:' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), 30, 3600) || ! Identity::admit('cart-create-global', 300, 3600) ) {
                return new \WP_Error('rate_limited', 'برای ایجاد سبد تازه کمی صبر کنید.', array( 'status' => 429 ));
            }
            $cart = Store::create();
            $token = Store::token($cart['id']);
        } else { return new \WP_Error('cart_token_required', 'ابتدا سبد خرید را آماده کنید.', array( 'status' => 401 )); 
        }
        return array( 'cart' => Store::refresh($cart), 'token' => $token, 'session' => $session );
    }

    private function patch( Request $request, array $context ): Response
    {
        global $wpdb;
        $input = $request->json()->all();
        $id = $input['operation_id'] ?? '';
        $revision = $input['base_revision'] ?? null;
        $changes = Store::changes($input['items'] ?? null);
        if (! MobileRequestStore::validRequestId($id) || ! is_int($revision) || $revision < 0 || $changes === null || array_diff(array_keys($input), array( 'operation_id', 'base_revision', 'items' )) ) {
            return $this->error('invalid_cart_operation', 'تغییر سبد معتبر نیست.', 400);
        }
        if (! Identity::admit('cart-patch:' . hash('sha256', $context['token']), 180, 60) ) { return $this->error('rate_limited', 'تغییرهای سبد کمی بعد همگام می‌شوند.', 429); 
        }
        $table = Store::table('carts'); $operations = Store::table('cart_operations');
        $key = Identity::hash('cart-patch:' . $context['token'] . ':' . $id);
        $hash = hash('sha256', wp_json_encode(array( $revision, $changes )));
        $wpdb->query('START TRANSACTION');
        try {
            $cart = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%s FOR UPDATE", $context['cart']['id']), ARRAY_A);
            if (! $cart ) { throw new \RuntimeException('cart_changed'); 
            }
            $cart=MobileWooCart::reconcile($cart);
            if(MobileCheckoutStore::busy($cart['id'])){$wpdb->query('ROLLBACK');return $this->error('checkout_in_progress','سفارش در حال بررسی است؛ ابتدا نتیجه آن را دریافت کنید.',409);}
            $prior = $wpdb->get_row($wpdb->prepare("SELECT payload_hash,applied_revision FROM {$operations} WHERE operation_key=%s", $key), ARRAY_A);
            if ($prior ) {
                $wpdb->query('COMMIT');
                if (! hash_equals($prior['payload_hash'], $hash) ) { return $this->json($this->snapshot($cart, $context['token']) + array( 'code' => 'operation_id_conflict', 'message' => 'این شناسه با تغییر دیگری استفاده شده است.' ), 409); 
                }
                return $this->json($this->snapshot($cart, $context['token']) + array( 'replayed' => true, 'applied_revision' => (int)$prior['applied_revision'] ));
            }
            $current = json_decode($cart['items'], true) ?: array();
            $merged = Store::mergePatch($current, $changes);
            if ($merged['conflicts'] ) {
                $wpdb->query('ROLLBACK');
                return $this->json($this->snapshot($cart, $context['token']) + array( 'code' => 'cart_conflict', 'message' => 'تعداد این کالا در دستگاه دیگری تغییر کرده است.', 'conflicts' => $merged['conflicts'] ), 409);
            }
            if (count($merged['items']) > 40 ) { $wpdb->query('ROLLBACK'); return $this->error('cart_too_large', 'حداکثر ۴۰ ردیف در یک سبد مجاز است.', 422); 
            }
            $catalog = new MobileCatalogController();
            foreach ( $changes as $change ) {
                if ($change['quantity'] === 0 ) { continue; 
                }
                $product = $catalog->publicProduct($change['id']);
                if (! $product || $product['type'] === 'variable' ) {
                    $wpdb->query('ROLLBACK');
                    return $this->json($this->snapshot($cart, $context['token']) + array( 'code' => 'invalid_cart_product', 'message' => 'کالای انتخاب‌شده معتبر نیست یا به انتخاب مدل نیاز دارد.', 'invalid_ids' => array( $change['id'] ) ), 422);
                }
            }
            $canonical=MobileWooCart::mutate($cart,$changes);
            $encoded = wp_json_encode($canonical);
            if ($encoded !== $cart['items'] ) {
                $cart['items'] = $encoded; $cart['revision'] = (int)$cart['revision'] + 1; $cart['updated_at'] = time();
                if ($wpdb->update($table, array( 'items' => $encoded, 'revision' => $cart['revision'], 'updated_at' => $cart['updated_at'] ), array( 'id' => $cart['id'] )) !== 1 ) { throw new \RuntimeException('cart_not_saved'); 
                }
            }
            if ($wpdb->insert($operations, array( 'operation_key' => $key, 'cart_id' => $cart['id'], 'payload_hash' => $hash, 'applied_revision' => $cart['revision'], 'created_at' => time() )) !== 1 ) { throw new \RuntimeException('cart_not_saved'); 
            }
            if ($wpdb->query('COMMIT') === false ) { throw new \RuntimeException('cart_not_saved'); 
            }
            try { $this->invalidate($cart); 
            } catch (\Throwable $ignored) { /* Durable cart commit remains authoritative. */ 
            }
            return $this->json($this->snapshot($cart, $context['token']) + array( 'replayed' => false, 'applied_revision' => (int)$cart['revision'] ));
        } catch ( \Throwable $error ) { $wpdb->query('ROLLBACK'); MobileWooCart::invalidate($context['cart']); if(in_array($error->getMessage(),array('unsupported_cart_options','cart_product_unavailable','cart_validation_failed'),true)){return $this->error($error->getMessage(),'شرایط یا موجودی کالا تغییر کرده است؛ سبد را بررسی کنید.',422);} return $this->error('cart_write_uncertain', 'وضعیت سبد را دوباره بررسی کنید؛ همین تغییر را با همان شناسه ادامه دهید.', 503); 
        }
    }

    public function claim( Request $request ): Response
    {
        global $wpdb;
        if (! self::enabled() ) { return $this->error('cart_unavailable', 'سبد ابری در دسترس نیست.', 503); 
        }
        $context = $this->context($request);
        if (is_wp_error($context) ) { return $this->contextError($context); 
        }
        if (! $context['session'] ) { return $this->error('unauthorized', 'ابتدا وارد حساب شوید.', 401); 
        }
        $id = $request->json('operation_id', '');
        if (! MobileRequestStore::validRequestId($id) || array_diff(array_keys($request->json()->all()), array('operation_id')) ) { return $this->error('invalid_cart_operation', 'شناسه انتقال سبد معتبر نیست.', 400); 
        }
        $user_id = (int)$context['session']['user_id'];
        if ((int)$context['cart']['user_id'] === $user_id ) {
            return $this->json($this->snapshot($context['cart'], $context['token']) + array( 'claim_accepted' => true, 'replayed' => true ));
        }
        $account = Store::create($user_id);
        $carts = Store::table('carts'); $tokens = Store::table('cart_tokens'); $operations = Store::table('cart_operations');
        $wpdb->query('START TRANSACTION');
        try {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$carts} WHERE id IN (%s,%s) ORDER BY id FOR UPDATE", $account['id'], $context['cart']['id']), ARRAY_A);
            $by_id = array_column($rows, null, 'id');
            $account = $by_id[$account['id']] ?? null; $guest = $by_id[$context['cart']['id']] ?? null;
            if (! $account || ! $guest || ! empty($guest['user_id']) ) { throw new \RuntimeException('cart_changed'); 
            }
            $account=MobileWooCart::reconcile($account);$guest=MobileWooCart::reconcile($guest);
            if(MobileCheckoutStore::busy($account['id'])){$wpdb->query('ROLLBACK');return $this->error('checkout_in_progress','ابتدا نتیجه سفارش جاری را بررسی کنید.',409);}
            $quantities = array();
            foreach ( array_merge(json_decode($account['items'], true) ?: array(), json_decode($guest['items'], true) ?: array()) as $item ) {
                $quantities[$item['id']] = ( $quantities[$item['id']] ?? 0 ) + (int)$item['quantity'];
            }
            if (count($quantities) > 40 || ( $quantities && max($quantities) > 999 ) ) {
                $wpdb->query('ROLLBACK');
                return $this->json($this->snapshot($guest, $context['token']) + array( 'code' => 'cart_merge_limit', 'message' => 'پیش از انتقال، تعداد ردیف‌ها یا تعداد کالا را کاهش دهید.', 'claim_accepted' => false ), 409);
            }
            ksort($quantities, SORT_NUMERIC); $items = array();
            foreach ( $quantities as $product_id => $quantity ) { $items[] = array( 'id' => (int)$product_id, 'quantity' => $quantity ); 
            }
            $changes=array();foreach($items as $item){$changes[]=array('id'=>$item['id'],'quantity'=>$item['quantity']);}
            $items=MobileWooCart::mutate($account,$changes);
            $account['items'] = wp_json_encode($items); $account['revision'] = (int)$account['revision'] + 1; $account['updated_at'] = time();
            if ($wpdb->update($carts, array( 'items' => $account['items'], 'revision' => $account['revision'], 'updated_at' => $account['updated_at'] ), array( 'id' => $account['id'] )) !== 1 ) { throw new \RuntimeException('cart_not_saved'); 
            }
            if ($wpdb->update($tokens, array( 'cart_id' => $account['id'] ), array( 'cart_id' => $guest['id'] )) < 1 ) { throw new \RuntimeException('cart_not_saved'); 
            }
            MobileWooCart::discard($guest);
            $wpdb->delete($carts, array( 'id' => $guest['id'] ));
            $key = Identity::hash('cart-claim:' . $context['token'] . ':' . $id);
            if ($wpdb->insert($operations, array( 'operation_key' => $key, 'cart_id' => $account['id'], 'payload_hash' => hash('sha256', (string)$user_id), 'applied_revision' => $account['revision'], 'created_at' => time() )) !== 1 ) { throw new \RuntimeException('cart_not_saved'); 
            }
            if ($wpdb->query('COMMIT') === false ) { throw new \RuntimeException('cart_not_saved'); 
            }
            try { $this->invalidate($account); 
            } catch (\Throwable $ignored) { /* Reconnect refresh recovers the committed revision. */ 
            }
            return $this->json($this->snapshot($account, $context['token']) + array( 'claim_accepted' => true, 'replayed' => false, 'merge_policy' => 'additive_review_availability' ));
        } catch ( \Throwable $error ) { $wpdb->query('ROLLBACK'); MobileWooCart::invalidate($context['cart']); if($account){MobileWooCart::invalidate($account);} return $this->error('cart_claim_uncertain', 'انتقال سبد را با همان شناسه دوباره بررسی کنید.', 503); 
        }
    }

    public function snapshot( array $cart, string $token ): array
    {
        $catalog = new MobileCatalogController(); $items = array(); $ready = true;
        foreach ( json_decode($cart['items'], true) ?: array() as $line ) {
            $product = $catalog->publicProduct((int)$line['id']); $issues = array();
            if (! $product ) { $issues[] = 'product_unavailable'; 
            }
            else {
                if (! $product['is_in_stock'] ) { $issues[] = 'out_of_stock'; 
                }
                if (! $product['is_purchasable'] ) { $issues[] = 'not_purchasable'; 
                }
                if ($product['type'] === 'variable' ) { $issues[] = 'variation_required'; 
                }
                $woo = wc_get_product((int)$line['id']);
                if (! $woo->has_enough_stock((int)$line['quantity']) || ( $woo->is_sold_individually() && (int)$line['quantity'] !== 1 ) ) { $issues[] = 'quantity_unavailable'; 
                }
            }
            if ($issues ) { $ready = false; 
            }
            $items[] = array( 'id' => (int)$line['id'], 'product' => $product, 'quantity' => (int)$line['quantity'], 'issues' => $issues );
        }
        return array( 'cart_token' => $token, 'revision' => (int)$cart['revision'], 'items' => $items, 'checkout_ready' => $ready && count($items)>0, 'owner' => empty($cart['user_id'])?'guest':'account', 'updated_at' => gmdate('c', (int)$cart['updated_at']), 'validated_at' => gmdate('c') );
    }

    private function discardGuest(array $context): Response
    {
        global $wpdb;$carts=Store::table('carts');$tokens=Store::table('cart_tokens');$operations=Store::table('cart_operations');
        $wpdb->query('START TRANSACTION');
        try{
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$carts} WHERE id=%s FOR UPDATE", $context['cart']['id']), ARRAY_A);
            if(!$row||!empty($row['user_id'])) {$wpdb->query('ROLLBACK');return $this->error('account_cart_retained', 'برای خالی کردن سبد حساب، ردیف‌ها را حذف کنید.', 409);
            }
            MobileWooCart::discard($row);$wpdb->delete($tokens, array('cart_id'=>$row['id']));$wpdb->delete($operations, array('cart_id'=>$row['id']));$wpdb->delete($carts, array('id'=>$row['id']));$wpdb->query('COMMIT');
            return $this->json(array('deleted'=>true));
        }catch(\Throwable $error){$wpdb->query('ROLLBACK');return $this->error('cart_write_uncertain', 'وضعیت سبد را دوباره بررسی کنید.', 503);
        }
    }

    private function invalidate(array $cart): void
    {
        if(!empty($cart['user_id'])&&class_exists('Digitalogic_Panel')) {
            \Digitalogic_Panel::record_event('mobile.cart.updated', array('user_id'=>(int)$cart['user_id'],'revision'=>(int)$cart['revision'],'audience'=>array('users'=>array((int)$cart['user_id']))));
        }
    }

    private function contextError( \WP_Error $error ): Response
    {
        return $this->error($error->get_error_code(), $error->get_error_message(), (int)($error->get_error_data()['status']??401)); 
    }
    private function error( string $code, string $message, int $status ): Response
    {
        return $this->json(array( 'code'=>$code,'message'=>$message ), $status); 
    }
    private function json( array $data,int $status=200 ): Response
    {
        return response()->json($data, $status, array('Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); 
    }
}
