<?php
namespace Digitalogic\Laravel\Services;

/** Durable quote/operation records. Never store a card number or gateway credential. */
final class MobileCheckoutStore {
    public static function table(string $name): string {
        global $wpdb;
        if(!in_array($name,array('checkout_quotes','checkout_operations'),true)){throw new \InvalidArgumentException('Unknown checkout table.');}
        return $wpdb->prefix.'digitalogic_mobile_'.$name;
    }
    public static function install(): void {
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$charset=$wpdb->get_charset_collate();
        $quotes=self::table('checkout_quotes');$operations=self::table('checkout_operations');
        dbDelta("CREATE TABLE {$quotes} (
            id char(32) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            cart_id char(32) NOT NULL,
            cart_revision bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            fingerprint char(64) NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$operations} (
            operation_key char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            request_id varchar(64) NOT NULL,
            quote_id char(32) DEFAULT NULL,
            cart_id char(32) NOT NULL,
            payload_hash char(64) NOT NULL,
            state varchar(32) NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            response longtext DEFAULT NULL,
            created_at bigint(20) unsigned NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (operation_key),
            UNIQUE KEY quote_id (quote_id),
            KEY cart_state (cart_id,state),
            KEY user_id (user_id)
        ) ENGINE=InnoDB {$charset};");
        foreach(array($quotes,$operations) as $table){if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table){throw new \RuntimeException('Checkout storage unavailable.');}}
        update_option('digitalogic_mobile_checkout_schema','1',false);
    }
    public static function busy(string $cart_id): bool {
        if(get_option('digitalogic_mobile_checkout_schema')!=='1'){return false;}
        global $wpdb;$table=self::table('checkout_operations');
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE cart_id=%s AND state IN ('validating','creating','payment_pending','uncertain') LIMIT 1",$cart_id));
    }
    public static function key(int $user_id,string $request_id): string {return MobileIdentityStore::hash('checkout:'.$user_id.':'.$request_id);}
    public static function operation(int $user_id,string $request_id): ?array {
        global $wpdb;$table=self::table('checkout_operations');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE operation_key=%s AND user_id=%d",self::key($user_id,$request_id),$user_id),ARRAY_A)?:null;
    }
    public static function quote(array $context,array $payload,string $fingerprint): array {
        global $wpdb;$table=self::table('checkout_quotes');
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at<%d LIMIT 100",time()-DAY_IN_SECONDS));
        $row=array('id'=>bin2hex(random_bytes(16)),'user_id'=>(int)$context['session']['user_id'],'cart_id'=>$context['cart']['id'],'cart_revision'=>(int)$context['cart']['revision'],'payload'=>wp_json_encode($payload),'fingerprint'=>$fingerprint,'expires_at'=>time()+600);
        if($wpdb->insert($table,$row)!==1){throw new \RuntimeException('Quote could not be saved.');}return $row;
    }
}
