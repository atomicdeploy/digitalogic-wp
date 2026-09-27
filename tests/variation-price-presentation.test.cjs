'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const createPresentation = require('../assets/js/variation-price-presentation.js');

function documentFixture() {
    return {
        createElement(name) {
            assert.equal(name, 'template');
            let html = '';
            return {
                get innerHTML() { return html; },
                set innerHTML(value) { html = String(value); },
                content: {
                    querySelector() {
                        const contract = html.match(/class="[^"]*digitalogic-variation-price-contract[^"]*"[^>]*data-digitalogic-price-raw="([^"]*)"/);
                        if (!contract) { return null; }
                        return {
                            getAttribute(name) {
                                return name === 'data-digitalogic-price-raw' ? contract[1] : null;
                            }
                        };
                    }
                }
            };
        }
    };
}

const presentation = createPresentation(null, documentFixture());

test('accepts server HTML only when its raw price matches the API field', () => {
    const html = '<span class="digitalogic-variation-price-contract" data-digitalogic-price-raw="293500"><span class="price">293,500 Toman</span></span>';
    assert.equal(presentation.validatedPriceHtml({
        digitalogic_price_contract: 1,
        digitalogic_price_raw: '293500',
        digitalogic_price_html: html,
        price_html: html
    }), html);
});

test('rejects mismatched, missing, and malformed raw price contracts', () => {
    const html = '<span class="digitalogic-variation-price-contract" data-digitalogic-price-raw="293500"><span class="price">293,500 Toman</span></span>';
    assert.equal(presentation.validatedPriceHtml({
        digitalogic_price_contract: 1,
        digitalogic_price_raw: '293501',
        digitalogic_price_html: html
    }), null);
    assert.equal(presentation.validatedPriceHtml({
        digitalogic_price_contract: 1,
        digitalogic_price_raw: null,
        digitalogic_price_html: html
    }), null);
    assert.equal(presentation.validatedPriceHtml({
        digitalogic_price_contract: 1,
        digitalogic_price_raw: '293,500',
        digitalogic_price_html: html
    }), null);
});

test('never accepts a price contract for an unavailable variation', () => {
    const html = '<span class="digitalogic-variation-price-contract" data-digitalogic-price-raw="293500"><span class="price">293,500 Toman</span></span>';
    assert.equal(presentation.validatedPriceHtml({
        is_in_stock: false,
        digitalogic_price_contract: 1,
        digitalogic_price_raw: '293500',
        digitalogic_price_html: html
    }), null);
    assert.equal(presentation.validatedPriceHtml({
        digitalogic_price_hidden_for_stock: true,
        digitalogic_price_contract: 1,
        digitalogic_price_raw: '293500',
        digitalogic_price_html: html
    }), null);
});

test('keeps a bounded legacy fallback for cached pre-contract payloads', () => {
    assert.equal(
        presentation.validatedPriceHtml({ price_html: '<span class="price">100 Toman</span>' }),
        '<span class="price">100 Toman</span>'
    );
    assert.equal(presentation.validatedPriceHtml({ price_html: '' }), null);
});
