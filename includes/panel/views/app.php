<?php
/**
 * Digitalogic in-site panel shell.
 *
 * @var string $panel_path
 */

if (!defined('ABSPATH')) {
    exit;
}

$config = Digitalogic_Panel::instance()->client_config();
$lang = strpos($config['locale'], 'fa') === 0 ? 'fa' : 'en';
$dir = $config['i18n'][$lang]['dir'];
$logo_url = !empty($config['theme']['logo_url']) ? $config['theme']['logo_url'] : '';
$fatal_copy = 'fa' === $lang
    ? array(
        'title' => 'نمایش پنل امکان‌پذیر نیست',
        'detail' => 'رابط پنل هنگام بارگذاری با خطا روبه‌رو شد. صفحه را دوباره بارگذاری کنید؛ اگر مشکل ادامه داشت، جزئیات کنسول را بررسی کنید.',
        'retry' => 'بارگذاری دوباره',
        'wordpress' => 'پیشخوان وردپرس',
    )
    : array(
        'title' => 'The panel could not be displayed',
        'detail' => 'The panel interface encountered an error while loading. Reload the page; if it continues, review the console details.',
        'retry' => 'Reload panel',
        'wordpress' => 'WordPress dashboard',
    );
$wordpress_admin_url = function_exists('admin_url') ? admin_url() : ($config['legacy_panel_url'] ?? '/wp-admin/');
$retry_url = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : ($config['panel_url'] ?? '/panel/');
?><!doctype html>
<html <?php language_attributes(); ?> dir="<?php echo esc_attr($dir); ?>">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(sprintf(__('Digitalogic Panel - %s', 'digitalogic'), get_bloginfo('name'))); ?></title>
    <?php wp_print_styles('digitalogic-panel'); ?>
