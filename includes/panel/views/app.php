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
?><!doctype html>
<html <?php language_attributes(); ?> dir="<?php echo esc_attr($dir); ?>">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(sprintf(__('Digitalogic Panel - %s', 'digitalogic'), get_bloginfo('name'))); ?></title>
    <?php
    $panel_styles = array('digitalogic-panel');
    if (wp_style_is('digitalogic-desktop-app', 'enqueued')) {
        $panel_styles[] = 'digitalogic-desktop-app';
    }
    wp_print_styles($panel_styles);
    ?>
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
    <script type="text/x-template" id="digitalogic-panel-template">
        <div class="dlp-layout" :class="{'is-sidebar-collapsed': sidebarCollapsed}" v-cloak>
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
                    <button class="dlp-sidebar-toggle" @click="toggleSidebar" :aria-label="sidebarCollapsed ? t.expandSidebar : t.collapseSidebar">
                        <span class="dashicons" :class="sidebarCollapsed ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-left-alt2'"></span>
                    </button>
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
                        <article v-for="card in dashboardCards" :key="card.key" class="dlp-card" draggable="true" :class="{'is-dragging': draggedCard === card.key}" @dragstart="startDrag(card.key)" @dragend="endDrag" @dragover.prevent @drop="dropCard(card.key)">
                            <div class="dlp-card-head">
                                <span class="dlp-card-label"><span :class="icon(card.icon)"></span> {{ card.label }}</span>
                                    <span class="dlp-card-tools">
                                        <span class="dlp-drag-handle" aria-hidden="true"></span>
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

                <section v-if="currentPage === 'products'">
                    <div class="dlp-toolbar">
                        <input class="dlp-input dlp-search" v-model="search" :placeholder="t.search" autofocus>
                        <span class="dlp-pill">{{ formatNumber(filteredProducts.length) }} / {{ formatNumber(products.length) }}</span>
                        <div class="dlp-custom-select dlp-filter-select">
                            <button class="dlp-button" @click.stop="toggleMenu('image-filter')"><span class="dashicons dashicons-format-image"></span>{{ imageFilterLabel }}</button>
                            <span class="dlp-menu" v-if="openMenu === 'image-filter'">
                                <button v-for="option in imageFilterOptions" :key="option.value" @click="setImageFilter(option.value)"><span :class="icon(option.icon)"></span>{{ option.label }}</button>
                            </span>
                        </div>
                        <div class="dlp-custom-select dlp-filter-select">
                            <button class="dlp-button" :disabled="!selectedProductIds.length" @click.stop="toggleMenu('bulk-products')"><span class="dashicons dashicons-list-view"></span>{{ t.bulkActions }} <span v-if="selectedProductIds.length" class="dlp-mono">{{ selectedProductIds.length }}</span></button>
                            <span class="dlp-menu" v-if="openMenu === 'bulk-products'">
                                <button @click="applyBulkAction('publish')"><span class="dashicons dashicons-yes-alt"></span>{{ t.publishSelected }}</button>
                                <button @click="applyBulkAction('draft')"><span class="dashicons dashicons-marker"></span>{{ t.draftSelected }}</button>
                                <button @click="applyBulkAction('instock')"><span class="dashicons dashicons-archive"></span>{{ t.markInStock }}</button>
                                <button @click="applyBulkAction('outofstock')"><span class="dashicons dashicons-warning"></span>{{ t.markOutOfStock }}</button>
                                <button @click="applyBulkAction('export')"><span class="dashicons dashicons-media-spreadsheet"></span>{{ t.exportSelected }}</button>
                            </span>
                        </div>
                        <button class="dlp-button" @click="columnMenuOpen = !columnMenuOpen"><span class="dashicons dashicons-visibility"></span>{{ t.columns }}</button>
                        <button v-if="selectedProduct && !pinnedEditorPinned" class="dlp-button" @click="togglePinnedEditor"><span class="dashicons dashicons-editor-contract"></span>{{ t.pinEditor }}</button>
                    </div>
                    <div class="dlp-column-panel" v-if="columnMenuOpen">
                        <label v-for="column in productColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('product', column)"> {{ column.labelKey ? t[column.labelKey] : column.label }}</label>
                        <button class="dlp-button" @click="resetColumns('product')">{{ t.resetColumns }}</button>
                    </div>
                    <div class="dlp-detail dlp-pinned-editor" v-if="selectedProduct && pinnedEditorPinned">
                        <div class="dlp-panel">
                            <div class="dlp-panel-head">
                                <strong :class="titleClass(selectedProduct)">{{ selectedProduct.name }}</strong>
                                <span class="dlp-cell-actions">
                                    <a class="dlp-icon-button" :href="selectedProduct.canonical_url || selectedProduct.permalink" target="_blank" rel="noopener" :aria-label="t.view"><span class="dashicons dashicons-visibility"></span></a>
                                    <a class="dlp-icon-button" :href="selectedProduct.edit_url" target="_blank" rel="noopener" :aria-label="t.editWooCommerce"><span class="dashicons dashicons-wordpress"></span></a>
                                    <button class="dlp-icon-button" @click="openProductDialog(selectedProduct)" :aria-label="t.edit"><span class="dashicons dashicons-editor-expand"></span></button>
                                    <button class="dlp-icon-button" @click="openProductToolbox(selectedProduct)" :aria-label="t.openToolbox"><span class="dashicons dashicons-external"></span></button>
                                    <button class="dlp-icon-button" @click="togglePinnedEditor" :aria-label="t.unpinEditor"><span class="dashicons dashicons-editor-contract"></span></button>
                                </span>
                            </div>
                            <div class="dlp-field-grid">
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('name'))"></span>{{ t.productTitle }}</span><input class="dlp-input" :class="titleClass(selectedProduct)" :value="selectedProduct.name" @input="editProduct(selectedProduct, 'name', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('part_number'))"></span>{{ t.partNumber }}</span><input class="dlp-input dlp-code-input" dir="ltr" :value="selectedProduct.part_number || ''" readonly></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sku'))"></span>{{ t.sku }}</span><input class="dlp-input dlp-code-input" dir="ltr" :value="selectedProduct.sku" @input="editProduct(selectedProduct, 'sku', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('status'))"></span>{{ t.status }}</span><span class="dlp-custom-select"><button class="dlp-select-trigger" @click.prevent.stop="toggleMenu('selected-status')">{{ statusLabel(selectedProduct.status) }}</button><span class="dlp-menu" v-if="openMenu === 'selected-status'"><button v-for="option in productStatusOptions" :key="option.value" @click="editProduct(selectedProduct, 'status', option.value); openMenu = ''"><span class="dlp-status-dot"></span>{{ option.label }}</button></span></span></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('stock_status'))"></span>{{ t.availability }}</span><span class="dlp-custom-select"><button class="dlp-select-trigger" @click.prevent.stop="toggleMenu('selected-stock')">{{ stockStatusLabel(selectedProduct.stock_status) }}</button><span class="dlp-menu" v-if="openMenu === 'selected-stock'"><button v-for="option in stockStatusOptions" :key="option.value" @click="editProduct(selectedProduct, 'stock_status', option.value); openMenu = ''"><span class="dlp-status-dot"></span>{{ option.label }}</button></span></span></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('regular_price'))"></span>{{ t.regularPrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.regular_price)" @input="onCellInput('product', selectedProduct, {field: 'regular_price', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sale_price'))"></span>{{ t.salePrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.sale_price)" @input="onCellInput('product', selectedProduct, {field: 'sale_price', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('stock_quantity'))"></span>{{ t.stock }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="numeric" data-numeric="true" :value="formatInputNumber(selectedProduct.stock_quantity)" @input="onCellInput('product', selectedProduct, {field: 'stock_quantity', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row dlp-field-wide"><span class="dlp-field-label"><span :class="icon(fieldIcon('category_ids'))"></span>{{ t.categories }}</span><select class="dlp-select dlp-multi-select" multiple :value="selectedCategoryIds(selectedProduct)" @change="onCategoryChange(selectedProduct, $event)"><option v-for="category in categoryOptions" :value="category.id">{{ category.name }}</option></select></label>
                            </div>
                        </div>
                        <aside class="dlp-card">
                            <button class="dlp-detail-image dlp-image-drop" :class="{'dlp-image-empty': !selectedProduct.image, 'is-uploading': imageUploading === selectedProduct.id}" @click="chooseProductImage(selectedProduct)" @dragover.prevent @drop.prevent="dropProductImage(selectedProduct, $event)" :aria-label="t.setProductImage">
                                <img v-if="selectedProduct.image" :src="selectedProduct.image" alt="" loading="lazy" decoding="async">
                                <span v-else class="dashicons dashicons-format-image"></span>
                            </button>
                            <p><strong><span :class="icon(fieldIcon('status'))"></span>{{ t.status }}</strong><br><span class="dlp-status-badge" :class="'is-' + statusTone(selectedProduct.status)"><span :class="icon(statusIcon(selectedProduct.status))"></span>{{ statusLabel(selectedProduct.status) }}</span></p>
                            <p><strong><span :class="icon(fieldIcon('stock_status'))"></span>{{ t.availability }}</strong><br><span class="dlp-status-badge" :class="'is-' + statusTone(selectedProduct.stock_status)"><span :class="icon(statusIcon(selectedProduct.stock_status))"></span>{{ stockStatusLabel(selectedProduct.stock_status) }}</span></p>
                            <p><strong><span :class="icon(fieldIcon('category_ids'))"></span>{{ t.categories }}</strong><br>{{ categoryNames(selectedProduct) }}</p>
                            <p><strong><span :class="icon(fieldIcon('total_sales'))"></span>{{ t.totalSales }}</strong><br><span class="dlp-mono">{{ formatNumber(selectedProduct.total_sales || 0) }}</span></p>
                            <p><strong><span :class="icon(fieldIcon('revisions'))"></span>{{ t.revisions }}</strong><br><span class="dlp-mono">{{ selectedProduct.revision_count || 0 }}</span></p>
                        </aside>
                    </div>
                    <input ref="productImageInput" class="dlp-hidden-file" type="file" accept="image/*" @change="onProductImagePicked">
                    <div class="dlp-panel">
                        <div class="dlp-table-wrap">
                            <table class="dlp-table dlp-data-grid">
                                <colgroup>
                                    <col style="width:44px">
                                    <col v-for="column in visibleProductColumns" :key="column.key" :class="'dlp-col-' + column.key" :style="{width: column.width + 'px'}">
                                    <col style="width:112px">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th><label class="dlp-check"><input type="checkbox" v-model="allProductsSelected" :aria-label="t.selectAll"><span></span></label></th>
                                        <th v-for="column in visibleProductColumns" :key="column.key" :data-column-key="column.key" :class="{'is-resizing': resizingColumn === column.key}" draggable="true" @contextmenu.prevent="openColumnContext('product', column, $event)" @dragstart="startColumnDrag(column.key)" @dragover.prevent @drop="dropColumn('product', column.key)">
                                            <button class="dlp-th-button" @click="cycleSort('product', column, $event)"><span class="dlp-th-label"><span :class="icon(column.icon || 'dashicons-editor-ul')"></span>{{ column.labelKey ? t[column.labelKey] : column.label }}</span><span>{{ sortLabel('product', column) }}</span></button>
                                            <span class="dlp-col-resizer" @mousedown="startColumnResize('product', column, $event)"></span>
                                        </th>
                                        <th>{{ t.actions }}</th>
                                    </tr>
                                    <tr class="dlp-filter-row">
                                        <th></th>
                                        <th v-for="column in visibleProductColumns" :key="'filter-' + column.key" :data-column-key="column.key" :class="{'is-resizing': resizingColumn === column.key}">
                                            <template v-if="column.filter === 'select'">
                                                <span class="dlp-custom-select dlp-filter-cell-select">
                                                    <button class="dlp-filter-control dlp-filter-trigger" @click.stop="toggleMenu('filter-' + column.key)">{{ customSelectLabel([{value: '', label: t.all}].concat(columnOptions(column)), productFilters[column.key] || '') }}</button>
                                                    <span class="dlp-menu" v-if="openMenu === 'filter-' + column.key">
                                                        <button @click="setProductFilter(column.key, ''); openMenu = ''">{{ t.all }}</button>
                                                        <button v-for="option in columnOptions(column)" :key="option.value" @click="setProductFilter(column.key, option.value); openMenu = ''">{{ option.label }}</button>
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
                                    <tr v-for="product in filteredProducts" :key="product.id" :class="{'is-edited': rowEdited(product), 'is-selected': isProductSelected(product), 'is-active-product': selectedProduct && selectedProduct.id === product.id}" @click="selectProductRow(product)" @focusin="selectProductRow(product)" @contextmenu.prevent="openProductRowContext(product, $event)">
                                        <td><label class="dlp-check"><input type="checkbox" v-model="selectedProducts[product.id]" :aria-label="t.selectRow"><span></span></label></td>
                                        <td v-for="column in visibleProductColumns" :key="column.key" :class="cellClass(column)" :data-column-key="column.key">
                                            <button v-if="column.type === 'select'" class="dlp-editable-cell dlp-select-cell" tabindex="0" :data-grid-kind="'product'" :data-grid-row="product.id" :data-grid-col="column.key" @click.stop="openSelectCell('product', product, column, $event)" @focus="selectProductRow(product)" @keydown="onGridCellKeydown($event, 'product', product, column)">
                                                <span class="dlp-status-badge" :class="'is-' + statusTone(product[column.field])"><span :class="icon(statusIcon(product[column.field]))"></span>{{ formatColumnValue(product, column) }}</span>
                                            </button>
                                            <span v-else-if="!isCellEditing('product', product, column)" class="dlp-editable-cell" tabindex="0" :data-grid-kind="'product'" :data-grid-row="product.id" :data-grid-col="column.key" @click.stop="startCellEdit('product', product, column)" @focus="selectProductRow(product)" @keydown="onGridCellKeydown($event, 'product', product, column)">
                                                <template v-if="column.field === 'name'"><span class="dlp-product-cell"><button class="dlp-thumb-button" @click.stop="chooseProductImage(product)" @dragover.prevent @drop.prevent="dropProductImage(product, $event)" :aria-label="t.setProductImage"><img v-if="product.image" :src="product.image" alt="" loading="lazy" decoding="async"><span v-else class="dlp-thumb-empty"><span class="dashicons dashicons-format-image"></span></span></button><span class="dlp-title-cell" :class="titleClass(product)">{{ product.name }}<span v-if="product.part_number" class="dlp-part-number">{{ product.part_number }}</span><span class="dlp-mobile-meta" v-if="responsiveProductColumns.length"><span v-for="meta in responsiveProductColumns" :key="meta.key">{{ meta.labelKey ? t[meta.labelKey] : meta.label }}: {{ formatColumnValue(product, meta) }}</span></span></span></span></template>
                                                <template v-else-if="column.field === 'status' || column.field === 'stock_status'"><span class="dlp-status-badge" :class="'is-' + statusTone(product[column.field])"><span :class="icon(statusIcon(product[column.field]))"></span>{{ formatColumnValue(product, column) }}</span></template>
                                                <template v-else>{{ formatColumnValue(product, column) }}</template>
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
                                                <span class="dlp-save-state" :class="'is-' + saveStatus('product', product.id)">{{ saveStatus('product', product.id) }}</span>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="!filteredProducts.length"><td :colspan="visibleProductColumns.length + 2" class="dlp-empty">{{ loading ? t.loading : t.noRows }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="dlp-column-context" v-if="columnContext" :style="columnContextStyle">
                        <button @click="contextSort('asc')"><span class="dashicons dashicons-arrow-up-alt"></span>{{ t.sortAsc }}</button>
                        <button @click="contextSort('desc')"><span class="dashicons dashicons-arrow-down-alt"></span>{{ t.sortDesc }}</button>
                        <button @click="hideContextColumn"><span class="dashicons dashicons-hidden"></span>{{ t.hideColumn }}</button>
                        <button @click="clearContextFilter"><span class="dashicons dashicons-dismiss"></span>{{ t.clear }}</button>
                    </div>
                    <div class="dlp-column-context dlp-row-context" v-if="rowContext && rowContextProduct()" :style="rowContextStyle">
                        <button @click="viewProduct(rowContextProduct()); closeRowContext()"><span class="dashicons dashicons-visibility"></span>{{ t.view }}</button>
                        <button @click="openProductPanel(rowContextProduct()); closeRowContext()"><span class="dashicons dashicons-edit"></span>{{ t.edit }}</button>
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
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('name'))"></span>{{ t.productTitle }}</span><input class="dlp-input" :class="titleClass(selectedProduct)" :value="selectedProduct.name" @input="editProduct(selectedProduct, 'name', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sku'))"></span>{{ t.sku }}</span><input class="dlp-input dlp-code-input" dir="ltr" :value="selectedProduct.sku" @input="editProduct(selectedProduct, 'sku', $event.target.value)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('status'))"></span>{{ t.status }}</span><select class="dlp-select" :value="selectedProduct.status" @change="onCellInput('product', selectedProduct, {field: 'status'}, $event)"><option v-for="option in productStatusOptions" :value="option.value">{{ option.label }}</option></select></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('stock_status'))"></span>{{ t.availability }}</span><select class="dlp-select" :value="selectedProduct.stock_status" @change="onCellInput('product', selectedProduct, {field: 'stock_status'}, $event)"><option v-for="option in stockStatusOptions" :value="option.value">{{ option.label }}</option></select></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('regular_price'))"></span>{{ t.regularPrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.regular_price)" @input="onCellInput('product', selectedProduct, {field: 'regular_price', numeric: true}, $event)"></label>
                                <label class="dlp-field dlp-field-row"><span class="dlp-field-label"><span :class="icon(fieldIcon('sale_price'))"></span>{{ t.salePrice }}</span><input class="dlp-input dlp-numeric" dir="ltr" inputmode="decimal" data-numeric="true" :value="formatInputNumber(selectedProduct.sale_price)" @input="onCellInput('product', selectedProduct, {field: 'sale_price', numeric: true}, $event)"></label>
                            </div>
                        </section>
                    </div>
                </section>

                <section v-if="currentPage === 'users'">
                    <div class="dlp-toolbar">
                        <input class="dlp-input dlp-search dlp-user-search" v-model="userSearch" :placeholder="t.searchUsers">
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
                            <table class="dlp-table dlp-data-grid">
                                <colgroup>
                                    <col style="width:44px">
                                    <col v-for="column in visibleUserColumns" :key="column.key" :style="{width: column.width + 'px'}">
                                    <col style="width:74px">
                                </colgroup>
                                <thead><tr><th></th><th v-for="column in visibleUserColumns" :key="column.key" draggable="true" @dragstart="startColumnDrag(column.key)" @dragover.prevent @drop="dropColumn('user', column.key)"><button class="dlp-th-button" @click="cycleSort('user', column, $event)">{{ column.labelKey ? t[column.labelKey] : column.label }} <span>{{ sortLabel('user', column) }}</span></button><span class="dlp-col-resizer" @mousedown="startColumnResize('user', column, $event)"></span></th><th>{{ t.actions }}</th></tr></thead>
                                <tbody>
                                    <tr v-for="item in filteredUsers" :key="item.id" :class="{'is-edited': userEdited(item), 'is-selected': !!selectedUsers[item.id], 'is-active-product': selectedUser && selectedUser.id === item.id}" @click="openUserPanel(item)">
                                        <td><label class="dlp-check"><input type="checkbox" v-model="selectedUsers[item.id]" :aria-label="t.selectRow"><span></span></label></td>
                                        <td v-for="column in visibleUserColumns" :key="column.key" :class="cellClass(column)">
                                            <span v-if="!isCellEditing('user', item, column)" class="dlp-editable-cell" tabindex="0" :data-grid-kind="'user'" :data-grid-row="item.id" :data-grid-col="column.key" @click.stop="startCellEdit('user', item, column)" @focus="openUserPanel(item)" @keydown="onGridCellKeydown($event, 'user', item, column)">{{ column.field === 'roles' ? roleText(item.roles) : formatColumnValue(item, column) }}</span>
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

                <section v-if="currentPage === 'reports'" class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.reports }}</strong></div><div class="dlp-report-grid"><button class="dlp-report-card" v-for="section in migrationSections" :key="section.key" @click="navigate(section.route)"><span :class="icon(section.icon)"></span><strong>{{ section.title }}</strong><span>{{ section.body }}</span></button></div></section>
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
                            <div class="dlp-field"><span>{{ t.productTable }}</span><label v-for="column in productColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('product', column)"> {{ column.labelKey ? t[column.labelKey] : column.label }}</label><button class="dlp-button" @click="resetColumns('product')">{{ t.resetColumns }}</button></div>
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
    <?php
    $panel_scripts = array('vue', 'digitalogic-panel');
    if (wp_script_is('digitalogic-desktop-app', 'enqueued')) {
        $panel_scripts[] = 'digitalogic-desktop-app';
    }
    wp_print_scripts($panel_scripts);
    ?>
</body>
</html>
