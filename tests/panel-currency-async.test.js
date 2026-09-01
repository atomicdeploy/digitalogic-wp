const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const panelSource = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'panel-app.js'), 'utf8');
const panelView = fs.readFileSync(path.join(__dirname, '..', 'includes', 'panel', 'views', 'app.php'), 'utf8');

test('panel currency save is revision-bound and follows one exact background job', () => {
    const saveCurrencySource = panelSource.match(/saveCurrency:\s*function[\s\S]*?\n\s*},\n\s*watchCurrencyJob:/)[0];

    assert.match(panelSource, /payload\.expected_state_revision\s*=\s*self\.summary/);
    assert.match(panelSource, /digitalogic_currency_job_status/);
    assert.match(panelSource, /job_id:\s*job\.job_id/);
    assert.match(panelSource, /generation:\s*Number\(job\.generation\)/);
	assert.match(panelSource, /request_id:\s*'currency:'\s*\+/);
	assert.match(saveCurrencySource, /ajaxOnly:\s*true/);
	assert.match(saveCurrencySource, /noAutoReplay:\s*true/);
	assert.match(panelSource, /digitalogic_cancel_currency_job/);
    assert.doesNotMatch(
        saveCurrencySource,
        /then\(function\([^)]*\)\s*\{[\s\S]*?return self\.loadSummary\(\)/,
        'The enqueue response must release the save request instead of waiting for repricing.'
    );
    assert.match(panelView, /currencyJob\.message_fa/);
    assert.match(panelView, /currencyJob\.progress/);
});

test('panel currency save distinguishes a rejected request from an unknown delivery', () => {
    const saveCurrencySource = panelSource.match(/saveCurrency:\s*function[\s\S]*?\n\s*},\n\s*watchCurrencyJob:/)[0];

    assert.match(saveCurrencySource, /error\.outcome_unknown\s*\|\|\s*error\.deliveryUnknown/);
    assert.match(saveCurrencySource, /if\s*\(!outcomeUnknown\)/);
    assert.match(saveCurrencySource, /self\.currencyJob\s*=\s*null/);
    assert.match(saveCurrencySource, /self\.error\s*=\s*error\.message/);
    assert.match(panelView, /v-if="currencyJob\.job_id && currencyJob\.generation"/);
});

test('panel Ajax transport bounds headers and body parsing', () => {
    assert.match(panelSource, /AbortController/);
    assert.match(panelSource, /ajax_request_timeout\s*\|\|\s*12000/);
    assert.match(panelSource, /response\.text\(\)/);
    assert.match(panelSource, /digitalogic_panel_request_timeout/);
    assert.match(panelSource, /digitalogic_panel_response_invalid/);
    assert.match(panelSource, /window\.clearTimeout\(timeout\)/);
});

test('panel job observation is bounded and never leaves an endless loading state', () => {
    assert.match(panelSource, /Date\.now\(\) \+ 180000/);
    assert.match(panelSource, /status:\s*'observation_timeout'/);
    assert.match(panelSource, /self\.saving = false/);
    assert.match(panelSource, /beforeUnmount:[\s\S]*?clearTimeout\(this\.currencyJobTimer\)/);
});

test('panel resumes publication after reload and renders exhausted publication as terminal', () => {
	assert.match(panelSource, /\['queued', 'running', 'cancelling', 'publishing'\]/);
    assert.match(panelSource, /data\.currency_job\.status === 'publication_failed'/);
	assert.match(panelSource, /\['confirmed', 'failed', 'cancelled', 'publication_failed', 'superseded'\]/);
    assert.match(panelSource, /job\.status === 'publishing'[\s\S]*?Date\.now\(\) \+ 180000/);
});
