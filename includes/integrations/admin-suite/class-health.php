<?php

namespace DigitalogicAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Health {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		add_action( 'wp_dashboard_setup', array( self::class, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_dashboard_style' ) );
	}

	public static function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'digitalogic-admin-health',
			'سلامت مدیریت دیجیتالاجیک',
			array( self::class, 'render' )
		);
	}

	public static function enqueue_dashboard_style( string $hook_suffix ): void {
		if ( $hook_suffix !== 'index.php' || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$path = DIGITALOGIC_PLUGIN_DIR . 'assets/css/admin-suite.css';
		if ( ! is_readable( $path ) || wp_style_is( Branding::ADMIN_STYLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( Branding::ADMIN_STYLE, DIGITALOGIC_PLUGIN_URL . 'assets/css/admin-suite.css', array(), (string) filemtime( $path ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$audit    = User_Normalizer::audit_counts( true );
		$webhooks = Webhooks::configuration_counts();
		$ui_label = Brand::ui_enabled()
			? 'رابط مدیریت دیجیتالاجیک فعال است'
			: 'رابط فعلی دیجیتالاجیک حفظ شده است';

		echo '<div class="digitalogic-admin-health">';
		self::health_item( 'برند', Brand::NAME, $ui_label );
		self::health_item(
			'Digits',
			Digits_Login::is_present() ? 'فعال' : 'شناسایی نشد',
			'فرم بومی رمز یک‌بارمصرف؛ ورود اصلی وردپرس در دسترس می‌ماند.'
		);
		self::health_item(
			'بررسی کاربران',
			sprintf(
				'%1$d کاربر واجد شرایط از %2$d کاربر بررسی‌شده',
				(int) ( $audit['eligible_phone_users'] ?? 0 ),
				(int) ( $audit['users_scanned'] ?? 0 )
			),
			sprintf(
				'%1$d فیلد تلفن، %2$d تعارض، %3$d کاربر تکراری و %4$d نام مستعار در انتظار اصلاح است.',
				(int) ( $audit['phone_fields_needing_change'] ?? 0 ),
				(int) ( $audit['conflicting_phone_users'] ?? 0 ),
				(int) ( $audit['duplicate_phone_users'] ?? 0 ),
				(int) ( $audit['alias_fields_needing_change'] ?? 0 )
			)
		);
		self::health_item(
			'رویدادهای n8n',
			sprintf( '%1$d از %2$d گروه پیکربندی شده است', (int) $webhooks['configured'], (int) $webhooks['total'] ),
			'نشانی و کلید رویدادهای ورود و کاربر هرگز نمایش داده نمی‌شود.'
		);
		echo '</div>';
	}

	private static function health_item( string $label, string $value, string $detail ): void {
		echo '<section class="digitalogic-admin-health__item">';
		echo '<span class="digitalogic-admin-health__label">' . esc_html( $label ) . '</span>';
		echo '<strong>' . esc_html( $value ) . '</strong>';
		echo '<small>' . esc_html( $detail ) . '</small>';
		echo '</section>';
	}
}
