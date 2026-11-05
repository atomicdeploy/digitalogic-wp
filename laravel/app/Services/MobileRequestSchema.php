<?php
namespace Digitalogic\Laravel\Services;

/** The native descriptors mirror the canonical storefront request validator. */
final class MobileRequestSchema {
	public static function forms(): array {
		$common  = array(
			self::field(
				'contact_name',
				'نام و نام خانوادگی',
				'text',
				array(
					'required'   => true,
					'max_length' => 100,
				)
			),
			self::field(
				'mobile',
				'شماره موبایل',
				'phone',
				array(
					'required'   => true,
					'max_length' => 20,
				)
			),
			self::field( 'email', 'ایمیل', 'email', array( 'max_length' => 150 ) ),
			self::field( 'company', 'نام شرکت', 'text', array( 'max_length' => 120 ) ),
		);
		$notes   = self::field( 'notes', 'توضیحات', 'textarea', array( 'max_length' => 3000 ) );
		$consent = self::field(
			'consent',
			'اجازه پیگیری این درخواست را می‌دهم',
			'checkbox',
			array(
				'required'      => true,
				'checked_value' => 'yes',
			)
		);
		$pcb     = array_merge(
			$common,
			array(
				self::field(
					'project_name',
					'نام پروژه',
					'text',
					array(
						'required'   => true,
						'max_length' => 120,
					)
				),
				self::field(
					'board_quantity',
					'تعداد برد',
					'number',
					array(
						'required' => true,
						'min'      => 5,
						'max'      => 100000,
						'step'     => 5,
						'default'  => 5,
					)
				),
				self::field(
					'board_length',
					'طول برد (میلی‌متر)',
					'number',
					array(
						'required' => true,
						'min'      => 1,
						'max'      => 600,
						'step'     => 0.01,
					)
				),
				self::field(
					'board_width',
					'عرض برد (میلی‌متر)',
					'number',
					array(
						'required' => true,
						'min'      => 1,
						'max'      => 600,
						'step'     => 0.01,
					)
				),
				self::select(
					'layers',
					'تعداد لایه',
					array(
						'2' => 'دو لایه',
						'4' => 'چهار لایه',
					),
					'2'
				),
				self::select(
					'thickness',
					'ضخامت برد',
					array(
						'0.8' => '0.8 mm',
						'1.0' => '1.0 mm',
						'1.2' => '1.2 mm',
						'1.6' => '1.6 mm',
						'2.0' => '2.0 mm',
					),
					'1.6'
				),
				self::select(
					'copper',
					'ضخامت مس',
					array(
						'1oz' => '1 oz',
						'2oz' => '2 oz',
					),
					'1oz'
				),
				self::select(
					'soldermask',
					'رنگ سولدرمسک',
					array(
						'green'  => 'سبز',
						'black'  => 'مشکی',
						'blue'   => 'آبی',
						'red'    => 'قرمز',
						'white'  => 'سفید',
						'yellow' => 'زرد',
					),
					'green'
				),
				self::select(
					'silkscreen',
					'رنگ سیلک',
					array(
						'white' => 'سفید',
						'black' => 'مشکی',
					),
					'white'
				),
				self::select(
					'surface_finish',
					'فینیش سطح',
					array(
						'hasl'           => 'HASL',
						'lead-free-hasl' => 'Lead-free HASL',
						'enig'           => 'ENIG',
					),
					'hasl'
				),
				self::select(
					'panelization',
					'پنلایز',
					array(
						'no'     => 'برد تکی',
						'yes'    => 'پنل لازم دارم',
						'review' => 'بررسی و پیشنهاد شما',
					),
					'no'
				),
				self::select(
					'stencil',
					'استنسیل',
					array(
						'no'     => 'لازم ندارم',
						'yes'    => 'لازم دارم',
						'unsure' => 'مطمئن نیستم',
					),
					'no'
				),
				self::select(
					'delivery_speed',
					'زمان تحویل',
					array(
						'standard' => 'استاندارد',
						'fast'     => 'سریع‌تر',
						'economy'  => 'اقتصادی',
					),
					'standard'
				),
				self::select(
					'assembly',
					'مونتاژ قطعات',
					array(
						'no'    => 'فقط PCB خام',
						'quote' => 'مونتاژ هم قیمت بدهید',
					),
					'no'
				),

				$notes,

				$consent,
			)
		);
		$foreign = array_merge(
			$common,
			array(
				self::field(
					'items',
					'قطعات مورد نیاز',
					'items',
					array(
						'max_items' => 10,
						'fields'    => array(
							self::field( 'name', 'نام قطعه', 'text', array( 'max_length' => 180 ) ),
							self::field( 'part_number', 'شماره فنی', 'text', array( 'max_length' => 120 ) ),
							self::field( 'url', 'لینک کالا', 'text', array( 'max_length' => 500 ) ),
							self::field(
								'quantity',
								'تعداد',
								'number',
								array(
									'min'     => 1,
									'step'    => 1,
									'default' => 1,
								)
							),
							self::field( 'notes', 'توضیحات قطعه', 'text', array( 'max_length' => 250 ) ),
						),
					)
				),
				self::select(
					'shipping_speed',
					'روش ارسال',
					array(
						'best'    => 'ترکیب مناسب قیمت و زمان',
						'economy' => 'اقتصادی',
						'express' => 'سریع',
					),
					'best'
				),
				self::field( 'target_date', 'مهلت تحویل', 'text', array( 'max_length' => 120 ) ),
				self::field( 'budget', 'بودجه یا ارز مرجع', 'text', array( 'max_length' => 120 ) ),
				self::select(
					'invoice',
					'فاکتور رسمی',
					array(
						'no'     => 'لازم ندارم',
						'yes'    => 'لازم دارم',
						'unsure' => 'بعداً هماهنگ کنیم',
					),
					'no'
				),

				$notes,

				$consent,
			)
		);
		$forms = array(
			array(
				'id'     => 'pcb',
				'title'  => 'ساخت برد PCB',
				'fields' => $pcb,
				'upload' => array(
					'required'   => true,
					'max_bytes'  => 20971520,
					'extensions' => array( 'zip', 'rar', '7z' ),
				),
				'rules'  => array( 'quantity_multiple' => 5 ),
			),
			array(
				'id'     => 'foreign',
				'title'  => 'تأمین قطعه از خارج',
				'fields' => $foreign,
				'upload' => array(
					'required'   => false,
					'max_bytes'  => 20971520,
					'extensions' => array( 'xlsx', 'xls', 'csv', 'pdf', 'zip' ),
				),
				'rules'  => array(
					'items_or_file' => true,
					'max_items'     => 10,
				),
			),
		);
		foreach ( $forms as &$form ) {
			$form['revision'] = hash( 'sha256', wp_json_encode( $form ) );}
		return $forms;
	}
	public static function form( string $type ): ?array {
		foreach ( self::forms() as $form ) {
			if ( $form['id'] === $type ) {
				return $form;
			}
		}return null;}
	private static function field( string $key, string $label, string $type, array $extra = array() ): array {
		return array_merge(
			array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => false,
			),
			$extra
		);}
	private static function select( string $key, string $label, array $choices, string $default ): array {
		$options = array();
		foreach ( $choices as $value => $title ) {
			$options[] = array(
				'value' => (string) $value,
				'label' => $title,
			);
		}return self::field(
			$key,
			$label,
			'select',
			array(
				'options' => $options,
				'default' => $default,
			)
		);}
}
