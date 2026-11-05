<?php
/**
 * Espressif ESP32-S3-DevKitC-1 v1.1 manufacturer reference.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

return array(
	'slug'         => 'esp32-s3-devkitc-1-v1-1',
	'manufacturer' => 'Espressif Systems',
	'model'        => 'ESP32-S3-DevKitC-1',
	'revision'     => '1.1',
	'title'        => 'راهنمای ESP32-S3-DevKitC-1 v1.1 | Pinout و راه‌اندازی',
	'excerpt'      => 'نقشهٔ ۴۴ پایهٔ J1 و J3، تغذیه و تست LED RGB روی برد رسمی Espressif',
	'sources'      => array(
		'headers'   => array( 'guide', 'راهنمای Espressif برای DevKitC-1 v1.1 و جدول J1 و J3', 'https://docs.espressif.com/projects/esp-dev-kits/en/latest/esp32s3/esp32-s3-devkitc-1/user_guide_v1.1.html' ),
		'schematic' => array( 'schematic', 'شماتیک رسمی ESP32-S3-DevKitC-1 v1.1', 'https://dl.espressif.com/dl/schematics/SCH_ESP32-S3-DevKitC-1_V1.1_20220413.pdf' ),
		'arduino'   => array( 'guide', 'نصب Arduino Core رسمی Espressif', 'https://docs.espressif.com/projects/arduino-esp32/en/latest/installing.html' ),
	),
	'connectors'   => array(
		'J1' => array(
			1  => array( '3V3', null, 'power-in', 'ریل ۳٫۳ ولت و مسیر تغذیهٔ مستقیم برد', 'headers' ),
			2  => array( '3V3', null, 'power-in', 'همان ریل ۳٫۳ ولت پایهٔ ۱', 'headers' ),
			3  => array( 'RST', null, 'control', 'ورودی EN برای Reset', 'headers' ),
			4  => array( 'GPIO4', 4, 'gpio', 'ADC1_CH3 و TOUCH4', 'headers' ),
			5  => array( 'GPIO5', 5, 'gpio', 'ADC1_CH4 و TOUCH5', 'headers' ),
			6  => array( 'GPIO6', 6, 'gpio', 'ADC1_CH5 و TOUCH6', 'headers' ),
			7  => array( 'GPIO7', 7, 'gpio', 'ADC1_CH6 و TOUCH7', 'headers' ),
			8  => array( 'GPIO15', 15, 'gpio', 'ADC2_CH4 و U0RTS', 'headers' ),
			9  => array( 'GPIO16', 16, 'gpio', 'ADC2_CH5 و U0CTS', 'headers' ),
			10 => array( 'GPIO17', 17, 'gpio', 'ADC2_CH6 و U1TXD', 'headers' ),
			11 => array( 'GPIO18', 18, 'gpio', 'ADC2_CH7 و U1RXD', 'headers' ),
			12 => array( 'GPIO8', 8, 'gpio', 'ADC1_CH7 و TOUCH8', 'headers' ),
			13 => array( 'GPIO3', 3, 'shared', 'پایهٔ Strapping با ADC1_CH2 و TOUCH3', 'headers' ),
			14 => array( 'GPIO46', 46, 'shared', 'پایهٔ Strapping', 'headers' ),
			15 => array( 'GPIO9', 9, 'gpio', 'ADC1_CH8 و TOUCH9', 'headers' ),
			16 => array( 'GPIO10', 10, 'gpio', 'ADC1_CH9 و TOUCH10', 'headers' ),
			17 => array( 'GPIO11', 11, 'gpio', 'ADC2_CH0 و TOUCH11', 'headers' ),
			18 => array( 'GPIO12', 12, 'gpio', 'ADC2_CH1 و TOUCH12', 'headers' ),
			19 => array( 'GPIO13', 13, 'gpio', 'ADC2_CH2 و TOUCH13', 'headers' ),
			20 => array( 'GPIO14', 14, 'gpio', 'ADC2_CH3 و TOUCH14', 'headers' ),
			21 => array( '5V', null, 'power-in', 'ورودی تغذیهٔ ۵ ولت', 'headers' ),
			22 => array( 'GND', null, 'ground', 'زمین مشترک با برچسب G روی برد', 'headers' ),
		),
		'J3' => array(
			1  => array( 'GND', null, 'ground', 'زمین مشترک با برچسب G روی برد', 'headers' ),
			2  => array( 'TX', 43, 'shared', 'GPIO43 و خروجی UART0', 'headers' ),
			3  => array( 'RX', 44, 'shared', 'GPIO44 و ورودی UART0', 'headers' ),
			4  => array( 'GPIO1', 1, 'gpio', 'ADC1_CH0 و TOUCH1', 'headers' ),
			5  => array( 'GPIO2', 2, 'gpio', 'ADC1_CH1 و TOUCH2', 'headers' ),
			6  => array( 'GPIO42', 42, 'shared', 'سیگنال JTAG با نام MTMS', 'headers' ),
			7  => array( 'GPIO41', 41, 'shared', 'سیگنال JTAG با نام MTDI', 'headers' ),
			8  => array( 'GPIO40', 40, 'shared', 'سیگنال JTAG با نام MTDO', 'headers' ),
			9  => array( 'GPIO39', 39, 'shared', 'سیگنال JTAG با نام MTCK', 'headers' ),
			10 => array( 'GPIO38', 38, 'shared', 'مسیر LED RGB روی نسخهٔ v1.1', 'headers' ),
			11 => array( 'GPIO37', 37, 'shared', 'در مدل‌های دارای حافظهٔ Octal برای ارتباط با حافظه رزرو می‌شود.', 'headers' ),
			12 => array( 'GPIO36', 36, 'shared', 'در مدل‌های دارای حافظهٔ Octal برای ارتباط با حافظه رزرو می‌شود.', 'headers' ),
			13 => array( 'GPIO35', 35, 'shared', 'در مدل‌های دارای حافظهٔ Octal برای ارتباط با حافظه رزرو می‌شود.', 'headers' ),
			14 => array( 'GPIO0', 0, 'shared', 'پایهٔ Strapping متصل به کلید BOOT', 'headers' ),
			15 => array( 'GPIO45', 45, 'shared', 'پایهٔ Strapping', 'headers' ),
			16 => array( 'GPIO48', 48, 'gpio', 'روی v1.0 به LED RGB وصل است. سطح ولتاژ این پایه را در دیتاشیت ماژول بررسی کنید.', 'headers' ),
			17 => array( 'GPIO47', 47, 'gpio', 'سطح ولتاژ این پایه را در دیتاشیت ماژول بررسی کنید.', 'headers' ),
			18 => array( 'GPIO21', 21, 'gpio', 'ورودی و خروجی دیجیتال', 'headers' ),
			19 => array( 'GPIO20', 20, 'shared', 'خط USB_D+ برای USB داخلی', 'headers' ),
			20 => array( 'GPIO19', 19, 'shared', 'خط USB_D- برای USB داخلی', 'headers' ),
			21 => array( 'GND', null, 'ground', 'زمین مشترک با برچسب G روی برد', 'headers' ),
			22 => array( 'GND', null, 'ground', 'زمین مشترک با برچسب G روی برد', 'headers' ),
		),
	),
	'parameters'   => array(
		array( 'usb_input_voltage', 'nominal', '5', 'V', 'ولتاژ نامی ورودی USB', 'schematic' ),
		array( 'external_5v_input', 'nominal', '5', 'V', 'تغذیه از پایهٔ ۵ ولت و GND', 'headers' ),
		array( 'logic_rail_voltage', 'nominal', '3.3', 'V', 'ریل منطقی و گزینهٔ تغذیه از پایهٔ ۳٫۳ ولت و GND', 'headers' ),
	),
	'content'      => <<<'HTML'
<div class="dgl-khub dgl-khub--article dgl-hw" dir="rtl" lang="fa">
<p>این راهنما برای برد رسمی Espressif با نسخهٔ PCB <code>v1.1</code> است. در این نسخه، LED RGB روی <code>GPIO38</code> قرار دارد. در نسخهٔ <code>v1.0</code> همین LED روی <code>GPIO48</code> است. پیش از اتصال سیم یا اجرای نمونه، نسخهٔ چاپ‌شده روی PCB و نوشتهٔ روی ماژول را بررسی کنید.</p>
<h2>تغذیه و اولین برنامه</h2>
<p>برای شروع از پورت <bdi dir="ltr">USB-to-UART</bdi> و کابل دیتای Micro-USB استفاده کنید. اگر بعداً از پایهٔ <code>5V</code> یا <code>3V3</code> تغذیه می‌دهید، فقط یکی از روش‌های تغذیهٔ معرفی‌شده در راهنمای سازنده را انتخاب کنید. به پایه‌های GPIO سیگنال ۵ ولت ندهید.</p>
<p>در Arduino IDE، بستهٔ رسمی ESP32 را نصب کنید، <bdi dir="ltr">ESP32S3 Dev Module</bdi> و پورت مبدل USB-to-UART را انتخاب کنید و نمونهٔ زیر را Upload کنید. LED RGB باید هر نیم‌ثانیه روشن و خاموش شود. اگر Upload شروع نشد، BOOT را نگه دارید، RESET را یک‌بار فشار دهید و دوباره امتحان کنید.</p>
<pre dir="ltr"><code class="language-cpp">constexpr uint8_t rgbPin = 38;

void setup() {}

void loop() {
  rgbLedWrite(rgbPin, 0, 24, 0);
  delay(500);
  rgbLedWrite(rgbPin, 0, 0, 0);
  delay(500);
}</code></pre>
<h2>پایه‌های مشترک</h2>
<p>روی ماژول‌های دارای حافظهٔ Octal، پایه‌های <code>GPIO35</code> تا <code>GPIO37</code> برای ارتباط با حافظه استفاده می‌شوند. برای USB داخلی هم <code>GPIO19</code> و <code>GPIO20</code> را در نظر بگیرید. شماره‌های جدول موقعیت فیزیکی روی <code>J1</code> و <code>J3</code> هستند.</p>
</div>
[dgl_hardware_reference board="esp32-s3-devkitc-1-v1-1"]
HTML
	,
);
