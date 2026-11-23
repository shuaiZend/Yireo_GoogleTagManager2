<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Plugin;

use Magento\Checkout\Api\Data\PaymentDetailsInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\GuestCartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Yireo\GoogleTagManager2\Api\CheckoutSessionDataProviderInterface;
use Yireo\GoogleTagManager2\DataLayer\Event\AddShippingInfo;

class TriggerAddShippingInfoDataLayerEvent
{
    private CheckoutSessionDataProviderInterface $checkoutSessionDataProvider;
    private AddShippingInfo $addShippingInfo;
    private CheckoutSession $checkoutSession;
    private GuestCartRepositoryInterface $guestCartRepository;
    private CartRepositoryInterface $cartRepository;

    public function __construct(
        CheckoutSessionDataProviderInterface $checkoutSessionDataProvider,
        AddShippingInfo $addShippingInfo,
        CheckoutSession $checkoutSession,
        GuestCartRepositoryInterface $guestCartRepository,
        CartRepositoryInterface $cartRepository
    ) {
        $this->checkoutSessionDataProvider = $checkoutSessionDataProvider;
        $this->addShippingInfo = $addShippingInfo;
        $this->checkoutSession = $checkoutSession;
        $this->guestCartRepository = $guestCartRepository;
        $this->cartRepository = $cartRepository;
    }

    /**
     * The REST checkout used by the Magento 2.4 OnePage checkout does not put the guest quote in
     * Magento\Checkout\Model\Session before this plugin runs. Resolve the quote from the cart ID
     * (masked guest cart or numeric customer cart) and bind it to the checkout session so that
     * AddShippingInfo and its CartItems/CartValue tags can build the event.
     *
     * $subject is intentionally untyped: the same plugin is registered for both
     * ShippingInformationManagementInterface and GuestShippingInformationManagementInterface.
     *
     * @param object $subject
     * @param PaymentDetailsInterface $paymentDetails
     * @param mixed $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return PaymentDetailsInterface
     */
    public function afterSaveAddressInformation(
        $subject,
        PaymentDetailsInterface $paymentDetails,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        $quote = $this->resolveQuote($cartId);
        if ($quote instanceof Quote) {
            $this->checkoutSession->setQuoteId((int)$quote->getId());
        }

        $email = null;
        if ($addressInformation->getShippingAddress()) {
            $email = $addressInformation->getShippingAddress()->getEmail() ?: null;
        }
        if (!$email && $addressInformation->getBillingAddress()) {
            $email = $addressInformation->getBillingAddress()->getEmail() ?: null;
        }

        $event = $quote instanceof Quote
            ? $this->addShippingInfo->getForQuote($quote, $email)
            : $this->addShippingInfo->get();
        if (array_key_exists('event', $event)) {
            $this->checkoutSessionDataProvider->add('add_shipping_info_event', $event);
        }

        return $paymentDetails;
    }

    /**
     * @param mixed $cartId
     * @return Quote|null
     */
    private function resolveQuote($cartId): ?Quote
    {
        try {
            $quote = $this->guestCartRepository->get($cartId);
            if ($quote instanceof Quote) {
                return $quote;
            }
        } catch (\Exception $e) {
            // Not a masked guest cart; fall through to the customer cart repository.
        }

        try {
            $quote = $this->cartRepository->get((int)$cartId);
            if ($quote instanceof Quote) {
                return $quote;
            }
        } catch (\Exception $e) {
            // No quote could be resolved; the existing session-based behavior remains.
        }

        return null;
    }
}
