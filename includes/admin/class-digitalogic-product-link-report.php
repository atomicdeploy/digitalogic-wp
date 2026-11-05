<?php
/**
 * Administrator report for external product link coverage.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Build and render the private external link report. */
final class Digitalogic_Product_Link_Report {
	const PAGE = 'digitalogic-product-link-report';

	const SOURCES = array(
		''            => 'همهٔ منابع',
		'storefront'  => 'پیوندهای درج‌شده در محصول',
		'research'    => 'نتایج پژوهش خصوصی',
		'attachments' => 'پیوست‌های پژوهش خصوصی',
		'supplier'    => 'پیوندهای خصوصی تأمین',
	);

	/** Register the Digitalogic admin submenu. */
	public static function menu() {
		add_submenu_page(
			'digitalogic',
			'گزارش دامنه‌های محصولات',
			'دامنه‌های محصولات',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Read catalog content and the two private source stores for one report.
	 *
	 * @param int    $category Category ID, 0 for all, or -1 for uncategorized.
	 * @param string $source   Source filter.
	 * @return array
	 */
	public static function report( $category = 0, $source = '' ) {
		global $wpdb;

		$where      = "p.post_type = 'product' AND p.post_status = 'publish'";
		$membership = "SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t USING (term_taxonomy_id)
			WHERE r.object_id = p.ID AND t.taxonomy = 'product_cat'";
		if ( $category > 0 ) {
			$where .= $wpdb->prepare( " AND EXISTS ($membership AND t.term_id = %d)", $category ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed membership clause and prepared term ID.
		} elseif ( -1 === $category ) {
			$where .= " AND NOT EXISTS ($membership)";
		}

		$links      = array();
		$local_host = preg_replace( '/^www\./', '', strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$add        = static function ( $id, $url ) use ( &$links, $local_host ) {
			if ( ! is_string( $url ) ) {
				return;
			}
			$url = trim( $url );
			if ( str_starts_with( $url, '//' ) ) {
				$url = 'https:' . $url;
			}
			try {
				$url = Digitalogic_Product_Research::url( $url );
			} catch ( RuntimeException $e ) {
				return;
			}
			$host = preg_replace( '/^www\./', '', wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host === $local_host || str_ends_with( $host, '.' . $local_host ) ) {
				return;
			}
			$links[ $host ][ (int) $id ][ $url ] = true;
		};

		$products = self::rows( "SELECT p.ID FROM {$wpdb->posts} p WHERE $where" );
		if ( '' === $source || 'storefront' === $source ) {
			$content = self::rows(
				"SELECT p.ID AS product_id, c.post_content, c.post_excerpt, m.meta_value AS external_url
				FROM {$wpdb->posts} p
				JOIN {$wpdb->posts} c ON c.ID = p.ID OR (c.post_parent = p.ID AND c.post_type = 'product_variation' AND c.post_status = 'publish')
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = c.ID AND m.meta_key = '_product_url'
				WHERE $where"
			);
			foreach ( $content as $row ) {
				$add( $row['product_id'], $row['external_url'] );
				foreach ( array( $row['post_content'], $row['post_excerpt'] ) as $html ) {
					$tags = new WP_HTML_Tag_Processor( $html );
					while ( $tags->next_tag( 'A' ) ) {
						$add( $row['product_id'], $tags->get_attribute( 'href' ) );
					}
				}
			}
		}

		if ( '' === $source || 'supplier' === $source ) {
			$supplier_key = Digitalogic_Product_Supplier_Links::META_KEY;
			$suppliers    = self::rows(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE $where AND m.meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scope is fixed SQL with a prepared category.
					$supplier_key
				)
			);
			foreach ( $suppliers as $row ) {
				$entries = Digitalogic_Product_Supplier_Links::instance()->get_links( (int) $row['ID'] );
				if ( is_wp_error( $entries ) ) {
					continue;
				}
				foreach ( $entries as $entry ) {
					$add( $row['ID'], $entry['url'] );
				}
			}
		}

		if ( in_array( $source, array( '', 'research', 'attachments' ), true ) ) {
			Digitalogic_Product_Research::ready();
			$table    = Digitalogic_Product_Research::table( 'listings' );
			$fields   = 'research' === $source ? 'l.url' : 'l.url, l.details';
			$listings = self::rows(
				"SELECT l.product_id, $fields FROM $table l JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE $where"
			);
			foreach ( $listings as $row ) {
				if ( 'attachments' !== $source ) {
					$add( $row['product_id'], $row['url'] );
				}
				if ( 'research' !== $source ) {
					$details = json_decode( $row['details'], true );
					foreach ( (array) ( $details['attachments'] ?? array() ) as $attachment ) {
						$add( $row['product_id'], $attachment['url'] ?? null );
					}
				}
			}
		}

		$hosts   = array();
		$covered = array();
		foreach ( $links as $host => $by_product ) {
			$count = 0;
			foreach ( $by_product as $id => $urls ) {
				$covered[ $id ] = true;
				$count         += count( $urls );
			}
			$hosts[ $host ] = array(
				'links'    => $count,
				'products' => count( $by_product ),
			);
		}
		return array(
			'products' => count( $products ),
			'covered'  => count( $covered ),
			'links'    => array_sum( array_column( $hosts, 'links' ) ),
			'hosts'    => $hosts,
		);
	}

	/**
	 * Execute a query assembled from fixed SQL and prepared filter values.
	 *
	 * @param string $query Report query.
	 * @return array
	 * @throws RuntimeException If a live query fails.
	 */
	private static function rows( $query ) {
		global $wpdb;
		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Live aggregate with fixed SQL and prepared values.
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'External product link query failed.' );
		}
		return $rows;
	}

	/** Render the current report and read-only filters. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'دسترسی به این گزارش فقط برای مدیر سایت است.' ), '', array( 'response' => 403 ) );
		}
		// Read-only filter parameters do not require a nonce.
		$category = (int) filter_input( INPUT_GET, 'dgl_category', FILTER_VALIDATE_INT );
		$source   = self::filter( 'dgl_source' );
		$search   = self::filter( 'dgl_host' );
		$sort     = self::filter( 'dgl_sort' );
		$source   = isset( self::SOURCES[ $source ] ) ? $source : '';
		$terms    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		echo '<div class="wrap" dir="rtl"><h1>گزارش دامنه‌های محصولات</h1>';
		if ( is_wp_error( $terms ) ) {
			echo '<div class="notice notice-error"><p>خواندن دسته‌بندی‌های محصولات ممکن نبود.</p></div></div>';
			return;
		}
		$category = ( -1 === $category || in_array( $category, array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ), true ) ) ? $category : 0;
		?>
		<p>این گزارش با هر بار باز کردن صفحه یا اعمال فیلتر، از داده‌های فعلی فروشگاه خوانده می‌شود.</p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<p style="display:flex;flex-wrap:wrap;align-items:end;gap:12px">
				<label>دسته‌بندی<br><select name="dgl_category">
					<option value="0">همهٔ دسته‌ها</option>
					<option value="-1" <?php selected( $category, -1 ); ?>>بدون دسته</option>
					<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $category, $term->term_id ); ?>><?php echo esc_html( $term->name . ' · ' . $term->term_id ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<label>منبع پیوند<br><select name="dgl_source">
					<?php foreach ( self::SOURCES as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $source, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<label>جست‌وجوی دامنه<br><input type="search" dir="ltr" name="dgl_host" value="<?php echo esc_attr( $search ); ?>" placeholder="example.com"></label>
				<label>مرتب‌سازی<br><select name="dgl_sort">
					<option value="links">تعداد پیوند</option>
					<option value="products" <?php selected( $sort, 'products' ); ?>>تعداد محصول</option>
					<option value="host" <?php selected( $sort, 'host' ); ?>>نام دامنه</option>
				</select></label>
				<button type="submit" class="button button-primary">اعمال فیلتر</button>
			</p>
		</form>
		<?php
		try {
			$report = self::report( $category, $source );
		} catch ( RuntimeException $e ) {
			echo '<div class="notice notice-error"><p>خواندن داده‌های گزارش ممکن نبود.</p></div></div>';
			return;
		}

		$hosts = $report['hosts'];
		ksort( $hosts, SORT_STRING );
		if ( 'host' !== $sort ) {
			$column = 'products' === $sort ? 'products' : 'links';
			uasort(
				$hosts,
				static function ( $a, $b ) use ( $column ) {
					return $b[ $column ] <=> $a[ $column ];
				}
			);
		}
		echo '<p><strong>' . esc_html(
			sprintf(
				'%s محصول منتشرشده · %s محصول دارای پیوند · %s بدون پیوند · %s پیوند · %s دامنه',
				number_format_i18n( $report['products'] ),
				number_format_i18n( $report['covered'] ),
				number_format_i18n( $report['products'] - $report['covered'] ),
				number_format_i18n( $report['links'] ),
				number_format_i18n( count( $hosts ) )
			)
		) . '</strong></p>';
		?>
		<p>هر پیوند، یک نشانی یکتا برای یک محصول است. مدل‌های یک محصول زیر همان محصول حساب می‌شوند. شمارش هر دسته بر اساس عضویت مستقیم است و یک محصول می‌تواند در چند دسته باشد. جست‌وجوی دامنه فقط ردیف‌های جدول را فیلتر می‌کند.</p>
		<p>دامنه‌های سایت، پیوندهای تکراری و پارامترهای رایج رهگیری حذف می‌شوند. این آمار منابع ثبت‌شده را نشان می‌دهد و اعتبار یا موجودی آن‌ها را تأیید نمی‌کند.</p>
		<table class="widefat striped" style="overflow-wrap:anywhere">
			<thead><tr><th scope="col">دامنه</th><th scope="col">پیوند یکتا</th><th scope="col">محصول</th></tr></thead>
			<tbody>
			<?php
			$shown = 0;
			foreach ( $hosts as $host => $counts ) {
				if ( '' !== $search && false === stripos( $host, $search ) ) {
					continue;
				}
				++$shown;
				echo '<tr><th scope="row"><bdi dir="ltr">' . esc_html( $host ) . '</bdi></th><td>' .
					esc_html( number_format_i18n( $counts['links'] ) ) . '</td><td>' .
					esc_html( number_format_i18n( $counts['products'] ) ) . '</td></tr>';
			}
			if ( ! $shown ) {
				echo '<tr><td colspan="3">دامنه‌ای با این فیلتر پیدا نشد.</td></tr>';
			}
			?>
			</tbody>
		</table></div>
		<?php
	}

	/**
	 * Read a text filter.
	 *
	 * @param string $name Query parameter.
	 * @return string
	 */
	private static function filter( $name ) {
		return sanitize_text_field( filter_input( INPUT_GET, $name ) ?? '' );
	}
}
