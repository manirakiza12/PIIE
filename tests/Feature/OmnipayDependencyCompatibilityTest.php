<?php
namespace Tests\Feature;
use Composer\InstalledVersions;
use Omnipay\Omnipay;
use PHPUnit\Framework\TestCase;
class OmnipayDependencyCompatibilityTest extends TestCase
{
    public function test_replacement_dependency_graph_retains_common_and_payment_driver_libraries(): void
    {
        foreach (['league/omnipay', 'omnipay/common', 'omnipay/paypal', 'php-http/discovery', 'php-http/guzzle7-adapter'] as $package) {
            $this->assertTrue(InstalledVersions::isInstalled($package), $package);
        }
        $this->assertFalse(InstalledVersions::isInstalled('omnipay/omnipay'));
    }
    public function test_paypal_driver_factories_remain_available_without_network_requests(): void
    {
        foreach (['Rest', 'Express', 'Pro'] as $driver) {
            $gateway = Omnipay::create('PayPal_'.$driver);
            $this->assertInstanceOf('Omnipay\\PayPal\\'.$driver.'Gateway', $gateway);
            $this->assertInstanceOf(\Omnipay\Common\GatewayInterface::class, $gateway);
        }
    }
}
