<?php
/**
 * Canonical public links and published business information for the native app.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

/** Read-only adapters; no independent product or business-content authority. */
final class MobileStorefrontInfo {
	/**
	 * Normalize an exact HTTPS public path, rejecting action-bearing URLs.
	 *
	 * @param string $url Requested absolute URL.
	 * @param string $host Canonical site host.
	 * @return string|null Decoded path, with optional trailing slash normalized.
	 */
	public static function public_path( string $url, string $host ): ?string {
		if ( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\\\\]/', $url ) || preg_match( '/%(?:2f|5c|25|0[0-9a-f]|1[0-9a-f]|7f)/i', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $host ) || array_intersect( array_keys( $parts ), array( 'user', 'pass', 'port', 'query', 'fragment' ) ) ) {
			return null;
		}
		$path = rawurldecode( $parts['path'] ?? '/' );
		if ( preg_match( '~(?:^|/)\.{1,2}(?:/|$)|//~', $path ) || ! preg_match( '//u', $path ) ) {
			return null;
		}
		return rtrim( $path, '/' ) . '/';
	}

	/**
	 * Resolve only an exact, currently public Woo product/category permalink.
	 *
	 * @param string $url Requested URL; never fetched over the network.
	 * @return array|null Public identity or no match.
	 */
	public static function resolve( string $url ): ?array {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$path = self::public_path( $url, $host );
		if ( null === $path ) {
			return null;
		}
		$bases         = get_option( 'woocommerce_permalinks', array() );
		$product_base  = '/' . trim( $bases['product_base'] ?? 'product', '/' ) . '/';
		$category_base = '/' . trim( $bases['category_base'] ?? 'product-category', '/' ) . '/';
		if ( str_starts_with( $path, $product_base ) ) {
			$id        = url_to_postid( $url );
			$post      = $id ? get_post( $id ) : null;
			$product   = $post && 'product' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password ? wc_get_product( $id ) : null;
			$canonical = $product ? get_permalink( $id ) : '';
			if ( $product && $product->is_visible() && self::public_path( $canonical, $host ) === $path ) {
				return array(
					'type'          => 'product',
					'id'            => (int) $id,
					'canonical_url' => $canonical,
				);
			}
		} elseif ( str_starts_with( $path, $category_base ) ) {
			$term      = get_term_by( 'slug', basename( rtrim( $path, '/' ) ), 'product_cat' );
			$canonical = $term ? get_term_link( $term ) : null;
			if ( is_string( $canonical ) && self::public_path( $canonical, $host ) === $path ) {
				return array(
					'type'          => 'category',
					'id'            => (int) $term->term_id,
					'canonical_url' => $canonical,
				);
			}
		}
		return null;
	}

	/**
	 * Load a published canonical page into an inert DOM, without shortcode execution.
	 *
	 * @param string $slug Existing page path.
	 * @return \DOMXPath|null Parsed published page.
	 */
	private static function page( string $slug ): ?\DOMXPath {
		$page = get_page_by_path( $slug );
		if ( ! $page || 'publish' !== $page->post_status || '' !== $page->post_password ) {
			return null;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( '<?xml encoding="UTF-8">' . $page->post_content, LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return new \DOMXPath( $document );
	}

	/**
	 * Read one visible text node, preserving only normalized text.
	 *
	 * @param \DOMXPath|null $dom Published page.
	 * @param string         $query XPath expression owned by this adapter.
	 * @return string Published text or empty.
	 */
	private static function text( ?\DOMXPath $dom, string $query ): string {
		$node = $dom ? $dom->query( $query )->item( 0 ) : null;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode owns this built-in property name.
		return $node ? trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ) : '';
	}

	/**
	 * Project existing contact/about pages; Woo's fulfillment origin is not visitor address.
	 *
	 * @return array Public business information with explicit source URLs.
	 */
	public static function business(): array {
		$contact = self::page( 'our-contacts' );
		$about   = self::page( 'about-us' );
		$phones  = array();
		if ( $contact ) {
			foreach ( $contact->query( '//div[contains(@class,"dg-detail-item")]//a[starts-with(@href,"tel:")]' ) as $anchor ) {
				$number = substr( $anchor->getAttribute( 'href' ), 4 );
				if ( preg_match( '/\A\+[0-9]{8,15}(?:,[0-9]{1,6})?\z/D', $number ) ) {
					$container = $contact->query( 'ancestor::div[contains(@class,"dg-detail-item")][1]', $anchor )->item( 0 );
					$label     = $container ? $contact->query( './/div[contains(@class,"dg-detail-label")]', $container )->item( 0 ) : null;
					$parts     = explode( ',', $number, 2 );
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode owns this built-in property name.
					$caption           = $label ? trim( $label->textContent ) : trim( $anchor->textContent );
					$phones[ $number ] = array(
						'label'     => $caption . ( isset( $parts[1] ) ? ' · داخلی ' . $parts[1] : '' ),
						'number'    => $parts[0],
						'extension' => $parts[1] ?? null,
					);
				}
			}
		}
		$email_node = $contact ? $contact->query( '//a[starts-with(@href,"mailto:")]' )->item( 0 ) : null;
		$email      = $email_node ? substr( $email_node->getAttribute( 'href' ), 7 ) : '';
		$location   = null;
		if ( $contact ) {
			foreach ( $contact->query( '//a[contains(@href,"google.com/maps/place/")]' ) as $anchor ) {
				if ( preg_match( '~/@(-?[0-9]+\.[0-9]+),(-?[0-9]+\.[0-9]+),~', $anchor->getAttribute( 'href' ), $match ) && abs( (float) $match[1] ) <= 90 && abs( (float) $match[2] ) <= 180 ) {
					$location = array(
						'latitude'  => (float) $match[1],
						'longitude' => (float) $match[2],
					);
					break;
				}
			}
		}
		$paragraphs = array();
		if ( $about ) {
			foreach ( $about->query( '//p' ) as $paragraph ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode owns this built-in property name.
				$text = trim( preg_replace( '/\s+/u', ' ', $paragraph->textContent ) );
				if ( '' !== $text ) {
					$paragraphs[] = $text;
				}
				if ( count( $paragraphs ) >= 4 ) {
					break;
				}
			}
		}
		return array(
			'name'        => get_bloginfo( 'name' ),
			'about'       => implode( "\n\n", $paragraphs ),
			'address'     => self::text( $contact, '//div[contains(@class,"dg-highlight-card")]/p[1]' ),
			'phones'      => array_values( $phones ),
			'email'       => is_email( $email ) ? $email : null,
			'location'    => $location,
			'hours'       => self::text( $contact, '//div[contains(@class,"dg-ext-item")][strong[contains(text(),"پشتیبانی تماس")]]/span' ),
			'source_urls' => array( home_url( '/our-contacts/' ), home_url( '/about-us/' ) ),
		);
	}
}
