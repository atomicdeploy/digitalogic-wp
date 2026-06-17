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
                response.success ? item.resolve(response.data) : item.reject(response.error || {message: 'WebSocket failed'});
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
                            if (pending[id]) {
                                pending[id].reject({message: 'WebSocket timeout'});
                                delete pending[id];
                            }
                        }, (config.websocket && config.websocket.request_timeout) || 15000)
                    };

                    socket.send(JSON.stringify({id: id, command: command, data: data}));
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
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
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

    function stored(key, fallback) {
        return window.localStorage.getItem(key) || fallback;
    }

    function storedTheme() {
        return window.localStorage.getItem('digitalogic_panel_theme') ||
            window.localStorage.getItem('digitalogic-admin-theme') ||
            'system';
    }

    function normalizePath(pathname) {
        var base = new URL(config.panel_url || '/panel/', window.location.origin).pathname.replace(/\/+$/, '');
        var path = pathname.indexOf(base) === 0 ? pathname.slice(base.length) : pathname;
        path = path || '/';
        return path.charAt(0) === '/' ? path : '/' + path;
    }

    function applyTheme(theme) {
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.setAttribute('data-dlp-theme', theme);
            document.body.setAttribute('data-dlp-theme', theme);
        } else {
            var inherited = window.localStorage.getItem('digitalogic-admin-theme');
            if (inherited === 'light' || inherited === 'dark') {
                document.documentElement.setAttribute('data-dlp-theme', inherited);
                document.body.setAttribute('data-dlp-theme', inherited);
                return;
            }

            document.documentElement.removeAttribute('data-dlp-theme');
            document.body.removeAttribute('data-dlp-theme');
        }
    }

    function applyStyleMode(styleMode) {
        document.documentElement.setAttribute('data-dlp-style', styleMode === 'classic' ? 'classic' : 'modern');
    }

    function enableRipples() {
        document.addEventListener('pointerdown', function(event) {
            var target = event.target.closest('button, .dlp-button, .dlp-icon-button, .dlp-menu-button, .dlp-nav button, a.dlp-button');
            if (!target || target.disabled) {
                return;
            }

            target.classList.add('dlp-ripple-host');

            var circle = document.createElement('span');
            var rect = target.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height) * 1.35;
            circle.className = 'dlp-ripple-circle';
            circle.style.width = size + 'px';
            circle.style.height = size + 'px';
            circle.style.left = (event.clientX - rect.left) + 'px';
            circle.style.top = (event.clientY - rect.top) + 'px';
            target.appendChild(circle);

            window.setTimeout(function() {
                circle.remove();
            }, 650);
        });
    }

    var transport = createTransport();

    Vue.createApp({
        data: function() {
            return {
                lang: stored('digitalogic_panel_language', (config.locale || '').indexOf('fa') === 0 ? 'fa' : 'en'),
                theme: storedTheme(),
                styleMode: stored('digitalogic_panel_style', 'modern'),
                route: normalizePath(window.location.pathname),
                loading: false,
                saving: false,
                error: '',
                summary: null,
                products: [],
                users: [],
                selectedProduct: null,
                search: '',
                edits: {},
                editingCell: null,
                currencyDraft: {dollar_price: '', yuan_price: ''},
                transport: 'ajax',
                openMenu: '',
                draggedCard: '',
                cardOrder: JSON.parse(stored('digitalogic_panel_cards', '["products","usd","cny","transport"]'))
            };
        },
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
                if (this.route.indexOf('/products') === 0) return 'products';
                if (this.route.indexOf('/users') === 0) return 'users';
                if (this.route.indexOf('/reports') === 0) return 'reports';
                if (this.route.indexOf('/cli') === 0) return 'cli';
                if (this.route.indexOf('/sync') === 0) return 'sync';
                if (this.route.indexOf('/settings') === 0) return 'settings';
                return 'dashboard';
            },
            productRouteId: function() {
                var match = this.route.match(/^\/products\/(\d+)/);
                return match ? parseInt(match[1], 10) : 0;
            },
            filteredProducts: function() {
                var term = this.search.trim().toLowerCase();
                if (!term) return this.products;
                return this.products.filter(function(product) {
                    return [product.id, product.name, product.sku, product.type, product.status].join(' ').toLowerCase().indexOf(term) !== -1;
                });
            },
            dashboardCards: function() {
                var currency = (this.summary && this.summary.currency) || {};
                var map = {
                    products: {key: 'products', label: this.t.totalProducts, value: this.formatNumber(this.summary && this.summary.products), icon: 'dashicons-products'},
                    usd: {key: 'usd', label: 'USD', value: this.formatMoney(currency.dollar_price), icon: 'dashicons-money-alt'},
                    cny: {key: 'cny', label: 'CNY', value: this.formatMoney(currency.yuan_price), icon: 'dashicons-money-alt'},
                    transport: {key: 'transport', label: this.t.transport, value: this.transport, icon: 'dashicons-randomize'}
                };
                return this.cardOrder.map(function(key) { return map[key]; }).filter(Boolean);
            },
            commands: function() {
                return (this.summary && this.summary.cli) || {};
            },
            patris: function() {
                return (this.summary && this.summary.patris) || {};
            },
            migrationSections: function() {
                return [
                    {key: 'price-reports', icon: 'dashicons-chart-area', title: this.t.priceReports, body: this.t.priceReportsText, route: '/products'},
                    {key: 'sync-prices', icon: 'dashicons-update', title: this.t.priceSync, body: this.t.priceSyncText, route: '/sync'},
                    {key: 'image-audit', icon: 'dashicons-format-image', title: this.t.imageAudit, body: this.t.imageAuditText, route: '/reports'},
                    {key: 'customer-report', icon: 'dashicons-groups', title: this.t.customerReports, body: this.t.customerReportsText, route: '/users'},
                    {key: 'currency-shipping', icon: 'dashicons-admin-tools', title: this.t.currencyShipping, body: this.t.currencyShippingText, route: '/settings'},
                    {key: 'excel-export', icon: 'dashicons-media-spreadsheet', title: this.t.excelExports, body: this.t.excelExportsText, route: '/cli'}
                ];
            }
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
            styleMode: function(value) {
                window.localStorage.setItem('digitalogic_panel_style', value);
                applyStyleMode(value);
            },
            route: function() {
                this.loadRoute();
            }
        },
        mounted: function() {
            document.documentElement.dir = this.t.dir || 'ltr';
            document.documentElement.lang = this.lang === 'fa' ? 'fa-IR' : 'en';
            applyTheme(this.theme);
            applyStyleMode(this.styleMode);
            enableRipples();
            this.loadRoute();
            var self = this;
            window.addEventListener('popstate', function() {
                self.route = normalizePath(window.location.pathname);
            });
            window.addEventListener('dragend', function() {
                self.endDrag();
            });
            window.setInterval(function() {
                self.transport = transport.isReady() ? 'websocket' : 'ajax';
            }, 400);
        },
        methods: {
            icon: function(name) {
                return 'dashicons ' + name;
            },
            navigate: function(path) {
                var nextUrl = (config.panel_url || '/panel/').replace(/\/+$/, '') + (path || '/');
                window.history.pushState({}, '', nextUrl);
                this.route = normalizePath(window.location.pathname);
            },
            run: function(command, data) {
                this.transport = transport.isReady() ? 'websocket' : 'ajax';
                return transport.request(command, data || {});
            },
            loadRoute: function() {
                this.error = '';
                if (!this.summary || ['dashboard', 'cli', 'sync', 'settings'].indexOf(this.currentPage) !== -1) {
                    this.loadSummary();
                }
                if (this.currentPage === 'products') {
                    this.loadProducts();
                    this.productRouteId ? this.loadProduct(this.productRouteId) : (this.selectedProduct = null);
                }
                if (this.currentPage === 'users') this.loadUsers();
            },
            loadSummary: function() {
                var self = this;
                return self.run('digitalogic_panel_summary').then(function(data) {
                    self.summary = data;
                    self.resetCurrencyDraft();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                });
            },
            loadProducts: function() {
                var self = this;
                self.loading = true;
                return self.run('digitalogic_get_products', {page: 1, limit: 1000}).then(function(data) {
                    self.products = data.products || [];
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.loading = false;
                });
            },
            loadProduct: function(id) {
                var self = this;
                return self.run('digitalogic_get_product', {product_id: id}).then(function(data) {
                    self.selectedProduct = data.product || null;
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                });
            },
            loadUsers: function() {
                var self = this;
                self.loading = true;
                return self.run('digitalogic_panel_users').then(function(data) {
                    self.users = data.users || [];
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.loading = false;
                });
            },
            editProduct: function(product, field, value) {
                if (!this.edits[product.id]) this.edits[product.id] = {};
                this.edits[product.id][field] = value;
                product[field] = value;
            },
            startCellEdit: function(product, field) {
                var key = product.id + ':' + field;
                this.editingCell = key;
                this.$nextTick(function() {
                    var input = document.querySelector('[data-cell-key="' + key + '"]');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                });
            },
            isCellEditing: function(product, field) {
                return this.editingCell === product.id + ':' + field;
            },
            finishCellEdit: function() {
                this.editingCell = null;
            },
            cellValue: function(product, field) {
                var value = product[field];
                return value === null || typeof value === 'undefined' || value === '' ? '-' : value;
            },
            rowEdited: function(product) {
                return !!(this.edits[product.id] && Object.keys(this.edits[product.id]).length);
            },
            saveProduct: function(product) {
                var self = this;
                var data = self.edits[product.id] || {};
                if (!Object.keys(data).length) return;
                self.saving = true;
                self.run('digitalogic_update_product', {product_id: product.id, data: data}).then(function() {
                    delete self.edits[product.id];
                    if (self.selectedProduct && self.selectedProduct.id === product.id) self.loadProduct(product.id);
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.saving = false;
                });
            },
            startDrag: function(key) {
                this.draggedCard = key;
            },
            dropCard: function(key) {
                var from = this.cardOrder.indexOf(this.draggedCard);
                var to = this.cardOrder.indexOf(key);
                if (from < 0 || to < 0 || from === to) {
                    this.endDrag();
                    return;
                }
                var cards = this.cardOrder.slice();
                cards.splice(to, 0, cards.splice(from, 1)[0]);
                this.cardOrder = cards;
                window.localStorage.setItem('digitalogic_panel_cards', JSON.stringify(cards));
                this.endDrag();
            },
            endDrag: function() {
                this.draggedCard = '';
            },
            toggleMenu: function(key) {
                this.openMenu = this.openMenu === key ? '' : key;
            },
            cardAction: function(card, action) {
                this.openMenu = '';
                if (action === 'edit') {
                    if (card.key === 'products') this.navigate('/products');
                    else if (card.key === 'usd' || card.key === 'cny') this.focusCurrency(card.key);
                    else this.navigate('/settings');
                }
                if (action === 'refresh') this.loadSummary();
            },
            resetCurrencyDraft: function() {
                var currency = (this.summary && this.summary.currency) || {};
                this.currencyDraft = {
                    dollar_price: currency.dollar_price || '',
                    yuan_price: currency.yuan_price || ''
                };
            },
            focusCurrency: function(key) {
                var self = this;
                this.$nextTick(function() {
                    var selector = key === 'cny' ? '[data-currency-field="yuan_price"]' : '[data-currency-field="dollar_price"]';
                    var input = document.querySelector(selector);
                    if (input) {
                        input.focus();
                        input.select();
                    }
                });
            },
            saveCurrency: function() {
                var self = this;
                self.saving = true;
                self.run('digitalogic_update_currency', {
                    dollar_price: self.currencyDraft.dollar_price,
                    yuan_price: self.currencyDraft.yuan_price
                }).then(function() {
                    return self.loadSummary();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.saving = false;
                });
            },
            copy: function(value) {
                if (navigator.clipboard) navigator.clipboard.writeText(value);
            },
            formatNumber: function(value) {
                return new Intl.NumberFormat(this.lang === 'fa' ? 'fa-IR' : 'en-US').format(Number(value || 0));
            },
            formatMoney: function(value) {
                return new Intl.NumberFormat(this.lang === 'fa' ? 'fa-IR' : 'en-US', {maximumFractionDigits: 0}).format(Number(value || 0));
            },
            roleText: function(roles) {
                return (roles || []).join(', ') || '-';
            }
        },
        template: document.getElementById('digitalogic-panel-template').innerHTML
    }).mount('#digitalogic-panel');
})(window, document);
