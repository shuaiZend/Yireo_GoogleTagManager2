/**
 * GoogleTagManager2 plugin for Magento - GIZTOP theme bridge
 *
 * Bridges AJAX-driven product list updates and Quickview add-to-cart events to the
 * Yireo GoogleTagManager2 data layer. It was written for the Olegnax Athlete2 theme
 * (Olegnax_InfiniteScroll, Olegnax_Quickview, Olegnax_LayeredNavigation) but every
 * selector and event name is configurable (etc/config.xml, group "giztop_bridge"),
 * so the defaults are plain Luma markup.
 *
 * - Infinite scroll: when Olegnax_InfiniteScroll appends `.item.product.product-item`
 *   tiles to the product grid, the appended tiles are emitted as a delta
 *   `view_item_list` (items of page 1 are never re-emitted, because the initial
 *   view_item_list is already pushed by the server-rendered data layer).
 * - Layered navigation: when Olegnax_LayeredNavigation replaces the main column
 *   (OXAjaxNav.replaceHTML), the new result set is emitted as a full
 *   `view_item_list`.
 * - Quickview: when the Quickview iframe submits its product form, Olegnax triggers
 *   `ajax:addToCart` on the parent document; this bridge converts it into an
 *   `add_to_cart` dataLayer event.
 *
 * Both observers share one debounced scan function, so a single DOM update can
 * never be pushed twice. The module is loaded through RequireJS via
 * text/x-magento-init, so it does not introduce any inline script bypassing CSP.
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

        var listId = window.location.pathname;
        var pageParam = '1';
        try {
            pageParam = new URLSearchParams(window.location.search).get('p') || '1';
        } catch (e) {
            debugLog('Warning: URLSearchParams unavailable, assuming page 1');
        }

        var itemKey = function (item) {
            return listId + '|p' + pageParam + '|' + item.item_id;
        };

        // First scan only records the initial list: the server-rendered data layer has already
        // pushed the initial view_item_list, so first-screen items must not be pushed again.
        var firstScanDone = false;
        var lastSignature = '';
        var emittedKeys = new Set();

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

        var collectItems = function () {
            var items = [];
            var seenIds = {};

            $(config.item_selector).each(function (index, itemElement) {
                var productId = resolveProductId(itemElement);
                if (!productId || seenIds[productId]) {
                    return;
                }

                seenIds[productId] = true;

                // The AddProductDetails plugin renders a per-product script for every list item,
                // providing the full GA4 item payload (name, price, categories, ...).
                var productData = window['YIREO_GOOGLETAGMANAGER2_PRODUCT_DATA_ID_' + productId] || {};
                var item = Object.assign({}, productData);
                item.item_id = productData.item_id || productId;
                item.index = items.length + 1;
                if (!item.item_list_id) {
                    item.item_list_id = listId;
                }
                if (!item.item_list_name) {
                    item.item_list_name = listId;
                }

                items.push(item);
            });

            return items;
        };

        var emitViewItemList = function (items) {
            pusher(
                {event: 'view_item_list', ecommerce: {items: items}},
                'push (view_item_list via bridge) [athlete2-bridge.js]'
            );
        };

        var scanAndEmit = function () {
            var items = collectItems();
            if (items.length === 0) {
                return;
            }

            var signature = items.map(function (item) {
                return item.item_id;
            }).join(',');

            if (!firstScanDone) {
                firstScanDone = true;
                lastSignature = signature;
                debugLog('Bridge initialized with ' + items.length + ' first-screen items', listId);
                return;
            }

            if (signature === lastSignature) {
                return;
            }

            if (signature.indexOf(lastSignature) === 0 && signature.length > lastSignature.length) {
                // Append-only change (infinite scroll): emit only the newly added items, so
                // that page 1 items are never pushed twice.
                var previousIds = lastSignature.split(',');
                var newItems = items.filter(function (item) {
                    return previousIds.indexOf(String(item.item_id)) === -1
                        && !emittedKeys.has(itemKey(item));
                });

                lastSignature = signature;
                newItems.forEach(function (item) {
                    emittedKeys.add(itemKey(item));
                });

                if (newItems.length > 0) {
                    emitViewItemList(newItems);
                }
                return;
            }

            // Replacement (layered navigation filter/sort): emit the full new result set.
            emittedKeys.clear();
            lastSignature = signature;
            emitViewItemList(items);
            items.forEach(function (item) {
                emittedKeys.add(itemKey(item));
            });
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
            payload = payload || {};

            // The Olegnax Quickview iframe triggers this event on the parent document with a
            // payload {sku, productIds, form, response}, where "form" is the iframe-local form.
            var formElement = payload.form && (payload.form[0] || payload.form);

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

            if (!productId && !sku) {
                logger(
                    'Warning: athlete2 bridge could not resolve the product of a quickview'
                    + ' add-to-cart, skipping push',
                    payload
                );
                return;
            }

            var quantity = 1;
            if (formElement) {
                var qtyInput = formElement.querySelector('input[name="qty"]');
                var qtyValue = qtyInput ? parseFloat(qtyInput.value) : NaN;
                if (!isNaN(qtyValue) && qtyValue > 0) {
                    quantity = qtyValue;
                }
            }

            var productData = productId
                ? (window['YIREO_GOOGLETAGMANAGER2_PRODUCT_DATA_ID_' + productId] || {})
                : {};
            var item = Object.assign({}, productData);
            item.item_id = productData.item_id || productId || sku;
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
        };

        var bindQuickviewEvent = function () {
            if (!config.quickview_event) {
                return;
            }

            $(document).off(config.quickview_event + '.yireoGtm2Bridge')
                .on(config.quickview_event + '.yireoGtm2Bridge', function (event, payload) {
                    handleQuickviewAddToCart(payload);
                });
        };

        attachListObserver();
        bindQuickviewEvent();
        scanAndEmit();
    };
});