</head>
<body class="digitalogic-panel-body">
    <div id="digitalogic-panel" data-path="<?php echo esc_attr($panel_path); ?>">
        <div class="dlp-boot">
            <div class="dlp-boot-mark">
                <?php if ($logo_url) : ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
                <?php else : ?>
                    <span>D</span>
                <?php endif; ?>
            </div>
            <div><?php esc_html_e('Loading...', 'digitalogic'); ?></div>
        </div>
    </div>
    <section id="digitalogic-panel-fallback" class="dlp-boot dlp-fatal-panel" role="alert" aria-labelledby="dlp-fatal-title" aria-describedby="dlp-fatal-description" tabindex="-1" hidden>
        <div class="dlp-boot-mark">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
            <?php else : ?>
                <span>D</span>
            <?php endif; ?>
        </div>
        <div class="dlp-fatal-copy">
            <h1 id="dlp-fatal-title"><?php echo esc_html($fatal_copy['title']); ?></h1>
            <p id="dlp-fatal-description"><?php echo esc_html($fatal_copy['detail']); ?></p>
            <code data-dlp-fatal-detail dir="ltr"></code>
        </div>
        <div class="dlp-actions dlp-fatal-actions">
            <a class="dlp-button dlp-primary" href="<?php echo esc_url($retry_url); ?>"><span class="dashicons dashicons-update"></span><?php echo esc_html($fatal_copy['retry']); ?></a>
            <a class="dlp-button" href="<?php echo esc_url($wordpress_admin_url); ?>"><span class="dashicons dashicons-admin-home"></span><?php echo esc_html($fatal_copy['wordpress']); ?></a>
        </div>
    </section>
    <script type="text/x-template" id="digitalogic-panel-template">
        <div class="dlp-layout" v-cloak>
            <aside class="dlp-sidebar">
                <div class="dlp-brand">
                    <div class="dlp-logo-mark">
                        <img v-if="configTheme && configTheme.logo_icon_url" :src="configTheme.logo_icon_url" :alt="(configTheme && configTheme.site_name) || 'Digitalogic'">
                        <span v-else>D</span>
                    </div>
                    <div class="dlp-brand-copy">
                        <div class="dlp-brand-title">Digitalogic</div>
                        <div class="dlp-brand-subtitle">{{ (configTheme && configTheme.site_name) || 'Panel' }}</div>
                    </div>
                </div>
                <div class="dlp-user">
                    <div class="dlp-avatar"><span class="dashicons dashicons-admin-users"></span></div>
                    <div class="dlp-user-copy">
                        <strong>{{ user.display_name || user.login }}</strong>
                        <div class="dlp-muted">{{ user.login }} &middot; {{ user.email }}</div>
                    </div>
                </div>
                <nav class="dlp-nav">
                    <a class="dlp-nav-link" :class="{'is-active': currentPage === 'dashboard'}" :href="routeHref('/')" data-route="/" @click="navigateClick('/', $event)"><span class="dlp-nav-label"><span class="dashicons dashicons-dashboard"></span>{{ t.dashboard }}</span></a>
                    <a class="dlp-nav-link" :class="{'is-active': currentPage === 'products'}" :href="routeHref('/products')" data-route="/products" @click="navigateClick('/products', $event)"><span class="dlp-nav-label"><span class="dashicons dashicons-products"></span>{{ t.products }}</span></a>
                    <a class="dlp-nav-link" :class="{'is-active': currentPage === 'users'}" :href="routeHref('/users')" data-route="/users" @click="navigateClick('/users', $event)"><span class="dlp-nav-label"><span class="dashicons dashicons-admin-users"></span>{{ t.users }}</span></a>
                    <a class="dlp-nav-link" :class="{'is-active': currentPage === 'reports'}" :href="routeHref('/reports')" data-route="/reports" @click="navigateClick('/reports', $event)"><span class="dlp-nav-label"><span class="dashicons dashicons-chart-bar"></span>{{ t.reports }}</span></a>
                    <a class="dlp-nav-link" :class="{'is-active': currentPage === 'cli'}" :href="routeHref('/cli')" data-route="/cli" @click="navigateClick('/cli', $event)"><span class="dlp-nav-label"><span class="dashicons dashicons-editor-code"></span>{{ t.cli }}</span></a>
                    <a class="dlp-nav-link" :class="{'is-active': currentPage === 'sync'}" :href="routeHref('/sync')" data-route="/sync" @click="navigateClick('/sync', $event)"><span class="dlp-nav-label"><span class="dashicons dashicons-update"></span>{{ t.sync }}</span></a>
                    <a class="dlp-nav-link" :class="{'is-active': currentPage === 'settings'}" :href="routeHref('/settings')" data-route="/settings" @click="navigateClick('/settings', $event)"><span class="dlp-nav-label"><span class="dashicons dashicons-admin-settings"></span>{{ t.settings }}</span></a>
                </nav>
            </aside>
            <main class="dlp-main">
                <header class="dlp-topbar">
                    <div class="dlp-title-block">
                        <h1 class="dlp-title">{{ t[currentPage] || t.dashboard }}</h1>
                        <div class="dlp-muted">{{ t.signedInAs }} {{ user.display_name || user.login }}</div>
                    </div>
                    <div class="dlp-actions">
                        <span class="dlp-pill dlp-transport" :class="transport === 'websocket' ? 'is-ok' : 'is-warn'"><span class="dlp-status-dot" aria-hidden="true"></span>{{ transport === 'websocket' ? t.connected : t.fallback }}</span>
                        <select class="dlp-select" v-model="lang" :aria-label="t.language"><option value="fa">فارسی</option><option value="en">English</option></select>
                        <div class="dlp-theme-picker">
                            <button class="dlp-button dlp-theme-trigger" @click="toggleMenu('theme')" :aria-label="t.themeAppearance">
                                <span :class="icon(activeThemeOption.icon)"></span>{{ activeThemeOption.label }}
                            </button>
                            <span class="dlp-menu dlp-theme-menu" v-if="openMenu === 'theme'">
                                <button v-for="option in themeOptions" :key="option.value" @click="setThemeChoice(option)">
                                    <span :class="icon(option.icon)"></span>
                                    <span>{{ option.label }}</span>
                                </button>
                            </span>
                        </div>
                        <a class="dlp-button" href="/wp-admin/" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-home"></span>{{ t.openWordPress }}</a>
                        <a class="dlp-icon-button" :href="config.logout_url" :aria-label="t.logout" :title="t.logout"><span class="dashicons dashicons-migrate"></span></a>
                    </div>
                </header>
                <div v-if="error" class="dlp-error">{{ error }}</div>
                <div class="dlp-toast-stack" aria-live="polite" aria-atomic="false">
                    <button v-for="toast in toasts" :key="toast.id" class="dlp-toast" :class="'is-' + toast.level" @click="dismissToast(toast.id)">
                        <span class="dlp-status-dot" aria-hidden="true"></span>
                        <span>{{ toast.message }}</span>
                    </button>
                </div>

                <section v-if="currentPage === 'dashboard'">
                    <div class="dlp-grid">
                        <article v-for="card in dashboardCards" :key="card.key" class="dlp-card" :class="{'is-dragging': draggedCard === card.key}" @dragover.prevent="draggedCard && draggedCard !== card.key" @drop="dropCard(card.key)">
                            <div class="dlp-card-head">
                                <span class="dlp-card-label"><span :class="icon(card.icon)"></span> {{ card.label }}</span>
                                    <span class="dlp-card-tools">
                                        <span class="dlp-drag-handle" draggable="true" aria-hidden="true" @dragstart.stop="startDrag(card.key, $event)" @dragend.stop="endDrag"></span>
                                    <button v-if="card.editable" class="dlp-menu-button" @click="startCardEdit(card, $event)" :aria-label="t.edit"><span class="dashicons dashicons-edit"></span></button>
                                    <button class="dlp-menu-button" @click="toggleMenu(card.key)" :aria-label="t.actions"><span class="dashicons dashicons-ellipsis"></span></button>
                                    <span class="dlp-menu" v-if="openMenu === card.key">
                                        <button v-if="card.editable" @click="cardAction(card, 'edit')"><span class="dashicons dashicons-edit"></span>{{ t.edit }}</button>
                                        <button v-if="card.key === 'products'" @click="cardAction(card, 'products')"><span class="dashicons dashicons-products"></span>{{ t.products }}</button>
                                    </span>
                                </span>
                            </div>
                            <div class="dlp-card-value" :class="{'is-editable': card.editable}">
                                <button v-if="!isCardEditing(card)" class="dlp-card-value-button dlp-currency-value" :class="{'dlp-numeric-local': card.editable}" @click="startCardEdit(card, $event)">{{ card.value }}</button>
                                <input v-else class="dlp-card-input dlp-currency-input dlp-numeric-local" dir="ltr" inputmode="decimal" :data-card-key="card.key" :value="formatCurrencyInputNumber(currencyDraft[card.field])" @input="onCurrencyInput(card.field, $event, true)" @blur="finishCurrencyField(card.field)" @keydown.enter.prevent="saveCurrencyField(card.field)" @keydown.esc.prevent="cancelCurrencyField(card.field)">
                            </div>
                        </article>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.recentActivity }}</strong></div>
                        <div class="dlp-table-wrap" v-if="summary && summary.logs && summary.logs.length">
                            <table class="dlp-table"><tbody><tr v-for="log in summary.logs" :key="log.id"><td>{{ logActionLabel(log.action) }}</td><td>{{ objectTypeLabel(log.object_type) }}</td><td>{{ formatDateTime(log.created_at) }}</td></tr></tbody></table>
                        </div>
                        <div v-else class="dlp-empty">{{ t.noRows }}</div>
                    </div>
                </section>

                <section v-if="currentPage === 'products'" class="dlp-products-section">
                    <div class="dlp-toolbar">
                        <label class="dlp-search-wrap" :data-hotkey="productSearchHotkeyLabel">
                            <input class="dlp-input dlp-search" v-model="search" :placeholder="t.search" :aria-label="t.search" :data-hotkey="productSearchHotkeyLabel" :aria-keyshortcuts="productSearchHotkey" autofocus>
                            <span class="dlp-search-kbd" :data-hotkey="productSearchHotkeyLabel" aria-hidden="true"></span>
                        </label>
                        <span class="dlp-pill">{{ formatNumber(productFilteredTotal) }} / {{ formatNumber(productTotal) }}</span>
                        <button class="dlp-button dlp-mode-toggle" :class="{'is-editing': productEditMode}" :aria-pressed="productEditMode ? 'true' : 'false'" @click="setProductEditMode(!productEditMode)"><span class="dashicons" :class="productEditMode ? 'dashicons-unlock' : 'dashicons-lock'"></span>{{ productEditMode ? t.editMode : t.viewMode }}</button>
                        <label class="dlp-inline-toggle" :class="{'is-disabled': !productEditMode}"><input type="checkbox" :checked="productAutosave" :disabled="!productEditMode" @change="setProductAutosave($event.target.checked)"><span>{{ t.autosave }}</span></label>
                        <button v-if="productPendingCount" class="dlp-button dlp-primary" :disabled="!productEditMode" @click="saveAllProductEditsFromButton"><span class="dashicons dashicons-saved"></span>{{ t.savePending }} ({{ formatNumber(productPendingCount) }})</button>
                        <div class="dlp-custom-select dlp-filter-select">
                            <button class="dlp-button" @click.stop="toggleMenu('image-filter')"><span class="dashicons dashicons-format-image"></span>{{ imageFilterLabel }}</button>
                            <span class="dlp-menu" v-if="openMenu === 'image-filter'">
                                <button v-for="option in imageFilterOptions" :key="option.value" @click="setImageFilter(option.value)"><span :class="icon(option.icon)"></span>{{ option.label }}</button>
                            </span>
                        </div>
                        <div class="dlp-custom-select dlp-filter-select">
                            <button class="dlp-button" :disabled="!selectedProductIds.length" @click.stop="toggleMenu('bulk-products')"><span class="dashicons dashicons-list-view"></span>{{ t.bulkActions }} <span v-if="selectedProductIds.length" class="dlp-mono">{{ selectedProductIds.length }}</span></button>
                            <span class="dlp-menu" v-if="openMenu === 'bulk-products'">
                                <button :disabled="!productEditMode" @click="applyBulkAction('publish')"><span class="dashicons dashicons-yes-alt"></span>{{ t.publishSelected }}</button>
                                <button :disabled="!productEditMode" @click="applyBulkAction('draft')"><span class="dashicons dashicons-marker"></span>{{ t.draftSelected }}</button>
                                <button :disabled="!productEditMode" @click="applyBulkAction('instock')"><span class="dashicons dashicons-archive"></span>{{ t.markInStock }}</button>
                                <button :disabled="!productEditMode" @click="applyBulkAction('outofstock')"><span class="dashicons dashicons-warning"></span>{{ t.markOutOfStock }}</button>
                                <button @click="applyBulkAction('export')"><span class="dashicons dashicons-media-spreadsheet"></span>{{ t.exportSelected }}</button>
                            </span>
                        </div>
                        <button class="dlp-button" @click="columnMenuOpen = !columnMenuOpen"><span class="dashicons dashicons-visibility"></span>{{ t.columns }}</button>
                        <button v-if="selectedProduct && !pinnedEditorPinned" class="dlp-button" @click="togglePinnedEditor"><span class="dashicons dashicons-sticky"></span>{{ t.pinEditor }}</button>
                    </div>
                    <div class="dlp-column-panel" v-if="columnMenuOpen">
                        <div class="dlp-column-group">
                            <strong>{{ t.productDetails }}</strong>
                            <label v-for="column in standardProductColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('product', column)"> {{ columnLabel(column) }}</label>
                        </div>
                        <div class="dlp-column-group is-warehouse" v-if="warehouseProductColumns.length">
                            <strong>{{ t.warehouseStock }}</strong>
                            <label v-for="column in warehouseProductColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('product', column)"> {{ columnLabel(column) }}</label>
                        </div>
                        <label class="dlp-column-option"><input type="checkbox" v-model="compactTable"> {{ t.compactTableMode }}</label>
                        <label class="dlp-column-option"><input type="checkbox" v-model="freezeFirstProductColumn"> {{ t.freezeFirstColumn }}</label>
                        <button class="dlp-button" @click="resetColumns('product')">{{ t.resetColumns }}</button>
                    </div>
                    <transition name="dlp-editor">
                    <div class="dlp-detail dlp-pinned-editor" v-if="selectedProduct && pinnedEditorPinned">
                        <div class="dlp-panel">
                            <div class="dlp-panel-head">
                                <strong :class="titleClass(selectedProduct)">{{ selectedProduct.name }}</strong>
                                <span class="dlp-cell-actions">
                                    <a class="dlp-icon-button" :href="selectedProduct.canonical_url || selectedProduct.permalink" target="_blank" rel="noopener" :aria-label="t.view"><span class="dashicons dashicons-visibility"></span></a>
                                    <a class="dlp-icon-button" :href="selectedProduct.edit_url" target="_blank" rel="noopener" :aria-label="t.editWooCommerce"><span class="dashicons dashicons-wordpress"></span></a>
                                    <button class="dlp-icon-button" @click="openProductDialog(selectedProduct)" :aria-label="t.edit"><span class="dashicons dashicons-editor-expand"></span></button>
                                    <button class="dlp-icon-button" @click="openProductToolbox(selectedProduct)" :aria-label="t.openToolbox"><span class="dashicons dashicons-external"></span></button>
                                    <button class="dlp-icon-button dlp-pin-toggle" @click="togglePinnedEditor" :aria-label="t.unpinEditor"><span class="dashicons dashicons-sticky"></span></button>
                                </span>
                            </div>
                            <div class="dlp-field-grid">
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('name'))"></span>{{ t.productTitle }}</span><input class="dlp-input" :class="titleClass(selectedProduct)" :dir="titleDir(selectedProduct)" :value="selectedProduct.name" :readonly="!productEditMode" @input="editProduct(selectedProduct, 'name', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('part_number'))"></span>{{ t.partNumber }}</span><input class="dlp-input dlp-code-input" dir="ltr" :value="selectedProduct.part_number || ''" readonly></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sku'))"></span>{{ t.sku }}</span><input class="dlp-input dlp-code-input" dir="ltr" :value="selectedProduct.sku" :readonly="!productEditMode" @input="editProduct(selectedProduct, 'sku', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('status'))"></span>{{ t.status }}</span><span class="dlp-custom-select"><button class="dlp-select-trigger" :disabled="!productEditMode" @click.prevent.stop="toggleMenu('selected-status')">{{ statusLabel(selectedProduct.status) }}</button><span class="dlp-menu" v-if="openMenu === 'selected-status'"><button v-for="option in productStatusOptions" :key="option.value" @click="editProduct(selectedProduct, 'status', option.value); openMenu = ''"><span class="dlp-status-dot"></span>{{ option.label }}</button></span></span></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('stock_status'))"></span>{{ t.availability }}</span><span class="dlp-custom-select"><button class="dlp-select-trigger" :disabled="!productEditMode" @click.prevent.stop="toggleMenu('selected-stock')">{{ stockStatusLabel(selectedProduct.stock_status) }}</button><span class="dlp-menu" v-if="openMenu === 'selected-stock'"><button v-for="option in stockStatusOptions" :key="option.value" @click="editProduct(selectedProduct, 'stock_status', option.value); openMenu = ''"><span class="dlp-status-dot"></span>{{ option.label }}</button></span></span></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('regular_price'))"></span>{{ t.regularPrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.regular_price)" :readonly="!productEditMode" @input="onCellInput('product', selectedProduct, {field: 'regular_price', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sale_price'))"></span>{{ t.salePrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.sale_price)" :readonly="!productEditMode" @input="onCellInput('product', selectedProduct, {field: 'sale_price', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('stock_quantity'))"></span>{{ t.stock }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="numeric" data-numeric="true" :value="formatInputNumber(selectedProduct.stock_quantity)" :readonly="!productEditMode" @input="onCellInput('product', selectedProduct, {field: 'stock_quantity', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row dlp-field-wide"><span class="dlp-field-label"><span :class="icon(fieldIcon('category_ids'))"></span>{{ t.categories }}</span><select class="dlp-select dlp-multi-select" multiple :disabled="!productEditMode" :value="selectedCategoryIds(selectedProduct)" @change="onCategoryChange(selectedProduct, $event)"><option v-for="category in categoryOptions" :value="category.id">{{ category.name }}</option></select></label>
                            </div>
                        </div>
                        <aside class="dlp-card">
                            <img v-if="selectedProduct.image" class="dlp-detail-image" :src="selectedProduct.image" alt="" loading="lazy" decoding="async">
                            <div v-else class="dlp-detail-image dlp-image-empty"><span class="dashicons dashicons-format-image"></span></div>
                            <p><strong><span :class="icon(fieldIcon('status'))"></span>{{ t.status }}</strong><br><span class="dlp-status-badge" :class="'is-' + statusTone(selectedProduct.status)"><span :class="icon(statusIcon(selectedProduct.status))"></span>{{ statusLabel(selectedProduct.status) }}</span></p>
                            <p><strong><span :class="icon(fieldIcon('stock_status'))"></span>{{ t.availability }}</strong><br><span class="dlp-status-badge" :class="'is-' + statusTone(selectedProduct.stock_status)"><span :class="icon(statusIcon(selectedProduct.stock_status))"></span>{{ stockStatusLabel(selectedProduct.stock_status) }}</span></p>
                            <p><strong><span :class="icon(fieldIcon('category_ids'))"></span>{{ t.categories }}</strong><br>{{ categoryNames(selectedProduct) }}</p>
                            <p><strong><span :class="icon(fieldIcon('total_sales'))"></span>{{ t.totalSales }}</strong><br><span class="dlp-mono">{{ formatNumber(selectedProduct.total_sales || 0) }}</span></p>
                            <p><strong><span :class="icon(fieldIcon('revisions'))"></span>{{ t.revisions }}</strong><br><span class="dlp-mono">{{ selectedProduct.revision_count || 0 }}</span></p>
                        </aside>
                    </div>
                    </transition>
                    <div class="dlp-panel">
                        <div class="dlp-table-wrap">
                            <span class="dlp-resize-guide" v-if="resizeGuide" :style="resizeGuideStyle" aria-hidden="true"></span>
                            <table class="dlp-table dlp-data-grid" data-grid-kind="product" :aria-colcount="visibleProductColumns.length + 2" :class="{'is-compact': compactTable, 'is-view-mode': !productEditMode, 'is-first-column-sticky': freezeFirstProductColumn}">
                                <colgroup>
                                    <col style="width:44px">
                                    <col v-for="column in visibleProductColumns" :key="column.key" :class="'dlp-col-' + column.key" :data-column-priority="column.priority || 1" :style="{width: column.width + 'px'}">
                                    <col class="dlp-col-actions" style="width:140px">
                                </colgroup>
                                <thead>
                                    <tr class="dlp-group-row">
                                        <th scope="col" rowspan="2"><label class="dlp-check"><input type="checkbox" v-model="allProductsSelected" :aria-label="t.selectAll"><span></span></label></th>
                                        <th scope="colgroup" :colspan="visibleStandardProductColumns.length">{{ t.productDetails }}</th>
                                        <th v-if="visibleWarehouseProductColumns.length" class="dlp-warehouse-group-heading" scope="colgroup" :colspan="visibleWarehouseProductColumns.length"><span class="dashicons dashicons-archive"></span>{{ t.warehouseStock }}</th>
                                        <th scope="col" rowspan="2">{{ t.actions }}</th>
                                    </tr>
                                    <tr class="dlp-column-header-row">
                                        <th scope="col" v-for="(column, columnIndex) in visibleProductColumns" :key="column.key" :data-column-key="column.key" :data-column-priority="column.priority || 1" :class="{'is-resizing': resizingColumn === column.key, 'is-sticky-first-data-column': columnIndex === 0, 'is-warehouse-column': column.warehouse}" :draggable="true" tabindex="0" @contextmenu.prevent="openColumnContext('product', column, $event)" @keydown="onColumnHeaderKeydown('product', column, $event)" @dragstart="startColumnDrag(column.key)" @dragover.prevent @drop="dropColumn('product', column.key)" @dblclick.stop="autoResizeColumn('product', column)">
                                            <button class="dlp-th-button" :aria-disabled="!column.sortable" @click="cycleSort('product', column, $event)"><span class="dlp-th-label"><span :class="icon(column.icon || 'dashicons-editor-ul')"></span>{{ columnLabel(column) }}</span><span>{{ sortLabel('product', column) }}</span></button>
                                            <button class="dlp-column-menu-button" @click.stop="openColumnContext('product', column, $event)" :aria-label="t.actions"><span class="dashicons dashicons-ellipsis"></span></button>
                                            <span class="dlp-col-resizer" @mousedown="startColumnResize('product', column, $event)" @dblclick.stop.prevent="autoResizeColumn('product', column)"></span>
                                        </th>
                                    </tr>
                                    <tr class="dlp-filter-row">
                                        <th></th>
                                        <th v-for="(column, columnIndex) in visibleProductColumns" :key="'filter-' + column.key" :data-column-key="column.key" :data-column-priority="column.priority || 1" :class="{'is-resizing': resizingColumn === column.key, 'is-sticky-first-data-column': columnIndex === 0}">
                                            <template v-if="!column.filter"></template>
                                            <template v-else-if="column.filter === 'select'">
                                                <span class="dlp-custom-select dlp-filter-cell-select">
                                                    <button class="dlp-filter-control dlp-filter-trigger" @click.stop="toggleMenu('filter-' + column.key)">{{ customSelectLabel([{value: '', label: t.all}].concat(columnFilterOptions(column)), productFilters[column.key] || '') }}</button>
                                                    <span class="dlp-menu" v-if="openMenu === 'filter-' + column.key">
                                                        <button @click="setProductFilter(column.key, ''); openMenu = ''">{{ t.all }}</button>
                                                        <button v-for="option in columnFilterOptions(column)" :key="option.value" @click="setProductFilter(column.key, option.value); openMenu = ''">{{ option.label }}</button>
                                                    </span>
                                                </span>
                                            </template>
                                            <template v-else-if="column.filter === 'numeric'">
                                                <span class="dlp-range-filter">
                                                    <input class="dlp-filter-control dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :placeholder="t.min" :value="rangeFilterValue(column.key, 'min')" @input="setRangeFilter(column.key, 'min', $event)">
                                                    <input class="dlp-filter-control dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :placeholder="t.max" :value="rangeFilterValue(column.key, 'max')" @input="setRangeFilter(column.key, 'max', $event)">
                                                </span>
                                            </template>
                                            <input v-else class="dlp-filter-control" :value="productFilters[column.key] || ''" @input="setProductFilter(column.key, $event.target.value)" :placeholder="t.filter">
                                        </th>
                                        <th><button class="dlp-icon-button" @click="clearProductFilters" :aria-label="t.clear"><span class="dashicons dashicons-dismiss"></span></button></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="product in filteredProducts" :key="product.id" :data-product-row="product.id" :class="{'is-edited': rowEdited(product), 'is-selected': isProductSelected(product), 'is-active-product': selectedProduct && selectedProduct.id === product.id}" @click="selectProductRow(product)" @dblclick="openProductPanel(product, {reveal: true})" @focusin="selectProductRow(product)" @contextmenu.prevent="openProductRowContext(product, $event)">
                                        <td><label class="dlp-check"><input type="checkbox" v-model="selectedProducts[product.id]" :aria-label="t.selectRow"><span></span></label></td>
                                        <td v-for="(column, columnIndex) in visibleProductColumns" :key="column.key" :class="[cellClass(column), {'is-sticky-first-data-column': columnIndex === 0, 'is-resizing': resizingColumn === column.key, 'is-warehouse-column': column.warehouse}]" :data-column-key="column.key" :data-column-priority="column.priority || 1">
                                            <button v-if="column.type === 'select'" class="dlp-editable-cell dlp-select-cell" :class="{'is-readonly': !column.editable || !productEditMode}" :disabled="!column.editable || !productEditMode" tabindex="0" :data-grid-row="product.id" :data-grid-col="column.key" @click.stop="openSelectCell('product', product, column, $event)" @focus="selectProductRow(product)" @keydown="onGridCellKeydown($event, product, column)">
                                                <span v-if="column.field === 'status' || column.field === 'stock_status'" class="dlp-status-badge" :class="'is-' + statusTone(product[column.field])"><span :class="icon(statusIcon(product[column.field]))"></span>{{ formatColumnValue(product, column) }}</span>
                                                <span v-else class="dlp-select-value">{{ formatColumnValue(product, column) }}</span>
                                            </button>
                                            <span v-else-if="!isCellEditing('product', product, column)" class="dlp-editable-cell" :class="{'is-readonly': !column.editable || !productEditMode}" tabindex="0" :data-grid-row="product.id" :data-grid-col="column.key" @pointerdown.stop="column.editable && productEditMode && startCellEdit('product', product, column, $event)" @focus="selectProductRow(product)" @keydown="onGridCellKeydown($event, product, column)">
                                                <template v-if="column.field === 'name'"><span class="dlp-product-cell"><img v-if="product.image" :src="product.image" alt="" loading="lazy" decoding="async"><span v-else class="dlp-thumb-empty"><span class="dashicons dashicons-format-image"></span></span><span class="dlp-title-cell" :class="titleClass(product)">{{ product.name }}<span v-if="product.part_number" class="dlp-part-number">{{ product.part_number }}</span><span class="dlp-mobile-meta" v-if="responsiveProductColumns.length"><span v-for="meta in responsiveProductColumns" :key="meta.key">{{ columnLabel(meta) }}: {{ formatColumnValue(product, meta) }}</span></span></span></span></template>
                                                <template v-else-if="column.field === 'status' || column.field === 'stock_status'"><span class="dlp-status-badge" :class="'is-' + statusTone(product[column.field])"><span :class="icon(statusIcon(product[column.field]))"></span>{{ formatColumnValue(product, column) }}</span></template>
                                                <template v-else>{{ formatColumnValue(product, column) }}</template>
                                            </span>
                                            <span v-else-if="column.field === 'name'" class="dlp-cell-edit-shell dlp-product-cell">
                                                <img v-if="product.image" :src="product.image" alt="" loading="lazy" decoding="async">
                                                <span v-else class="dlp-thumb-empty"><span class="dashicons dashicons-format-image"></span></span>
                                                <input class="dlp-cell-input dlp-title-edit-input" :class="titleClass(product)" :dir="titleDir(product)" :data-cell-key="'product:' + product.id + ':' + column.field" :value="inputValue(product, column)" @input="onCellInput('product', product, column, $event)" @keydown.enter.prevent="finishCellEdit('product', product)" @keydown.esc.prevent="editingCell = null" @blur="finishCellEdit('product', product)">
                                            </span>
                                            <input v-else class="dlp-cell-input" :class="{'dlp-numeric': column.numeric}" :dir="column.numeric ? 'ltr' : null" :inputmode="column.numeric ? 'decimal' : null" :data-numeric="column.numeric ? 'true' : null" :data-cell-key="'product:' + product.id + ':' + column.field" :value="inputValue(product, column)" @input="onCellInput('product', product, column, $event)" @keydown.enter.prevent="finishCellEdit('product', product)" @keydown.esc.prevent="editingCell = null" @blur="finishCellEdit('product', product)">
                                        </td>
                                        <td>
                                            <span class="dlp-cell-actions">
                                                <button class="dlp-icon-button" @click="viewProduct(product)" :aria-label="t.view"><span class="dashicons dashicons-visibility"></span></button>
                                                <button class="dlp-icon-button" @click="handleProductEditClick(product, $event)" :aria-label="t.edit" :title="t.editShortcutTitle"><span class="dashicons dashicons-edit"></span></button>
                                                <span class="dlp-row-menu-wrap">
                                                    <button class="dlp-icon-button" @click="toggleRowMenu('product', product.id, $event)" :aria-label="t.actions"><span class="dashicons dashicons-ellipsis"></span></button>
                                                </span>
                                                <span v-if="saveStatus('product', product.id)" class="dlp-save-state" :class="'is-' + saveStatus('product', product.id)" role="status" aria-live="polite" :title="saveStatus('product', product.id)">{{ saveStatus('product', product.id) }}</span>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="!filteredProducts.length"><td :colspan="visibleProductColumns.length + 2" class="dlp-empty">{{ loading ? t.loading : t.noRows }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <nav class="dlp-pagination" v-if="productTotalPages > 1" :aria-label="t.pagination">
                        <button class="dlp-button" :disabled="productPage <= 1 || loading" @click="goToProductPage(productPage - 1)"><span class="dashicons" :class="t.dir === 'rtl' ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-left-alt2'"></span>{{ t.previous }}</button>
                        <button v-for="page in productPageNumbers" :key="page" class="dlp-button dlp-page-button" :class="{'is-current': page === productPage}" :aria-current="page === productPage ? 'page' : null" :disabled="loading" @click="goToProductPage(page)">{{ formatNumber(page) }}</button>
                        <button class="dlp-button" :disabled="productPage >= productTotalPages || loading" @click="goToProductPage(productPage + 1)">{{ t.next }}<span class="dashicons" :class="t.dir === 'rtl' ? 'dashicons-arrow-left-alt2' : 'dashicons-arrow-right-alt2'"></span></button>
                    </nav>
                    <div class="dlp-column-context" v-if="columnContext" :style="columnContextStyle">
                        <button v-if="contextColumn() && contextColumn().sortable" @click="contextSort('asc')"><span class="dashicons dashicons-arrow-up-alt"></span>{{ t.sortAsc }}</button>
                        <button v-if="contextColumn() && contextColumn().sortable" @click="contextSort('desc')"><span class="dashicons dashicons-arrow-down-alt"></span>{{ t.sortDesc }}</button>
                        <button @click="hideContextColumn"><span class="dashicons dashicons-hidden"></span>{{ t.hideColumn }}</button>
                        <button v-if="contextColumn() && contextColumn().filter" @click="clearContextFilter"><span class="dashicons dashicons-dismiss"></span>{{ t.clear }}</button>
                    </div>
                    <div class="dlp-column-context dlp-row-context" v-if="rowContext && rowContextProduct()" :style="rowContextStyle">
                        <button @click="viewProduct(rowContextProduct()); closeRowContext()"><span class="dashicons dashicons-visibility"></span>{{ t.view }}</button>
                        <button @click="openProductPanel(rowContextProduct(), {reveal: true}); closeRowContext()"><span class="dashicons dashicons-edit"></span>{{ t.edit }}</button>
                        <button @click="openProductDialog(rowContextProduct()); closeRowContext()"><span class="dashicons dashicons-editor-expand"></span>{{ t.modalEdit }}</button>
                        <button @click="editProductPage(rowContextProduct()); closeRowContext()"><span class="dashicons dashicons-wordpress"></span>{{ t.editWooCommerce }}</button>
                        <button @click="copy(rowContextProduct().sku || rowContextProduct().id); closeRowContext()"><span class="dashicons dashicons-clipboard"></span>{{ t.copy }}</button>
                    </div>
                    <div class="dlp-column-context dlp-cell-dropdown" v-if="selectCell" :style="selectCellStyle">
                        <button v-for="option in selectCellOptions()" :key="option.value" @click="applySelectCellValue(option.value)">
                            <span class="dlp-status-dot" aria-hidden="true"></span>{{ option.label }}
                        </button>
                    </div>
                    <div class="dlp-dialog-backdrop" v-if="productDialogOpen && selectedProduct" @click.self="productDialogOpen = false">
                        <section class="dlp-dialog">
                            <div class="dlp-panel-head">
                                <strong :class="titleClass(selectedProduct)">{{ selectedProduct.name }}</strong>
                                <button class="dlp-icon-button" @click="productDialogOpen = false"><span class="dashicons dashicons-no-alt"></span></button>
                            </div>
                            <div class="dlp-field-grid">
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('name'))"></span>{{ t.productTitle }}</span><input class="dlp-input" :class="titleClass(selectedProduct)" :dir="titleDir(selectedProduct)" :value="selectedProduct.name" :readonly="!productEditMode" @input="editProduct(selectedProduct, 'name', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sku'))"></span>{{ t.sku }}</span><input class="dlp-input dlp-code-input" dir="ltr" :value="selectedProduct.sku" :readonly="!productEditMode" @input="editProduct(selectedProduct, 'sku', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('status'))"></span>{{ t.status }}</span><select class="dlp-select" :disabled="!productEditMode" :value="selectedProduct.status" @change="onCellInput('product', selectedProduct, {field: 'status'}, $event)"><option v-for="option in productStatusOptions" :value="option.value">{{ option.label }}</option></select></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('stock_status'))"></span>{{ t.availability }}</span><select class="dlp-select" :disabled="!productEditMode" :value="selectedProduct.stock_status" @change="onCellInput('product', selectedProduct, {field: 'stock_status'}, $event)"><option v-for="option in stockStatusOptions" :value="option.value">{{ option.label }}</option></select></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('regular_price'))"></span>{{ t.regularPrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.regular_price)" :readonly="!productEditMode" @input="onCellInput('product', selectedProduct, {field: 'regular_price', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sale_price'))"></span>{{ t.salePrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.sale_price)" :readonly="!productEditMode" @input="onCellInput('product', selectedProduct, {field: 'sale_price', numeric: true}, $event)"></label>
                            </div>
                        </section>
                    </div>
                </section>

                <section v-if="currentPage === 'users'">
                    <div class="dlp-toolbar">
                        <input class="dlp-input dlp-search" v-model="userSearch" :placeholder="t.searchUsers">
                        <span class="dlp-pill">{{ formatNumber(filteredUsers.length) }} / {{ formatNumber(users.length) }}</span>
                        <button class="dlp-button dlp-primary" @click="startCreateUser"><span class="dashicons dashicons-plus-alt2"></span>{{ t.createUser }}</button>
                        <button class="dlp-button" :disabled="!selectedUserIds.length" @click="deleteSelectedUsers"><span class="dashicons dashicons-trash"></span>{{ t.deleteSelected }}</button>
                    </div>
                    <div class="dlp-detail dlp-user-editor" v-if="selectedUser">
                        <div class="dlp-panel">
                            <div class="dlp-panel-head">
                                <strong>{{ selectedUser.id ? (selectedUser.display_name || selectedUser.login) : t.createUser }}</strong>
                                <span class="dlp-cell-actions">
                                    <a v-if="selectedUser.id" class="dlp-icon-button" :href="selectedUser.edit_url || ('/wp-admin/user-edit.php?user_id=' + selectedUser.id)" target="_blank" rel="noopener" :aria-label="t.edit"><span class="dashicons dashicons-admin-users"></span></a>
                                    <button class="dlp-icon-button" @click="saveUserDetails" :disabled="saving" :aria-label="t.save"><span class="dashicons dashicons-saved"></span></button>
                                    <button v-if="selectedUser.id" class="dlp-icon-button" @click="deleteSelectedUser" :aria-label="t.delete"><span class="dashicons dashicons-trash"></span></button>
                                    <button class="dlp-icon-button" @click="selectedUser = null"><span class="dashicons dashicons-no-alt"></span></button>
                                </span>
                            </div>
                            <div class="dlp-field-grid">
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-admin-users"></span>{{ t.username }}</span><input class="dlp-input dlp-code-input" dir="ltr" :readonly="!!selectedUser.id" :value="selectedUser.login" @input="editSelectedUser('login', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-email-alt"></span>Email</span><input class="dlp-input dlp-email" dir="ltr" :value="selectedUser.email" @input="editSelectedUser('email', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-id"></span>{{ t.displayName }}</span><input class="dlp-input" :value="selectedUser.display_name" @input="editSelectedUser('display_name', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-shield"></span>{{ t.role }}</span><span class="dlp-custom-select"><button class="dlp-select-trigger" @click.prevent.stop="toggleMenu('selected-user-role')">{{ customSelectLabel(userRoleOptions, selectedUserRole(selectedUser)) }}</button><span class="dlp-menu" v-if="openMenu === 'selected-user-role'"><button v-for="option in userRoleOptions" :key="option.value" @click="editSelectedUser('role', option.value); openMenu = ''">{{ option.label }}</button></span></span></label>
                            </div>
                        </div>
                        <aside class="dlp-card">
                            <div class="dlp-panel-head"><strong>{{ t.purchaseHistory }}</strong></div>
                            <div v-if="userOrderLoading" class="dlp-empty">{{ t.loading }}</div>
                            <div v-else-if="!userOrders.length" class="dlp-empty">{{ t.noRows }}</div>
                            <div v-else class="dlp-order-list">
                                <a v-for="order in userOrders" :key="order.id" :href="order.edit_url" target="_blank" rel="noopener">
                                    <span class="dlp-mono">#{{ order.id }}</span>
                                    <span>{{ order.status }}</span>
                                    <strong>{{ formatMoney(order.total) }}</strong>
                                    <small>{{ formatDateTime(order.date_created) }}</small>
                                </a>
                            </div>
                        </aside>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-table-wrap">
                            <table class="dlp-table dlp-data-grid" data-grid-kind="user" :class="{'is-compact': compactTable}">
                                <colgroup>
                                    <col style="width:44px">
                                    <col v-for="column in visibleUserColumns" :key="column.key" :style="{width: column.width + 'px'}">
                                    <col style="width:74px">
                                </colgroup>
                                <thead><tr><th></th><th v-for="column in visibleUserColumns" :key="column.key" :data-column-key="column.key" :class="{'is-resizing': resizingColumn === column.key}" draggable="true" tabindex="0" @contextmenu.prevent="openColumnContext('user', column, $event)" @keydown="onColumnHeaderKeydown('user', column, $event)" @dragstart="startColumnDrag(column.key)" @dragover.prevent @drop="dropColumn('user', column.key)" @dblclick.stop="autoResizeColumn('user', column)"><button class="dlp-th-button" @click="cycleSort('user', column, $event)"><span class="dlp-th-label">{{ columnLabel(column) }}</span><span>{{ sortLabel('user', column) }}</span></button><button class="dlp-column-menu-button" @click.stop="openColumnContext('user', column, $event)" :aria-label="t.actions"><span class="dashicons dashicons-ellipsis"></span></button><span class="dlp-col-resizer" @mousedown="startColumnResize('user', column, $event)" @dblclick.stop.prevent="autoResizeColumn('user', column)"></span></th><th>{{ t.actions }}</th></tr></thead>
                                <tbody>
                                    <tr v-for="item in filteredUsers" :key="item.id" :class="{'is-edited': userEdited(item), 'is-selected': !!selectedUsers[item.id], 'is-active-product': selectedUser && selectedUser.id === item.id}" @click="openUserPanel(item)">
                                        <td><label class="dlp-check"><input type="checkbox" v-model="selectedUsers[item.id]" :aria-label="t.selectRow"><span></span></label></td>
                                        <td v-for="column in visibleUserColumns" :key="column.key" :class="cellClass(column)" :data-column-key="column.key">
                                            <span v-if="!isCellEditing('user', item, column)" class="dlp-editable-cell" :class="{'is-readonly': !column.editable}" tabindex="0" @pointerdown.stop="column.editable && startCellEdit('user', item, column, $event)" @focus="openUserPanel(item)">{{ column.field === 'roles' ? roleText(item.roles) : formatColumnValue(item, column) }}</span>
                                            <input v-else class="dlp-cell-input" :data-cell-key="'user:' + item.id + ':' + column.field" :value="inputValue(item, column)" @input="onCellInput('user', item, column, $event)" @keydown.enter.prevent="finishCellEdit('user', item)" @keydown.esc.prevent="editingCell = null" @blur="finishCellEdit('user', item)">
                                        </td>
                                        <td>
                                            <span class="dlp-cell-actions">
                                                <button class="dlp-icon-button" @click.stop="openUserDialog(item)" :aria-label="t.edit"><span class="dashicons dashicons-edit"></span></button>
                                                <a class="dlp-icon-button" :href="item.edit_url || ('/wp-admin/user-edit.php?user_id=' + item.id)" target="_blank" rel="noopener" :aria-label="t.edit"><span class="dashicons dashicons-admin-users"></span></a>
                                                <button class="dlp-icon-button" @click="copy(item.email || item.login)" :aria-label="t.copy"><span class="dashicons dashicons-clipboard"></span></button>
                                                <button class="dlp-icon-button" @click.stop="deleteUserRow(item)" :aria-label="t.delete"><span class="dashicons dashicons-trash"></span></button>
                                                <span class="dlp-save-state" :class="'is-' + saveStatus('user', item.id)">{{ saveStatus('user', item.id) }}</span>
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="dlp-dialog-backdrop" v-if="userDialogOpen && selectedUser" @click.self="userDialogOpen = false">
                        <section class="dlp-dialog">
                            <div class="dlp-panel-head"><strong>{{ selectedUser.display_name || selectedUser.login }}</strong><button class="dlp-icon-button" @click="userDialogOpen = false"><span class="dashicons dashicons-no-alt"></span></button></div>
                            <div class="dlp-field-grid">
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-admin-users"></span>{{ t.username }}</span><input class="dlp-input dlp-code-input" dir="ltr" :readonly="!!selectedUser.id" :value="selectedUser.login" @input="editSelectedUser('login', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-email-alt"></span>Email</span><input class="dlp-input dlp-email" dir="ltr" :value="selectedUser.email" @input="editSelectedUser('email', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-id"></span>{{ t.displayName }}</span><input class="dlp-input" :value="selectedUser.display_name" @input="editSelectedUser('display_name', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span class="dashicons dashicons-shield"></span>{{ t.role }}</span><span class="dlp-custom-select"><button class="dlp-select-trigger" @click.prevent.stop="toggleMenu('dialog-user-role')">{{ customSelectLabel(userRoleOptions, selectedUserRole(selectedUser)) }}</button><span class="dlp-menu" v-if="openMenu === 'dialog-user-role'"><button v-for="option in userRoleOptions" :key="option.value" @click="editSelectedUser('role', option.value); openMenu = ''">{{ option.label }}</button></span></span></label>
                            </div>
                            <div class="dlp-dialog-actions">
                                <button class="dlp-button dlp-primary" @click="saveUserDetails"><span class="dashicons dashicons-saved"></span>{{ t.save }}</button>
                                <button v-if="selectedUser.id" class="dlp-button" @click="deleteSelectedUser"><span class="dashicons dashicons-trash"></span>{{ t.delete }}</button>
                            </div>
                        </section>
                    </div>
                </section>

                <section v-if="currentPage === 'reports'" class="dlp-reports-section">
                    <div class="dlp-panel">
                        <div class="dlp-panel-head">
                            <strong>{{ t.reports }}</strong>
                            <button class="dlp-button" :disabled="$data.reportLoading || loading" @click="loadReports(true)"><span class="dashicons dashicons-update" :class="{'dlp-spin': $data.reportLoading || loading}"></span>{{ ($data.reportLoading || loading) ? t.loading : t.refresh }}</button>
                        </div>
                        <div class="dlp-report-grid">
                            <button class="dlp-report-card" v-for="section in migrationSections" :key="section.key" :disabled="!section.route && !reports" @click="openMigrationSection(section)"><span :class="icon(section.icon)"></span><strong>{{ section.title }}</strong><span>{{ section.body }}</span></button>
                        </div>
                    </div>
                    <div class="dlp-panel" v-if="reports">
                        <div class="dlp-panel-head">
                            <strong>{{ t.problemRows }}</strong>
                            <span class="dlp-actions">
                                <span v-if="reports.generated_at" class="dlp-muted">{{ t.generatedAt }}: {{ formatDateTime(reports.generated_at) }}</span>
                                <span class="dlp-pill">{{ formatNumber(reports.counts && reports.counts.patris_products) }} Patris/API</span>
                            </span>
                        </div>
                        <div class="dlp-report-summary">
                            <span><strong>{{ formatNumber(reports.counts && reports.counts.woocommerce_products) }}</strong>{{ t.products }}</span>
                            <span><strong>{{ formatNumber(reports.counts && reports.counts.patris_products) }}</strong>{{ t.patrisProducts }}</span>
                            <span v-if="reports.counts && reports.counts.matched_products !== undefined"><strong>{{ formatNumber(reports.counts.matched_products) }}</strong>{{ t.exactCodeMatches }}</span>
                            <span v-if="reports.counts && reports.counts.drift_products !== undefined"><strong>{{ formatNumber(reports.counts.drift_products) }}</strong>{{ t.driftProducts }}</span>
                            <span v-if="reports.counts && reports.counts.positive_source_only_products !== undefined"><strong>{{ formatNumber(reports.counts.positive_source_only_products) }}</strong>{{ t.positiveSourceOnly }}</span>
                            <span v-if="reports.counts && reports.counts.patris_customers !== undefined"><strong>{{ formatNumber(reports.counts.patris_customers) }}</strong>{{ t.customerReports }}</span>
                        </div>
                            <div v-if="error" class="dlp-error dlp-report-error" role="alert"><span>{{ error }}</span><button class="dlp-button" :disabled="loading" @click="loadReports(reports.pagination ? reports.pagination.page : 1)"><span class="dashicons dashicons-update" :class="{'dlp-spin': loading}"></span>{{ t.retry }}</button></div>
                            <div v-if="reports.status !== 'current' && reports.status !== 'static'" class="dlp-empty">{{ t.reportSourceUnavailable }}</div>
                            <div class="dlp-actions">
                                <button class="dlp-button" :class="{'is-primary': reportView === 'warnings'}" :disabled="loading" @click="setReportView('warnings')">{{ t.warnings }}</button>
                                <button class="dlp-button" :class="{'is-primary': reportView === 'price_list'}" :disabled="loading" @click="setReportView('price_list')">{{ t.priceList }}</button>
                            </div>
                            <div v-if="reportView === 'warnings'" class="dlp-actions">
                                <button class="dlp-button" :class="{'is-primary': !reportCategory}" :disabled="loading" @click="setReportCategory('')">{{ t.allWarnings }}</button>
                                <button v-for="category in reportCategories" :key="category.key" class="dlp-button" :class="{'is-primary': reportCategory === category.key}" :disabled="loading" @click="setReportCategory(category.key)">{{ reportCategoryTitle(category) }} ({{ formatNumber(category.count) }})</button>
                            </div>
                            <div v-if="!reports.rows.length" class="dlp-empty">{{ t.noRows }}</div>
                            <div v-else class="dlp-table-wrap">
                                <table class="dlp-table dlp-report-table" :aria-label="reportView === 'price_list' ? t.priceList : t.problemRows">
                                    <thead><tr><th scope="col">{{ t.sku }}</th><th scope="col">{{ t.reportState }}</th><th scope="col">{{ t.products }}</th><th scope="col">Patris/API</th><th scope="col">{{ t.stock }}</th><th scope="col">{{ t.foreignPrice }}</th><th scope="col">{{ t.weight }}</th><th scope="col">{{ t.finalPrice }}</th><th scope="col">{{ t.findings }}</th><th scope="col">{{ t.actions }}</th></tr></thead>
                                    <tbody>
                                        <tr v-for="(item, itemIndex) in reports.rows" :key="item.status + ':' + (item.woo_id || item.product_code) + ':' + itemIndex">
                                            <td class="dlp-mono"><bdi dir="auto">{{ item.product_code }}</bdi></td>
                                            <td>{{ reportStateLabel(item.status) }}</td>
                                            <td><bdi dir="auto">{{ reportWooValue(item.woocommerce, 'name') }}</bdi></td>
                                            <td><bdi dir="auto">{{ reportSparseValue(item.source, 'name') }}</bdi></td>
                                            <td class="dlp-cell-numeric"><bdi dir="auto">{{ reportSparseValue(item.source, 'total_stock') }} / {{ reportWooValue(item.woocommerce, 'stock_quantity') }}</bdi></td>
                                            <td class="dlp-cell-numeric"><bdi dir="auto">{{ reportSparseValue(item.source, 'foreign_price') }}</bdi></td>
                                            <td class="dlp-cell-numeric"><bdi dir="auto">{{ reportSparseValue(item.source, 'weight_grams') }}</bdi></td>
                                            <td class="dlp-cell-numeric"><bdi dir="auto">{{ reportSparseValue(item.source, 'final_price') }} / {{ reportWooValue(item.woocommerce, 'active_price') }}</bdi></td>
                                            <td><span v-if="!item.issues || !item.issues.length">{{ t.current }}</span><span v-for="issue in item.issues" :key="issue" class="dlp-pill">{{ reportIssueTitle(issue) }}</span></td>
                                            <td><a v-if="item.edit_url" class="dlp-icon-button" :href="item.edit_url" target="_blank" rel="noopener" :aria-label="t.edit"><span class="dashicons dashicons-edit"></span></a></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <nav v-if="reports.pagination && reports.pagination.pages > 1" class="dlp-pagination dlp-report-pagination" :aria-label="t.pagination">
                                <button class="dlp-button" :disabled="reports.pagination.page <= 1 || loading" @click="loadReports(reports.pagination.page - 1)"><span :class="icon(t.dir === 'rtl' ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-left-alt2')"></span>{{ t.previous }}</button>
                                <span>{{ t.page }} {{ formatNumber(reports.pagination.page) }} / {{ formatNumber(reports.pagination.pages) }}</span>
                                <button class="dlp-button" :disabled="reports.pagination.page >= reports.pagination.pages || loading" @click="loadReports(reports.pagination.page + 1)">{{ t.next }}<span :class="icon(t.dir === 'rtl' ? 'dashicons-arrow-left-alt2' : 'dashicons-arrow-right-alt2')"></span></button>
                            </nav>
                    </div>
                    <div v-else class="dlp-panel"><div class="dlp-empty">{{ loading ? t.loading : (error || t.noRows) }}</div></div>
                </section>
                <section v-if="currentPage === 'cli'" class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.commandUsage }}</strong></div><div class="dlp-field-grid"><div class="dlp-field" v-for="(command, key) in commands" :key="key"><span>{{ key }}</span><code class="dlp-code">{{ command }}</code><button class="dlp-button" @click="copy(command)"><span class="dashicons dashicons-clipboard"></span>{{ t.copy }}</button></div></div></section>
                <section v-if="currentPage === 'sync'" class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.patrisSync }}</strong></div><div class="dlp-field-grid"><div class="dlp-field"><span>Repository</span><code class="dlp-code">{{ patris.project }}</code></div><div class="dlp-field"><span>Mode</span><code class="dlp-code">{{ patris.mode }}</code></div><div class="dlp-field"><span>Suggested watcher</span><code class="dlp-code">{{ patris.suggested_bridge }}</code></div></div></section>
                <section v-if="currentPage === 'settings'" class="dlp-settings">
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.interfaceSettings }}</strong></div>
                        <div class="dlp-field-grid">
                            <label class="dlp-field"><span>{{ t.language }}</span><select class="dlp-select" v-model="lang"><option value="fa">فارسی</option><option value="en">English</option></select></label>
                            <div class="dlp-field"><span>{{ t.themeAppearance }}</span><div class="dlp-theme-picker is-field"><button class="dlp-button dlp-theme-trigger" @click="toggleMenu('settings-theme')"><span :class="icon(activeThemeOption.icon)"></span>{{ activeThemeOption.label }}</button><span class="dlp-menu dlp-theme-menu" v-if="openMenu === 'settings-theme'"><button v-for="option in themeOptions" :key="option.value" @click="setThemeChoice(option)"><span :class="icon(option.icon)"></span><span>{{ option.label }}</span></button></span></div></div>
                            <label class="dlp-field"><span>{{ t.transport }}</span><input class="dlp-input" :value="transport" readonly></label>
                        </div>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.currency }}</strong><button class="dlp-button dlp-primary" :disabled="saving" @click="saveCurrency"><span class="dashicons dashicons-saved"></span>{{ t.save }}</button></div>
                        <div class="dlp-field-grid">
                            <label class="dlp-field"><span>USD</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(currencyDraft.dollar_price)" @input="onCurrencyInput('dollar_price', $event)"></label>
                            <label class="dlp-field"><span>CNY</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(currencyDraft.yuan_price)" @input="onCurrencyInput('yuan_price', $event)"></label>
                        </div>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.tableSettings }}</strong></div>
                        <div class="dlp-field-grid">
                            <div class="dlp-field"><span>{{ t.productTable }}</span><div class="dlp-column-group"><strong>{{ t.productDetails }}</strong><label v-for="column in standardProductColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('product', column)"> {{ columnLabel(column) }}</label></div><div class="dlp-column-group is-warehouse" v-if="warehouseProductColumns.length"><strong>{{ t.warehouseStock }}</strong><label v-for="column in warehouseProductColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('product', column)"> {{ columnLabel(column) }}</label></div><button class="dlp-button" @click="resetColumns('product')">{{ t.resetColumns }}</button></div>
                            <div class="dlp-field"><span>{{ t.userTable }}</span><label v-for="column in userColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('user', column)"> {{ column.labelKey ? t[column.labelKey] : column.label }}</label><button class="dlp-button" @click="resetColumns('user')">{{ t.resetColumns }}</button></div>
                        </div>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.bridgeSettings }}</strong></div>
                        <div class="dlp-field-grid">
                            <div class="dlp-field"><span>Panel URL</span><code class="dlp-code">{{ settings && settings.urls && settings.urls.panel }}</code></div>
                            <div class="dlp-field"><span>REST</span><code class="dlp-code">{{ settings && settings.urls && settings.urls.rest }}</code></div>
                            <div class="dlp-field"><span>Bridge REST</span><code class="dlp-code">{{ settings && settings.urls && settings.urls.bridge_rest }}</code></div>
                            <div class="dlp-field"><span>WebSocket</span><code class="dlp-code">{{ settings && settings.websocket && settings.websocket.url }}</code></div>
                            <div class="dlp-field"><span>WordPress bootstrap</span><code class="dlp-code">{{ settings && settings.bridge && settings.bridge.wordpress_bootstrap }}</code></div>
                            <div class="dlp-field"><span>Laravel bootstrap</span><code class="dlp-code">{{ settings && settings.bridge && (settings.bridge.laravel_bootstrap ? 'ready' : 'pending app/bootstrap') }}</code></div>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </script>
    <?php wp_print_scripts(array('vue', 'digitalogic-panel')); ?>
    <script>
        window.setTimeout(function() {
            var root = document.getElementById('digitalogic-panel');
            var fallback = document.getElementById('digitalogic-panel-fallback');
            if (!root || root.querySelector('.dlp-layout') || !fallback || !fallback.hidden) return;
            root.hidden = true;
            fallback.hidden = false;
            var detail = fallback.querySelector('[data-dlp-fatal-detail]');
            if (detail) detail.textContent = 'PANEL-BOOT-TIMEOUT';
            if (window.console && typeof window.console.groupCollapsed === 'function') {
                window.console.groupCollapsed('%c DIGITALOGIC %c Panel bootstrap timeout', 'background:#dc2626;color:#fff;border-radius:5px;padding:3px 7px;font-weight:700', 'color:#dc2626;font-weight:700');
                window.console.error('The panel application did not mount within four seconds.');
                window.console.groupEnd();
            }
            fallback.focus();
        }, 4000);
    </script>
</body>
</html>
