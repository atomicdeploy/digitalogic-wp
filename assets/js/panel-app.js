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
        var eventListeners = [];

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

                if (response.id && pending[response.id]) {
                    var item = pending[response.id];
                    delete pending[response.id];
                    clearTimeout(item.timeout);
                    response.success ? item.resolve(response.data) : item.reject(response.error || {message: 'WebSocket failed'});
                    return;
                }

                eventListeners.forEach(function(listener) {
                    listener(response);
                });
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
            },
            onEvent: function(listener) {
                eventListeners.push(listener);
            }
        };
    }

    function stored(key, fallback) {
        return window.localStorage.getItem(key) || fallback;
    }

    function storedJson(key, fallback) {
        try {
            return JSON.parse(window.localStorage.getItem(key) || JSON.stringify(fallback));
        } catch (e) {
            return fallback;
        }
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
            return;
        }

        var inherited = window.localStorage.getItem('digitalogic-admin-theme');
        if (inherited === 'light' || inherited === 'dark') {
            document.documentElement.setAttribute('data-dlp-theme', inherited);
            document.body.setAttribute('data-dlp-theme', inherited);
            return;
        }

        document.documentElement.removeAttribute('data-dlp-theme');
        document.body.removeAttribute('data-dlp-theme');
    }

    function applyStyleMode(styleMode) {
        document.documentElement.setAttribute('data-dlp-style', styleMode === 'classic' ? 'classic' : 'modern');
    }

    function defaultProductColumns() {
        return [
            {key: 'id', label: 'ID', field: 'id', width: 76, visible: true, sortable: true, editable: false},
            {key: 'name', labelKey: 'products', field: 'name', width: 260, visible: true, sortable: true, editable: true},
            {key: 'sku', labelKey: 'sku', field: 'sku', width: 140, visible: true, sortable: true, editable: true},
            {key: 'regular_price', labelKey: 'regularPrice', field: 'regular_price', width: 132, visible: true, sortable: true, editable: true, numeric: true},
            {key: 'sale_price', labelKey: 'salePrice', field: 'sale_price', width: 132, visible: true, sortable: true, editable: true, numeric: true},
            {key: 'stock_quantity', labelKey: 'stock', field: 'stock_quantity', width: 104, visible: true, sortable: true, editable: true, numeric: true},
            {key: 'status', labelKey: 'status', field: 'status', width: 108, visible: true, sortable: true, editable: false}
        ];
    }

    function defaultUserColumns() {
        return [
            {key: 'id', label: 'ID', field: 'id', width: 76, visible: true, sortable: true, editable: false},
            {key: 'display_name', labelKey: 'displayName', field: 'display_name', width: 220, visible: true, sortable: true, editable: true},
            {key: 'email', label: 'Email', field: 'email', width: 260, visible: true, sortable: true, editable: true},
            {key: 'roles', labelKey: 'role', field: 'roles', width: 180, visible: true, sortable: true, editable: false}
        ];
    }

    function mergeColumns(saved, defaults) {
        var map = {};
        defaults.forEach(function(column) {
            map[column.key] = Object.assign({}, column);
        });

        (Array.isArray(saved) ? saved : []).forEach(function(column) {
            if (column && map[column.key]) {
                map[column.key] = Object.assign(map[column.key], {
                    width: Math.max(72, parseInt(column.width, 10) || map[column.key].width),
                    visible: column.visible !== false
                });
            }
        });

        var order = (Array.isArray(saved) ? saved : []).map(function(column) {
            return column && column.key;
        }).filter(function(key) {
            return key && map[key];
        });

        defaults.forEach(function(column) {
            if (order.indexOf(column.key) === -1) {
                order.push(column.key);
            }
        });

        return order.map(function(key) {
            return map[key];
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
                notice: '',
                summary: null,
                settings: null,
                products: [],
                users: [],
                selectedProduct: null,
                search: '',
                userSearch: '',
                edits: {},
                userEdits: {},
                editingCell: null,
                currencyDraft: {dollar_price: '', yuan_price: ''},
                transport: 'ajax',
                openMenu: '',
                openRowMenu: '',
                columnMenuOpen: false,
                selectedProducts: {},
                selectedUsers: {},
                draggedCard: '',
                draggedColumn: '',
                sortState: storedJson('digitalogic_panel_product_sorts', []),
                userSortState: storedJson('digitalogic_panel_user_sorts', []),
                productColumns: mergeColumns(storedJson('digitalogic_panel_product_columns', []), defaultProductColumns()),
                userColumns: mergeColumns(storedJson('digitalogic_panel_user_columns', []), defaultUserColumns()),
                cardOrder: storedJson('digitalogic_panel_cards', ['products', 'usd', 'cny']).filter(function(key) { return key !== 'transport'; }),
                saveTimers: {},
                userSaveTimers: {},
                saveState: {},
                lastEventId: 0,
                eventTimer: null
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
            visibleProductColumns: function() {
                return this.productColumns.filter(function(column) { return column.visible !== false; });
            },
            visibleUserColumns: function() {
                return this.userColumns.filter(function(column) { return column.visible !== false; });
            },
            filteredProducts: function() {
                var term = this.search.trim().toLowerCase();
                var rows = !term ? this.products.slice() : this.products.filter(function(product) {
                    return [product.id, product.name, product.sku, product.type, product.status].join(' ').toLowerCase().indexOf(term) !== -1;
                });
                return this.applySorts(rows, this.sortState);
            },
            filteredUsers: function() {
                var term = this.userSearch.trim().toLowerCase();
                var rows = !term ? this.users.slice() : this.users.filter(function(user) {
                    return [user.id, user.display_name, user.login, user.email, (user.roles || []).join(' ')].join(' ').toLowerCase().indexOf(term) !== -1;
                });
                return this.applySorts(rows, this.userSortState);
            },
            dashboardCards: function() {
                var currency = (this.summary && this.summary.currency) || {};
                var map = {
                    products: {key: 'products', label: this.t.totalProducts, value: this.formatNumber(this.summary && this.summary.products), icon: 'dashicons-products', editable: false},
                    usd: {key: 'usd', label: 'USD', value: this.formatMoney(currency.dollar_price), field: 'dollar_price', icon: 'dashicons-money-alt', editable: true},
                    cny: {key: 'cny', label: 'CNY', value: this.formatMoney(currency.yuan_price), field: 'yuan_price', icon: 'dashicons-money-alt', editable: true}
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
            },
            allProductsSelected: {
                get: function() {
                    return this.filteredProducts.length > 0 && this.filteredProducts.every(function(product) {
                        return !!this.selectedProducts[product.id];
                    }, this);
                },
                set: function(value) {
                    var selected = Object.assign({}, this.selectedProducts);
                    this.filteredProducts.forEach(function(product) {
                        value ? selected[product.id] = true : delete selected[product.id];
                    });
                    this.selectedProducts = selected;
                }
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
            },
            productColumns: {
                deep: true,
                handler: function(value) {
                    window.localStorage.setItem('digitalogic_panel_product_columns', JSON.stringify(value));
                }
            },
            userColumns: {
                deep: true,
                handler: function(value) {
                    window.localStorage.setItem('digitalogic_panel_user_columns', JSON.stringify(value));
                }
            }
        },
        mounted: function() {
            document.documentElement.dir = this.t.dir || 'ltr';
            document.documentElement.lang = this.lang === 'fa' ? 'fa-IR' : 'en';
            applyTheme(this.theme);
            applyStyleMode(this.styleMode);
            this.loadRoute();
            this.bindGlobalEvents();
        },
        beforeUnmount: function() {
            if (this.eventTimer) {
                window.clearInterval(this.eventTimer);
            }
        },
        methods: {
            bindGlobalEvents: function() {
                var self = this;
                window.addEventListener('popstate', function() {
                    self.route = normalizePath(window.location.pathname);
                });
                window.addEventListener('dragend', function() {
                    self.endDrag();
                    self.draggedColumn = '';
                });
                window.setInterval(function() {
                    self.transport = transport.isReady() ? 'websocket' : 'ajax';
                }, 400);
                transport.onEvent(function(event) {
                    self.handleTransportEvent(event);
                });
                this.eventTimer = window.setInterval(function() {
                    self.fetchEvents();
                }, 5000);
            },
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
                if (!this.summary || ['dashboard', 'cli', 'sync', 'settings', 'reports'].indexOf(this.currentPage) !== -1) {
                    this.loadSummary();
                }
                if (this.currentPage === 'settings') this.loadSettings();
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
            loadSettings: function() {
                var self = this;
                return self.run('digitalogic_panel_settings').then(function(data) {
                    self.settings = data;
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
            fetchEvents: function() {
                var self = this;
                if (!transport.isReady()) {
                    return;
                }
                this.run('digitalogic_panel_events', {since: this.lastEventId}).then(function(data) {
                    (data.events || []).forEach(function(event) {
                        self.lastEventId = Math.max(self.lastEventId, Number(event.id || 0));
                        self.handlePanelEvent(event.name || event.event, event.data || {});
                    });
                }).catch(function() {});
            },
            handleTransportEvent: function(event) {
                if (event && event.event && event.event !== 'connected' && event.event !== 'response') {
                    this.handlePanelEvent(event.name || event.event, event.data || {});
                }
            },
            handlePanelEvent: function(name) {
                if (!name) return;
                if (name.indexOf('product') !== -1 && this.currentPage === 'products') this.loadProducts();
                if (name.indexOf('currency') !== -1) this.loadSummary();
                if (name.indexOf('user') !== -1 && this.currentPage === 'users') this.loadUsers();
            },
            normalizeDigits: function(value) {
                return String(value || '').replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function(digit) {
                    var code = digit.charCodeAt(0);
                    return String(code >= 0x06F0 ? code - 0x06F0 : code - 0x0660);
                });
            },
            normalizeNumber: function(value) {
                var cleaned = this.normalizeDigits(value)
                    .replace(/[\u066C\u060C,\s]/g, '')
                    .replace(/[^0-9.]/g, '');
                var parts = cleaned.split('.');
                if (parts.length > 2) {
                    cleaned = parts.shift() + '.' + parts.join('');
                }
                return cleaned;
            },
            formatInputNumber: function(value) {
                var raw = this.normalizeNumber(value);
                if (raw === '') return '';
                var parts = raw.split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                return parts.join('.');
            },
            formatColumnValue: function(row, column) {
                var value = row[column.field];
                if (Array.isArray(value)) return value.join(', ');
                if (value === null || typeof value === 'undefined' || value === '') return '-';
                return column.numeric ? this.formatInputNumber(value) : value;
            },
            inputValue: function(row, column) {
                return column.numeric ? this.formatInputNumber(row[column.field]) : (row[column.field] || '');
            },
            startCellEdit: function(kind, row, column) {
                if (!column.editable) return;
                var key = kind + ':' + row.id + ':' + column.field;
                this.editingCell = key;
                this.$nextTick(function() {
                    var input = document.querySelector('[data-cell-key="' + key + '"]');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                });
            },
            isCellEditing: function(kind, row, column) {
                return this.editingCell === kind + ':' + row.id + ':' + column.field;
            },
            onCellInput: function(kind, row, column, event) {
                var value = event.target.value;
                if (column.numeric) {
                    var raw = this.normalizeNumber(value);
                    event.target.value = this.formatInputNumber(raw);
                    value = raw;
                }
                kind === 'user' ? this.editUser(row, column.field, value) : this.editProduct(row, column.field, value);
            },
            finishCellEdit: function(kind, row) {
                if (kind === 'user') this.flushUserSave(row);
                else this.flushProductSave(row);
                this.editingCell = null;
            },
            editProduct: function(product, field, value) {
                if (!this.edits[product.id]) this.edits[product.id] = {};
                this.edits[product.id][field] = value;
                product[field] = value;
                this.scheduleProductSave(product);
            },
            editUser: function(user, field, value) {
                if (!this.userEdits[user.id]) this.userEdits[user.id] = {};
                this.userEdits[user.id][field] = value;
                user[field] = value;
                this.scheduleUserSave(user);
            },
            scheduleProductSave: function(product) {
                var self = this;
                window.clearTimeout(this.saveTimers[product.id]);
                this.saveTimers[product.id] = window.setTimeout(function() {
                    self.saveProduct(product);
                }, 800);
            },
            scheduleUserSave: function(user) {
                var self = this;
                window.clearTimeout(this.userSaveTimers[user.id]);
                this.userSaveTimers[user.id] = window.setTimeout(function() {
                    self.saveUser(user);
                }, 800);
            },
            flushProductSave: function(product) {
                window.clearTimeout(this.saveTimers[product.id]);
                return this.saveProduct(product);
            },
            flushUserSave: function(user) {
                window.clearTimeout(this.userSaveTimers[user.id]);
                return this.saveUser(user);
            },
            rowEdited: function(product) {
                return !!(this.edits[product.id] && Object.keys(this.edits[product.id]).length);
            },
            userEdited: function(user) {
                return !!(this.userEdits[user.id] && Object.keys(this.userEdits[user.id]).length);
            },
            saveProduct: function(product) {
                var self = this;
                var data = self.edits[product.id] || {};
                if (!Object.keys(data).length) return Promise.resolve();
                self.saveState['product:' + product.id] = 'saving';
                return self.run('digitalogic_update_product', {product_id: product.id, data: data}).then(function() {
                    delete self.edits[product.id];
                    self.saveState['product:' + product.id] = 'saved';
                    window.setTimeout(function() { delete self.saveState['product:' + product.id]; }, 1400);
                    if (self.selectedProduct && self.selectedProduct.id === product.id) self.loadProduct(product.id);
                }).catch(function(error) {
                    self.saveState['product:' + product.id] = 'error';
                    self.error = error.message || self.t.error;
                });
            },
            saveUser: function(user) {
                var self = this;
                var data = self.userEdits[user.id] || {};
                if (!Object.keys(data).length) return Promise.resolve();
                self.saveState['user:' + user.id] = 'saving';
                return self.run('digitalogic_panel_update_user', {user_id: user.id, data: data}).then(function(response) {
                    delete self.userEdits[user.id];
                    self.saveState['user:' + user.id] = 'saved';
                    Object.assign(user, response.user || {});
                    window.setTimeout(function() { delete self.saveState['user:' + user.id]; }, 1400);
                }).catch(function(error) {
                    self.saveState['user:' + user.id] = 'error';
                    self.error = error.message || self.t.error;
                });
            },
            saveStatus: function(kind, id) {
                return this.saveState[kind + ':' + id] || '';
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
            startColumnDrag: function(key) {
                this.draggedColumn = key;
            },
            dropColumn: function(kind, key) {
                var list = kind === 'user' ? this.userColumns : this.productColumns;
                var from = list.findIndex(function(column) { return column.key === this.draggedColumn; }, this);
                var to = list.findIndex(function(column) { return column.key === key; });
                if (from < 0 || to < 0 || from === to) {
                    this.draggedColumn = '';
                    return;
                }
                var next = list.slice();
                next.splice(to, 0, next.splice(from, 1)[0]);
                kind === 'user' ? (this.userColumns = next) : (this.productColumns = next);
                this.draggedColumn = '';
            },
            startColumnResize: function(kind, column, event) {
                var self = this;
                var startX = event.clientX;
                var startWidth = column.width;
                event.preventDefault();
                function move(moveEvent) {
                    column.width = Math.max(72, startWidth + (moveEvent.clientX - startX));
                }
                function up() {
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', up);
                    kind === 'user'
                        ? window.localStorage.setItem('digitalogic_panel_user_columns', JSON.stringify(self.userColumns))
                        : window.localStorage.setItem('digitalogic_panel_product_columns', JSON.stringify(self.productColumns));
                }
                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', up);
            },
            toggleColumn: function(kind, column) {
                column.visible = column.visible === false;
                if (kind === 'user') this.userColumns = this.userColumns.slice();
                else this.productColumns = this.productColumns.slice();
            },
            resetColumns: function(kind) {
                if (kind === 'user') this.userColumns = defaultUserColumns();
                else this.productColumns = defaultProductColumns();
            },
            cycleSort: function(kind, column, event) {
                if (!column.sortable) return;
                var state = (kind === 'user' ? this.userSortState : this.sortState).slice();
                var item = state.find(function(sort) { return sort.key === column.key; });
                if (!event.shiftKey) state = item ? [item] : [];
                item = state.find(function(sort) { return sort.key === column.key; });
                if (!item) state.push({key: column.key, field: column.field, direction: 'asc'});
                else if (item.direction === 'asc') item.direction = 'desc';
                else state = state.filter(function(sort) { return sort.key !== column.key; });
                if (kind === 'user') {
                    this.userSortState = state;
                    window.localStorage.setItem('digitalogic_panel_user_sorts', JSON.stringify(state));
                } else {
                    this.sortState = state;
                    window.localStorage.setItem('digitalogic_panel_product_sorts', JSON.stringify(state));
                }
            },
            sortLabel: function(kind, column) {
                var state = kind === 'user' ? this.userSortState : this.sortState;
                var index = state.findIndex(function(sort) { return sort.key === column.key; });
                if (index < 0) return '';
                return (state[index].direction === 'asc' ? '↑' : '↓') + (state.length > 1 ? String(index + 1) : '');
            },
            applySorts: function(rows, sorts) {
                if (!sorts || !sorts.length) return rows;
                return rows.slice().sort(function(a, b) {
                    for (var i = 0; i < sorts.length; i++) {
                        var sort = sorts[i];
                        var av = Array.isArray(a[sort.field]) ? a[sort.field].join(', ') : a[sort.field];
                        var bv = Array.isArray(b[sort.field]) ? b[sort.field].join(', ') : b[sort.field];
                        var result = String(av || '').localeCompare(String(bv || ''), undefined, {numeric: true, sensitivity: 'base'});
                        if (result !== 0) return sort.direction === 'desc' ? -result : result;
                    }
                    return 0;
                });
            },
            toggleMenu: function(key) {
                this.openMenu = this.openMenu === key ? '' : key;
            },
            toggleRowMenu: function(kind, id) {
                var key = kind + ':' + id;
                this.openRowMenu = this.openRowMenu === key ? '' : key;
            },
            cardAction: function(card, action) {
                this.openMenu = '';
                if (action === 'edit' && card.editable) this.startCardEdit(card);
                if (action === 'products') this.navigate('/products');
            },
            startCardEdit: function(card) {
                if (!card.editable) return;
                this.editingCell = 'card:' + card.key;
                this.$nextTick(function() {
                    var input = document.querySelector('[data-card-key="' + card.key + '"]');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                });
            },
            isCardEditing: function(card) {
                return this.editingCell === 'card:' + card.key;
            },
            onCurrencyInput: function(field, event) {
                var raw = this.normalizeNumber(event.target.value);
                event.target.value = this.formatInputNumber(raw);
                this.currencyDraft[field] = raw;
            },
            saveCurrencyField: function() {
                return this.saveCurrency();
            },
            resetCurrencyDraft: function() {
                var currency = (this.summary && this.summary.currency) || {};
                this.currencyDraft = {
                    dollar_price: currency.dollar_price || '',
                    yuan_price: currency.yuan_price || ''
                };
            },
            saveCurrency: function() {
                var self = this;
                self.saving = true;
                return self.run('digitalogic_update_currency', {
                    dollar_price: self.normalizeNumber(self.currencyDraft.dollar_price),
                    yuan_price: self.normalizeNumber(self.currencyDraft.yuan_price)
                }).then(function() {
                    self.editingCell = null;
                    return self.loadSummary();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.saving = false;
                });
            },
            viewProduct: function(product) {
                window.open(product.permalink || ('/?p=' + product.id), '_blank', 'noopener');
            },
            editProductPage: function(product) {
                window.open(product.edit_url || ('/wp-admin/post.php?post=' + encodeURIComponent(product.id) + '&action=edit'), '_blank', 'noopener');
            },
            copy: function(value) {
                if (navigator.clipboard) navigator.clipboard.writeText(value);
            },
            formatNumber: function(value) {
                return new Intl.NumberFormat(this.lang === 'fa' ? 'fa-IR' : 'en-US').format(Number(this.normalizeNumber(value) || 0));
            },
            formatMoney: function(value) {
                return new Intl.NumberFormat(this.lang === 'fa' ? 'fa-IR' : 'en-US', {maximumFractionDigits: 0}).format(Number(this.normalizeNumber(value) || 0));
            },
            roleText: function(roles) {
                return (roles || []).join(', ') || '-';
            }
        },
        template: document.getElementById('digitalogic-panel-template').innerHTML
    }).mount('#digitalogic-panel');
})(window, document);
