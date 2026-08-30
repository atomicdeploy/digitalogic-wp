const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'storefront-realtime.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '..', 'includes', 'integrations', 'class-digitalogic-storefront-realtime.php'), 'utf8');

test('storefront client elects one SSE owner and relays through BroadcastChannel with storage fallback', () => {
    assert.match(source, /new window\.BroadcastChannel\(channelName\)/);
    assert.match(source, /new window\.EventSource/);
    assert.match(source, /leaderKey/);
    assert.match(source, /expiresAt/);
    assert.match(source, /writeLocal\(eventKey/);
    assert.match(source, /storageEvent\.key === eventKey/);
});

test('storefront client uses persistent public cache and tab-scoped refresh guards', () => {
    assert.match(source, /window\.localStorage\.setItem/);
    assert.match(source, /window\.sessionStorage\.setItem/);
    assert.match(source, /currencyTtlMs/);
    assert.match(source, /lastProductEventId/);
    assert.match(source, /pending-product-event/);
});

test('product updates refresh the live WooCommerce fragment and safely fall back to reload', () => {
    assert.match(source, /fetch\(requestUrl\.toString\(\)/);
    assert.match(source, /current\.replaceWith\(replacement\)/);
    assert.match(source, /wc_product_gallery/);
    assert.match(source, /wc_variation_form/);
    assert.match(source, /fallbackReload\(eventId\)/);
});

test('SSE server is bounded, non-buffered, and public-event allowlisted', () => {
    assert.match(php, /Content-Type: text\/event-stream/);
    assert.match(php, /X-Accel-Buffering: no/);
    assert.match(php, /ob_end_flush/);
    assert.match(php, /zlib\.output_compression/);
    assert.match(php, /apache_setenv\( 'no-gzip'/);
    assert.match(php, /STREAM_SECONDS\s*=\s*20/);
    assert.match(php, /PUBLIC_EVENT_NAMES/);
    assert.match(php, /product\.stock\.changed/);
});
