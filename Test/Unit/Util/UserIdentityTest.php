<?php declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Test\Unit\Util;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use Yireo\GoogleTagManager2\Config\Config;
use Yireo\GoogleTagManager2\Util\UserIdentity;

class UserIdentityTest extends TestCase
{
    public function testNoIdentityByDefault(): void
    {
        $identity = $this->createIdentity(Config::USER_ID_MODE_NONE, 'secret');

        $this->assertNull($identity->getForEmail('user@example.com'));
        $this->assertNull($identity->getForCustomerId(42, 'user@example.com'));
        $this->assertFalse($identity->isEnabled());
    }

    public function testEmailHashIsStableAndNormalised(): void
    {
        $identity = $this->createIdentity(Config::USER_ID_MODE_EMAIL_HASH, 'secret');

        $expected = 'email:' . hash_hmac('sha256', 'user@example.com', 'secret');
        $this->assertSame($expected, $identity->getForEmail(' User@Example.com '));
        $this->assertSame($expected, $identity->getForEmail('user@example.com'));
        $this->assertNotSame($expected, $identity->getForEmail('other@example.com'));
        $this->assertTrue($identity->isEnabled());
    }

    public function testInvalidEmailAndMissingCryptKeyReturnNull(): void
    {
        $identity = $this->createIdentity(Config::USER_ID_MODE_EMAIL_HASH, 'secret');
        $this->assertNull($identity->getForEmail('not-an-email'));

        $noKey = $this->createIdentity(Config::USER_ID_MODE_EMAIL_HASH, '');
        $this->assertNull($noKey->getForEmail('user@example.com'));
    }

    public function testCustomerIdMode(): void
    {
        $identity = $this->createIdentity(Config::USER_ID_MODE_CUSTOMER_ID, 'secret');

        $this->assertSame('cust:42', $identity->getForCustomerId(42, null));
        $this->assertNull($identity->getForCustomerId(0, null));
    }

    private function createIdentity(string $mode, string $cryptKey): UserIdentity
    {
        $config = $this->createMock(Config::class);
        $config->method('getUserIdMode')->willReturn($mode);

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('crypt/key')->willReturn($cryptKey);

        return new UserIdentity($config, $deploymentConfig);
    }
}
