<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Product\Repository;

use MyParcelNL\Pdk\App\Order\Collection\PdkProductCollection;
use MyParcelNL\Pdk\App\Order\Model\PdkProduct;
use MyParcelNL\Pdk\App\Order\Repository\AbstractPdkPdkProductRepository;
use MyParcelNL\Pdk\Base\Contract\WeightServiceInterface;
use MyParcelNL\Pdk\Settings\Model\ProductSettings;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;

class MagentoPdkProductRepository extends AbstractPdkPdkProductRepository
{
    /**
     * @var \MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface
     */
    protected $weightService;

    /**
     * @param  \MyParcelNL\Pdk\Storage\Contract\StorageInterface    $storage
     * @param  \MyParcelNL\Pdk\Base\Contract\WeightServiceInterface $weightService
     */
    public function __construct(StorageInterface $storage, WeightServiceInterface $weightService)
    {
        parent::__construct($storage);
        $this->weightService = $weightService;
    }

    public function getProduct($identifier): PdkProduct
    {
        // TODO: Implement getProduct() method.
    }

    public function getProductSettings($identifier): ProductSettings
    {
        // TODO: Implement getProductSettings() method.
    }

    public function getProducts(array $identifiers = []): PdkProductCollection
    {
        // TODO: Implement getProducts() method.
    }

    public function update(PdkProduct $product): void
    {
        // TODO: Implement update() method.
    }
}
