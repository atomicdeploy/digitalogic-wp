(function(window, document) {
    'use strict';

    var config = window.digitalogicPanel || {};
    var Vue = window.Vue;

    if (!Vue || !document.getElementById('digitalogic-panel')) {
        return;
    }

    function createTransport() {
        var socket = null;
        var ready = false;
        var connecting = false;
        var requestId = 0;
        var pending = {};

        function connect() {
            var ws = config.websocket || {};
            if (!ws.enabled || !ws.url || ready || connecting || typeof window.WebSocket === 'undefined') {
                return;
            }

            connecting = true;
            var separator = ws.url.indexOf('?') === -1 ? '?' : '&';
            var authParam = ws.token ? 'token=' + encodeURIComponent(ws.token) : 'nonce=' + encodeURIComponent(ws.nonce || '');
            socket = new window.WebSocket(ws.url + separator + authParam);

            socket.onopen = function() {
                ready = true;
                connecting = false;
            };

            socket.onmessage = function(event) {
                var response;
                try {
                    response = JSON.parse(event.data);
                } catch (e) {
                    return;
                }

                if (!response.id || !pending[response.id]) {
                    return;
                }

                var item = pending[response.id];
                delete pending[response.id];
                clearTimeout(item.timeout);

                if (response.success) {
                    item.resolve(response.data);
                } else {
                    item.reject(response.error || {message: 'WebSocket failed'});
                }
            };

            socket.onclose = function() {
                ready = false;
                connecting = false;
                Object.keys(pending).forEach(function(id) {
                    pending[id].reject({message: 'WebSocket disconnected'});
                    clearTimeout(pending[id].timeout);
                    delete pending[id];
                });
                window.setTimeout(connect, ws.reconnect_interval || 3000);
            };

            socket.onerror = function() {
                ready = false;
                connecting = false;
            };
        }

        function request(command, data) {
            data = data || {};
            if (ready && socket && socket.readyState === window.WebSocket.OPEN) {
                return new Promise(function(resolve, reject) {
                    var id = 'panel_' + (++requestId);
                    pending[id] = {
                        resolve: resolve,
                        reject: reject,
                        timeout: window.setTimeout(function() {
                            if (!pending[id]) {
                                return;
                            }
                            pending[id].reject({message: 'WebSocket timeout'});
                            delete pending[id];
                        }, (config.websocket && config.websocket.request_timeout) || 15000)
                    };

                    socket.send(JSON.stringify({
                        id: id,
                        command: command,
                        data: data
                    }));
                }).catch(function() {
                    return ajax(command, data);
                });
            }

            return ajax(command, data);
        }

        function ajax(command, data) {
            var body = new URLSearchParams();
            body.set('action', 'digitalogic_panel_command');
            body.set('nonce', config.nonce || '');
            body.set('command', command);
            body.set('data', JSON.stringify(data || {}));

            return window.fetch(config.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function(response) {
                return response.json();
            }).then(function(json) {
                if (!json || !json.success) {
                    throw new Error((json && json.data) || 'AJAX failed');
                }

                return json.data;
            });
        }

        connect();

        return {
            request: request,
            isReady: function() {
                return ready;
            }
        };
    }

    function initialLanguage() {
        if (window.localStorage.getItem('digitalogic_panel_language')) {
            return window.localStorage.getItem('digitalogic_panel_language');
        }

        return (config.locale || '').indexOf('fa') === 0 ? 'fa' : 'en';
    }

    function initialTheme() {
        return window.localStorage.getItem('digitalogic_panel_theme') || 'system';
    }

    function productPath(id) {
        return '/products/' + encodeURIComponent(id);
    }

    var transport = createTransport();

    Vue.createApp({
        data: function() {
            return {
                lang: initialLanguage(),
                theme: initialTheme(),
                route: normalizePath(window.location.pathname),
                loading: false,
                saving: false,
                error: '',
                summary: null,
                products: [],
                users: [],
                selectedProduct: null,
                search: '',
                page: 1,
                limit: 50,
                edits: {},
                transport: 'ajax'
            };
        },
        watch: {
            lang: function(value) {
                window.localStorage.setItem('digitalogic_panel_language', value);
                document.documentElement.dir = this.t.dir || 'ltr';
                document.documentElement.lang = value === 'fa' ? 'fa-IR' : 'en';
            },
            theme: function(value) {
                window.localStorage.setItem('digitalogic_panel_theme', value);
                applyTheme(value);
            },
            route: function() {
                this.loadRoute();
            }
        },
        mounted: function() {
            document.documentElement.dir = this.t.dir || 'ltr';
            document.documentElement.lang = this.lang === 'fa' ? 'fa-IR' : 'en';
            applyTheme(this.theme);
            this.loadRoute();

            var self = this;
            window.addEventListener('popstate', function() {
                self.route = normalizePath(window.location.pathname);
            });

            window.setInterval(function() {
                self.transport = transport.isReady() ? 'websocket' : 'ajax';
            }, 1000);
        },
        methods: {
            navigate: function(path) {
                path = path || '/';
                var nextUrl = (config.panel_url || '/panell/').replace(/\/+$/, '') + path;
                window.history.pushState({}, '', nextUrl);
                this.route = normalizePath(window.location.pathname);
            },
            loadRoute: function() {
                this.error = '';
                if (this.currentPage === 'products') {
                    this.loadProducts();
                    if (this.productRouteId) {
                        this.loadProduct(this.productRouteId);
                    } else {
                        this.selectedProduct = null;
                    }
                    return;
                }
                if (this.currentPage === 'users') {
                    this.loadUsers();
                    return;
                }
                if (this.currentPage === 'settings') {
                    return;
                }
                this.loadSummary();
            },
            run: function(command, data) {
                this.transport = transport.isReady() ? 'websocket' : 'ajax';
                return transport.request(command, data).catch(function(error) {
                    throw error;
                });
            },
            loadSummary: function() {
                var self = this;
                self.loading = true;
                self.run('digitalogic_panel_summary').then(function(data) {
                    self.summary = data;
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.loading = false;
                });
            },
            loadProducts: function() {
                var self = this;
                self.loading = true;
                self.run('digitalogic_get_products', {
                    page: self.page,
                    limit: self.limit,
                    search: self.search
                }).then(function(data) {
                    self.products = data.products || [];
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.loading = false;
                });
            },
            loadProduct: function(id) {
                var self = this;
                self.run('digitalogic_get_product', {product_id: id}).then(function(data) {
                    self.selectedProduct = data.product || null;
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                });
            },
            loadUsers: function() {
                var self = this;
                self.loading = true;
                self.run('digitalogic_panel_users').then(function(data) {
                    self.users = data.users || [];
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.loading = false;
                });
            },
            editProduct: function(product, field, value) {
                if (!this.edits[product.id]) {
                    this.edits[product.id] = {};
                }
                this.edits[product.id][field] = value;
                product[field] = value;
            },
            saveProduct: function(product) {
                var self = this;
                var data = self.edits[product.id] || {};
                if (!Object.keys(data).length) {
                    return;
                }
                self.saving = true;
                self.run('digitalogic_update_product', {
                    product_id: product.id,
                    data: data
                }).then(function() {
                    delete self.edits[product.id];
                    if (self.selectedProduct && self.selectedProduct.id === product.id) {
                        self.loadProduct(product.id);
                    }
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.saving = false;
                });
            },
            openProduct: function(product) {
                this.navigate(productPath(product.id));
            },
            roleText: function(roles) {
                return (roles || []).join(', ') || '-';
            },
            formatNumber: function(value) {
                return new Intl.NumberFormat(this.lang === 'fa' ? 'fa-IR' : 'en-US').format(value || 0);
            }
        },
        template: [
            '<div class="dlp-layout" v-cloak>',
            '  <aside class="dlp-sidebar">',
            '    <div class="dlp-brand">',
            '      <div class="dlp-logo-mark">D</div>',
            '      <div><div class="dlp-brand-title">Digitalogic</div><div class="dlp-brand-subtitle">{{ configTheme.site_name || "Panel" }}</div></div>',
            '    </div>',
            '    <nav class="dlp-nav">',
            '      <button :class="{\'is-active\': currentPage === \'dashboard\'}" @click="navigate(\'/\')"><span>{{ t.dashboard }}</span><span>⌘</span></button>',
            '      <button :class="{\'is-active\': currentPage === \'products\'}" @click="navigate(\'/products\')"><span>{{ t.products }}</span><span>↗</span></button>',
            '      <button :class="{\'is-active\': currentPage === \'users\'}" @click="navigate(\'/users\')"><span>{{ t.users }}</span><span>◎</span></button>',
            '      <button :class="{\'is-active\': currentPage === \'settings\'}" @click="navigate(\'/settings\')"><span>{{ t.settings }}</span><span>⚙</span></button>',
            '    </nav>',
            '  </aside>',
            '  <main class="dlp-main">',
            '    <header class="dlp-topbar">',
            '      <div><h1 class="dlp-title">{{ t[currentPage] || t.dashboard }}</h1><div class="dlp-muted">{{ t.signedInAs }} {{ user.display_name }}</div></div>',
            '      <div class="dlp-userbar">',
            '        <span class="dlp-pill" :class="transport === \'websocket\' ? \'is-ok\' : \'is-warn\'">{{ transport === \'websocket\' ? t.connected : t.fallback }}</span>',
            '        <select class="dlp-select" v-model="lang" :aria-label="t.language"><option value="fa">فارسی</option><option value="en">English</option></select>',
            '        <select class="dlp-select" v-model="theme"><option value="system">{{ t.system }}</option><option value="light">{{ t.light }}</option><option value="dark">{{ t.dark }}</option></select>',
            '        <a class="dlp-secondary-button" href="/wp-admin/">{{ t.openWordPress }}</a>',
            '      </div>',
            '    </header>',
            '    <div v-if="error" class="dlp-error">{{ error }}</div>',
            '    <section v-if="currentPage === \'dashboard\'">',
            '      <div class="dlp-grid">',
            '        <div class="dlp-card"><div class="dlp-card-label">{{ t.totalProducts }}</div><div class="dlp-card-value">{{ formatNumber(summary && summary.products) }}</div></div>',
            '        <div class="dlp-card"><div class="dlp-card-label">USD</div><div class="dlp-card-value">{{ summary && summary.currency ? summary.currency.dollar_price : "-" }}</div></div>',
            '        <div class="dlp-card"><div class="dlp-card-label">CNY</div><div class="dlp-card-value">{{ summary && summary.currency ? summary.currency.yuan_price : "-" }}</div></div>',
            '        <div class="dlp-card"><div class="dlp-card-label">{{ t.transport }}</div><div class="dlp-card-value">{{ transport }}</div></div>',
            '      </div>',
            '      <div class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.recentActivity }}</strong><button class="dlp-icon-button" @click="loadSummary">{{ t.refresh }}</button></div>',
            '        <div v-if="!summary || !summary.logs || !summary.logs.length" class="dlp-empty">{{ t.noRows }}</div>',
            '        <div class="dlp-table-wrap" v-else><table class="dlp-table"><tbody><tr v-for="log in summary.logs" :key="log.id"><td>{{ log.action }}</td><td>{{ log.object_type }}</td><td>{{ log.created_at }}</td></tr></tbody></table></div>',
            '      </div>',
            '    </section>',
            '    <section v-if="currentPage === \'products\'">',
            '      <div class="dlp-toolbar"><input class="dlp-input" v-model="search" @keyup.enter="loadProducts" :placeholder="t.search"><button class="dlp-primary-button" @click="loadProducts">{{ t.refresh }}</button></div>',
            '      <div class="dlp-detail" v-if="selectedProduct">',
            '        <div class="dlp-panel"><div class="dlp-panel-head"><strong>{{ selectedProduct.name }}</strong><button class="dlp-icon-button" @click="navigate(\'/products\')">×</button></div><div class="dlp-field-grid">',
            '          <label class="dlp-field"><span>{{ t.sku }}</span><input class="dlp-input" :value="selectedProduct.sku" @input="editProduct(selectedProduct, \'sku\', $event.target.value)"></label>',
            '          <label class="dlp-field"><span>{{ t.regularPrice }}</span><input class="dlp-input" :value="selectedProduct.regular_price" @input="editProduct(selectedProduct, \'regular_price\', $event.target.value)"></label>',
            '          <label class="dlp-field"><span>{{ t.salePrice }}</span><input class="dlp-input" :value="selectedProduct.sale_price" @input="editProduct(selectedProduct, \'sale_price\', $event.target.value)"></label>',
            '          <label class="dlp-field"><span>{{ t.stock }}</span><input class="dlp-input" :value="selectedProduct.stock_quantity" @input="editProduct(selectedProduct, \'stock_quantity\', $event.target.value)"></label>',
            '        </div><div class="dlp-toolbar"><button class="dlp-primary-button" :disabled="saving" @click="saveProduct(selectedProduct)">{{ t.save }}</button></div></div>',
            '        <aside class="dlp-card"><img v-if="selectedProduct.image" class="dlp-detail-image" :src="selectedProduct.image" alt=""><p><strong>{{ t.status }}</strong><br>{{ selectedProduct.status }}</p><p><strong>{{ t.sku }}</strong><br>{{ selectedProduct.sku || "-" }}</p></aside>',
            '      </div>',
            '      <div class="dlp-panel" v-else><div class="dlp-table-wrap"><table class="dlp-table"><thead><tr><th>ID</th><th>{{ t.products }}</th><th>{{ t.sku }}</th><th>{{ t.regularPrice }}</th><th>{{ t.salePrice }}</th><th>{{ t.stock }}</th><th></th></tr></thead><tbody>',
            '        <tr v-for="product in products" :key="product.id"><td>{{ product.id }}</td><td><div class="dlp-product-cell"><img v-if="product.image" :src="product.image" alt=""><span>{{ product.name }}</span></div></td><td>{{ product.sku || "-" }}</td><td><input class="dlp-input" :value="product.regular_price" @input="editProduct(product, \'regular_price\', $event.target.value)"></td><td><input class="dlp-input" :value="product.sale_price" @input="editProduct(product, \'sale_price\', $event.target.value)"></td><td><input class="dlp-input" :value="product.stock_quantity" @input="editProduct(product, \'stock_quantity\', $event.target.value)"></td><td><button class="dlp-secondary-button" @click="openProduct(product)">{{ t.view }}</button><button class="dlp-primary-button" :disabled="saving" @click="saveProduct(product)">{{ t.save }}</button></td></tr>',
            '        <tr v-if="!products.length"><td colspan="7" class="dlp-empty">{{ loading ? t.loading : t.noRows }}</td></tr>',
            '      </tbody></table></div></div>',
            '    </section>',
            '    <section v-if="currentPage === \'users\'" class="dlp-panel"><div class="dlp-table-wrap"><table class="dlp-table"><thead><tr><th>ID</th><th>{{ t.users }}</th><th>Email</th><th>Role</th></tr></thead><tbody><tr v-for="item in users" :key="item.id"><td>{{ item.id }}</td><td>{{ item.display_name }}</td><td>{{ item.email }}</td><td>{{ roleText(item.roles) }}</td></tr><tr v-if="!users.length"><td colspan="4" class="dlp-empty">{{ loading ? t.loading : t.noRows }}</td></tr></tbody></table></div></section>',
            '    <section v-if="currentPage === \'settings\'" class="dlp-panel"><div class="dlp-panel-head"><strong>{{ t.panelSettings }}</strong></div><div class="dlp-field-grid"><label class="dlp-field"><span>{{ t.language }}</span><select class="dlp-select" v-model="lang"><option value="fa">فارسی</option><option value="en">English</option></select></label><label class="dlp-field"><span>{{ t.transport }}</span><input class="dlp-input" :value="transport" readonly></label><label class="dlp-field"><span>Theme</span><select class="dlp-select" v-model="theme"><option value="system">{{ t.system }}</option><option value="light">{{ t.light }}</option><option value="dark">{{ t.dark }}</option></select></label></div></section>',
            '  </main>',
            '</div>'
        ].join(''),
        computed: {
            t: function() {
                return (config.i18n && config.i18n[this.lang]) || (config.i18n && config.i18n.en) || {};
            },
            user: function() {
                return config.user || {};
            },
            configTheme: function() {
                return config.theme || {};
            },
            currentPage: function() {
                if (this.route.indexOf('/products') === 0) {
                    return 'products';
                }
                if (this.route.indexOf('/users') === 0) {
                    return 'users';
                }
                if (this.route.indexOf('/settings') === 0) {
                    return 'settings';
                }
                return 'dashboard';
            },
            productRouteId: function() {
                var match = this.route.match(/^\/products\/(\d+)/);
                return match ? parseInt(match[1], 10) : 0;
            }
        }
    }).mount('#digitalogic-panel');

    function normalizePath(pathname) {
        var base = new URL(config.panel_url || '/panell/', window.location.origin).pathname.replace(/\/+$/, '');
        var path = pathname.indexOf(base) === 0 ? pathname.slice(base.length) : pathname;
        path = path || '/';
        return path.charAt(0) === '/' ? path : '/' + path;
    }

    function applyTheme(theme) {
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.setAttribute('data-dlp-theme', theme);
        } else {
            document.documentElement.removeAttribute('data-dlp-theme');
        }
    }
})(window, document);
