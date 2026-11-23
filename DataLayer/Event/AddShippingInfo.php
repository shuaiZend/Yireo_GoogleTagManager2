<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\DataLayer\Event;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ShippingMethodManagementInterface;
use Yireo\GoogleTagManager2\Api\Data\EventInterface;
use Yireo\GoogleTagManager2\DataLayer\Mapper\CartItemDataMapper;
use Yireo\GoogleTagManager2\Util\PriceFormatter;

class AddShippingInfo implements EventInterface
{
    private ShippingMethodManagementInterface $shippingMethodManagement;
    private CheckoutSession $checkoutSession;
    private CartItemDataMapper $cartItemDataMapper;
    private PriceFormatter $priceFormatter;

    public function __construct(
        ShippingMethodManagementInterface $shippingMethodManagement,
        CheckoutSession $checkoutSession,
        CartItemDataMapper $cartItemDataMapper,
        PriceFormatter $priceFormatter
    ) {
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->checkoutSession = $checkoutSession;
        $this->cartItemDataMapper = $cartItemDataMapper;
        $this->priceFormatter = $priceFormatter;
    }

    /**
     * @return array
     */
    public function get(): array
    {
        if (false === $this->checkoutSession->hasQuote()) {
            return [];
        }

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (NoSuchEntityException|LocalizedException $e) {
            return [];
        }

        return $this->getForQuote($quote);
    }

    /**
     * Build add_shipping_info directly from a resolved quote. The REST checkout used by Magento
     * 2.4 does not populate Magento\Checkout\Model\Session with the guest quote, so the plugin
     * resolves the quote from the cart ID and calls this method instead of get().
     *
     * @param Quote $quote
     * @return array
     */
    public function getForQuote(Quote $quote): array
    {
        $shippingMethod = $this->getShippingMethodFromQuote($quote);
        if (empty($shippingMethod)) {
            return [];
        }

        $items = [];
        foreach ($quote->getAllVisibleItems() as $cartItem) {
            try {
                $items[] = $this->cartItemDataMapper->mapByCartItem($cartItem);
            } catch (LocalizedException $e) {
                // Keep the event usable even if one item cannot be mapped.
            }
        }

        return [
            'event' => 'add_shipping_info',
            'ecommerce' => [
                'currency' => $quote->getQuoteCurrencyCode(),
                'value' => $this->priceFormatter->format((float)$quote->getSubtotalWithDiscount()),
                'shipping_tier' => $shippingMethod,
                'items' => $items,
            ],
        ];
    }

    /**
     * @param Quote $quote
     * @return string|null
     */
    public function getShippingMethodFromQuote(Quote $quote): ?string
    {
        try {
            $shippingMethod = $this->shippingMethodManagement->get($quote->getId());
            if ($shippingMethod instanceof ShippingMethodInterface) {
                return $shippingMethod->getCarrierCode().'_'.$shippingMethod->getMethodCode();
            }
        } catch (NoSuchEntityException $e) {
        } catch (StateException $e) {
        }

        try {
            return $quote->getShippingAddress()->getShippingMethod();
        } catch (NoSuchEntityException $e) {
        }

        return null;
    }
}
