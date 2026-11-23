<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\DataLayer\Event;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Yireo\GoogleTagManager2\Api\Data\EventInterface;
use Yireo\GoogleTagManager2\DataLayer\Mapper\CustomerDataMapper;
use Yireo\GoogleTagManager2\Util\UserIdentity;

class Login implements EventInterface
{
    private CustomerInterface $customer;
    private CustomerDataMapper $customerDataMapper;
    private UserIdentity $userIdentity;

    public function __construct(
        CustomerDataMapper $customerDataMapper,
        UserIdentity $userIdentity
    ) {
        $this->customerDataMapper = $customerDataMapper;
        $this->userIdentity = $userIdentity;
    }

    public function setCustomer(CustomerInterface $customer): Login
    {
        $this->customer = $customer;
        return $this;
    }

    public function get(): array
    {
        $event = [
            'event' => 'login',
            'customer' => $this->customerDataMapper->mapByCustomer($this->customer)
        ];

        $userId = $this->userIdentity->getForCustomer($this->customer);
        if ($userId !== null) {
            $event['user_id'] = $userId;
        }

        return $event;
    }
}
