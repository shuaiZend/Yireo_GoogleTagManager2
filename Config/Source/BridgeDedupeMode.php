<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BridgeDedupeMode implements OptionSourceInterface
{
    const MODE_EVENT = 'event';
    const MODE_ALL = 'all';
    const MODE_NONE = 'none';

    /**
     * {@inheritdoc}
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_EVENT, 'label' => __('Event (dedupe repeated payloads, except transactional events)')],
            ['value' => self::MODE_ALL, 'label' => __('All (legacy: dedupe every identical payload)')],
            ['value' => self::MODE_NONE, 'label' => __('None (never dedupe)')],
        ];
    }
}
