<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Config;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Yireo\GoogleTagManager2\Config\Source\ViewCartOccurancesOptions;

class Config implements ArgumentInterface
{
    public const ENVIRONMENT_AUTH_PATTERN = '/^[A-Za-z0-9_-]+$/D';
    public const ENVIRONMENT_PREVIEW_PATTERN = '/^env-[0-9]+$/D';

    public const USER_ID_MODE_NONE = 'none';
    public const USER_ID_MODE_CUSTOMER_ID = 'customer_id';
    public const USER_ID_MODE_EMAIL_HASH = 'email_hash';

    private ScopeConfigInterface $scopeConfig;
    private CookieHelper $cookieHelper;
    private StoreManagerInterface $storeManager;
    private AppState $appState;

    /**
     * Config constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CookieHelper $cookieHelper
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CookieHelper $cookieHelper,
        AppState $appState
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->cookieHelper = $cookieHelper;
        $this->appState = $appState;
    }

    /**
     * Check whether the module is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        $enabled = (bool)$this->getModuleConfigValue('enabled', false);
        if (false === $enabled) {
            return false;
        }

        if (false === $this->isDeveloperMode() && false === $this->isIdValid()) {
            return false;
        }

        return true;
    }

    /**
     * Check whether the module is in debugging mode
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return (bool)$this->getModuleConfigValue('debug');
    }

    /**
     * Wait for user interaction to start
     *
     * @return bool
     */
    public function waitForUserInteraction(): bool
    {
        return (bool)$this->getModuleConfigValue('wait_for_ui');
    }

    /**
     * Check whether the extension inserts the gtm.js container script itself
     *
     * @return bool
     */
    public function loadContainer(): bool
    {
        return (bool)$this->getModuleConfigValue('load_container');
    }

    /**
     * Check whether mouse clicks are debugged as well
     *
     * @return bool
     */
    public function isDebugClicks(): bool
    {
        return $this->isDeveloperMode() && $this->isDebug() && $this->getModuleConfigValue('debug_clicks');
    }

    /**
     * Return the GTM ID
     *
     * @return string
     */
    public function getId(): string
    {
        return (string)$this->getModuleConfigValue('id');
    }

    /**
     * Return the GTM IDs as a list, skipping empty values
     *
     * @return string[]
     */
    public function getIds(): array
    {
        $ids = array_map('trim', explode(',', $this->getId()));

        return array_values(array_filter($ids));
    }

    /**
     * Return the GTM environment query (gtm_auth, gtm_preview) or an empty string when not (properly) configured
     *
     * @return string
     */
    public function getEnvironmentQuery(): string
    {
        $auth = trim((string)$this->getModuleConfigValue('environment_auth'), " \n\r\t\v\0");
        $preview = trim((string)$this->getModuleConfigValue('environment_preview'), " \n\r\t\v\0");

        if (false === (bool)preg_match(self::ENVIRONMENT_AUTH_PATTERN, $auth)
            || false === (bool)preg_match(self::ENVIRONMENT_PREVIEW_PATTERN, $preview)) {
            return '';
        }

        return '&gtm_auth=' . rawurlencode($auth) . '&gtm_preview=' . rawurlencode($preview) . '&gtm_cookies_win=x';
    }

    /**
     * @return int
     */
    public function getMaximumCategoryProducts(): int
    {
        return (int)$this->getModuleConfigValue('category_products');
    }

    /**
     * @return string[]
     */
    public function getProductEavAttributeCodes(): array
    {
        return explode(',', (string)$this->getModuleConfigValue('product_eav_attributes'));
    }

    /**
     * @return string[]
     */
    public function getCategoryEavAttributeCodes(): array
    {
        return explode(',', (string)$this->getModuleConfigValue('category_eav_attributes'));
    }

    /**
     * @return string[]
     */
    public function getCustomerEavAttributeCodes(): array
    {
        return explode(',', (string)$this->getModuleConfigValue('customer_eav_attributes'));
    }

