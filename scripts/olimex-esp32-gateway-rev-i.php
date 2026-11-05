<?php
/**
 * Reviewed data from the Olimex Rev I schematic and PCB layout.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

$olimex = 'https://github.com/OLIMEX/ESP32-GATEWAY/blob/d45c9bc8120534b130a4f8bb8fa6cd3dc78e4ea0/HARDWARE/';
$design = $olimex . 'Hardware%20revision%20I/ESP32-GATEWAY_Rev_I.';

return array(
	'slug'         => 'olimex-esp32-gateway-rev-i',
	'manufacturer' => 'Olimex',
	'model'        => 'ESP32-GATEWAY',
	'revision'     => 'I',
	'title'        => 'راهنمای Olimex ESP32-GATEWAY Rev I | Pinout و راه‌اندازی',
	'excerpt'      => 'شماره پایه‌های CON1، تغذیه، محدودیت‌های GPIO و آزمون اولیه Olimex ESP32-GATEWAY Rev I بر پایه شماتیک رسمی',
	'sources'      => array(
		'pcb'       => array( 'pcb', 'فایل PCB رسمی Olimex، نسخه I', $design . 'kicad_pcb' ),
		'schematic' => array( 'schematic', 'شماتیک رسمی Olimex، نسخه I', $design . 'pdf' ),
		'changes'   => array( 'changelog', 'تغییرات نسخه‌های سخت‌افزاری Olimex', $olimex . 'README.md' ),
		'gpio'      => array( 'datasheet', 'محدودیت‌های GPIO در مستندات Espressif', 'https://docs.espressif.com/projects/esp-idf/en/stable/esp32/api-reference/peripherals/gpio.html' ),
		'ti'        => array( 'datasheet', 'مشخصات رگولاتور TPS62A02A از Texas Instruments', 'https://www.ti.com/product/TPS62A02A' ),
		'arduino'   => array( 'guide', 'نصب Arduino Core رسمی Espressif', 'https://docs.espressif.com/projects/arduino-esp32/en/latest/installing.html' ),
	),
	'pins'         => array(
		1  => array( '+3V3', null, 'power-out', 'خروجی ریل ۳٫۳ ولت', 'pcb' ),
		2  => array( 'GND', null, 'ground', 'زمین مشترک مدار', 'pcb' ),
		3  => array( 'ESP_EN', null, 'control', 'ورودی فعال‌سازی ESP32 — پایین کشیدن آن برد را Reset می‌کند.', 'schematic' ),
		4  => array( 'GPIO5', 5, 'shared', 'کنترل تغذیه PHY اترنت و یکی از پایه‌های Strapping', 'pcb' ),
		5  => array( 'GPIO2', 2, 'shared', 'مسیر DATA0 کارت microSD و پایه Strapping', 'pcb' ),
		6  => array( 'GPIO4', 4, 'shared', 'مسیر DATA1 سوکت microSD — شماتیک و حالت کارت را بررسی کنید.', 'pcb' ),
		7  => array( 'GPIO12', 12, 'shared', 'مسیر DATA2 سوکت microSD و پایه Strapping — در زمان Reset سطح آن را با مدار جانبی تغییر ندهید.', 'pcb' ),
		8  => array( 'GPIO13', 13, 'shared', 'مسیر DATA3 سوکت microSD — شماتیک و حالت کارت را بررسی کنید.', 'pcb' ),
		9  => array( 'GPIO14', 14, 'shared', 'کلاک کارت microSD', 'pcb' ),
		10 => array( 'GPIO15', 15, 'shared', 'مسیر CMD کارت microSD و پایه Strapping', 'pcb' ),
		11 => array( 'GPIO16', 16, 'gpio', 'ورودی و خروجی دیجیتال روی CON1', 'pcb' ),
		12 => array( 'GPIO17_E1', null, 'open', 'مسیر GPIO17 با جامپر باز جدا شده است. GPIO17 کلاک PHY اترنت است.', 'schematic' ),
		13 => array( 'GPIO32', 32, 'gpio', 'ورودی و خروجی دیجیتال روی CON1', 'pcb' ),
		14 => array( 'GPIO33', 33, 'shared', 'متصل به LED کاربر روی برد', 'schematic' ),
		15 => array( 'GPIO34', 34, 'input', 'متصل به کلید کاربر BUT1', 'schematic' ),
		16 => array( 'GPIO35', 35, 'input', 'فقط ورودی، بدون Pull-up و Pull-down داخلی', 'gpio' ),
		17 => array( 'GPIO36', 36, 'input', 'فقط ورودی، بدون Pull-up و Pull-down داخلی', 'gpio' ),
		18 => array( 'GPIO39', 39, 'input', 'فقط ورودی، بدون Pull-up و Pull-down داخلی', 'gpio' ),
		19 => array( 'GND', null, 'ground', 'زمین مشترک مدار', 'pcb' ),
		20 => array( '+5V_EXT', null, 'power-in', 'ورودی تغذیه خارجی ۵ ولت — برای شروع فقط از USB استفاده کنید.', 'schematic' ),
	),
	'parameters'   => array(
		array( 'usb_input_voltage', 'nominal', '5', 'V', 'ولتاژ نامی ورودی USB-C برای تغذیه برد', 'schematic' ),
		array( 'external_input_voltage', 'nominal', '5', 'V', 'ولتاژ نامی ورودی +5V_EXT روی پایه ۲۰ کانکتور CON1', 'schematic' ),
		array( 'logic_rail_voltage', 'nominal', '3.3', 'V', 'ولتاژ نامی ریل +3V3 و سطح منطقی GPIO — سیگنال ۵ ولت را مستقیم به GPIO ندهید.', 'schematic' ),
		array( 'regulator_output_current', 'rated', '2', 'A', 'جریان نامی قطعه TPS62A02A طبق دیتاشیت TI — جریان مصرفی برد و جریان مجاز برای بار خارجی روی CON1 از این عدد به دست نمی‌آید.', 'ti' ),
	),
	'content'      => <<<'HTML'
<div class="dgl-khub dgl-khub--article dgl-hw" dir="rtl" lang="fa">
<p>با این برد می‌توانید یک گیت‌وی کوچک بسازید که هم Ethernet دارد و هم Wi-Fi. اگر می‌خواهید برای اولین بار روشنش کنید یا قطعه‌ای به پایه‌ها وصل کنید، اول نوشتهٔ <code dir="ltr">Rev.I</code> روی PCB را پیدا کنید. جدول پایین برای همین نسخه است.</p>
<p><strong>دامنهٔ این راهنما</strong> این مرجع از روی شماتیک و فایل PCB رسمی ساخته شده است. نسخهٔ موجودی فروشگاه هنوز تأیید نشده و آزمون عملی روی نمونهٔ فروشگاه انجام نشده است.</p>
<h2>اول با USB راه‌اندازی کنید</h2>
<ol>
<li>کابل USB-C دیتا را وصل کنید و فعلاً تغذیهٔ خارجی و مدارهای جانبی را جدا بگذارید.</li>
<li>در Arduino IDE بستهٔ <bdi dir="ltr">esp32 by Espressif Systems</bdi> را نصب کنید. در فهرست بردها <bdi dir="ltr">OLIMEX ESP32-GATEWAY</bdi> و بعد پورت برد را انتخاب کنید.</li>
<li>برای آزمون اولیه، کد پایین را Upload کنید. در Serial Monitor با سرعت <bdi dir="ltr">115200</bdi> باید هر ثانیه یک پیام ببینید.</li>
</ol>
<pre dir="ltr"><code class="language-cpp">void setup() {
  Serial.begin(115200);
}

void loop() {
  Serial.println("ESP32-GATEWAY ready");
  delay(1000);
}</code></pre>
<p>اگر پورت ظاهر نشد، با یک کابل دیتای دیگر امتحان کنید و شناسایی مبدل سریال CH340 را بررسی کنید. اگر برد هنگام اضافه کردن یک سنسور Reset می‌شود، مدار جانبی را جدا کنید و دوباره فقط با USB روشنش کنید. برای سنجش مصرف، جریان ورودی را در حالت مورد استفادهٔ خودتان اندازه بگیرید. روشن بودن Ethernet، Wi-Fi و تجهیزات جانبی می‌تواند نتیجه را تغییر دهد.</p>
<h2>قبل از سیم‌کشی</h2>
<p>شماره‌های جدول شمارهٔ فیزیکی پایه‌های <code dir="ltr">CON1</code> هستند. مثلاً <code dir="ltr">GPIO16</code> روی پایهٔ ۱۱ است. برای تعیین جهت شمارش، علامت پایهٔ ۱ را روی طرح PCB یا خود برد پیدا کنید. در Rev I مسیر <code dir="ltr">GPIO17</code> به پایهٔ ۱۲ با جامپر باز جدا شده و کلاک Ethernet روی GPIO17 باقی مانده است.</p>
</div>
[dgl_hardware_reference board="olimex-esp32-gateway-rev-i"]
HTML
	,
);
