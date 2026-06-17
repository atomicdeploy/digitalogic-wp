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
            <div class="dlp-boot-mark">D</div>
            <div><?php esc_html_e('Loading...', 'digitalogic'); ?></div>
        </div>
    </div>
    <script type="text/x-template" id="digitalogic-panel-template">
        <div class="dlp-layout" v-cloak>
            <aside class="dlp-sidebar">
                <div class="dlp-brand">
                    <div class="dlp-logo-mark">D</div>
                    <div>
                        <div class="dlp-brand-title">Digitalogic</div>
                        <div class="dlp-brand-subtitle">{{ (configTheme && configTheme.site_name) || 'Panel' }}</div>
                    </div>
                </div>
                <div class="dlp-user">
                    <div class="dlp-avatar"><span class="dashicons dashicons-admin-users"></span></div>
                    <div>
                        <strong>{{ user.display_name || user.login }}</strong>
                        <div class="dlp-muted">{{ user.login }} · {{ user.email }}</div>
                    </div>
                </div>
                <nav class="dlp-nav">
                    <button :class="{'is-active': currentPage === 'dashboard'}" @click="navigate('/')"><span class="dlp-nav-label"><span class="dashicons dashicons-dashboard"></span>{{ t.dashboard }}</span></button>
                    <button :class="{'is-active': currentPage === 'products'}" @click="navigate('/products')"><span class="dlp-nav-label"><span class="dashicons dashicons-products"></span>{{ t.products }}</span></button>
                    <button :class="{'is-active': currentPage === 'users'}" @click="navigate('/users')"><span class="dlp-nav-label"><span class="dashicons dashicons-admin-users"></span>{{ t.users }}</span></button>
                    <button :class="{'is-active': currentPage === 'cli'}" @click="navigate('/cli')"><span class="dlp-nav-label"><span class="dashicons dashicons-editor-code"></span>{{ t.cli }}</span></button>
                    <button :class="{'is-active': currentPage === 'sync'}" @click="navigate('/sync')"><span class="dlp-nav-label"><span class="dashicons dashicons-update"></span>{{ t.sync }}</span></button>
                    <button :class="{'is-active': currentPage === 'settings'}" @click="navigate('/settings')"><span class="dlp-nav-label"><span class="dashicons dashicons-admin-settings"></span>{{ t.settings }}</span></button>
                </nav>
            </aside>
            <main class="dlp-main">
                <header class="dlp-topbar">
                    <div>
                        <h1 class="dlp-title">{{ t[currentPage] || t.dashboard }}</h1>
                        <div class="dlp-muted">{{ t.signedInAs }} {{ user.display_name || user.login }}</div>
                    </div>
                    <div class="dlp-actions">
                        <span class="dlp-pill" :class="transport === 'websocket' ? 'is-ok' : 'is-warn'"><span class="dashicons dashicons-randomize"></span>{{ transport === 'websocket' ? t.connected : t.fallback }}</span>
                        <select class="dlp-select" v-model="lang" :aria-label="t.language"><option value="fa">فارسی</option><option value="en">English</option></select>
                        <select class="dlp-select" v-model="theme"><option value="system">{{ t.system }}</option><option value="light">{{ t.light }}</option><option value="dark">{{ t.dark }}</option></select>
                        <a class="dlp-button" href="/wp-admin/">{{ t.openWordPress }}</a>
                    </div>
                </header>
                <div v-if="error" class="dlp-error">{{ error }}</div>

                <section v-if="currentPage === 'dashboard'">
                    <div class="dlp-grid">
                        <article v-for="card in dashboardCards" :key="card.key" class="dlp-card" draggable="true" :class="{'is-dragging': draggedCard === card.key}" @dragstart="startDrag(card.key)" @dragover.prevent @drop="dropCard(card.key)">
                            <div class="dlp-card-head">
                                <span class="dlp-card-label"><span :class="icon(card.icon)"></span> {{ card.label }}</span>
                                <span class="dlp-card-menu">
                                    <button class="dlp-menu-button" @click="toggleMenu(card.key)" :aria-label="t.actions"><span class="dashicons dashicons-ellipsis"></span></button>
                                    <span class="dlp-menu" v-if="openMenu === card.key">
                                        <button @click="cardAction(card, 'edit')"><span class="dashicons dashicons-edit"></span> {{ t.edit }}</button>
                                        <button @click="cardAction(card, 'refresh')"><span class="dashicons dashicons-update"></span> {{ t.refresh }}</button>
                                    </span>
                                </span>
                            </div>
                            <div class="dlp-card-value">{{ card.value }}</div>
                            <div class="dlp-muted"><span class="dashicons dashicons-move"></span> {{ t.reorder }}</div>
                        </article>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.recentActivity }}</strong><button class="dlp-button" @click="loadSummary"><span class="dashicons dashicons-update"></span> {{ t.refresh }}</button></div>
                        <div class="dlp-table-wrap" v-if="summary && summary.logs && summary.logs.length">
                            <table class="dlp-table"><tbody><tr v-for="log in summary.logs" :key="log.id"><td>{{ log.action }}</td><td>{{ log.object_type }}</td><td>{{ log.created_at }}</td></tr></tbody></table>
                        </div>
                        <div v-else class="dlp-empty">{{ t.noRows }}</div>
                    </div>
                    <div class="dlp-panel">
                        <div class="dlp-panel-head"><strong>{{ t.missingFeatures }}</strong></div>
                        <div class="dlp-empty">{{ t.missingText }}</div>
                    </div>
                </section>

                <section v-if="currentPage === 'products'">
                    <div class="dlp-toolbar">
                        <input class="dlp-input dlp-search" v-model="search" :placeholder="t.search" autofocus>
                        <span class="dlp-pill">{{ formatNumber(filteredProducts.length) }} / {{ formatNumber(products.length) }}</span>
                        <button class="dlp-button dlp-primary" @click="loadProducts"><span class="dashicons dashicons-update"></span> {{ t.refresh }}</button>
                    </div>
                    <div class="dlp-detail" v-if="selectedProduct">
                        <div class="dlp-panel">
                            <div class="dlp-panel-head"><strong>{{ selectedProduct.name }}</strong><button class="dlp-icon-button" @click="navigate('/products')"><span class="dashicons dashicons-no-alt"></span></button></div>
                            <div class="dlp-field-grid">
                                <label class="dlp-field"><span>{{ t.sku }}</span><input class="dlp-input" :value="selectedProduct.sku" @input="editProduct(selectedProduct, 'sku', $event.target.value)"></label>
                                <label class="dlp-field"><span>{{ t.regularPrice }}</span><input class="dlp-input" :value="selectedProduct.regular_price" @input="editProduct(selectedProduct, 'regular_price', $event.target.value)"></label>
                                <label class="dlp-field"><span>{{ t.salePrice }}</span><input class="dlp-input" :value="selectedProduct.sale_price" @input="editProduct(selectedProduct, 'sale_price', $event.target.value)"></label>
                                <label class="dlp-field"><span>{{ t.stock }}</span><input class="dlp-input" :value="selectedProduct.stock_quantity" @input="editProduct(selectedProduct, 'stock_quantity', $event.target.value)"></label>
                            </div>
                            <div class="dlp-toolbar"><button class="dlp-button dlp-primary" :disabled="saving" @click="saveProduct(selectedProduct)">{{ t.save }}</button></div>
                        </div>
                        <aside class="dlp-card">
                            <img v-if="selectedProduct.image" class="dlp-detail-image" :src="selectedProduct.image" alt="">
                            <p><strong>{{ t.status }}</strong><br>{{ selectedProduct.status }}</p>
                            <p><strong>{{ t.sku }}</strong><br>{{ selectedProduct.sku || '-' }}</p>
                        </aside>
                    </div>
                    <div class="dlp-panel" v-else>
                        <div class="dlp-table-wrap"><table class="dlp-table"><thead><tr><th>ID</th><th>{{ t.products }}</th><th>{{ t.sku }}</th><th>{{ t.regularPrice }}</th><th>{{ t.salePrice }}</th><th>{{ t.stock }}</th><th>{{ t.actions }}</th></tr></thead><tbody>
                            <tr v-for="product in filteredProducts" :key="product.id"><td>{{ product.id }}</td><td><div class="dlp-product-cell"><img v-if="product.image" :src="product.image" alt=""><span>{{ product.name }}</span></div></td><td>{{ product.sku || '-' }}</td><td><input class="dlp-input" :value="product.regular_price" @input="editProduct(product, 'regular_price', $event.target.value)"></td><td><input class="dlp-input" :value="product.sale_price" @input="editProduct(product, 'sale_price', $event.target.value)"></td><td><input class="dlp-input" :value="product.stock_quantity" @input="editProduct(product, 'stock_quantity', $event.target.value)"></td><td><button class="dlp-button" @click="navigate('/products/' + product.id)"><span class="dashicons dashicons-visibility"></span> {{ t.view }}</button><button class="dlp-button dlp-primary" :disabled="saving" @click="saveProduct(product)">{{ t.save }}</button></td></tr>
                            <tr v-if="!filteredProducts.length"><td colspan="7" class="dlp-empty">{{ loading ? t.loading : t.noRows }}</td></tr>
                        </tbody></table></div>
                    </div>
                </section>

                <section v-if="currentPage === 'users'" class="dlp-panel"><div class="dlp-table-wrap"><table class="dlp-table"><thead><tr><th>ID</th><th>{{ t.users }}</th><th>Email</th><th>Role</th></tr></thead><tbody><tr v-for="item in users" :key="item.id"><td>{{ item.id }}</td><td>{{ item.display_name }}</td><td>{{ item.email }}</td><td>{{ roleText(item.roles) }}</td></tr></tbody></table></div></section>
                <section v-if="currentPage === 'cli'" class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.commandUsage }}</strong></div><div class="dlp-field-grid"><div class="dlp-field" v-for="(command, key) in commands" :key="key"><span>{{ key }}</span><code class="dlp-code">{{ command }}</code><button class="dlp-button" @click="copy(command)"><span class="dashicons dashicons-clipboard"></span> {{ t.copy }}</button></div></div></section>
                <section v-if="currentPage === 'sync'" class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.patrisSync }}</strong></div><div class="dlp-field-grid"><div class="dlp-field"><span>Repository</span><code class="dlp-code">{{ patris.project }}</code></div><div class="dlp-field"><span>Mode</span><code class="dlp-code">{{ patris.mode }}</code></div><div class="dlp-field"><span>Suggested watcher</span><code class="dlp-code">{{ patris.suggested_bridge }}</code></div></div></section>
                <section v-if="currentPage === 'settings'" class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.panelSettings }}</strong></div><div class="dlp-field-grid"><label class="dlp-field"><span>{{ t.language }}</span><select class="dlp-select" v-model="lang"><option value="fa">فارسی</option><option value="en">English</option></select></label><label class="dlp-field"><span>{{ t.transport }}</span><input class="dlp-input" :value="transport" readonly></label><label class="dlp-field"><span>Theme</span><select class="dlp-select" v-model="theme"><option value="system">{{ t.system }}</option><option value="light">{{ t.light }}</option><option value="dark">{{ t.dark }}</option></select></label></div></section>
            </main>
        </div>
    </script>
    <?php wp_print_scripts(array('vue', 'digitalogic-panel')); ?>
</body>
</html>
