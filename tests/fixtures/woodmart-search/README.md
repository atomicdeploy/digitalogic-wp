# Woodmart search browser fixture

The fixture uses the unminified Woodmart 8.6.6 search scripts served by Digitalogic.
The HTML and layout styles isolate the header and mobile search overlay. The
Woodmart scripts handle opening the overlay, requesting results and rendering
suggestions. The test supplies synthetic search responses and loads the Digitalogic
JavaScript and stylesheet from the checkout being tested.

- [autocomplete.js](https://digitalogic.ir/wp-content/themes/woodmart/js/libs/autocomplete.js) retains its upstream MIT-style license notice.
- [ajaxSearch.js](https://digitalogic.ir/wp-content/themes/woodmart/js/scripts/global/ajaxSearch.js) and [searchFullScreen.js](https://digitalogic.ir/wp-content/themes/woodmart/js/scripts/global/searchFullScreen.js) are from the [GPL-3.0 Woodmart distribution](https://digitalogic.ir/wp-content/themes/woodmart/style.css).

Use the dependency versions in CI and run `node --test tests/woodmart-search.browser.cjs`.
Set `WOODMART_SEARCH_LIVE=1` to use the deployed storefront and live inventory.
The live check defaults to ADS1256 and HC-SR04. Set `WOODMART_SEARCH_SOLD_OUT` and
`WOODMART_SEARCH_IN_STOCK` when those products change availability.
