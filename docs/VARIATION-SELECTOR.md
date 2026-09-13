# Source model selector ownership

The Digitalogic plugin owns the PHP metadata adapter, progressive enhancement JavaScript and component CSS. The attribute and variation identity are Digitalogic domain data; the same adapter must render on product pages, Elementor widgets and WoodMart AJAX quick views. A child-theme template override would duplicate WooCommerce templates and omit alternate rendering paths.

WoodMart and the child theme own typography, theme colors and form design tokens. The component inherits those values and keeps narrowly scoped fallback CSS. No theme templates or Elementor content are changed.

WooCommerce's original select remains the selection/submission authority. The UI emits its existing jQuery change event and follows availability/reset events. It never calculates prices, changes SKU mappings or invents media. The price presentation asset belongs to the pricing owner and is enqueued here when present. Images use existing child/parent attachments; absent images leave a text card. Ambiguous multi-attribute choices do not borrow a child's SKU or image.

Titles use the child Persian name, then its source name, then the native option label. A distinct stored variation description is shown beneath the title, with SKU on its own line. No duplicated source-name description is manufactured.

Release ownership remains with the parent pricing/integration task. Production acceptance must use deployed assets on digitalogic.ir and record desktop/mobile selection, filtering, keyboard navigation, reset, WooCommerce variation IDs, and WoodMart dynamic forms before merge.
