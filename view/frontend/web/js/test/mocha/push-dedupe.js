const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const moduleRoot = path.resolve(__dirname, '../../../../../..');

function createPusher(dedupeMode) {
    const window = {
        location: {pathname: '/category.html'},
        dataLayer: [],
        YIREO_GOOGLETAGMANAGER2_PAST_EVENTS: []
    };
    if (typeof dedupeMode !== 'undefined') {
        window.YIREO_GOOGLETAGMANAGER2_DEDUPE_MODE = dedupeMode;
    }

    const context = vm.createContext({
        window,
        console,
        encodeURIComponent,
        btoa(value) {
            return Buffer.from(value, 'binary').toString('base64');
        }
    });

    context.define = function (deps, factory) {
        context.__pusher = factory(function () {});
    };

    const source = fs.readFileSync(
        path.join(moduleRoot, 'view/frontend/web/js/push.js'),
        'utf8'
    );
    vm.runInContext(source, context, {filename: 'push.js'});

    return {
        push(eventData, message) {
            context.__pusher(eventData, message);
        },
        dataLayer: window.dataLayer,
        events(eventName) {
            return window.dataLayer.filter(
                entry => entry && typeof entry === 'object' && entry.event === eventName
            );
        }
    };
}

const addToCart = () => ({
    event: 'add_to_cart',
    ecommerce: {items: [{item_id: '42', quantity: 1}]}
});

const viewItemList = () => ({
    event: 'view_item_list',
    ecommerce: {items: [{item_id: '42'}]}
});

describe('push.js dedupe strategy', function () {
    it('pushes a second identical add_to_cart (default mode "event" exempts transactional events)', function () {
        const pusher = createPusher();
        pusher.push(addToCart(), 'test');
        pusher.push(addToCart(), 'test');

        assert.strictEqual(pusher.events('add_to_cart').length, 2);
    });

    it('pushes a second identical remove_from_cart', function () {
        const pusher = createPusher();
        const event = () => ({event: 'remove_from_cart', ecommerce: {items: [{item_id: '42'}]}});
        pusher.push(event(), 'test');
        pusher.push(event(), 'test');

        assert.strictEqual(pusher.events('remove_from_cart').length, 2);
    });

    it('dedupes a repeated identical view_item_list (default mode "event")', function () {
        const pusher = createPusher();
        pusher.push(viewItemList(), 'test');
        pusher.push(viewItemList(), 'test');

        assert.strictEqual(pusher.events('view_item_list').length, 1);
    });

    it('never dedupes when meta.dedupe is false, even for view_item_list', function () {
        const pusher = createPusher();
        const event = viewItemList();
        event.meta = {dedupe: false};
        pusher.push(event, 'test');
        pusher.push(JSON.parse(JSON.stringify(event)), 'test');

        assert.strictEqual(pusher.events('view_item_list').length, 2);
    });

    it('dedupes add_to_cart when meta.dedupe is true (per-event override)', function () {
        const pusher = createPusher();
        const event = addToCart();
        event.meta = {dedupe: true};
        pusher.push(event, 'test');
        pusher.push(JSON.parse(JSON.stringify(event)), 'test');

        assert.strictEqual(pusher.events('add_to_cart').length, 1);
    });

    it('dedupes every event in mode "all" (legacy behavior)', function () {
        const pusher = createPusher('all');
        pusher.push(addToCart(), 'test');
        pusher.push(addToCart(), 'test');

        assert.strictEqual(pusher.events('add_to_cart').length, 1);
    });

    it('never dedupes in mode "none"', function () {
        const pusher = createPusher('none');
        pusher.push(viewItemList(), 'test');
        pusher.push(viewItemList(), 'test');

        assert.strictEqual(pusher.events('view_item_list').length, 2);
    });

    it('skips a payload without event data', function () {
        const pusher = createPusher();
        pusher.push({meta: {foo: 'bar'}}, 'test');

        assert.strictEqual(pusher.dataLayer.length, 0);
    });

    it('clears the previous ecommerce object before pushing an ecommerce event', function () {
        const pusher = createPusher();
        pusher.push(viewItemList(), 'test');

        const cleared = pusher.dataLayer.filter(
            entry => entry && typeof entry === 'object' && 'ecommerce' in entry
                && entry.ecommerce === null
        );
        assert.strictEqual(cleared.length, 1);
    });
});
