const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(
	path.join(__dirname, '..', 'assets', 'js', 'product-identity.js'),
	'utf8'
);

test('selected variations and Woodmart fallback expose the public Product Code safely', () => {
	assert.match(source, /variation\.digitalogic_product_code/);
	assert.match(source, /settings\.singleProductCode/);
	assert.match(source, /کد کالا/);
	assert.doesNotMatch(source, /کد پاتریس/);
	assert.match(source, /value\.textContent = code/);
	assert.match(source, /itemCode\.textContent = text\(child && child\.code\)/);
	assert.match(source, /reset_data hide_variation/);
	assert.match(source, /digitalogic:select-product-code/);
	assert.doesNotMatch(source, /patris/i);
});

test('an identical generic Woo SKU is hidden only after an exact Code match', () => {
	assert.match(source, /text\(sku\.textContent\) === code/);
	assert.match(source, /digitalogic-duplicate-product-code-sku/);
	assert.match(source, /markDuplicateLoopSkus/);
	assert.match(source, /markDuplicateCustomerSkus/);
	assert.match(source, /\.wd-product-sku/);
	assert.match(source, /\.digitalogic-cart-product-code/);
});

test('variation events stay scoped to their own product and dedicated slot', () => {
	assert.match(source, /\$form\.closest\('\.product'\)/);
	assert.match(source, /var identity = variationSlot\(\$form\)/);
	assert.match(source, /data-digitalogic-hidden-for-variation/);
	assert.match(source, /variationName === '' && variationCode === ''/);
	assert.doesNotMatch(source, /singleIdentity\(\) \|\| variationSlot/);
});
