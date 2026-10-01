<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Block\System\Config\Form\DeliveryCostsMatrix;
use MyParcelNL\Magento\Service\Settings;

/** Magento's block base class is an empty stub under these tests, so getRequest() is declared here. */
class DeliveryCostsMatrixUnderTest extends DeliveryCostsMatrix
{
    public $request;

    public function getRequest()
    {
        return $this->request;
    }
}

/** @param string[] $contracted v2 carrier names in the contract of store 1; none reads permissive */
function deliveryCostsMatrixFor(array $contracted): DeliveryCostsMatrixUnderTest
{
    $settings = Mockery::mock(Settings::class);
    $settings->shouldReceive('getCurrentScopeFromRequest')->andReturn([ScopeInterface::SCOPE_STORES, 1]);

    $block = newInstanceWithoutConstructor(DeliveryCostsMatrixUnderTest::class);
    setPrivateProperty($block, 'contractDefinitions', storedContractFor(1, $contracted));
    setPrivateProperty($block, 'settings', $settings);
    $block->request = Mockery::mock(RequestInterface::class);

    return $block;
}

it('prices every carrier the settings form has a tab for, exportable or not', function () {
    $carriers = deliveryCostsMatrixFor(['POSTNL', 'HOOPLA'])->getCarriers();

    expect(array_keys($carriers))->toBe(['postnl', 'hoopla'])
        ->and($carriers['postnl'])->toBe('PostNL')
        ->and($carriers['hoopla'])->toBe('hoopla');
});

it('prices no carrier when the contract could not be read', function () {
    expect(deliveryCostsMatrixFor([])->getCarriers())->toBe([]);
});