    /**
     * @return string
     */
    public function getStoreName(): string
    {
        $storeName = (string)$this->getConfigValue('general/store_information/name');
        if (!empty($storeName)) {
            return $storeName;
        }

        return (string)$this->storeManager->getDefaultStoreView()->getName();
    }

    /**
     * @return string
     */
    public function getCookieRestrictionModeName(): string
    {
        if ($this->cookieHelper->isCookieRestrictionModeEnabled()) {
            return CookieHelper::IS_USER_ALLOWED_SAVE_COOKIE;
        }

        return '';
    }

    /**
     * @return int
     */
    public function getCurrentWebsiteId(): int
    {
        return (int)$this->storeManager->getStore()->getWebsiteId();
    }

    /**
     * @return string
     */
    public function getViewCartOccurances(): string
    {
        return $this->getModuleConfigValue('view_cart_occurances');
    }

    /**
     * @return bool
     */
    public function showViewCartEventEverywhere(): bool
    {
        return $this->getViewCartOccurances() === ViewCartOccurancesOptions::EVERYWHERE;
    }

    /**
     * @return bool
     */
    public function showViewMiniCartOnExpandOnly(): bool
    {
        return (bool)$this->getModuleConfigValue('view_cart_on_mini_cart_expand_only');
    }

    /**
     * @return string
     */
    public function getProductListValueOnCategory(): string
    {
        return (string)$this->getModuleConfigValue('product_list_value_on_category');
    }

    public function hasServerSideTracking(): bool
    {
        return (bool)$this->getModuleConfigValue(
            'serverside_enabled',
            0
        );
    }

    /**
     *
     * Get the Google tag manager url. Defaults to googletagmanager.com. when field is filled return that url.
     *
     * @return string
     */
    public function getGoogleTagmanagerUrl(): string
    {
        $default = 'https://www.googletagmanager.com';
        if (false === $this->hasServerSideTracking()) {
            return $default;
        }

        return $this->getModuleConfigValue(
            'serverside_gtm_url',
            $default
        );
    }

    public function getOrderStatesForPurchaseEvent(): array
    {
        if (false === $this->hasServerSideTracking()) {
            return [];
        }

        return explode(',', (string)$this->getModuleConfigValue('order_states_for_purchase_event'));
    }

    /**
     * Check whether to use base currency for purchase data
     *
     * @return bool
     */
    public function useBaseCurrency(): bool
    {
        return (bool)$this->getModuleConfigValue('use_base_currency', false);
    }

    /**
     * Get the maximum transaction value threshold
     *
     * @return float
     */
    public function getMaxTransactionValue(): float
    {
        return (float)$this->getModuleConfigValue('max_transaction_value', 0);
    }

    /**
     * Check whether to include shipping in adjusted total
     *
     * @return bool
     */
    public function includeShippingInAdjustedValue(): bool
    {
        return (bool)$this->getModuleConfigValue('include_shipping_in_adjusted_value', false);
    }

    /**
     * How the GA4 user_id should be derived. The default is none: no identity is emitted unless
     * the merchant explicitly opts in to customer_id or email_hash.
     *
     * @return string
     */
    public function getUserIdMode(): string
    {
        $mode = (string)$this->getModuleConfigValue('user_id_mode', self::USER_ID_MODE_NONE);
        $allowed = [
            self::USER_ID_MODE_NONE,
            self::USER_ID_MODE_CUSTOMER_ID,
            self::USER_ID_MODE_EMAIL_HASH,
        ];

        return in_array($mode, $allowed, true) ? $mode : self::USER_ID_MODE_NONE;
    }

    /**
     * Return a configuration value
     *
     * @param string $key
     * @param null $defaultValue
     *
     * @return mixed|null
     */
    public function getModuleConfigValue(string $key, $defaultValue = null, $group = 'settings')
    {
        return $this->getConfigValue('googletagmanager2/' . $group . '/' . $key, $defaultValue);
    }

