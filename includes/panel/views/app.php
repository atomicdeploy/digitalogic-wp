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
    <script type="text/x-template" id="digitalogic-panel-template">
        <div class="dlp-layout" v-cloak>
            <aside class="dlp-sidebar">
                <div class="dlp-brand">
                    <div class="dlp-logo-mark">
                        <img v-if="configTheme && configTheme.logo_url" :src="configTheme.logo_url" :alt="(configTheme && configTheme.site_name) || 'Digitalogic'">
                        <span v-else>D</span>
                    </div>
                    <div class="dlp-brand-copy">
                        <div class="dlp-brand-title">Digitalogic</div>
                        <div class="dlp-brand-subtitle">{{ (configTheme && configTheme.site_name) || 'Panel' }}</div>
                    </div>
                </div>
                <div class="dlp-user">
                    <div class="dlp-avatar"><span class="dashicons dashicons-admin-users"></span></div>
                    <div>
                        <strong>{{ user.display_name || user.login }}</strong>
                        <div class="dlp-muted">{{ user.login }} &middot; {{ user.email }}</div>
                    </div>
                </div>
                <nav class="dlp-nav">
                    <button :class="{'is-active': currentPage === 'dashboard'}" @click="navigate('/')"><span class="dlp-nav-label"><span class="dashicons dashicons-dashboard"></span>{{ t.dashboard }}</span></button>
                    <button :class="{'is-active': currentPage === 'products'}" @click="navigate('/products')"><span class="dlp-nav-label"><span class="dashicons dashicons-products"></span>{{ t.products }}</span></button>
                    <button :class="{'is-active': currentPage === 'users'}" @click="navigate('/users')"><span class="dlp-nav-label"><span class="dashicons dashicons-admin-users"></span>{{ t.users }}</span></button>
                    <button :class="{'is-active': currentPage === 'reports'}" @click="navigate('/reports')"><span class="dlp-nav-label"><span class="dashicons dashicons-chart-bar"></span>{{ t.reports }}</span></button>
                    <button :class="{'is-active': currentPage === 'cli'}" @click="navigate('/cli')"><span class="dlp-nav-label"><span class="dashicons dashicons-editor-code"></span>{{ t.cli }}</span></button>
                    <button :class="{'is-active': currentPage === 'sync'}" @click="navigate('/sync')"><span class="dlp-nav-label"><span class="dashicons dashicons-update"></span>{{ t.sync }}</span></button>
                    <button :class="{'is-active': currentPage === 'settings'}" @click="navigate('/settings')"><span class="dlp-nav-label"><span class="dashicons dashicons-admin-settings"></span>{{ t.settings }}</span></button>
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
                        <select class="dlp-select" v-model="theme"><option value="system">{{ t.system }}</option><option value="light">{{ t.light }}</option><option value="dark">{{ t.dark }}</option></select>
                        <select class="dlp-select" v-model="styleMode" :aria-label="t.panelStyle"><option value="modern">{{ t.modernStyle }}</option><option value="classic">{{ t.classicStyle }}</option></select>
                        <a class="dlp-button" href="/wp-admin/" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-home"></span>{{ t.openWordPress }}</a>
                    </div>
                </header>
                <div v-if="error" class="dlp-error">{{ error }}</div>

                <section v-if="currentPage === 'dashboard'">
                    <div class="dlp-grid">
                        <article v-for="card in dashboardCards" :key="card.key" class="dlp-card" draggable="true" :class="{'is-dragging': draggedCard === card.key}" @dragstart="startDrag(card.key)" @dragend="endDrag" @dragover.prevent @drop="dropCard(card.key)">
                            <div class="dlp-card-head">
                                <span class="dlp-card-label"><span :class="icon(card.icon)"></span> {{ card.label }}</span>
                                <span class="dlp-card-tools">
                                    <span class="dlp-drag-handle" aria-hidden="true"></span>
                                    <button v-if="card.editable" class="dlp-menu-button" @click="startCardEdit(card)" :aria-label="t.edit"><span class="dashicons dashicons-edit"></span></button>
                                    <button class="dlp-menu-button" @click="toggleMenu(card.key)" :aria-label="t.actions"><span class="dashicons dashicons-ellipsis"></span></button>
                                    <span class="dlp-menu" v-if="openMenu === card.key">
                                        <button v-if="card.editable" @click="cardAction(card, 'edit')"><span class="dashicons dashicons-edit"></span>{{ t.edit }}</button>
                                        <button v-if="card.key === 'products'" @click="cardAction(card, 'products')"><span class="dashicons dashicons-products"></span>{{ t.products }}</button>
                                    </span>
                                </span>
                            </div>
                            <div class="dlp-card-value">
                                <button v-if="!isCardEditing(card)" class="dlp-card-value-button" @click="startCardEdit(card)">{{ card.value }}</button>
                                <input v-else class="dlp-card-input" :data-card-key="card.key" :value="formatInputNumber(currencyDraft[card.field])" @input="onCurrencyInput(card.field, $event)" @blur="saveCurrencyField" @keydown.enter.prevent="saveCurrencyField" @keydown.esc.prevent="editingCell = null">
                            </div>
                        </article>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.recentActivity }}</strong></div>
                        <div class="dlp-table-wrap" v-if="summary && summary.logs && summary.logs.length">
                            <table class="dlp-table"><tbody><tr v-for="log in summary.logs" :key="log.id"><td>{{ log.action }}</td><td>{{ log.object_type }}</td><td>{{ log.created_at }}</td></tr></tbody></table>
                        </div>
                        <div v-else class="dlp-empty">{{ t.noRows }}</div>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.reports }}</strong><button class="dlp-button" @click="navigate('/reports')"><span class="dashicons dashicons-visibility"></span>{{ t.view }}</button></div>
                        <div class="dlp-report-grid">
                            <button class="dlp-report-card" v-for="section in migrationSections" :key="section.key" @click="navigate(section.route)">
                                <span :class="icon(section.icon)"></span>
                                <strong>{{ section.title }}</strong>
                                <span>{{ section.body }}</span>
                            </button>
                        </div>
                    </div>
                </section>

                <section v-if="currentPage === 'products'">
                    <div class="dlp-toolbar">
                        <input class="dlp-input dlp-search" v-model="search" :placeholder="t.search" autofocus>
                        <span class="dlp-pill">{{ formatNumber(filteredProducts.length) }} / {{ formatNumber(products.length) }}</span>
                        <button class="dlp-button" @click="columnMenuOpen = !columnMenuOpen"><span class="dashicons dashicons-visibility"></span>{{ t.columns }}</button>
                    </div>
                    <div class="dlp-column-panel" v-if="columnMenuOpen">
                        <label v-for="column in productColumns" :key="column.key"><input type="checkbox" :checked="column.visible !== false" @change="toggleColumn('product', column)"> {{ column.labelKey ? t[column.labelKey] : column.label }}</label>
                        <button class="dlp-button" @click="resetColumns('product')">{{ t.resetColumns }}</button>
                    </div>
                    <div class="dlp-detail" v-if="selectedProduct">
                        <div class="dlp-panel">
                            <div class="dlp-panel-head"><strong>{{ selectedProduct.name }}</strong><button class="dlp-icon-button" @click="navigate('/products')"><span class="dashicons dashicons-no-alt"></span></button></div>
                            <div class="dlp-field-grid">
                                <label class="dlp-field"><span>{{ t.sku }}</span><input class="dlp-input" :value="selectedProduct.sku" @input="editProduct(selectedProduct, 'sku', $event.target.value)"></label>
                                <label class="dlp-field"><span>{{ t.regularPrice }}</span><input class="dlp-input" :value="formatInputNumber(selectedProduct.regular_price)" @input="onCellInput('product', selectedProduct, {field: 'regular_price', numeric: true}, $event)"></label>
                                <label class="dlp-field"><span>{{ t.salePrice }}</span><input class="dlp-input" :value="formatInputNumber(selectedProduct.sale_price)" @input="onCellInput('product', selectedProduct, {field: 'sale_price', numeric: true}, $event)"></label>
                                <label class="dlp-field"><span>{{ t.stock }}</span><input class="dlp-input" :value="formatInputNumber(selectedProduct.stock_quantity)" @input="onCellInput('product', selectedProduct, {field: 'stock_quantity', numeric: true}, $event)"></label>
                            </div>
                        </div>
                        <aside class="dlp-card">
                            <img v-if="selectedProduct.image" class="dlp-detail-image" :src="selectedProduct.image" alt="">
                            <p><strong>{{ t.status }}</strong><br>{{ selectedProduct.status }}</p>
                            <p><strong>{{ t.sku }}</strong><br>{{ selectedProduct.sku || '-' }}</p>
                        </aside>
                    </div>
                    <div class="dlp-panel" v-else>
                        <div class="dlp-table-wrap">
                            <table class="dlp-table dlp-data-grid">
                                <colgroup>
                                    <col style="width:44px">
                                    <col v-for="column in visibleProductColumns" :key="column.key" :style="{width: column.width + 'px'}">
                                    <col style="width:112px">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" v-model="allProductsSelected" :aria-label="t.selectAll"></th>
                                        <th v-for="column in visibleProductColumns" :key="column.key" draggable="true" @dragstart="startColumnDrag(column.key)" @dragover.prevent @drop="dropColumn('product', column.key)">
                                            <button class="dlp-th-button" @click="cycleSort('product', column, $event)">{{ column.labelKey ? t[column.labelKey] : column.label }} <span>{{ sortLabel('product', column) }}</span></button>
                                            <span class="dlp-col-resizer" @mousedown="startColumnResize('product', column, $event)"></span>
                                        </th>
                                        <th>{{ t.actions }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="product in filteredProducts" :key="product.id" :class="{'is-edited': rowEdited(product)}">
                                        <td><input type="checkbox" v-model="selectedProducts[product.id]" :aria-label="t.selectRow"></td>
                                        <td v-for="column in visibleProductColumns" :key="column.key">
                                            <span v-if="!isCellEditing('product', product, column)" class="dlp-editable-cell" tabindex="0" @click="startCellEdit('product', product, column)" @focus="startCellEdit('product', product, column)">{{ formatColumnValue(product, column) }}</span>
                                            <input v-else class="dlp-cell-input" :data-cell-key="'product:' + product.id + ':' + column.field" :value="inputValue(product, column)" @input="onCellInput('product', product, column, $event)" @keydown.enter.prevent="finishCellEdit('product', product)" @keydown.esc.prevent="editingCell = null" @blur="finishCellEdit('product', product)">
                                        </td>
                                        <td>
                                            <span class="dlp-cell-actions">
                                                <button class="dlp-icon-button" @click="viewProduct(product)" :aria-label="t.view"><span class="dashicons dashicons-visibility"></span></button>
                                                <button class="dlp-icon-button" @click="editProductPage(product)" :aria-label="t.edit"><span class="dashicons dashicons-edit"></span></button>
                                                <span class="dlp-row-menu-wrap">
                                                    <button class="dlp-icon-button" @click="toggleRowMenu('product', product.id)" :aria-label="t.actions"><span class="dashicons dashicons-ellipsis"></span></button>
                                                    <span class="dlp-menu" v-if="openRowMenu === 'product:' + product.id">
                                                        <button @click="viewProduct(product)"><span class="dashicons dashicons-visibility"></span>{{ t.view }}</button>
                                                        <button @click="editProductPage(product)"><span class="dashicons dashicons-edit"></span>{{ t.edit }}</button>
                                                        <button @click="copy(product.sku || product.id)"><span class="dashicons dashicons-clipboard"></span>{{ t.copy }}</button>
                                                    </span>
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
                </section>

                <section v-if="currentPage === 'users'">
                    <div class="dlp-toolbar">
                        <input class="dlp-input dlp-search" v-model="userSearch" :placeholder="t.searchUsers">
                        <span class="dlp-pill">{{ formatNumber(filteredUsers.length) }} / {{ formatNumber(users.length) }}</span>
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
                                    <tr v-for="item in filteredUsers" :key="item.id" :class="{'is-edited': userEdited(item)}">
                                        <td><input type="checkbox" v-model="selectedUsers[item.id]" :aria-label="t.selectRow"></td>
                                        <td v-for="column in visibleUserColumns" :key="column.key">
                                            <span v-if="!isCellEditing('user', item, column)" class="dlp-editable-cell" tabindex="0" @click="startCellEdit('user', item, column)" @focus="startCellEdit('user', item, column)">{{ column.field === 'roles' ? roleText(item.roles) : formatColumnValue(item, column) }}</span>
                                            <input v-else class="dlp-cell-input" :data-cell-key="'user:' + item.id + ':' + column.field" :value="inputValue(item, column)" @input="onCellInput('user', item, column, $event)" @keydown.enter.prevent="finishCellEdit('user', item)" @keydown.esc.prevent="editingCell = null" @blur="finishCellEdit('user', item)">
                                        </td>
                                        <td><span class="dlp-save-state" :class="'is-' + saveStatus('user', item.id)">{{ saveStatus('user', item.id) }}</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
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
                            <label class="dlp-field"><span>Theme</span><select class="dlp-select" v-model="theme"><option value="system">{{ t.system }}</option><option value="light">{{ t.light }}</option><option value="dark">{{ t.dark }}</option></select></label>
                            <label class="dlp-field"><span>{{ t.panelStyle }}</span><select class="dlp-select" v-model="styleMode"><option value="modern">{{ t.modernStyle }}</option><option value="classic">{{ t.classicStyle }}</option></select></label>
                            <label class="dlp-field"><span>{{ t.transport }}</span><input class="dlp-input" :value="transport" readonly></label>
                        </div>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.currency }}</strong><button class="dlp-button dlp-primary" :disabled="saving" @click="saveCurrency"><span class="dashicons dashicons-saved"></span>{{ t.save }}</button></div>
                        <div class="dlp-field-grid">
                            <label class="dlp-field"><span>USD</span><input class="dlp-input" :value="formatInputNumber(currencyDraft.dollar_price)" @input="onCurrencyInput('dollar_price', $event)"></label>
                            <label class="dlp-field"><span>CNY</span><input class="dlp-input" :value="formatInputNumber(currencyDraft.yuan_price)" @input="onCurrencyInput('yuan_price', $event)"></label>
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
    <?php wp_print_scripts(array('vue', 'digitalogic-panel')); ?>
</body>
</html>
