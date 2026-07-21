(function(window, document) {
    'use strict';

    var config = window.digitalogicPanel || {};
    var Vue = window.Vue;
    var productQuery = window.DigitalogicProductQuery;
    var adminThemeStorageKey = config.theme_storage_key || 'digitalogic-admin-theme';
    var panelThemeStorageKey = 'digitalogic_panel_theme';

    if (!Vue || !productQuery || !document.getElementById('digitalogic-panel')) {
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
        if (config.theme_mode === 'light' || config.theme_mode === 'dark') {
            return config.theme_mode;
        }

        return readBroadcastTheme() || 'light';
    }

    function readBroadcastTheme() {
        var adminTheme = window.localStorage.getItem(adminThemeStorageKey);
        var panelTheme = window.localStorage.getItem(panelThemeStorageKey);

        if (adminTheme === 'light' || adminTheme === 'dark') {
            return adminTheme;
        }

        if (panelTheme === 'light' || panelTheme === 'dark') {
            return panelTheme;
        }

        return '';
    }

    function normalizeStyleMode(value) {
        return 'modern';
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

        applyTheme('light');
    }

    function broadcastTheme(theme) {
        if (theme !== 'light' && theme !== 'dark') {
            return;
        }

        window.localStorage.setItem(adminThemeStorageKey, theme);
        window.localStorage.setItem(panelThemeStorageKey, theme);
        window.localStorage.setItem(adminThemeStorageKey + ':updated', String(Date.now()));
    }

    function applyStyleMode(styleMode) {
        document.documentElement.setAttribute('data-dlp-style', normalizeStyleMode(styleMode));
    }

    function defaultProductColumns() {
        return [
            {key: 'id', label: 'ID', field: 'id', width: 76, visible: true, sortable: true, editable: false, mono: true, filter: 'text', icon: 'dashicons-tag', priority: 1},
            {key: 'name', labelKey: 'productTitle', field: 'name', width: 340, visible: true, sortable: true, editable: true, filter: 'text', icon: 'dashicons-products', priority: 1},
            {key: 'part_number', labelKey: 'partNumber', field: 'part_number', width: 150, visible: false, sortable: false, editable: false, mono: true, filter: 'text', icon: 'dashicons-tag', priority: 3},
            {key: 'sku', labelKey: 'sku', field: 'sku', width: 140, visible: true, sortable: true, editable: true, mono: true, filter: 'text', icon: 'dashicons-editor-code', priority: 1},
            {key: 'type', labelKey: 'productType', field: 'type', width: 122, visible: false, sortable: false, editable: false, type: 'select', filter: 'select', icon: 'dashicons-category', priority: 3},
            {key: 'regular_price', labelKey: 'regularPrice', field: 'regular_price', width: 132, visible: true, sortable: true, editable: true, numeric: true, filter: 'numeric', icon: 'dashicons-money-alt', priority: 2},
            {key: 'sale_price', labelKey: 'salePrice', field: 'sale_price', width: 132, visible: true, sortable: true, editable: true, numeric: true, filter: 'numeric', icon: 'dashicons-tickets-alt', priority: 3},
            {key: 'min_price', labelKey: 'minPrice', field: 'min_price', width: 132, visible: true, sortable: false, editable: false, numeric: true, filter: false, icon: 'dashicons-arrow-down-alt', priority: 3},
            {key: 'max_price', labelKey: 'maxPrice', field: 'max_price', width: 132, visible: true, sortable: false, editable: false, numeric: true, filter: false, icon: 'dashicons-arrow-up-alt', priority: 3},
            {key: 'weight', labelKey: 'weight', field: 'weight', width: 112, visible: false, sortable: true, editable: true, numeric: true, filter: 'numeric', icon: 'dashicons-image-filter', priority: 3},
            {key: 'patris_foreign_currency', labelKey: 'patrisCurrency', field: 'patris_foreign_currency', width: 106, visible: true, sortable: true, editable: true, filter: 'text', icon: 'dashicons-money-alt', priority: 3},
            {key: 'patris_foreign_price', labelKey: 'patrisForeignPrice', field: 'patris_foreign_price', width: 138, visible: true, sortable: true, editable: true, numeric: true, filter: 'numeric', icon: 'dashicons-chart-line', priority: 3},
            {key: 'patris_weight_grams', labelKey: 'patrisWeight', field: 'patris_weight_grams', width: 118, visible: true, sortable: true, editable: true, numeric: true, filter: 'numeric', icon: 'dashicons-image-filter', priority: 3},
            {key: 'patris_final_price', labelKey: 'patrisFinalPrice', field: 'patris_final_price', width: 138, visible: true, sortable: true, editable: true, numeric: true, filter: 'numeric', icon: 'dashicons-yes-alt', priority: 3},
            {key: 'patris_location', labelKey: 'patrisLocation', field: 'patris_location', width: 132, visible: false, sortable: true, editable: true, filter: 'text', icon: 'dashicons-location-alt', priority: 3},
            {key: 'patris_updated_at', labelKey: 'patrisUpdatedAt', field: 'patris_updated_at', width: 158, visible: false, sortable: true, editable: false, filter: 'text', icon: 'dashicons-clock', priority: 3},
            {key: 'stock_quantity', labelKey: 'stock', field: 'stock_quantity', width: 104, visible: true, sortable: true, editable: true, numeric: true, filter: 'numeric', icon: 'dashicons-archive', priority: 2},
            {key: 'stock_status', labelKey: 'availability', field: 'stock_status', width: 132, visible: true, sortable: true, editable: true, type: 'select', filter: 'select', icon: 'dashicons-yes-alt', priority: 2},
            {key: 'status', labelKey: 'status', field: 'status', width: 118, visible: true, sortable: true, editable: true, type: 'select', filter: 'select', icon: 'dashicons-visibility', priority: 2}
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

    var transport = createTransport();

    var app = Vue.createApp({
        data: function() {
            return {
                config: config,
                lang: stored('digitalogic_panel_language', (config.locale || '').indexOf('fa') === 0 ? 'fa' : 'en'),
                theme: storedTheme(),
                styleMode: normalizeStyleMode(stored('digitalogic_panel_style', 'modern')),
                route: normalizePath(window.location.pathname),
                loading: false,
                saving: false,
                error: '',
                notice: '',
                summary: null,
                settings: null,
                reports: null,
                products: [],
                users: [],
                selectedProduct: null,
                selectedUser: null,
                search: '',
                productPage: 1,
                productPageSize: 50,
                productTotal: 0,
                productFilteredTotal: 0,
                productTotalPages: 0,
                productRequestSequence: 0,
                productQueryTimer: null,
                productEditMode: stored('digitalogic_panel_product_edit_mode', 'view') === 'edit',
                productAutosave: stored('digitalogic_panel_product_autosave', '1') !== '0',
                userSearch: '',
                edits: {},
                userEdits: {},
                editingCell: null,
                currencyDraft: {dollar_price: '', yuan_price: ''},
                currencyEditOriginal: '',
                currencyEditField: '',
                transport: 'ajax',
                openMenu: '',
                openRowMenu: '',
                rowContext: null,
                selectCell: null,
                columnMenuOpen: false,
                productFilters: storedJson('digitalogic_panel_product_filters', {}),
                imageFilter: stored('digitalogic_panel_image_filter', 'all'),
                compactTable: stored('digitalogic_panel_compact_table', '0') === '1',
                freezeFirstProductColumn: stored('digitalogic_panel_freeze_first_product_column', '1') !== '0',
                columnContext: null,
                selectedProducts: {},
                selectedUsers: {},
                productDialogOpen: false,
                userDialogOpen: false,
                userEditorMode: 'view',
                userOrders: [],
                userOrderLoading: false,
                pinnedEditorPinned: stored('digitalogic_panel_pinned_editor', '1') !== '0',
                draggedCard: '',
                draggedColumn: '',
                resizingColumn: '',
                sortState: storedJson('digitalogic_panel_product_sorts', []),
                userSortState: storedJson('digitalogic_panel_user_sorts', []),
                productColumns: productQuery.mergeColumns(storedJson('digitalogic_panel_product_columns', []), defaultProductColumns()),
                userColumns: productQuery.mergeColumns(storedJson('digitalogic_panel_user_columns', []), defaultUserColumns()),
                cardOrder: storedJson('digitalogic_panel_cards', ['products', 'usd', 'cny']).filter(function(key) { return key !== 'transport'; }),
                saveTimers: {},
                savePromises: {},
                userSaveTimers: {},
                saveState: {},
                lastEventId: Number(config.event_cursor || 0),
                eventTimer: null,
                toasts: [],
                toastId: 0
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
            responsiveProductColumns: function() {
                return this.visibleProductColumns.filter(function(column) {
                    return Number(column.priority || 1) > 1;
                });
            },
            visibleUserColumns: function() {
                return this.userColumns.filter(function(column) { return column.visible !== false; });
            },
            productStatusOptions: function() {
                return [
                    {value: 'publish', label: this.t.publish},
                    {value: 'draft', label: this.t.draft},
                    {value: 'pending', label: this.t.pending},
                    {value: 'private', label: this.t.private}
                ];
            },
            stockStatusOptions: function() {
                return [
                    {value: 'instock', label: this.t.instock},
                    {value: 'outofstock', label: this.t.outofstock},
                    {value: 'onbackorder', label: this.t.onbackorder}
                ];
            },
            productTypeOptions: function() {
                return [
                    {value: 'simple', label: this.t.simpleProduct},
                    {value: 'variable', label: this.t.variableProduct},
                    {value: 'variation', label: this.t.productVariation},
                    {value: 'grouped', label: this.t.groupedProduct},
                    {value: 'external', label: this.t.externalProduct}
                ];
            },
            userRoleOptions: function() {
                return [
                    {value: 'customer', label: this.t.customer},
                    {value: 'subscriber', label: this.t.subscriber},
                    {value: 'shop_manager', label: this.t.shopManager},
                    {value: 'administrator', label: this.t.administrator}
                ];
            },
            imageFilterOptions: function() {
                return [
                    {value: 'all', label: this.t.all, icon: 'dashicons-images-alt2'},
                    {value: 'with', label: this.t.withImage, icon: 'dashicons-format-image'},
                    {value: 'without', label: this.t.withoutImage, icon: 'dashicons-hidden'}
                ];
            },
            imageFilterLabel: function() {
                var option = this.imageFilterOptions.find(function(item) {
                    return item.value === this.imageFilter;
                }, this);
                return option ? option.label : this.t.all;
            },
            themeOptions: function() {
                var modes = [
                    {theme: 'light', label: this.t.light, icon: 'dashicons-lightbulb'},
                    {theme: 'dark', label: this.t.dark, icon: 'dashicons-hidden'}
                ];
                var styles = [
                    {style: 'modern', label: this.t.modernStyle, icon: 'dashicons-art'}
                ];
                var options = [];
                styles.forEach(function(style) {
                    modes.forEach(function(mode) {
                        options.push({
                            value: style.style + ':' + mode.theme,
                            style: style.style,
                            theme: mode.theme,
                            icon: style.style === 'default' ? style.icon : mode.icon,
                            label: style.label + ' · ' + mode.label
                        });
                    });
                });
                return options;
            },
            activeThemeOption: function() {
                var value = normalizeStyleMode(this.styleMode) + ':' + this.theme;
                return this.themeOptions.find(function(option) {
                    return option.value === value;
                }) || this.themeOptions[0] || {label: '', icon: 'dashicons-admin-appearance'};
            },
            columnContextStyle: function() {
                if (!this.columnContext) return {};
                return {
                    left: this.columnContext.x + 'px',
                    top: this.columnContext.y + 'px'
                };
            },
            rowContextStyle: function() {
                if (!this.rowContext) return {};
                return {
                    left: this.rowContext.x + 'px',
                    top: this.rowContext.y + 'px'
                };
            },
            selectCellStyle: function() {
                if (!this.selectCell) return {};
                return {
                    left: this.selectCell.x + 'px',
                    top: this.selectCell.y + 'px',
                    minWidth: Math.max(176, this.selectCell.width || 0) + 'px'
                };
            },
            selectedProductIds: function() {
                return Object.keys(this.selectedProducts).filter(function(id) {
                    return !!this.selectedProducts[id];
                }, this).map(function(id) {
                    return Number(id);
                }).filter(Boolean);
            },
            selectedUserIds: function() {
                return Object.keys(this.selectedUsers).filter(function(id) {
                    return !!this.selectedUsers[id];
                }, this).map(function(id) {
                    return Number(id);
                }).filter(Boolean);
            },
            filteredProducts: function() {
                return this.products.slice();
            },
            productPendingCount: function() {
                return Object.keys(this.edits).filter(function(id) {
                    return this.edits[id] && Object.keys(this.edits[id]).length;
                }, this).length;
            },
            productPageNumbers: function() {
                return productQuery.pageWindow(this.productPage, this.productTotalPages, 2);
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
            search: function() {
                this.queueProductReload(true);
            },
            lang: function(value) {
                window.localStorage.setItem('digitalogic_panel_language', value);
                document.documentElement.dir = this.t.dir || 'ltr';
                document.documentElement.lang = value === 'fa' ? 'fa-IR' : 'en';
            },
            theme: function(value) {
                applyTheme(value);
            },
            styleMode: function(value) {
                var normalized = normalizeStyleMode(value);
                window.localStorage.setItem('digitalogic_panel_style', normalized);
                applyStyleMode(normalized);
            },
            productFilters: {
                deep: true,
                handler: function(value) {
                    window.localStorage.setItem('digitalogic_panel_product_filters', JSON.stringify(value || {}));
                    this.queueProductReload(true);
                }
            },
            imageFilter: function(value) {
                window.localStorage.setItem('digitalogic_panel_image_filter', value || 'all');
                this.queueProductReload(true);
            },
            productEditMode: function(value) {
                window.localStorage.setItem('digitalogic_panel_product_edit_mode', value ? 'edit' : 'view');
            },
            productAutosave: function(value) {
                window.localStorage.setItem('digitalogic_panel_product_autosave', value ? '1' : '0');
                if (value) this.saveAllProductEdits().catch(function() {});
            },
            compactTable: function(value) {
                window.localStorage.setItem('digitalogic_panel_compact_table', value ? '1' : '0');
            },
            freezeFirstProductColumn: function(value) {
                window.localStorage.setItem('digitalogic_panel_freeze_first_product_column', value ? '1' : '0');
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
            if (this.productQueryTimer) {
                window.clearTimeout(this.productQueryTimer);
            }
            Object.keys(this.saveTimers).forEach(function(id) {
                window.clearTimeout(this.saveTimers[id]);
            }, this);
        },
        methods: {
            bindGlobalEvents: function() {
                var self = this;
                window.addEventListener('popstate', function() {
                    self.route = normalizePath(window.location.pathname);
                });
                window.addEventListener('storage', function(event) {
                    if (event.key !== adminThemeStorageKey && event.key !== panelThemeStorageKey) {
                        return;
                    }

                    var nextTheme = readBroadcastTheme() || storedTheme();
                    if (nextTheme !== self.theme) {
                        self.theme = nextTheme;
                        return;
                    }

                    applyTheme(nextTheme);
                });
                window.addEventListener('click', function(event) {
                    if (!event.target.closest('.dlp-column-context') && !event.target.closest('.dlp-theme-picker') && !event.target.closest('.dlp-row-menu-wrap') && !event.target.closest('.dlp-custom-select')) {
                        self.columnContext = null;
                        self.rowContext = null;
                        self.selectCell = null;
                        self.openRowMenu = '';
                        if (self.openMenu) self.openMenu = '';
                    }
                });
                window.addEventListener('keydown', function(event) {
                    var editableTarget = event.target && event.target.closest && event.target.closest('input, textarea, select, [contenteditable="true"]');
                    if (event.key === 'Escape') {
                        self.columnContext = null;
                        self.selectCell = null;
                        self.openMenu = '';
                        self.openRowMenu = '';
                        self.productDialogOpen = false;
                        self.userDialogOpen = false;
                    }
                    if (event.key === 'F2' && self.currentPage === 'products') {
                        event.preventDefault();
                        var search = document.querySelector('.dlp-search');
                        if (search) {
                            search.focus();
                            search.scrollIntoView({block: 'nearest', inline: 'nearest'});
                        }
                    }
                    if (self.currentPage === 'products' && !editableTarget && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
                        event.preventDefault();
                        self.moveProductSelection(event.key === 'ArrowDown' ? 1 : -1);
                    }
                });
                window.addEventListener('message', function(event) {
                    var data = event && event.data ? event.data : {};
                    if (data && data.type === 'digitalogic-product-updated') {
                        self.loadProducts();
                        if (data.productId) self.loadProduct(data.productId);
                    }
                });
                window.addEventListener('dragend', function() {
                    self.endDrag();
                    self.draggedColumn = '';
                    self.resizingColumn = '';
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
                if (this.currentPage === 'reports') this.loadReports();
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
            loadReports: function() {
                var self = this;
                self.loading = true;
                return self.run('digitalogic_get_reports', {}).then(function(data) {
                    self.reports = data;
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.loading = false;
                });
            },
            loadProducts: function(page) {
                var self = this;
                var requestedPage = Math.max(1, parseInt(page || self.productPage, 10) || 1);
                var requestSequence = ++self.productRequestSequence;
                var payload = productQuery.buildPayload({
                    page: requestedPage,
                    pageSize: self.productPageSize,
                    search: self.search,
                    filters: self.productFilters,
                    image: self.imageFilter,
                    sorts: self.sortState
                });
                self.loading = true;
                return self.run('digitalogic_get_products', payload).then(function(data) {
                    if (requestSequence !== self.productRequestSequence) return;
                    self.productTotal = Number(data.recordsTotal || data.total || 0);
                    self.productFilteredTotal = Number(data.recordsFiltered || 0);
                    self.productTotalPages = Number(data.pages || Math.ceil(self.productFilteredTotal / self.productPageSize) || 0);
                    if (self.productTotalPages && requestedPage > self.productTotalPages) {
                        return self.loadProducts(self.productTotalPages);
                    }
                    self.products = productQuery.applyPendingEdits(data.products, self.edits);
                    self.productPage = Math.max(1, Math.min(Number(data.page || requestedPage), Math.max(1, self.productTotalPages)));
                    if (self.selectedProduct && self.selectedProduct.id) {
                        var selected = self.productById(self.selectedProduct.id);
                        if (selected) Object.assign(self.selectedProduct, selected);
                    }
                }).catch(function(error) {
                    if (requestSequence !== self.productRequestSequence) return;
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    if (requestSequence === self.productRequestSequence) self.loading = false;
                });
            },
            queueProductReload: function(resetPage) {
                if (this.currentPage !== 'products') return;
                if (resetPage) this.productPage = 1;
                window.clearTimeout(this.productQueryTimer);
                var self = this;
                this.productQueryTimer = window.setTimeout(function() {
                    self.loadProducts();
                }, 280);
            },
            goToProductPage: function(page) {
                page = Math.max(1, Math.min(parseInt(page, 10) || 1, Math.max(1, this.productTotalPages)));
                if (page === this.productPage && this.products.length) return;
                this.productPage = page;
                this.loadProducts(page);
            },
            loadProduct: function(id) {
                var self = this;
                return self.run('digitalogic_get_product', {product_id: id}).then(function(data) {
                    self.selectedProduct = productQuery.applyPendingEdits(
                        data.product ? [data.product] : [],
                        self.edits
                    )[0] || null;
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
                this.run('digitalogic_panel_events', {since: this.lastEventId}).then(function(data) {
                    (data.events || []).forEach(function(event) {
                        self.lastEventId = Math.max(self.lastEventId, Number(event.id || 0));
                        self.handlePanelEvent(event.name || event.event, event.data || {});
                    });
                }).catch(function() {});
            },
            handleTransportEvent: function(event) {
                if (event && event.event && event.event !== 'connected' && event.event !== 'response') {
                    this.lastEventId = Math.max(this.lastEventId, Number(event.id || 0));
                    this.handlePanelEvent(event.name || event.event, event.data || {});
                }
            },
            handlePanelEvent: function(name) {
                if (!name) return;
                var data = arguments.length > 1 && arguments[1] ? arguments[1] : {};
                if (name.indexOf('toast') !== -1 || name.indexOf('broadcast') !== -1 || data.message) {
                    this.addToast({
                        message: data.message || data.title || name,
                        level: data.level || data.type || 'info'
                    });
                }
                if (name.indexOf('product') !== -1 && this.currentPage === 'products') this.loadProducts();
                if (name.indexOf('currency') !== -1) this.loadSummary();
                if (name.indexOf('user') !== -1 && this.currentPage === 'users') this.loadUsers();
            },
            addToast: function(toast) {
                var self = this;
                var id = ++this.toastId;
                var item = {
                    id: id,
                    message: toast && toast.message ? String(toast.message) : '',
                    level: toast && toast.level ? String(toast.level) : 'info'
                };
                if (!item.message) return;
                this.toasts = this.toasts.concat(item).slice(-4);
                window.setTimeout(function() {
                    self.toasts = self.toasts.filter(function(current) {
                        return current.id !== id;
                    });
                }, 5200);
            },
            dismissToast: function(id) {
                this.toasts = this.toasts.filter(function(current) {
                    return current.id !== id;
                });
            },
            normalizeDigits: function(value) {
                return String(value || '').replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function(digit) {
                    var code = digit.charCodeAt(0);
                    return String(code >= 0x06F0 ? code - 0x06F0 : code - 0x0660);
                });
            },
            localizeDigits: function(value) {
                value = String(value || '');
                if (this.lang !== 'fa') return value;
                var digits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
                return value.replace(/[0-9]/g, function(digit) {
                    return digits[Number(digit)];
                }).replace(/,/g, '٬').replace(/\./g, '٫');
            },
            normalizeNumber: function(value) {
                var cleaned = this.normalizeDigits(value)
                    .replace(/[\u066B]/g, '.')
                    .replace(/[\u066C\u060C,\s٬]/g, '')
                    .replace(/[^0-9.]/g, '');
                var parts = cleaned.split('.');
                if (parts.length > 2) {
                    cleaned = parts.shift() + '.' + parts.join('');
                }
                return cleaned;
            },
            caretForRawPosition: function(formatted, rawPosition) {
                if (rawPosition <= 0) return 0;
                var count = 0;
                for (var i = 0; i < formatted.length; i++) {
                    if (/[0-9\u06F0-\u06F9\u0660-\u0669.\u066B]/.test(formatted.charAt(i))) {
                        count++;
                    }
                    if (count >= rawPosition) {
                        return i + 1;
                    }
                }
                return formatted.length;
            },
            formatNumericEvent: function(event, localized) {
                var input = event.target;
                var selectionStart = typeof input.selectionStart === 'number' ? input.selectionStart : String(input.value || '').length;
                var rawBeforeCaret = this.normalizeNumber(String(input.value || '').slice(0, selectionStart)).length;
                var raw = this.normalizeNumber(input.value);
                var formatted = this.formatInputNumber(raw, localized);
                var caret = this.caretForRawPosition(formatted, rawBeforeCaret);
                input.value = formatted;
                if (typeof input.setSelectionRange === 'function') {
                    var restoreCaret = function() {
                        if (document.activeElement === input) {
                            input.setSelectionRange(caret, caret);
                        }
                    };
                    restoreCaret();
                    window.requestAnimationFrame(restoreCaret);
                    window.setTimeout(restoreCaret, 0);
                    this.$nextTick(function() {
                        window.requestAnimationFrame(restoreCaret);
                        window.setTimeout(restoreCaret, 0);
                    });
                }
                return raw;
            },
            formatInputNumber: function(value, localized) {
                var raw = this.normalizeNumber(value);
                if (raw === '') return '';
                var parts = raw.split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                var formatted = parts.join('.');
                return localized ? this.localizeDigits(formatted) : formatted;
            },
            formatCurrencyInputNumber: function(value) {
                return this.formatInputNumber(value, this.lang === 'fa');
            },
            formatColumnValue: function(row, column) {
                var value = row[column.field];
                if (Array.isArray(value)) return value.join(', ');
                if (value === null || typeof value === 'undefined' || value === '') return '-';
                if (column.field === 'status') return this.statusLabel(value);
                if (column.field === 'stock_status') return this.stockStatusLabel(value);
                if (column.field === 'type') return this.customSelectLabel(this.productTypeOptions, value);
                return column.numeric ? this.formatInputNumber(value) : value;
            },
            inputValue: function(row, column) {
                return column.numeric ? this.formatInputNumber(row[column.field]) : (row[column.field] || '');
            },
            startCellEdit: function(kind, row, column) {
                if (!column.editable) return;
                if (kind === 'product' && !this.productEditMode) return;
                if (column.type === 'select') return;
                var key = kind + ':' + row.id + ':' + column.field;
                this.editingCell = key;
                if (kind === 'product') {
                    this.selectProductRow(row);
                }
                this.$nextTick(function() {
                    var input = document.querySelector('[data-cell-key="' + key + '"]');
                    if (input) {
                        input.focus();
                        if (!column.numeric && typeof input.select === 'function') {
                            input.select();
                        }
                    }
                });
            },
            isCellEditing: function(kind, row, column) {
                return this.editingCell === kind + ':' + row.id + ':' + column.field;
            },
            onCellInput: function(kind, row, column, event) {
                var value = event.target.value;
                if (column.numeric) {
                    value = this.formatNumericEvent(event);
                }
                kind === 'user' ? this.editUser(row, column.field, value) : this.editProduct(row, column.field, value);
            },
            finishCellEdit: function(kind, row) {
                if (kind === 'user') this.flushUserSave(row);
                else if (this.productAutosave) this.flushProductSave(row);
                this.editingCell = null;
            },
            onGridCellKeydown: function(event, product, column) {
                var editKeys = ['Enter', 'F2'];
                if (editKeys.indexOf(event.key) !== -1) {
                    event.preventDefault();
                    this.startCellEdit('product', product, column);
                    return;
                }
                if (['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown'].indexOf(event.key) === -1) {
                    return;
                }

                event.preventDefault();
                var rows = this.filteredProducts;
                var columns = this.visibleProductColumns;
                var rowIndex = rows.findIndex(function(item) { return Number(item.id) === Number(product.id); });
                var columnIndex = columns.findIndex(function(item) { return item.key === column.key; });

                if (event.key === 'ArrowUp') rowIndex = Math.max(0, rowIndex - 1);
                if (event.key === 'ArrowDown') rowIndex = Math.min(rows.length - 1, rowIndex + 1);
                if (event.key === 'ArrowLeft') columnIndex = Math.max(0, columnIndex - 1);
                if (event.key === 'ArrowRight') columnIndex = Math.min(columns.length - 1, columnIndex + 1);

                var nextRow = rows[rowIndex];
                var nextColumn = columns[columnIndex];
                if (!nextRow || !nextColumn) return;

                this.$nextTick(function() {
                    var next = document.querySelector('[data-grid-row="' + nextRow.id + '"][data-grid-col="' + nextColumn.key + '"]');
                    if (next) next.focus();
                });
                this.selectProductRow(nextRow);
            },
            moveProductSelection: function(delta) {
                var rows = this.filteredProducts;
                if (!rows.length) return;
                var currentId = this.selectedProduct && this.selectedProduct.id ? Number(this.selectedProduct.id) : Number(rows[0].id);
                var index = rows.findIndex(function(item) {
                    return Number(item.id) === currentId;
                });
                if (index < 0) index = 0;
                index = Math.max(0, Math.min(rows.length - 1, index + delta));
                this.openProductPanel(rows[index], {preserveScroll: true});
                this.focusProductCell(rows[index], this.visibleProductColumns[0]);
            },
            focusProductCell: function(product, column) {
                if (!product || !column) return;
                this.$nextTick(function() {
                    var next = document.querySelector('[data-grid-row="' + product.id + '"][data-grid-col="' + column.key + '"]');
                    if (next) next.focus({preventScroll: true});
                });
            },
            editProduct: function(product, field, value) {
                if (!this.productEditMode || !product || !product.id) return;
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
                if (!this.productAutosave) return;
                var self = this;
                window.clearTimeout(this.saveTimers[product.id]);
                this.saveTimers[product.id] = window.setTimeout(function() {
                    self.saveProduct(product).catch(function() {});
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
                var productId = product && Number(product.id);
                if (!productId) return Promise.resolve();
                if (self.savePromises[productId]) {
                    return self.savePromises[productId].then(function() {
                        return self.edits[productId] && Object.keys(self.edits[productId]).length
                            ? self.saveProduct(product)
                            : undefined;
                    });
                }

                var snapshot = Object.assign({}, self.edits[productId] || {});
                if (!Object.keys(snapshot).length) return Promise.resolve();
                self.saveState['product:' + productId] = 'saving';
                var savePromise = self.run('digitalogic_update_product', {product_id: productId, data: snapshot}).then(function(response) {
                    var remaining = productQuery.reconcileEdits(self.edits[productId], snapshot);
                    if (Object.keys(remaining).length) self.edits[productId] = remaining;
                    else delete self.edits[productId];
                    if (response && response.product) {
                        Object.assign(product, response.product);
                        Object.assign(product, remaining);
                        if (self.selectedProduct && Number(self.selectedProduct.id) === productId) {
                            Object.assign(self.selectedProduct, response.product);
                            Object.assign(self.selectedProduct, remaining);
                        }
                    }
                    self.saveState['product:' + productId] = 'saved';
                    window.setTimeout(function() { delete self.saveState['product:' + productId]; }, 1400);
                    if (!Object.keys(remaining).length && self.selectedProduct && Number(self.selectedProduct.id) === productId) self.loadProduct(productId);
                    if (window.opener && window.opener !== window) {
                        window.opener.postMessage({type: 'digitalogic-product-updated', productId: productId}, window.location.origin);
                    }
                }).catch(function(error) {
                    self.saveState['product:' + productId] = 'error';
                    self.error = error.message || self.t.error;
                    throw error;
                }).finally(function() {
                    delete self.savePromises[productId];
                });
                self.savePromises[productId] = savePromise;
                return savePromise;
            },
            saveAllProductEdits: function() {
                var self = this;
                var ids = Object.keys(this.edits).filter(function(id) {
                    return self.edits[id] && Object.keys(self.edits[id]).length;
                });
                if (!ids.length) return Promise.resolve();

                return Promise.all(ids.map(function(id) {
                    return self.saveProduct(self.productById(id) || (self.selectedProduct && Number(self.selectedProduct.id) === Number(id) ? self.selectedProduct : {id: Number(id)}));
                })).catch(function(error) {
                    self.error = error && error.message ? error.message : self.t.error;
                    throw error;
                });
            },
            setProductEditMode: function(enabled) {
                var self = this;
                enabled = !!enabled;
                if (enabled || !this.productPendingCount) {
                    this.productEditMode = enabled;
                    this.editingCell = null;
                    this.selectCell = null;
                    return Promise.resolve();
                }

                return this.saveAllProductEdits().then(function() {
                    self.productEditMode = false;
                    self.editingCell = null;
                    self.selectCell = null;
                }).catch(function() {
                    self.productEditMode = true;
                });
            },
            setProductAutosave: function(enabled) {
                this.productAutosave = !!enabled;
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
                var rtl = (document.documentElement.dir || '').toLowerCase() === 'rtl';
                event.preventDefault();
                event.stopPropagation();
                this.resizingColumn = column.key;
                function move(moveEvent) {
                    var delta = moveEvent.clientX - startX;
                    column.width = Math.max(72, startWidth + (rtl ? -delta : delta));
                }
                function up() {
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', up);
                    self.resizingColumn = '';
                    kind === 'user'
                        ? window.localStorage.setItem('digitalogic_panel_user_columns', JSON.stringify(self.userColumns))
                        : window.localStorage.setItem('digitalogic_panel_product_columns', JSON.stringify(self.productColumns));
                }
                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', up);
            },
            autoResizeColumn: function(kind, column) {
                if (!column) return;
                var table = document.querySelector('[data-grid-kind="' + kind + '"]');
                if (!table) return;
                var max = 72;
                var header = table.querySelector('th[data-column-key="' + column.key + '"] .dlp-th-label');
                if (header) {
                    max = Math.max(max, header.scrollWidth + 58);
                }
                table.querySelectorAll('tbody td[data-column-key="' + column.key + '"]').forEach(function(cell) {
                    var target = cell.querySelector('.dlp-editable-cell, .dlp-cell-input, .dlp-cell-edit-shell') || cell;
                    max = Math.max(max, target.scrollWidth + 28);
                });
                column.width = Math.max(72, Math.min(620, Math.ceil(max)));
                if (kind === 'user') {
                    this.userColumns = this.userColumns.slice();
                    window.localStorage.setItem('digitalogic_panel_user_columns', JSON.stringify(this.userColumns));
                } else {
                    this.productColumns = this.productColumns.slice();
                    window.localStorage.setItem('digitalogic_panel_product_columns', JSON.stringify(this.productColumns));
                }
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
                if (kind === 'product' || !event.shiftKey) state = item ? [item] : [];
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
                    this.queueProductReload(true);
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
            setThemeChoice: function(option) {
                if (!option) return;
                this.styleMode = normalizeStyleMode(option.style);
                this.theme = option.theme === 'dark' ? 'dark' : 'light';
                this.openMenu = '';
                this.saveThemeChoice(this.theme);
            },
            saveThemeChoice: function(theme) {
                var self = this;
                var value = theme === 'dark' ? 'dark' : 'light';

                broadcastTheme(value);

                return this.run('digitalogic_panel_set_theme', {theme: value}).then(function(response) {
                    if (response && response.theme) {
                        config.theme_mode = response.theme;
                        config.admin_color = response.admin_color || config.admin_color;
                    }
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                });
            },
            toggleRowMenu: function(kind, id, event) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                if (kind === 'product') {
                    var product = this.productById(id);
                    if (product && event) {
                        this.openProductRowContext(product, event);
                        return;
                    }
                }
                var key = kind + ':' + id;
                this.openRowMenu = this.openRowMenu === key ? '' : key;
            },
            cardAction: function(card, action) {
                this.openMenu = '';
                if (action === 'edit' && card.editable) this.startCardEdit(card, null);
                if (action === 'products') this.navigate('/products');
            },
            caretFromPoint: function(input, clientX) {
                var value = String(input.value || '');
                if (typeof clientX !== 'number' || !value.length) {
                    return value.length;
                }
                var rect = input.getBoundingClientRect();
                var ratio = Math.max(0, Math.min(1, (clientX - rect.left) / Math.max(1, rect.width)));
                return Math.max(0, Math.min(value.length, Math.round(value.length * ratio)));
            },
            startCardEdit: function(card, event) {
                if (!card.editable) return;
                var clickX = event && typeof event.clientX === 'number' ? event.clientX : null;
                this.editingCell = 'card:' + card.key;
                this.currencyEditField = card.field;
                this.currencyEditOriginal = this.normalizeNumber(this.currencyDraft[card.field]);
                this.$nextTick(function() {
                    var input = document.querySelector('[data-card-key="' + card.key + '"]');
                    if (input) {
                        input.focus();
                        if (typeof input.setSelectionRange === 'function') {
                            var caret = this.caretFromPoint(input, clickX);
                            input.setSelectionRange(caret, caret);
                        }
                    }
                }.bind(this));
            },
            isCardEditing: function(card) {
                return this.editingCell === 'card:' + card.key;
            },
            onCurrencyInput: function(field, event) {
                var raw = this.formatNumericEvent(event, arguments.length > 2 ? arguments[2] : false);
                this.currencyDraft[field] = raw;
            },
            finishCurrencyField: function(field) {
                if (this.currencyEditField === field) {
                    this.discardCurrencyEdit(field);
                }
            },
            cancelCurrencyField: function(field) {
                this.discardCurrencyEdit(field);
            },
            discardCurrencyEdit: function(field) {
                var currency = (this.summary && this.summary.currency) || {};
                if (field) {
                    this.currencyDraft[field] = currency[field] || '';
                } else {
                    this.resetCurrencyDraft();
                }
                this.editingCell = null;
                this.currencyEditField = '';
                this.currencyEditOriginal = '';
            },
            saveCurrencyField: function(field) {
                var current = this.normalizeNumber(this.currencyDraft[field]);
                if (current === this.currencyEditOriginal) {
                    this.editingCell = null;
                    this.currencyEditField = '';
                    this.currencyEditOriginal = '';
                    return Promise.resolve();
                }
                this.currencyEditField = '';
                return this.saveCurrency(field);
            },
            resetCurrencyDraft: function() {
                var currency = (this.summary && this.summary.currency) || {};
                this.currencyDraft = {
                    dollar_price: currency.dollar_price || '',
                    yuan_price: currency.yuan_price || ''
                };
            },
            saveCurrency: function(field) {
                var self = this;
                var payload = {};
                if (field) {
                    payload[field] = self.normalizeNumber(self.currencyDraft[field]);
                } else {
                    payload = {
                        dollar_price: self.normalizeNumber(self.currencyDraft.dollar_price),
                        yuan_price: self.normalizeNumber(self.currencyDraft.yuan_price)
                    };
                }
                if (!Object.keys(payload).length) return Promise.resolve();
                self.saving = true;
                return self.run('digitalogic_update_currency', payload).then(function() {
                    self.editingCell = null;
                    self.currencyEditField = '';
                    self.currencyEditOriginal = '';
                    return self.loadSummary();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.saving = false;
                });
            },
            routeHref: function(path) {
                return (config.panel_url || '/panel/').replace(/\/+$/, '') + (path || '/');
            },
            navigateClick: function(path, event) {
                if (event && (event.button === 1 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)) {
                    return;
                }
                if (event) event.preventDefault();
                this.navigate(path);
            },
            viewProduct: function(product) {
                window.open(product.canonical_url || product.permalink || ('/?p=' + product.id), '_blank', 'noopener');
            },
            handleProductEditClick: function(product, event) {
                if (event) event.preventDefault();
                if (event && event.altKey) {
                    this.editProductPage(product);
                    return;
                }
                if (event && (event.ctrlKey || event.metaKey)) {
                    this.navigate('/products/' + product.id);
                    return;
                }
                if (event && event.shiftKey) {
                    this.openProductDialog(product);
                    return;
                }
                this.openProductPanel(product);
            },
            openProductPanel: function(product) {
                var options = arguments.length > 1 && arguments[1] ? arguments[1] : {};
                var self = this;
                var open = function() {
                    self.selectedProduct = product;
                    self.pinnedEditorPinned = true;
                    window.localStorage.setItem('digitalogic_panel_pinned_editor', '1');
                    self.productDialogOpen = false;
                    self.loadProduct(product.id);
                };
                if (options.preserveScroll) {
                    this.withProductScrollAnchor(product, open, !!options.reveal);
                } else {
                    open();
                    if (options.reveal) {
                        this.$nextTick(function() {
                            self.revealProductEditor();
                        });
                    }
                }
            },
            openProductDialog: function(product) {
                this.selectedProduct = product;
                this.productDialogOpen = true;
                this.loadProduct(product.id);
            },
            editProductPage: function(product) {
                window.open(product.edit_url || ('/wp-admin/post.php?post=' + encodeURIComponent(product.id) + '&action=edit'), '_blank', 'noopener');
            },
            togglePinnedEditor: function() {
                var self = this;
                this.withProductScrollAnchor(this.selectedProduct, function() {
                    self.pinnedEditorPinned = !self.pinnedEditorPinned;
                    window.localStorage.setItem('digitalogic_panel_pinned_editor', self.pinnedEditorPinned ? '1' : '0');
                }, false);
            },
            openProductToolbox: function(product) {
                if (!product || !product.id) return;
                window.open(this.routeHref('/products/' + product.id + '?toolbox=1'), 'digitalogic-product-toolbox-' + product.id, 'width=1100,height=760,menubar=no,toolbar=no,location=no,status=no');
            },
            productById: function(id) {
                id = Number(id);
                return this.products.find(function(product) {
                    return Number(product.id) === id;
                }) || null;
            },
            selectProductRow: function(product) {
                if (!product || !product.id) return;
                if (!this.selectedProduct || Number(this.selectedProduct.id) !== Number(product.id)) {
                    this.openProductPanel(product, {preserveScroll: true});
                }
            },
            productRowElement: function(product) {
                if (!product || !product.id) return null;
                return document.querySelector('[data-product-row="' + product.id + '"]');
            },
            withProductScrollAnchor: function(product, callback, reveal) {
                var row = this.productRowElement(product);
                var before = row ? row.getBoundingClientRect().top : null;
                callback();
                this.$nextTick(function() {
                    var nextRow = this.productRowElement(product);
                    if (nextRow && before !== null) {
                        var after = nextRow.getBoundingClientRect().top;
                        window.scrollBy(0, after - before);
                    }
                    if (reveal) {
                        this.revealProductEditor();
                    }
                }.bind(this));
            },
            revealProductEditor: function() {
                var editor = document.querySelector('.dlp-pinned-editor');
                if (editor) {
                    editor.scrollIntoView({block: 'start', inline: 'nearest', behavior: 'smooth'});
                }
            },
            openProductRowContext: function(product, event) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                this.selectProductRow(product);
                this.rowContext = {
                    kind: 'product',
                    id: product.id,
                    x: event ? event.clientX : 0,
                    y: event ? event.clientY : 0
                };
                this.openRowMenu = '';
            },
            closeRowContext: function() {
                this.rowContext = null;
            },
            rowContextProduct: function() {
                return this.rowContext ? this.productById(this.rowContext.id) : null;
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
            formatDateTime: function(value) {
                if (!value) return '-';
                var text = String(value).trim().replace(' ', 'T');
                var date = new Date(text);
                if (Number.isNaN(date.getTime())) {
                    date = new Date(String(value).replace(/-/g, '/'));
                }
                if (Number.isNaN(date.getTime())) return value;
                return new Intl.DateTimeFormat(this.lang === 'fa' ? 'fa-IR-u-ca-persian' : 'en-US', {
                    dateStyle: 'medium',
                    timeStyle: 'short'
                }).format(date);
            },
            logActionLabel: function(value) {
                return this.t[value] || this.t[String(value || '').replace(/[^a-z0-9_]/gi, '_')] || value || '-';
            },
            objectTypeLabel: function(value) {
                return this.t[value] || value || '-';
            },
            roleText: function(roles) {
                return (roles || []).join(', ') || '-';
            },
            statusLabel: function(value) {
                var option = this.productStatusOptions.find(function(item) {
                    return item.value === value;
                });
                return option ? option.label : (value || '-');
            },
            stockStatusLabel: function(value) {
                var option = this.stockStatusOptions.find(function(item) {
                    return item.value === value;
                });
                return option ? option.label : (value || '-');
            },
            statusTone: function(value) {
                if (value === 'publish' || value === 'instock') return 'success';
                if (value === 'pending' || value === 'onbackorder') return 'warning';
                if (value === 'draft') return 'muted';
                if (value === 'private') return 'private';
                if (value === 'outofstock') return 'danger';
                return 'muted';
            },
            statusIcon: function(value) {
                if (value === 'publish' || value === 'instock') return 'dashicons-yes-alt';
                if (value === 'pending' || value === 'onbackorder') return 'dashicons-clock';
                if (value === 'outofstock') return 'dashicons-warning';
                if (value === 'private') return 'dashicons-lock';
                return 'dashicons-marker';
            },
            columnOptions: function(column) {
                if (column.field === 'status') return this.productStatusOptions;
                if (column.field === 'stock_status') return this.stockStatusOptions;
                if (column.field === 'type') return this.productTypeOptions;
                if (column.field === 'role') return this.userRoleOptions;
                return [];
            },
            customSelectLabel: function(options, value) {
                var option = (options || []).find(function(item) {
                    return String(item.value) === String(value);
                });
                return option ? option.label : (value || '-');
            },
            openSelectCell: function(kind, row, column, event) {
                if (!row || !column || !column.editable || column.type !== 'select') return;
                if (kind === 'product' && !this.productEditMode) return;
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                if (kind === 'product') this.selectProductRow(row);
                var rect = event && event.currentTarget ? event.currentTarget.getBoundingClientRect() : {left: 0, bottom: 0, width: 180};
                this.selectCell = {
                    kind: kind,
                    rowId: row.id,
                    columnKey: column.key,
                    field: column.field,
                    x: rect.left,
                    y: rect.bottom + 6,
                    width: rect.width
                };
                this.editingCell = null;
            },
            selectCellColumn: function() {
                if (!this.selectCell) return null;
                var list = this.selectCell.kind === 'user' ? this.userColumns : this.productColumns;
                return list.find(function(column) {
                    return column.key === this.selectCell.columnKey;
                }, this);
            },
            selectCellRow: function() {
                if (!this.selectCell) return null;
                var rows = this.selectCell.kind === 'user' ? this.users : this.products;
                return rows.find(function(row) {
                    return Number(row.id) === Number(this.selectCell.rowId);
                }, this) || null;
            },
            selectCellOptions: function() {
                var column = this.selectCellColumn();
                return column ? this.columnOptions(column) : [];
            },
            applySelectCellValue: function(value) {
                var row = this.selectCellRow();
                var column = this.selectCellColumn();
                if (!row || !column) return;
                if (this.selectCell.kind === 'user') {
                    this.editUser(row, column.field, value);
                    this.flushUserSave(row);
                } else {
                    this.editProduct(row, column.field, value);
                    if (this.productAutosave) this.flushProductSave(row);
                }
                this.selectCell = null;
            },
            isLatinText: function(value) {
                value = String(value || '').trim();
                return value !== '' && !/[\u0600-\u06FF]/.test(value);
            },
            fieldIcon: function(name) {
                var map = {
                    name: 'dashicons-products',
                    sku: 'dashicons-editor-code',
                    part_number: 'dashicons-tag',
                    type: 'dashicons-category',
                    status: 'dashicons-visibility',
                    stock_status: 'dashicons-yes-alt',
                    regular_price: 'dashicons-money-alt',
                    sale_price: 'dashicons-tickets-alt',
                    stock_quantity: 'dashicons-archive',
                    category_ids: 'dashicons-category',
                    total_sales: 'dashicons-chart-line',
                    revisions: 'dashicons-backup'
                };
                return map[name] || 'dashicons-edit';
            },
            cellClass: function(column) {
                return {
                    'dlp-cell-mono': !!column.mono,
                    'dlp-cell-numeric': !!column.numeric,
                    'dlp-cell-title': column.field === 'name',
                    'dlp-cell-select-like': column.type === 'select',
                    'dlp-cell-email': column.field === 'email',
                    'is-resizing': this.resizingColumn === column.key
                };
            },
            titleClass: function(product) {
                return {
                    'dlp-latin-title': this.isLatinText(product.name)
                };
            },
            categoryOptions: function() {
                return (this.summary && this.summary.categories) || [];
            },
            selectedCategoryIds: function(product) {
                return (product && Array.isArray(product.category_ids)) ? product.category_ids.map(String) : [];
            },
            categoryNames: function(product) {
                if (!product || !Array.isArray(product.categories) || !product.categories.length) {
                    return '-';
                }
                return product.categories.map(function(category) {
                    return category.name;
                }).join(', ');
            },
            onCategoryChange: function(product, event) {
                var values = Array.prototype.slice.call(event.target.options)
                    .filter(function(option) { return option.selected; })
                    .map(function(option) { return parseInt(option.value, 10); })
                    .filter(Boolean);
                this.editProduct(product, 'category_ids', values);
            },
            isProductSelected: function(product) {
                return !!this.selectedProducts[product.id];
            },
            setImageFilter: function(value) {
                this.imageFilter = value || 'all';
                this.openMenu = '';
            },
            applyBulkAction: function(action) {
                var ids = this.selectedProductIds;
                var self = this;
                if (!ids.length) return;
                if (action === 'export') {
                    return this.run('digitalogic_export', {format: 'csv', product_ids: ids}).then(function(response) {
                        if (response && response.url) window.open(response.url, '_blank', 'noopener');
                    });
                }
                if (!this.productEditMode) return;
                var data = {};
                if (action === 'publish') data.status = 'publish';
                if (action === 'draft') data.status = 'draft';
                if (action === 'instock') data.stock_status = 'instock';
                if (action === 'outofstock') data.stock_status = 'outofstock';
                if (!Object.keys(data).length) return;
                var updates = {};
                ids.forEach(function(id) {
                    updates[id] = data;
                    var product = self.productById(id);
                    if (product) Object.assign(product, data);
                });
                this.openMenu = '';
                return this.run('digitalogic_bulk_update', {updates: updates}).then(function() {
                    self.selectedProducts = {};
                    return self.loadProducts();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                });
            },
            setProductFilter: function(key, value) {
                var next = Object.assign({}, this.productFilters);
                if (value === '' || value === null || typeof value === 'undefined') {
                    delete next[key];
                } else {
                    next[key] = value;
                }
                this.productFilters = next;
            },
            setRangeFilter: function(key, bound, event) {
                var raw = this.formatNumericEvent(event);
                var current = typeof this.productFilters[key] === 'object' && this.productFilters[key] !== null
                    ? Object.assign({}, this.productFilters[key])
                    : {};
                if (raw === '') {
                    delete current[bound];
                } else {
                    current[bound] = raw;
                }
                var next = Object.assign({}, this.productFilters);
                if (!current.min && !current.max) delete next[key];
                else next[key] = current;
                this.productFilters = next;
            },
            rangeFilterValue: function(key, bound) {
                var filter = this.productFilters[key];
                return filter && typeof filter === 'object' && filter[bound] ? this.formatInputNumber(filter[bound]) : '';
            },
            clearProductFilters: function() {
                this.productFilters = {};
                this.imageFilter = 'all';
                window.localStorage.setItem('digitalogic_panel_image_filter', 'all');
            },
            openColumnContext: function(kind, column, event) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                this.columnContext = {
                    kind: kind,
                    columnKey: column.key,
                    x: event.clientX,
                    y: event.clientY
                };
            },
            contextColumn: function() {
                if (!this.columnContext) return null;
                var list = this.columnContext.kind === 'user' ? this.userColumns : this.productColumns;
                return list.find(function(column) {
                    return column.key === this.columnContext.columnKey;
                }, this);
            },
            contextSort: function(direction) {
                var column = this.contextColumn();
                if (!column || !column.sortable) return;
                var sort = {key: column.key, field: column.field, direction: direction};
                if (this.columnContext.kind === 'user') {
                    this.userSortState = [sort];
                    window.localStorage.setItem('digitalogic_panel_user_sorts', JSON.stringify(this.userSortState));
                } else {
                    this.sortState = [sort];
                    window.localStorage.setItem('digitalogic_panel_product_sorts', JSON.stringify(this.sortState));
                    this.queueProductReload(true);
                }
                this.columnContext = null;
            },
            hideContextColumn: function() {
                var column = this.contextColumn();
                if (!column) return;
                this.toggleColumn(this.columnContext.kind, column);
                this.columnContext = null;
            },
            clearContextFilter: function() {
                if (!this.columnContext || this.columnContext.kind !== 'product') {
                    this.columnContext = null;
                    return;
                }
                this.setProductFilter(this.columnContext.columnKey, '');
                this.columnContext = null;
            },
            selectedUserRole: function(user) {
                return user && Array.isArray(user.roles) && user.roles.length ? user.roles[0] : 'customer';
            },
            openUserPanel: function(user) {
                this.selectedUser = Object.assign({}, user);
                this.userEditorMode = user && user.id ? 'edit' : 'create';
                this.userDialogOpen = false;
                this.loadUserOrders(user);
            },
            openUserDialog: function(user) {
                this.openUserPanel(user);
                this.userDialogOpen = true;
            },
            startCreateUser: function() {
                this.selectedUser = {id: 0, login: '', email: '', display_name: '', roles: ['customer']};
                this.userEditorMode = 'create';
                this.userOrders = [];
            },
            editSelectedUser: function(field, value) {
                if (!this.selectedUser) return;
                if (field === 'role') {
                    this.selectedUser.roles = [value];
                    return;
                }
                this.selectedUser[field] = value;
            },
            saveUserDetails: function() {
                if (!this.selectedUser) return Promise.resolve();
                var self = this;
                var data = {
                    login: this.selectedUser.login,
                    email: this.selectedUser.email,
                    display_name: this.selectedUser.display_name,
                    role: this.selectedUserRole(this.selectedUser)
                };
                var command = this.selectedUser.id ? 'digitalogic_panel_update_user' : 'digitalogic_panel_create_user';
                var payload = this.selectedUser.id ? {user_id: this.selectedUser.id, data: data} : {data: data};
                this.saving = true;
                return this.run(command, payload).then(function(response) {
                    self.selectedUser = response.user || self.selectedUser;
                    return self.loadUsers();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                }).finally(function() {
                    self.saving = false;
                });
            },
            deleteSelectedUser: function() {
                if (!this.selectedUser || !this.selectedUser.id || !window.confirm(this.t.confirmDeleteUser)) return;
                var self = this;
                return this.run('digitalogic_panel_delete_user', {user_id: this.selectedUser.id}).then(function() {
                    self.selectedUser = null;
                    self.userOrders = [];
                    return self.loadUsers();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                });
            },
            loadUserOrders: function(user) {
                if (!user || !user.id) {
                    this.userOrders = [];
                    return Promise.resolve();
                }
                var self = this;
                this.userOrderLoading = true;
                return this.run('digitalogic_panel_user_orders', {user_id: user.id}).then(function(response) {
                    self.userOrders = response.orders || [];
                }).catch(function() {
                    self.userOrders = [];
                }).finally(function() {
                    self.userOrderLoading = false;
                });
            },
            userById: function(id) {
                id = Number(id);
                return this.users.find(function(user) {
                    return Number(user.id) === id;
                }) || null;
            },
            deleteUserRow: function(user) {
                this.selectedUser = Object.assign({}, user);
                return this.deleteSelectedUser();
            },
            deleteSelectedUsers: function() {
                var self = this;
                var ids = this.selectedUserIds.slice();
                if (!ids.length || !window.confirm(this.t.confirmDeleteUser)) return Promise.resolve();
                var chain = Promise.resolve();
                ids.forEach(function(id) {
                    chain = chain.then(function() {
                        return self.run('digitalogic_panel_delete_user', {user_id: id});
                    });
                });
                return chain.then(function() {
                    self.selectedUsers = {};
                    self.selectedUser = null;
                    return self.loadUsers();
                }).catch(function(error) {
                    self.error = error.message || self.t.error;
                });
            }
        },
        template: document.getElementById('digitalogic-panel-template').innerHTML
    });

    app.config.errorHandler = function(error) {
        var message = error && (error.stack || error.message || String(error));
        window.digitalogicPanelLastError = message;
        document.documentElement.setAttribute('data-dlp-last-error', message || 'Panel render error');
        if (window.console && typeof window.console.error === 'function') {
            window.console.error('Digitalogic panel render error', error);
        }
    };

    app.mount('#digitalogic-panel');
})(window, document);
