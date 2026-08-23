'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

function source(relativePath) {
    return fs.readFileSync(path.join(__dirname, '..', relativePath), 'utf8');
}

test('the panel distinguishes SKU from the canonical Product Code', () => {
    const panel = source('assets/js/panel-app.js');
    const labels = source('includes/panel/class-panel.php');
    const admin = source('assets/js/admin.js');
    const adminView = source('includes/admin/views/products.php');

    assert.match(
        panel,
        /key:\s*'patris_product_code',[^\n]+labelKey:\s*'productCode'[^\n]+editable:\s*true/
    );
    assert.match(panel, /key:\s*'sku',[^\n]+labelKey:\s*'sku'[^\n]+editable:\s*true/);
    assert.match(labels, /'productCode'\s*=>\s*'Product Code'/);
    assert.match(labels, /'productCode'\s*=>\s*'کد کالا'/);
    assert.match(labels, /'sku'\s*=>\s*'SKU'/);
    assert.match(admin, /data:\s*'patris_product_code',[\s\S]*?productCodeCell\(row,\s*data\)/);
    assert.match(admin, /\{\s*data:\s*'sku'\s*\}/);
    assert.match(adminView, /Product Code[\s\S]*?SKU/);
});

test('canonical Product Code edits use only the dedicated idempotent command', () => {
    const panel = source('assets/js/panel-app.js');
    const admin = source('assets/js/admin.js');
    const saveMethod = panel.match(
		/saveProductCode:\s*function\(product, desiredCode\)\s*\{([\s\S]*?)\n\s*\},\n\s*handleProductCodeSaveError:/
    );

    assert.ok(saveMethod, 'The panel must expose one dedicated Product Code save method.');
    assert.match(saveMethod[1], /digitalogic_update_product_code/);
    assert.match(saveMethod[1], /expected_code:\s*intent\.expected_code/);
    assert.match(saveMethod[1], /if_match:\s*intent\.if_match/);
    assert.match(saveMethod[1], /request_id:\s*intent\.request_id/);
    assert.match(saveMethod[1], /intent\.signature\s*!==\s*signature/);
	assert.match(saveMethod[1], /\{[\s\S]*?ajaxOnly:\s*true,[\s\S]*?bounded:\s*true,[\s\S]*?transportDeadline:\s*false[\s\S]*?\}/);
    assert.doesNotMatch(saveMethod[1], /digitalogic_update_product['"]/);
    assert.match(admin, /fieldName\s*===\s*'patris_product_code'/);
    assert.match(admin, /digitalogic_update_product_code/);
    assert.match(admin, /expected_code:\s*intent\.expected_code/);
    assert.match(admin, /if_match:\s*intent\.if_match/);
    assert.match(admin, /request_id:\s*intent\.request_id/);
	assert.match(admin, /\{[\s\S]*?ajaxOnly:\s*true,[\s\S]*?bounded:\s*true[\s\S]*?\}/);
});

test('Product Code requests have bounded transports and never auto-replay across transports', () => {
	const panel = source('assets/js/panel-app.js');
	const admin = source('assets/js/admin.js');

	assert.match(panel, /typeof window\.AbortController === 'function'/);
	assert.match(panel, /Promise\.race\(\[fetchRequest, timeoutRequest\]\)/);
	assert.match(panel, /controller\.abort\(\)/);
	assert.match(panel, /digitalogic_request_timeout/);
	assert.match(panel, /saveProductCode:[\s\S]*?ajaxOnly:\s*true,[\s\S]*?bounded:\s*true,[\s\S]*?transportDeadline:\s*false/);
	assert.match(admin, /requestOptions\.timeout\s*=\s*Math\.max\(1000,\s*Math\.min\(30000/);
	assert.match(admin, /digitalogic_update_product_code[\s\S]*?ajaxOnly:\s*true,[\s\S]*?bounded:\s*true/);
});

test('classic Product Code requests remain serialized across redraws and stale callbacks', () => {
	const contract = source('assets/js/product-code-contract.js');
	const admin = source('assets/js/admin.js');

	assert.match(contract, /createRequestRegistry/);
	assert.match(admin, /productCodeRequests\.begin\(productId, requestSnapshot\)/);
	assert.match(admin, /productCodeRequests\.isCurrent\(productId, requestSnapshot\)/);
	assert.match(admin, /productCodeRequests\.finish\(productId, requestSnapshot\)/);
	assert.match(admin, /productCodeRequests\.has\(row\.id\)/);
	assert.match(admin, /productCodeRequests\.size\(\)\s*===\s*0/);
});

test('both surfaces expose an explicit pending-proposal retry and fail closed without the verifier', () => {
	const panel = source('assets/js/panel-app.js');
	const template = source('includes/panel/views/app.php');
	const admin = source('assets/js/admin.js');

	assert.match(panel, /retryPendingProductCode:\s*function/);
	assert.match(panel, /pending_proposal:\s*recovery\.product_code/);
	assert.match(panel, /intent\.pending_mode\s*=\s*'new_request'/);
	assert.match(template, /productCodePendingProposal\(product\)/);
	assert.match(template, /retryPendingProductCode\(product\)/);
	assert.match(admin, /digitalogic-product-code-retry/);
	assert.match(admin, /retryPendingProductCode\(\$\(this\)\.data\('id'\)\)/);
	assert.match(admin, /digitalogic_product_code_verifier_unavailable[\s\S]*?is-readonly/);
	assert.doesNotMatch(admin, /if \(!productCodeRequests \|\| productCodeRequests\.has\(row\.id\)\)/);
});

test('structured precondition errors rotate state while unknown and retryable outcomes remain safe', () => {
	const panel = source('assets/js/panel-app.js');
	const admin = source('assets/js/admin.js');
	const adminPhp = source('includes/admin/class-admin.php');
	const panelPhp = source('includes/panel/class-panel.php');

	for (const php of [adminPhp, panelPhp]) {
		assert.match(php, /'code'\s*=>\s*\$result->get_error_code\(\)/);
		assert.match(php, /'data'\s*=>\s*\$error_data/);
		assert.match(php, /'status'\s*=>\s*\$status/);
	}
	for (const js of [panel, admin]) {
		assert.match(js, /digitalogic_product_code_precondition_failed/);
		assert.match(js, /intent\.expected_code\s*=\s*details\.current_code/);
		assert.match(js, /intent\.if_match\s*=\s*details\.current_revision/);
		assert.match(js, /intent\.request_id\s*=\s*''/);
		assert.match(js, /digitalogic_product_code_outcome_unknown/);
	}
	assert.match(panel, /status\s*>=\s*500/);
	assert.match(admin, /status\s*>=\s*500/);
});

test('source-managed rows are visibly read-only while the backend remains authoritative', () => {
	const panel = source('assets/js/panel-app.js');
	const template = source('includes/panel/views/app.php');
	const admin = source('assets/js/admin.js');
	const manager = source('includes/class-product-manager.php');

	assert.match(manager, /'patris_product_code_editable'\s*=>/);
	assert.match(manager, /'patris_product_code_edit_reason'\s*=>/);
	assert.match(manager, /editability_for\(\s*\$product_id,\s*\$cached_product_code\s*\)/);
	assert.match(panel, /isProductColumnEditable:\s*function/);
	assert.match(panel, /product\.patris_product_code_editable\s*!==\s*false/);
	assert.match(template, /isProductColumnEditable\(product, column\)/);
	assert.match(template, /productColumnEditReason\(product, column\)/);
	assert.match(admin, /patris_product_code_editable\s*===\s*false/);
	assert.match(admin, /product_code_metadata_conflict/);
	assert.match(admin, /hasClass\('is-readonly'\)/);
	for (const js of [panel, admin]) {
		assert.match(js, /digitalogic_product_code_source_managed/);
		assert.match(js, /digitalogic_product_code_meta_conflict/);
		assert.match(js, /patris_product_code_editable\s*=\s*false/);
		assert.match(js, /patris_product_code_edit_reason\s*=/);
	}
});

test('both admin surfaces verify the exact terminal schema and request fingerprint', () => {
	const contract = source('assets/js/product-code-contract.js');
	const panel = source('assets/js/panel-app.js');
	const admin = source('assets/js/admin.js');

	assert.match(contract, /schema:\s*SCHEMA/);
	assert.match(contract, /constantTimeEqual\(result\.request_id,\s*request\.request_id\)/);
	assert.match(contract, /constantTimeEqual\(result\.request_fingerprint,\s*fingerprint\)/);
	assert.match(contract, /constantTimeEqual\(result\.revision,\s*revision\)/);
	assert.match(contract, /verification\.database_readback\s*===\s*true/);
	assert.match(contract, /verification\.source_governance\s*===\s*true/);
	assert.match(panel, /contract\.validateResult\(result,\s*prepared\)/);
	assert.match(admin, /contract\.validateResult\(result,\s*prepared\)/);
});

test('every supported canonical-code writer shares the source identity lock', () => {
	const editor = source('includes/class-digitalogic-product-code-editor.php');
	const receiver = source('includes/class-product-sync-receiver.php');
	const feed = source('includes/class-patris-feed.php');
	const materializer = source('includes/class-patris-catalog-materializer.php');

	assert.match(receiver, /public function acquire_source_identity_lock\(/);
	assert.match(receiver, /public function release_source_identity_lock\(/);
	assert.match(editor, /acquire_source_identity_lock\(\s*0\s*\)/);
	assert.match(feed, /function apply_product_feed[\s\S]*?acquire_source_identity_lock\(\s*0\s*\)/);
	assert.match(materializer, /\$source_identity_locked\s*=\s*Digitalogic_Product_Sync_Receiver::instance\(\)->acquire_source_identity_lock\(\s*0\s*\)/);
});

test('soft-deleted products retain exact Product Code ownership until permanent deletion', () => {
	const editor = source('includes/class-digitalogic-product-code-editor.php');
	const conflictQuery = editor.match(
		/\/\* digitalogic_product_code_conflicts \*\/[\s\S]*?LIMIT 3/
	);

	assert.ok(conflictQuery, 'The exact conflict query must remain identifiable.');
	assert.match(conflictQuery[0], /p\.post_status\s*<>\s*'auto-draft'/);
	assert.doesNotMatch(conflictQuery[0], /post_status[^\n]*trash/);
});

test('the dispatcher and direct admin AJAX surface expose the same Living command', () => {
    const dispatcher = source('includes/class-command-dispatcher.php');
    const admin = source('includes/admin/class-admin.php');
    const service = source('includes/class-digitalogic-product-code-editor.php');

    assert.match(dispatcher, /'digitalogic_update_product_code'\s*=>\s*array\(\s*\$this,\s*'update_product_code'\s*\)/);
    assert.match(dispatcher, /Digitalogic_Product_Code_Editor::instance\(\)->edit\(\s*\$payload\s*\)/);
    assert.match(admin, /wp_ajax_digitalogic_update_product_code/);
    assert.match(admin, /send_command_response\(\s*'digitalogic_update_product_code'/);
    assert.match(service, /public const SCHEMA\s*=\s*'digitalogic\.product-code-edit'/);
    assert.doesNotMatch(service, /(?:^|[^A-Za-z0-9])v[0-9]+(?:[^A-Za-z0-9]|$)/i);
	assert.doesNotMatch(service, /(?:\/v[0-9]+|-v[0-9]+|_v[0-9]+)/i);
});

test('manual outcome reconciliation is versionless, documented, translated, and dry-run first', () => {
	const service = source('includes/class-digitalogic-product-code-editor.php');
	const cli = source('includes/cli/class-cli-commands.php');
	const docs = source('docs/PRODUCT-CODE-EDIT.md');
	const pot = source('languages/digitalogic.pot');
	const fa = source('languages/digitalogic-fa_IR.po');

	assert.match(service, /RECONCILIATION_SCHEMA\s*=\s*'digitalogic\.product-code-reconciliation'/);
	assert.match(cli, /'digitalogic product-code reconcile'/);
	assert.match(cli, /'apply'\s*=>\s*isset\(\s*\$assoc_args\['apply'\]\s*\)/);
	assert.match(docs, /wp digitalogic product-code reconcile/);
	assert.match(docs, /Reconciliation never changes Product Code\./);
	for (const catalog of [pot, fa]) {
		assert.match(catalog, /msgid "The Product Code reconciliation manifest no longer matches the exact dry-run\."/);
		assert.match(catalog, /msgid "You are not allowed to reconcile Product Code operations\."/);
	}
	assert.doesNotMatch(service, /(?:\/v[0-9]+|-v[0-9]+|_v[0-9]+)/i);
	assert.doesNotMatch(cli.match(/public function product_code_reconcile[\s\S]*?\n\t\}/)[0], /(?:\/v[0-9]+|-v[0-9]+|_v[0-9]+)/i);
});

test('classic Product Code assets use content-sensitive cache busting', () => {
	const admin = source('includes/admin/class-admin.php');

	assert.match(admin, /\$product_code_contract_version\s*=\s*filemtime\(/);
	assert.match(admin, /\$admin_script_version\s*=\s*filemtime\(/);
	assert.match(admin, /digitalogic-product-code-contract[^\n]+\$product_code_contract_version/);
	assert.match(admin, /digitalogic-admin[^\n]+\$admin_script_version/);
});
