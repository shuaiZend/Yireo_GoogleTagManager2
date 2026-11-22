/**
 * GoogleTagManager2 plugin for Magento - GIZTOP theme bridge
 *
 * Bridges AJAX-driven product list updates and (optionally) Quickview add-to-cart events to the
 * Yireo GoogleTagManager2 data layer. It was written for the Olegnax Athlete2 theme
 * (Olegnax_InfiniteScroll, Olegnax_Quickview, Olegnax_LayeredNavigation) but every selector and
 * event name is configurable (etc/config.xml, group "giztop_bridge"), so the defaults are plain
 * Luma markup.
 *
 * - Infinite scroll: when Olegnax_InfiniteScroll appends or prepends
 *   `.item.product.product-item` tiles, only the newly added tiles are emitted as a delta
 *   `view_item_list`; the initial server-rendered list is never re-emitted.
 * - Layered navigation: when the query string changes and the result set changes, the new result
 *   set is emitted as a full `view_item_list`. A sort-only change (same items, different order)
 *   is not re-emitted.
 * - Quickview: disabled by default. The server-side checkout_cart_add_product_complete observer
 *   already emits the authoritative add_to_cart; enabling the bridge branch would duplicate it.
 *
 * The module is loaded through RequireJS via text/x-magento-init, so it does not introduce any
 * inline script bypassing CSP.
 *
 * @author      GIZTOP
 * @copyright   Copyright (c) 2026
 * @license     Open Software License
 */