    /**
     * Return a configuration value
     *
     * @param string $key
     * @param null $defaultValue
     *
     * @return mixed|null
     */
    public function getConfigValue(string $key, $defaultValue = null)
    {
        try {
            $value = $this->scopeConfig->getValue(
                $key,
                ScopeInterface::SCOPE_STORE,
                $this->storeManager->getStore()
            );
        } catch (NoSuchEntityException $e) {
            return $defaultValue;
        }

        if (empty($value)) {
            $value = $defaultValue;
        }

        return $value;
    }

    /**
     * @return bool
     */
    private function isDeveloperMode(): bool
    {
        return $this->appState->getMode() === AppState::MODE_DEVELOPER;
    }

    /**
     * @return bool
     */
    private function isIdValid(): bool
    {
        return 0 === strpos($this->getId(), 'GTM-');
    }

    /**
     * Check whether the consent component is enabled
     *
     * @return bool
     */
    public function isConsent(): bool
    {
        return (bool)$this->getModuleConfigValue(
            'enabled',
            false,
            'consent'
        );
    }

    /**
     *
     * Get the privacy statement url.
     *
     * @return string|null
     */
    public function getPrivacyStatementUrl(): ?string
    {
        return $this->getModuleConfigValue(
            'privacy_statement_url',
            null,
            'consent'
        );
    }

    /**
     * Check whether the GIZTOP athlete2 bridge (infinite scroll, quickview, layered navigation)
     * is enabled. It defaults to "off" so that a fresh installation does not start emitting
     * bridge events before the theme selectors have been verified on the target store.
     *
     * @return bool
     */
    public function isBridgeEnabled(): bool
    {
        return (bool)$this->getModuleConfigValue('enabled', false, 'giztop_bridge');
    }

    /**
     * @return string
     */
    public function getBridgeProductSelector(): string
    {
        return (string)$this->getModuleConfigValue(
            'product_selector',
            'input[name="product"]',
            'giztop_bridge'
        );
    }

    /**
     * @return string
     */
    public function getBridgeGridSelector(): string
    {
        return (string)$this->getModuleConfigValue(
            'grid_selector',
            '.column.main .products.products-grid, .column.main .products.products-list,'
            . ' .column.main .products-grid.grid',
            'giztop_bridge'
        );
    }

    /**
     * @return string
     */
    public function getBridgeItemSelector(): string
    {
        return (string)$this->getModuleConfigValue(
            'item_selector',
            '.column.main .item.product.product-item',
            'giztop_bridge'
        );
    }

    /**
     * @return string
     */
    public function getBridgeColumnSelector(): string
    {
        return (string)$this->getModuleConfigValue(
            'column_selector',
            '.column.main',
            'giztop_bridge'
        );
    }

    /**
     * @return string
     */
    public function getBridgeQuickviewEvent(): string
    {
        return (string)$this->getModuleConfigValue(
            'quickview_event',
            'ajax:addToCart',
            'giztop_bridge'
        );
    }

    /**
     * Whether the bridge should emit add_to_cart for Quickview iframe submissions. Disabled by
     * default because the server-side checkout_cart_add_product_complete observer already emits
     * the authoritative event; enabling both produces a duplicate add_to_cart in the parent page.
     *
     * @return bool
     */
    public function isBridgeQuickviewAddToCartEnabled(): bool
    {
        return (bool)$this->getModuleConfigValue('quickview_add_to_cart', false, 'giztop_bridge');
    }

    /**
     * @return int
     */
    public function getBridgeDebounce(): int
    {
        return (int)$this->getModuleConfigValue('debounce', 150, 'giztop_bridge');
    }

    /**
     * Global dedupe mode for the JavaScript pusher: "all" (legacy identical-JSON dedupe for every
     * event), "event" (default: transactional events like add_to_cart are never deduped) or
     * "none" (no identical-JSON dedupe at all).
     *
     * @return string
     */
    public function getBridgeDedupeMode(): string
    {
        return (string)$this->getModuleConfigValue('dedupe_mode', 'event', 'giztop_bridge');
    }

    /**
     * @return bool
     */
    public function isBridgeDebug(): bool
    {
        return (bool)$this->getModuleConfigValue('debug', false, 'giztop_bridge');
    }
}
