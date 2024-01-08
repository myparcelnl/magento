<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Product\Repository;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Pdk\App\Order\Collection\PdkProductCollection;
use MyParcelNL\Pdk\App\Order\Model\PdkProduct;
use MyParcelNL\Pdk\App\Order\Repository\AbstractPdkPdkProductRepository;
use MyParcelNL\Pdk\Base\Contract\WeightServiceInterface;
use MyParcelNL\Pdk\Settings\Model\ProductSettings;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;

class MagentoPdkProductRepository extends AbstractPdkPdkProductRepository
{
    /**
     * @var \MyParcelNL\Pdk\Base\Contract\WeightServiceInterface
     */
    protected $weightService;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param  \MyParcelNL\Pdk\Storage\Contract\StorageInterface    $storage
     * @param  \MyParcelNL\Pdk\Base\Contract\WeightServiceInterface $weightService
     * @param  \Magento\Catalog\Api\ProductRepositoryInterface      $productRepository
     * @param  \Magento\Store\Model\StoreManagerInterface           $storeManager
     */
    public function __construct(
        StorageInterface           $storage,
        WeightServiceInterface     $weightService,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface      $storeManager
    ) {
        parent::__construct($storage);
        $this->weightService     = $weightService;
        $this->productRepository = $productRepository;
        $this->storeManager      = $storeManager;
    }

    /**
     * @param  \Magento\Catalog\Model\Product\|string|int $identifier
     *
     * @return \MyParcelNL\Pdk\App\Order\Model\PdkProduct
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getProduct($identifier): PdkProduct
    {
        $product = $this->getMagentoProduct($identifier);

        return $this->retrieve((string) $product->getId(), function () use ($product) {
            return new PdkProduct([
                'externalIdentifier' => (string) $product->getId(),
                'sku'                => $product->getSku(),
                'isDeliverable'      => $this->isDeliverable($product),
                'name'               => $product->getName(),
                'price'              => [
                    'amount'   => $product->getPrice() * 100,
                    'currency' => $this->storeManager->getStore()
                        ->getCurrentCurrency()
                        ->getCode(),
                ],
                'weight'             => $this->weightService->convertToGrams($product->getWeight()),
                // Dimensions are weird in Magento. Customer has to create a custom attribute for it.
                'settings'           => $this->getProductSettings($product),
            ]);
        });
    }

    /**
     * @param $identifier
     *
     * @return \MyParcelNL\Pdk\Settings\Model\ProductSettings
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getProductSettings($identifier): ProductSettings
    {
        $product = $this->getMagentoProduct($identifier);

        return $this->retrieve(sprintf('product_settings_%s', $product->getId()), function () use ($product) {
            $data = []; // TODO: get product settings from database

            return new ProductSettings($data ?: []);
        });
    }

    /**
     * @param  array $identifiers
     *
     * @return \MyParcelNL\Pdk\App\Order\Collection\PdkProductCollection
     */
    public function getProducts(array $identifiers = []): PdkProductCollection
    {
        return new PdkProductCollection(array_map([$this, 'getProduct'], $identifiers));
    }

    /**
     * @param  \MyParcelNL\Pdk\App\Order\Model\PdkProduct $product
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function update(PdkProduct $product): void
    {
        $magentoProduct = $this->getMagentoProduct($product->externalIdentifier);

        // TODO: update product in database

        $this->save($product->externalIdentifier, $product);
    }

    /**
     * @param $identifier
     *
     * @return \Magento\Catalog\Model\Product
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getMagentoProduct($identifier): Product
    {
        if ($identifier instanceof Product) {
            $product = $identifier;
        } else {
            $product = $this->retrieve("magento_product$identifier", function () use ($identifier) {
                return $this->productRepository->getById($identifier);
            });
        }

        return $product;
    }

    /**
     * @param  \Magento\Catalog\Model\Product $product
     *
     * @return bool
     */
    private function isDeliverable(Product $product): bool
    {
        return $product->getTypeId() === 'simple';
    }
}
