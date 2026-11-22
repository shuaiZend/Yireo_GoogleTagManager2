define(['yireoGoogleTagManagerLogger'], function (logger) {
    return function (eventData, message) {
        window.YIREO_GOOGLETAGMANAGER2_PAST_EVENTS = window.YIREO_GOOGLETAGMANAGER2_PAST_EVENTS || [];

        const metaData = Object.assign({}, eventData.meta);

        const cleanEventData = Object.assign({}, eventData);
        if (cleanEventData.meta) {
            delete cleanEventData.meta;
        }

        // Objects have no "length" property, so the previous check
        // (cleanEventData.length === 0) never matched. Compare the keys instead.
        if (Object.keys(cleanEventData).length === 0) {
            return;
        }

        if (metaData && metaData.allowed_pages && metaData.allowed_pages.length > 0
            && false === metaData.allowed_pages.some(page => window.location.pathname.includes(page))) {
            logger('Warning: Skipping event, not in allowed pages', window.location.pathname, eventData);
            return;
        }

        // Prevent the same event from being triggered twice, when containing the same data.
        // Whether the identical-JSON check applies is configurable:
        // - metaData.dedupe (boolean) wins over everything;
        // - window.YIREO_GOOGLETAGMANAGER2_DEDUPE_MODE = "all"|"event"|"none" (default "event").
        // With the default mode "event", transactional events (add_to_cart, remove_from_cart,
        // purchase, refund) are never dropped for having identical JSON: adding the same product
        // twice is two genuine events, not a duplicate.
        const eventName = cleanEventData.event || '';
        if (shouldDedupe(metaData, eventName)) {
            const eventHash = btoa(encodeURIComponent(JSON.stringify(cleanEventData)));
            if (window.YIREO_GOOGLETAGMANAGER2_PAST_EVENTS.includes(eventHash)) {
                logger('Warning: Event already triggered', eventData);
                return;
            }

            window.YIREO_GOOGLETAGMANAGER2_PAST_EVENTS.push(eventHash);
        }

        if (!message) {
            message = 'push (unknown) [unknown]';
        }

        logger(message, eventData);
        window.dataLayer = window.dataLayer || [];

        // Clear any previous ecommerce object first, so that non-ecommerce events
        // do not inherit the previous ecommerce payload.
        if (cleanEventData.ecommerce) {
            window.dataLayer.push({ecommerce: null});
        }

        window.dataLayer.push(cleanEventData);
    };

    function shouldDedupe(metaData, eventName) {
        if (metaData && typeof metaData.dedupe === 'boolean') {
            return metaData.dedupe;
        }

        const dedupeMode = window.YIREO_GOOGLETAGMANAGER2_DEDUPE_MODE || 'event';
        if (dedupeMode === 'none') {
            return false;
        }

        if (dedupeMode === 'all') {
            return true;
        }

        const transactionalEvents = ['add_to_cart', 'remove_from_cart', 'purchase', 'refund'];
        return transactionalEvents.indexOf(eventName) === -1;
    }
});
