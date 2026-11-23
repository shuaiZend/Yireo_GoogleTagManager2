<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Util;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Yireo\GoogleTagManager2\Config\Config;

/**
 * Resolves the GA4 user_id for logged-in customers, guest quotes and orders.
 *
 * email_hash mode keeps the same identity for a guest order and a later account with the
 * same email address. The email is never emitted: only HMAC-SHA256(normalized email) is used,
 * keyed with the Magento crypt key. Rotating the crypt key changes the user_id and is therefore
 * a deliberate identity reset.
 */
class UserIdentity
{
    private Config $config;
    private DeploymentConfig $deploymentConfig;

    public function __construct(
        Config $config,
        DeploymentConfig $deploymentConfig
    ) {
        $this->config = $config;
        $this->deploymentConfig = $deploymentConfig;
    }

    public function getMode(): string
    {
        return $this->config->getUserIdMode();
    }

    public function isEnabled(): bool
    {
        return $this->getMode() !== Config::USER_ID_MODE_NONE;
    }

    public function getForCustomer(CustomerInterface $customer): ?string
    {
        return $this->getForCustomerId((int)$customer->getId(), $customer->getEmail());
    }

    public function getForCustomerId(int $customerId, ?string $email = null): ?string
    {
        $mode = $this->getMode();
        if ($mode === Config::USER_ID_MODE_CUSTOMER_ID && $customerId > 0) {
            return 'cust:' . $customerId;
        }

        if ($mode === Config::USER_ID_MODE_EMAIL_HASH) {
            return $this->getForEmail($email);
        }

        return null;
    }

    public function getForOrder(OrderInterface $order): ?string
    {
        return $this->getForCustomerId((int)$order->getCustomerId(), $order->getCustomerEmail());
    }

    public function getForQuote(Quote $quote): ?string
    {
        $email = $quote->getCustomerEmail();
        if (!$email && $quote->getBillingAddress()) {
            $email = $quote->getBillingAddress()->getEmail();
        }
        if (!$email && $quote->getShippingAddress()) {
            $email = $quote->getShippingAddress()->getEmail();
        }

        return $this->getForCustomerId((int)$quote->getCustomerId(), $email);
    }

    public function getForEmail(?string $email): ?string
    {
        if ($this->getMode() !== Config::USER_ID_MODE_EMAIL_HASH || $email === null) {
            return null;
        }

        $email = mb_strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $cryptKey = (string)$this->deploymentConfig->get('crypt/key');
        if ($cryptKey === '') {
            return null;
        }

        return 'email:' . hash_hmac('sha256', $email, $cryptKey);
    }
}
