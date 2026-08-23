<?php
/**
 * Product Code operator-language catalog tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Keep principal owner-facing failures translated with canonical terminology. */
final class ProductCodeTranslationsTest extends TestCase {

	/** Principal edit, recovery, and source-guard strings exist in both catalogs. */
	public function test_principal_product_code_messages_are_translated(): void {
		$pot = file_get_contents( dirname( __DIR__ ) . '/languages/digitalogic.pot' );
		$po  = file_get_contents( dirname( __DIR__ ) . '/languages/digitalogic-fa_IR.po' );
		$this->assertIsString( $pot );
		$this->assertIsString( $po );

		$translations = array(
			'This Product Code is managed by the catalog source; correct it in the source.' => 'این کد کالا توسط منبع کاتالوگ مدیریت می‌شود؛ آن را در منبع اصلاح کنید.',
			'The exact Product Code outcome is unknown; stop editing and reconcile the database with the audit record.' => 'نتیجه دقیق ویرایش کد کالا نامشخص است؛ ویرایش را متوقف و پایگاه‌داده را با سابقه ممیزی تطبیق دهید.',
			'The Product Code response could not be verified; retry the same value.' => 'پاسخ ویرایش کد کالا قابل راستی‌آزمایی نبود؛ همان مقدار را دوباره امتحان کنید.',
			'Product Code changes must use the dedicated audited operation.' => 'تغییر کد کالا باید فقط از عملیات اختصاصی و ممیزی‌شده انجام شود.',
			'The source Product Code write did not pass exact database readback.' => 'ثبت کد کالای منبع در بازخوانی دقیق پایگاه‌داده تأیید نشد.',
		);
		foreach ( $translations as $english => $persian ) {
			$this->assertStringContainsString( 'msgid "' . $english . '"', $pot );
			$this->assertStringContainsString( 'msgid "' . $english . '"', $po );
			$this->assertStringContainsString( 'msgstr "' . $persian . '"', $po );
		}

		$this->assertStringNotContainsString( 'کد پاتریس', $po );
		$this->assertStringNotContainsString( 'نام فنی ثبت‌شده در پاتریس', $po );
	}
}
