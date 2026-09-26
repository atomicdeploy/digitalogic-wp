# Storefront media-gap evidence ledger

Observed from the production WooCommerce catalog at `2026-09-26T14:59:38Z`. This is a read-only evidence record, not an image-mapping manifest.

## Scope and authority

- Query scope: published parent `product` posts only. Variations were excluded because a variation can legitimately inherit its parent image.
- Image state: `get_post_thumbnail_id()` was absent. The live product page then rendered WooCommerce's placeholder presentation.
- Identity authority: `_digitalogic_patris_product_code`, falling back only to the product SKU when already stored on the same WooCommerce record.
- No image assignment was made. A title, description, category, manufacturer resemblance, or visually similar search result is never sufficient evidence for a media mapping.

| Observation | Count |
| --- | ---: |
| Published parent products | 986 |
| Missing featured image | 962 |
| Explicit WooCommerce-placeholder attachment | 0 |
| Missing image with authoritative code | 909 |
| Missing image without an authoritative code | 53 |

## Browser-confirmed representative

| Product ID | Product code | Product | Live state | Decision |
| ---: | --- | --- | --- | --- |
| 10912 | `102007003` | سنسور فاصله و موقعیت HC-SR04 | WooCommerce placeholder with the site's “image pending” treatment | Preserve placeholder; no candidate image is authorized |

## Bounded sample from the same readback

| Product ID | Product code | Product |
| ---: | --- | --- |
| 10488 | absent | Arduino Mega |
| 10491 | absent | Arduino Nano |
| 10600 | absent | ماژول وای‌فای ESP-12 مبتنی بر ESP8266 |
| 10670 | absent | Raspberry Pi Pico |
| 10677 | absent | Raspberry Pi Zero Series |
| 10697 | absent | ESP-32 |
| 10706 | absent | NVIDIA Jetson Series |
| 10756 | absent | HC-05 |
| 10803 | `109001` | آی‌سی خودرو SPC563M64L5COAY |
| 10826 | `109004` | آی‌سی خودرو ATIC39-B4 |
| 10828 | `109005` | آی‌سی خودرو ATIC17E1 |
| 10830 | `109008` | آی‌سی خودرو L974113TR |
| 10912 | `102007003` | سنسور فاصله و موقعیت HC-SR04 |

## Admission rule for future media work

An image may enter an implementation manifest only when the exact product code/SKU is present on both the WooCommerce record and an authoritative media source. The ledger must record that source, its observation time, the exact matching identifier, and a reviewer decision. Ambiguous or absent identifiers remain unresolved.
