<?php
/**
 * Shared software library and product knowledge topics.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns reusable software, topic, and operating-system content on the storefront.
 */
final class Digitalogic_Knowledge_Hub {

	public const POST_TYPE        = 'dgl_software';
	public const TOPIC_TAXONOMY   = 'dgl_topic';
	public const OS_TAXONOMY      = 'dgl_operating_system';
	public const OFFICIAL_URL_KEY = '_digitalogic_official_url';

	/**
	 * Shared integration instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register hooks. */
	private function __construct() {
		add_action( 'init', array( $this, 'register_content_types' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'dgl_software_library', array( $this, 'software_library_shortcode' ) );
		add_shortcode( 'dgl_sbc_catalog', array( $this, 'sbc_catalog_shortcode' ) );
		add_filter( 'the_content', array( $this, 'decorate_software_content' ) );
		add_filter( 'the_content', array( $this, 'append_product_knowledge' ), 25 );
		add_filter( 'template_include', array( $this, 'software_archive_template' ) );
	}

	/**
	 * Use the plugin-owned archive so the software section has a stable design.
	 *
	 * @param string $template Theme-selected template path.
	 */
	public function software_archive_template( string $template ): string {
		if ( is_post_type_archive( self::POST_TYPE ) ) {
			$owned = DIGITALOGIC_PLUGIN_DIR . 'templates/software-archive.php';
			if ( is_readable( $owned ) ) {
				return $owned;
			}
		}

		return $template;
	}

	/** Register the public software type and the shared product vocabularies. */
	public function register_content_types(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => 'نرم‌افزارهای ضروری',
					'singular_name' => 'نرم‌افزار و راهنما',
					'add_new_item'  => 'افزودن نرم‌افزار یا راهنما',
					'edit_item'     => 'ویرایش نرم‌افزار یا راهنما',
				),
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'software' ),
				'menu_icon'    => 'dashicons-desktop',
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
			)
		);

		register_taxonomy(
			self::TOPIC_TAXONOMY,
			array( 'product', self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => 'موضوع‌های فنی',
					'singular_name' => 'موضوع فنی',
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'topic' ),
			)
		);

		register_taxonomy(
			self::OS_TAXONOMY,
			array( 'product', self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => 'سیستم‌عامل‌های پشتیبانی‌شده',
					'singular_name' => 'سیستم‌عامل',
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'operating-system' ),
			)
		);
	}

	/** Enqueue the design only where the hub can appear. */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$path    = DIGITALOGIC_PLUGIN_DIR . 'assets/css/knowledge-hub.css';
		$version = is_readable( $path ) ? (string) filemtime( $path ) : DIGITALOGIC_VERSION;
		wp_enqueue_style( 'digitalogic-knowledge-hub', DIGITALOGIC_PLUGIN_URL . 'assets/css/knowledge-hub.css', array(), $version );
	}

	/** Render the complete software archive grid. */
	public function software_library_shortcode(): string {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		if ( empty( $posts ) ) {
			return '';
		}

		$html = '<section class="dgl-khub" dir="rtl"><div class="dgl-khub__heading"><span class="dgl-khub__eyebrow">DIGITALOGIC ESSENTIALS</span><h2>نرم‌افزارها و راهنماهای ضروری</h2><p>ابزارهای رسمی و مسیرهای نصب بررسی‌شده برای بردهای توسعه و رایانه‌های تک‌برد.</p></div><div class="dgl-khub__grid">';
		foreach ( $posts as $post ) {
			$html .= $this->software_card( $post );
		}
		$html .= '</div></section>';

		return $html;
	}

	/** Render products assigned to the single-board-computer topic. */
	public function sbc_catalog_shortcode(): string {
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded public taxonomy archive.
					array(
						'taxonomy' => self::TOPIC_TAXONOMY,
						'field'    => 'slug',
						'terms'    => 'single-board-computer',
					),
				),
			)
		);

		if ( empty( $products ) ) {
			return '';
		}

		$html = '<section class="dgl-khub dgl-khub--sbc" dir="rtl"><div class="dgl-khub__heading"><span class="dgl-khub__eyebrow">SINGLE-BOARD COMPUTERS</span><h2>مرکز رایانه‌های تک‌برد</h2><p>محصولات SBC همراه با سیستم‌عامل‌های رسماً پشتیبانی‌شده و ابزارهای راه‌اندازی مرتبط.</p></div><div class="dgl-khub__grid">';
		foreach ( $products as $product ) {
			$html .= '<article class="dgl-khub__card dgl-khub__card--product"><a class="dgl-khub__card-link" href="' . esc_url( get_permalink( $product ) ) . '"><span class="dgl-khub__glyph" aria-hidden="true">SBC</span><h3>' . esc_html( get_the_title( $product ) ) . '</h3>' . $this->term_pills( (int) $product->ID, self::OS_TAXONOMY ) . '<span class="dgl-khub__action">مشاهده محصول ←</span></a></article>';
		}
		$html .= '</div></section>';

		return $html;
	}

	/** Add shared topics, OS badges and relevant tools beneath a product. */
	public function render_product_knowledge(): void {
		$product_id = get_the_ID();
		$topics     = wp_get_post_terms( $product_id, self::TOPIC_TAXONOMY, array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $topics ) || empty( $topics ) ) {
			return;
		}

		$tools = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- six related resources at most.
					array(
						'taxonomy' => self::TOPIC_TAXONOMY,
						'field'    => 'slug',
						'terms'    => $topics,
					),
				),
			)
		);

		echo '<section class="dgl-khub dgl-khub--product" dir="rtl"><div class="dgl-khub__heading"><span class="dgl-khub__eyebrow">راه‌اندازی و سازگاری</span><h2>منابع مناسب این محصول</h2>' . wp_kses_post( $this->term_pills( $product_id, self::TOPIC_TAXONOMY ) . $this->term_pills( $product_id, self::OS_TAXONOMY ) ) . '</div>';
		if ( ! empty( $tools ) ) {
			echo '<div class="dgl-khub__grid">';
			foreach ( $tools as $tool ) {
				echo wp_kses_post( $this->software_card( $tool ) );
			}
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * Append product resources through the content path used by the live theme.
	 *
	 * @param string $content Current product description.
	 */
	public function append_product_knowledge( string $content ): string {
		if ( ! is_singular( 'product' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		ob_start();
		$this->render_product_knowledge();
		return $content . (string) ob_get_clean();
	}

	/**
	 * Add the official-source callout to an individual software guide.
	 *
	 * @param string $content Current post content.
	 */
	public function decorate_software_content( string $content ): string {
		if ( ! is_singular( self::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$url = get_post_meta( get_the_ID(), self::OFFICIAL_URL_KEY, true );
		if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://' ) ) {
			return $content;
		}

		$callout = '<aside class="dgl-khub__official" dir="rtl"><strong>منبع رسمی</strong><span>برای دریافت آخرین نسخه، فقط از وب‌سایت سازنده استفاده کنید.</span><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">رفتن به صفحه رسمی ↗</a></aside>';
		return '<div class="dgl-khub dgl-khub--article" dir="rtl">' . $callout . $content . '</div>';
	}

	/**
	 * Idempotently create the canonical vocabularies, guides and SBC landing page.
	 * This is invoked explicitly during deployment; it never runs as a timer.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function provision_defaults() {
		$this->register_content_types();

		$topics  = array(
			'single-board-computer' => 'رایانه تک‌برد (SBC)',
			'raspberry-pi'          => 'Raspberry Pi',
			'linux'                 => 'لینوکس',
			'remote-access'         => 'دسترسی از راه دور',
			'arduino'               => 'Arduino',
			'esp'                   => 'ESP32 / ESP8266',
			'embedded-development'  => 'توسعه سیستم‌های نهفته',
		);
		$systems = array(
			'raspberry-pi-os' => 'Raspberry Pi OS',
			'ubuntu'          => 'Ubuntu',
		);

		foreach ( $topics as $slug => $name ) {
			$this->ensure_term( self::TOPIC_TAXONOMY, $slug, $name );
		}
		foreach ( $systems as $slug => $name ) {
			$this->ensure_term( self::OS_TAXONOMY, $slug, $name );
		}

		$created = array();
		foreach ( $this->default_guides() as $guide ) {
			$post_id = $this->upsert_guide( $guide );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			$created[ $guide['slug'] ] = $post_id;
		}

		$page    = get_page_by_path( 'sbc', OBJECT, 'page' );
		$page_id = $page ? (int) $page->ID : wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'sbc',
				'post_title'   => 'رایانه‌های تک‌برد (SBC)',
				'post_excerpt' => 'مرکز انتخاب SBC، سیستم‌عامل‌های پشتیبانی‌شده و ابزارهای راه‌اندازی.',
				'post_content' => '<p>در این بخش می‌توانید بردهای SBC را بر اساس خانواده محصول و سیستم‌عامل‌های رسماً پشتیبانی‌شده مقایسه کنید. برای هر محصول، ابزارها و راهنماهای مشترک به‌صورت خودکار نمایش داده می‌شوند.</p>[dgl_sbc_catalog]',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		flush_rewrite_rules( false );

		return array(
			'guides'   => $created,
			'sbc_page' => (int) $page_id,
		);
	}

	/**
	 * Return the reviewed default guide manifests.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function default_guides(): array {
		return array(
			array(
				'slug'    => 'raspberry-pi-imager',
				'title'   => 'Raspberry Pi Imager',
				'excerpt' => 'ابزار رسمی Raspberry Pi برای دریافت و نوشتن آخرین ایمیج سیستم‌عامل روی کارت microSD یا حافظه USB.',
				'url'     => 'https://www.raspberrypi.com/software/',
				'topics'  => array( 'raspberry-pi', 'single-board-computer', 'linux' ),
				'content' => '<h2>نصب سیستم‌عامل با Raspberry Pi Imager</h2><p>Imager ابزار رسمی Raspberry Pi است و فهرست نسخه‌های تازه سیستم‌عامل را هنگام اجرا دریافت می‌کند؛ بنابراین به‌جای تکیه بر یک لینک قدیمی ISO، همیشه خود ابزار و منبع رسمی را مبنا قرار دهید. فایل‌های Raspberry Pi معمولاً با قالب‌های <code>.img</code>، <code>.img.xz</code> یا <code>.zip</code> منتشر می‌شوند و الزاماً ISO نیستند.</p><ol><li>آخرین Imager را از دکمه «منبع رسمی» دریافت و نصب کنید.</li><li>در برنامه به‌ترتیب <strong>Device</strong>، <strong>Operating System</strong> و سپس <strong>Storage</strong> را انتخاب کنید.</li><li>اگر ایمیج جداگانه‌ای از منبع رسمی گرفته‌اید، از گزینه <strong>Use custom</strong> استفاده کنید.</li><li>در تنظیمات سفارشی، نام میزبان، Wi‑Fi، منطقه زمانی و SSH را فقط در صورت نیاز وارد کنید.</li><li>نام و ظرفیت حافظه مقصد را دوباره کنترل کنید؛ نوشتن ایمیج تمام داده‌های آن را پاک می‌کند.</li><li><strong>Write</strong> را بزنید و تا پایان مرحله بررسی یا Verify صبر کنید، سپس حافظه را به‌صورت امن جدا کنید.</li></ol><p><strong>نکته انتخاب سیستم‌عامل:</strong> گزینه پیشنهادی برای استفاده عمومی Raspberry Pi OS است. برای Ubuntu فقط مدلی را انتخاب کنید که در فهرست رسمی Canonical پشتیبانی شده باشد.</p>',
			),
			array(
				'slug'    => 'rustdesk',
				'title'   => 'RustDesk',
				'excerpt' => 'نرم‌افزار متن‌باز و چندسکویی برای دسترسی و پشتیبانی از راه دور.',
				'url'     => 'https://github.com/rustdesk/rustdesk/releases',
				'topics'  => array( 'remote-access', 'linux', 'single-board-computer' ),
				'content' => '<h2>دسترسی از راه دور با RustDesk</h2><p>RustDesk یک ابزار متن‌باز برای کنترل از راه دور است. برای جلوگیری از دریافت فایل جعلی یا نسخه قدیمی، آخرین نسخه پایدار را فقط از صفحه رسمی انتشار دریافت کنید و بسته متناسب با سیستم‌عامل و معماری دستگاه را انتخاب کنید.</p><ol><li>در صفحه Releases، جدیدترین نسخه پایدار را باز کنید.</li><li>برای Raspberry Pi و لینوکس، معماری سیستم مانند ARM64 یا ARMHF را پیش از دانلود بررسی کنید.</li><li>پس از نصب، شناسه و رمز موقت را فقط از مسیر امن با فرد مورد اعتماد به اشتراک بگذارید.</li><li>برای دسترسی دائمی، رمز قوی و تنظیمات امنیتی مناسب تعریف کنید و دسترسی‌های غیرضروری را ببندید.</li></ol>',
			),
			array(
				'slug'    => 'arduino-ide',
				'title'   => 'Arduino IDE',
				'excerpt' => 'محیط رسمی Arduino برای نصب بردها، مدیریت کتابخانه‌ها، کامپایل و آپلود برنامه.',
				'url'     => 'https://www.arduino.cc/en/software',
				'topics'  => array( 'arduino', 'embedded-development', 'esp' ),
				'content' => '<h2>شروع کار با Arduino IDE</h2><p>نسخه جاری Arduino IDE را از وب‌سایت رسمی دریافت کنید. پس از نصب، برد را متصل کنید و از منوی Boards Manager بسته رسمی خانواده برد را نصب کنید.</p><ol><li>از بخش <strong>Tools → Board</strong> مدل دقیق برد را انتخاب کنید.</li><li>از بخش <strong>Tools → Port</strong> درگاه صحیح را انتخاب کنید.</li><li>مثال Blink را باز کنید، Verify را بزنید و سپس Upload کنید.</li><li>اگر Arduino Nano قدیمی دارید و آپلود ناموفق است، گزینه Processor را با <strong>ATmega328P (Old Bootloader)</strong> نیز بررسی کنید.</li></ol>',
			),
			array(
				'slug'    => 'platformio-vscode',
				'title'   => 'PlatformIO IDE برای VS Code',
				'excerpt' => 'محیط توسعه حرفه‌ای بردهای نهفته داخل VS Code با مدیریت پروژه، کتابخانه و پلتفرم.',
				'url'     => 'https://docs.platformio.org/en/latest/integration/ide/vscode.html',
				'topics'  => array( 'arduino', 'embedded-development', 'esp' ),
				'content' => '<h2>نصب PlatformIO در VS Code</h2><p>افزونه رسمی <strong>PlatformIO IDE</strong> را از Marketplace نصب کنید. PlatformIO Core همراه افزونه نصب می‌شود و معمولاً نصب جداگانه لازم نیست.</p><ol><li>VS Code را باز کنید و در Extensions عبارت PlatformIO IDE را جستجو کنید.</li><li>افزونه منتشرشده توسط PlatformIO را نصب و VS Code را بازنشانی کنید.</li><li>از PlatformIO Home یک پروژه جدید بسازید و نام دقیق Board و Framework را انتخاب کنید.</li><li>کد را Build و سپس Upload کنید؛ محیط سریال از Serial Monitor در دسترس است.</li></ol>',
			),
			array(
				'slug'    => 'arduino-community-vscode',
				'title'   => 'Arduino Community Edition برای VS Code',
				'excerpt' => 'افزونه جامعه‌محور Arduino برای VS Code با تکیه بر Arduino CLI.',
				'url'     => 'https://marketplace.visualstudio.com/items?itemName=vscode-arduino.vscode-arduino-community',
				'topics'  => array( 'arduino', 'embedded-development', 'esp' ),
				'content' => '<h2>Arduino Community Edition در VS Code</h2><p>این افزونه ادامه جامعه‌محور افزونه Arduino برای VS Code است. در Marketplace شناسه <code>vscode-arduino.vscode-arduino-community</code> را بررسی کنید یا فرمان <code>ext install vscode-arduino-community</code> را اجرا کنید.</p><p>برای نصب تازه، استفاده از Arduino CLI همراه افزونه پیشنهاد می‌شود. این افزونه جای Arduino IDE را نمی‌گیرد و پشتیبانی مستقیم آن از IDE 2.x را نباید فرض کرد؛ تنظیمات ابزار و مسیر CLI را طبق مستندات خود افزونه انجام دهید.</p>',
			),
			array(
				'slug'    => 'esp-arduino-cores',
				'title'   => 'نصب Arduino Core برای ESP32 و ESP8266',
				'excerpt' => 'راهنمای نصب بسته‌های رسمی ESP32 و ESP8266 در Boards Manager آردوینو.',
				'url'     => 'https://docs.espressif.com/projects/arduino-esp32/en/latest/installing.html',
				'topics'  => array( 'esp', 'arduino', 'embedded-development' ),
				'content' => '<h2>نصب Core رسمی ESP در Arduino IDE</h2><p>در Arduino IDE وارد <strong>File → Preferences</strong> شوید و آدرس مناسب را در Additional Boards Manager URLs قرار دهید.</p><h3>ESP32</h3><p><code>https://espressif.github.io/arduino-esp32/package_esp32_index.json</code></p><h3>ESP8266</h3><p><code>https://arduino.esp8266.com/stable/package_esp8266com_index.json</code></p><ol><li>Boards Manager را باز کنید و برای ESP32 بسته منتشرشده توسط Espressif Systems یا برای ESP8266 بسته ESP8266 Community را نصب کنید.</li><li>مدل دقیق برد، Upload Speed و درگاه را انتخاب کنید.</li><li>یک مثال ساده WiFi Scan یا Blink را کامپایل و آپلود کنید.</li><li>اگر آپلود آغاز نشد، فقط مطابق راهنمای سازنده برد دکمه BOOT را نگه دارید و اتصال USB/درایور را بررسی کنید.</li></ol>',
			),
		);
	}

	/**
	 * Create or refresh one canonical guide.
	 *
	 * @param array<string,mixed> $guide Guide manifest.
	 * @return int|WP_Error
	 */
	private function upsert_guide( array $guide ) {
		$current = get_page_by_path( $guide['slug'], OBJECT, self::POST_TYPE );
		$data    = array(
			'ID'           => $current ? (int) $current->ID : 0,
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_name'    => $guide['slug'],
			'post_title'   => $guide['title'],
			'post_excerpt' => $guide['excerpt'],
			'post_content' => $guide['content'],
		);
		$post_id = wp_insert_post( $data, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::OFFICIAL_URL_KEY, esc_url_raw( $guide['url'] ) );
		wp_set_object_terms( $post_id, $guide['topics'], self::TOPIC_TAXONOMY, false );
		return (int) $post_id;
	}

	/**
	 * Ensure a reusable taxonomy term exists.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $slug     Stable term slug.
	 * @param string $name     Public term label.
	 */
	private function ensure_term( string $taxonomy, string $slug, string $name ): void {
		if ( ! term_exists( $slug, $taxonomy ) ) {
			wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		}
	}

	/**
	 * Render one escaped software card.
	 *
	 * @param WP_Post $post Software guide post.
	 */
	private function software_card( WP_Post $post ): string {
		$topics = $this->term_pills( (int) $post->ID, self::TOPIC_TAXONOMY );
		return '<article class="dgl-khub__card"><a class="dgl-khub__card-link" href="' . esc_url( get_permalink( $post ) ) . '"><span class="dgl-khub__glyph" aria-hidden="true">' . esc_html( $this->card_monogram( $post->post_name ) ) . '</span><h3>' . esc_html( get_the_title( $post ) ) . '</h3><p>' . esc_html( get_the_excerpt( $post ) ) . '</p>' . $topics . '<span class="dgl-khub__action">مشاهده راهنما ←</span></a></article>';
	}

	/**
	 * Return the compact visual label for a guide.
	 *
	 * @param string $slug Guide slug.
	 */
	private function card_monogram( string $slug ): string {
		$labels = array(
			'raspberry-pi-imager'      => 'RPi',
			'rustdesk'                 => 'RD',
			'arduino-ide'              => '∞',
			'platformio-vscode'        => 'PIO',
			'arduino-community-vscode' => 'VS',
			'esp-arduino-cores'        => 'ESP',
		);
		return $labels[ $slug ] ?? 'DL';
	}

	/**
	 * Render the shared taxonomy terms as badges.
	 *
	 * @param int    $post_id  Object ID.
	 * @param string $taxonomy Shared taxonomy name.
	 */
	private function term_pills( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		$class = self::OS_TAXONOMY === $taxonomy ? ' dgl-khub__pill--os' : '';
		$html  = '<div class="dgl-khub__pills">';
		foreach ( $terms as $term ) {
			$html .= '<span class="dgl-khub__pill' . esc_attr( $class ) . '">' . esc_html( $term->name ) . '</span>';
		}
		return $html . '</div>';
	}
}
