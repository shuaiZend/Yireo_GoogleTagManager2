<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Yireo\GoogleTagManager2\Config\Config;

class UserIdMode implements OptionSourceInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::USER_ID_MODE_NONE,
                'label' => __('None'),
            ],
            [
                'value' => Config::USER_ID_MODE_EMAIL_HASH,
                'label' => __('Email hash (HMAC-SHA256, guest + customer)'),
            ],
            [
                'value' => Config::USER_ID_MODE_CUSTOMER_ID,
                'label' => __('Magento customer ID (logged-in only)'),
            ],
        ];
    }
}
