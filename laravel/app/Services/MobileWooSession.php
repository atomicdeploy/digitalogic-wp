<?php
namespace Digitalogic\Laravel\Services;

/** The installed WooCommerce session storage, selected by authenticated owner instead of browser cookie. */
final class MobileWooSession extends \WC_Session_Handler {
    private bool $readOnly=true;
    public function __construct(array $cart) {
        parent::__construct();
        $this->_customer_id=self::key($cart);
        $this->_data=(array)$this->get_session($this->_customer_id,array());
        if(!empty($cart['user_id'])&&!array_key_exists('cart',$this->_data)){
            $persistent=get_user_meta((int)$cart['user_id'],'_woocommerce_persistent_cart_'.get_current_blog_id(),true);
            if(is_array($persistent)&&isset($persistent['cart'])){$this->set('cart',$persistent['cart']);}
        }
        if(empty($cart['user_id'])){$this->_session_expiration=time()+90*DAY_IN_SECONDS;$this->_session_expiring=time()+89*DAY_IN_SECONDS;}
    }
    public static function key(array $cart): string {return !empty($cart['user_id'])?(string)(int)$cart['user_id']:'t_'.substr($cart['id'],0,30);}
    public function has_session(){return true;}
    public function init(){}
    public function set_customer_session_cookie($set){}
    public function save_data($old_session_key=''){if(!$this->readOnly){parent::save_data($old_session_key);}}
    public function persist(): void {$this->readOnly=false;try{parent::save_data();}finally{$this->readOnly=true;}}
}