define([
    'jquery',
    'underscore',
    'yireoGoogleTagManagerLogger',
    'yireoGoogleTagManagerPush'
], function ($, _, logger, pusher) {
    'use strict';

    return function (bridgeConfig) {
        var config = Object.assign({
            product_selector: 'input[name="product"]',
            item_selector: '.column.main .item.product.product-item',
            grid_selector: '.column.main .products.products-grid,'
                + ' .column.main .products.products-list, .column.main .products-grid.grid',
            column_selector: '.column.main',
            quickview_event: 'ajax:addToCart',
            quickview_add_to_cart: false,
            debounce: 150,
            debug: false
        }, bridgeConfig || {});

        var debugLog = function () {
            if (!config.debug) {
                return;
            }

            logger.apply(logger, arguments);
        };

        if (!('MutationObserver' in window)) {
            logger('Warning: MutationObserver is not supported, athlete2 bridge disabled');
            return;
        }

        var emittedKeys = new Set();
        var lastIds = [];
        var lastQuerySignature = null;
        var initialized = false;
        var listMeta = null;

        var currentPageParam = function () {
            try {
                return new URLSearchParams(window.location.search).get('p') || '1';
            } catch (e) {
                return '1';
            }
        };

        // The list identity must ignore pagination and sorting: infinite scroll changes "p" but is
        // the same list, and a sort-only change reorders the same items and must not re-emit.
        var querySignature = function () {
            var ignored = [
                'p', 'order', 'dir', 'mode', 'limit',
                'product_list_order', 'product_list_dir', 'product_list_mode', 'product_list_limit'
            ];
            var params = [];
            try {
                var searchParams = new URLSearchParams(window.location.search);
                searchParams.forEach(function (value, key) {
                    if (ignored.indexOf(key) === -1) {
                        params.push(key + '=' + value);
                    }
                });
            } catch (e) {
                // URLSearchParams unavailable: fall back to the pathname only.
            }
            params.sort();
            return window.location.pathname + (params.length > 0 ? '?' + params.join('&') : '');
        };

        var findListMeta = function () {
            if (!window.dataLayer) {
                return null;
            }

            for (var i = window.dataLayer.length - 1; i >= 0; i--) {
                var entry = window.dataLayer[i];
                if (!entry || !entry.ecommerce) {
                    continue;
                }
                if (entry.ecommerce.item_list_id || entry.ecommerce.item_list_name) {
                    return {
                        id: entry.ecommerce.item_list_id || entry.ecommerce.item_list_name,
                        name: entry.ecommerce.item_list_name || entry.ecommerce.item_list_id
                    };
                }
                if (entry.ecommerce.items && entry.ecommerce.items.length > 0) {
                    var first = entry.ecommerce.items[0];
                    if (first.item_list_id || first.item_list_name) {
                        return {
                            id: first.item_list_id || first.item_list_name,
                            name: first.item_list_name || first.item_list_id
                        };
                    }
                }
            }

            return null;
        };

        var hasServerViewItemList = function () {
            if (!window.dataLayer) {
                return false;
            }

            for (var i = window.dataLayer.length - 1; i >= 0; i--) {
                var entry = window.dataLayer[i];
                if (entry && entry.event === 'view_item_list') {
                    return true;
                }
            }

            return false;
        };

        var listId = function () {
            return listMeta && listMeta.id ? listMeta.id : window.location.pathname;
        };

        var itemKey = function (productId) {
            return listId() + '|' + querySignature() + '|' + productId;
        };

        var resolveProductId = function (itemElement) {
            var id = itemElement.getAttribute('data-entity-id') || itemElement.getAttribute('data-entity_id');
            if (id && /^\d+$/.test(id)) {
                return id;
            }

            var input = itemElement.querySelector(config.product_selector);
            if (input && /^\d+$/.test(input.value || '')) {
                return input.value;
            }

            return null;
        };

        var resolveSku = function (itemElement) {
            var sku = itemElement.getAttribute('data-product-sku');
            if (!sku) {
                var form = itemElement.querySelector('[data-product-sku]');
                if (form) {
                    sku = form.getAttribute('data-product-sku');
                }
            }

            return sku || null;
        };

        var collectItems = function (indexOffset) {
            var items = [];
            var seenIds = {};

            $(config.item_selector).each(function (index, itemElement) {
                var productId = resolveProductId(itemElement);
                var productData = productId
                    ? (window['YIREO_GOOGLETAGMANAGER2_PRODUCT_DATA_ID_' + productId] || {})
                    : {};

                // Keep one identifier type per event: the server uses the SKU as item_id. Never mix
                // the numeric entity ID with SKUs in the same view_item_list.
                var sku = productData.item_id || resolveSku(itemElement) || null;
                if (!sku || seenIds[sku]) {
                    return;
                }
                seenIds[sku] = true;

                var item = Object.assign({}, productData);
                item.item_id = sku;
                item.index = indexOffset + items.length + 1;
                if (listMeta && listMeta.id) {
                    item.item_list_id = item.item_list_id || listMeta.id;
                    item.item_list_name = item.item_list_name || listMeta.name || listMeta.id;
                }

                items.push(item);
            });

            return items;
        };

        var emitViewItemList = function (items) {
            if (items.length === 0) {
                return;
            }

            var ecommerce = {items: items};
            if (listMeta && listMeta.id) {
                ecommerce.item_list_id = listMeta.id;
                ecommerce.item_list_name = listMeta.name || listMeta.id;
            }

            pusher(
                {event: 'view_item_list', ecommerce: ecommerce},
                'push (view_item_list via bridge) [athlete2-bridge.js]'
            );
        };

        var remember = function (items) {
            items.forEach(function (item) {
                emittedKeys.add(itemKey(item.item_id));
            });
        };

        var scanAndEmit = function () {
            listMeta = findListMeta() || listMeta || {id: window.location.pathname, name: window.location.pathname};

            var items = collectItems(0);
            if (items.length === 0) {
                debugLog('Bridge scan found no product items yet', config.item_selector);
                return;
            }

            var ids = items.map(function (item) {
                return String(item.item_id);
            });
            var currentIdSet = new Set(ids);
            var lastIdSet = new Set(lastIds);
            var querySignatureNow = querySignature();

            if (!initialized) {
                initialized = true;
                lastIds = ids;
                lastQuerySignature = querySignatureNow;

                if (hasServerViewItemList()) {
                    // The server-rendered data layer already emitted the first screen.
                    remember(items);
                    debugLog('Bridge initialized, server view_item_list already present', ids.length);
                    return;
                }

                emitViewItemList(items);
                remember(items);
                debugLog('Bridge initialized and emitted ' + items.length + ' first-screen items');
                return;
            }

            var setChanged = ids.length !== lastIds.length
                || ids.some(function (id) { return !lastIdSet.has(id); })
                || lastIds.some(function (id) { return !currentIdSet.has(id); });
            var queryChanged = querySignatureNow !== lastQuerySignature;

            if (queryChanged) {
                if (setChanged) {
                    // Filter/clear change: the old list no longer describes the page.
                    emittedKeys.clear();
                    emitViewItemList(items);
                    remember(items);
                    debugLog('Bridge emitted a replaced result set of ' + items.length + ' items');
                } else {
                    debugLog('Bridge skipped a sort-only change (same item set)');
                }

                lastIds = ids;
                lastQuerySignature = querySignatureNow;
                return;
            }

            // Same query: infinite scroll append or prepend. Emit only items never emitted before.
            var newItems = items.filter(function (item) {
                return !emittedKeys.has(itemKey(item.item_id));
            });

            if (newItems.length > 0) {
                newItems.forEach(function (item, index) {
                    item.index = lastIds.length + index + 1;
                });
                emitViewItemList(newItems);
                remember(newItems);
                debugLog('Bridge emitted ' + newItems.length + ' appended/prepended items');
            }

            lastIds = ids;
            lastQuerySignature = querySignatureNow;
        };

        var debouncedScan = _.debounce(scanAndEmit, config.debounce);

        var attachListObserver = function () {
            var target = document.querySelector(config.column_selector)
                || document.querySelector(config.grid_selector)
                || document.body;

            if (!target) {
                logger('Warning: athlete2 bridge found no container to observe', config.column_selector);
                return;
            }

            var observer = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].addedNodes && mutations[i].addedNodes.length > 0) {
                        debouncedScan();
                        return;
                    }
                }
            });

            observer.observe(target, {childList: true, subtree: true});

            // Fallback for themes that replace the observed node itself (OXAjaxNav may replace
            // `.column.main`); Magento triggers contentUpdated after AJAX content is inserted.
            $(document).off('contentUpdated.yireoGtm2Bridge')
                .on('contentUpdated.yireoGtm2Bridge', debouncedScan);

            debugLog('Bridge observing container', target);
        };

        var findCurrency = function () {
            if (!window.dataLayer) {
                return null;
            }

            for (var i = window.dataLayer.length - 1; i >= 0; i--) {
                var entry = window.dataLayer[i];
                if (entry && entry.ecommerce && entry.ecommerce.currency) {
                    return entry.ecommerce.currency;
                }
            }

            return null;
        };

        var handleQuickviewAddToCart = function (payload) {
            if (!config.quickview_add_to_cart) {
                return;
            }

            try {
                payload = payload || {};

                // Olegnax Quickview triggers this event on the parent document with
                // {sku, productIds, form, response}; "form" is the iframe-local jQuery form.
                var formElement = payload.form && (payload.form[0] || payload.form);
                if (formElement && typeof formElement.querySelector !== 'function') {
                    formElement = null;
                }

                var productId = null;
                if (payload.productIds && payload.productIds.length > 0) {
                    productId = String(payload.productIds[0]);
                }
                if (!productId && formElement) {
                    var input = formElement.querySelector(config.product_selector);
                    if (input && /^\d+$/.test(input.value || '')) {
                        productId = input.value;
                    }
                }

                var sku = payload.sku
                    || (formElement ? formElement.getAttribute('data-product-sku') : null)
                    || null;

                if (!sku && !productId) {
                    logger('Warning: athlete2 bridge could not resolve a quickview product, skipping push');
                    return;
                }

                var productData = productId
                    ? (window['YIREO_GOOGLETAGMANAGER2_PRODUCT_DATA_ID_' + productId] || {})
                    : {};
                var item = Object.assign({}, productData);
                item.item_id = productData.item_id || sku || productId;

                var quantity = 1;
                if (formElement) {
                    var qtyInput = formElement.querySelector('input[name="qty"]');
                    var qtyValue = qtyInput ? parseFloat(qtyInput.value) : NaN;
                    if (!isNaN(qtyValue) && qtyValue > 0) {
                        quantity = qtyValue;
                    }
                }
                item.quantity = quantity;

                var ecommerce = {items: [item]};
                var currency = findCurrency();
                if (currency) {
                    ecommerce.currency = currency;
                }

                pusher(
                    {event: 'add_to_cart', ecommerce: ecommerce},
                    'push (add_to_cart via quickview bridge) [athlete2-bridge.js]'
                );
            } catch (e) {
                logger('Warning: athlete2 bridge quickview handler failed', e);
            }
        };

        var bindQuickviewEvent = function () {
            if (!config.quickview_add_to_cart || !config.quickview_event) {
                return;
            }

            $(document).off(config.quickview_event + '.yireoGtm2Bridge')
                .on(config.quickview_event + '.yireoGtm2Bridge', function (event, payload) {
                    handleQuickviewAddToCart(payload);
                });
        };

        // Initialize the list metadata before the first scan so the first push can use the same
        // item_list_id as the server-rendered data layer (when available).
        listMeta = findListMeta() || {id: window.location.pathname, name: window.location.pathname};

        attachListObserver();
        bindQuickviewEvent();
        scanAndEmit();
    };
});
