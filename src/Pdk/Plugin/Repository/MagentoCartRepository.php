<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Plugin\Repository;

use Magento\Checkout\Model\Session;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Adapter\MagentoAddressAdapter;
use MyParcelNL\Pdk\App\Cart\Model\PdkCart;
use MyParcelNL\Pdk\App\Cart\Repository\AbstractPdkCartRepository;
use MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface;
use MyParcelNL\Pdk\Base\Contract\CurrencyServiceInterface;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;

class MagentoCartRepository extends AbstractPdkCartRepository
{
    /**
     * @var \MyParcelNL\Magento\Adapter\MagentoAddressAdapter
     */
    private $addressAdapter;

    /**
     * @var \MyParcelNL\Pdk\Base\Contract\CurrencyServiceInterface
     */
    private $currencyService;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @param  \Magento\Framework\ObjectManagerInterface                        $objectManager
     * @param  \MyParcelNL\Pdk\Storage\Contract\StorageInterface                $storage
     * @param  \MyParcelNL\Pdk\App\Order\Contract\PdkProductRepositoryInterface $pdkProductRepository
     * @param  \MyParcelNL\Pdk\Base\Contract\CurrencyServiceInterface           $currencyService
     * @param  \MyParcelNL\Magento\Adapter\MagentoAddressAdapter                $addressAdapter
     */
    public function __construct(
        ObjectManagerInterface        $objectManager,
        StorageInterface              $storage,
        PdkProductRepositoryInterface $pdkProductRepository,
        CurrencyServiceInterface      $currencyService,
        MagentoAddressAdapter         $addressAdapter
    ) {
        parent::__construct($storage);
        $this->objectManager     = $objectManager;
        $this->productRepository = $pdkProductRepository;
        $this->currencyService   = $currencyService;
        $this->addressAdapter    = $addressAdapter;
    }

    /**
     * @param  mixed $input
     *
     * @return \MyParcelNL\Pdk\App\Cart\Model\PdkCart
     */
    public function get($input): PdkCart
    {
        $quote = $this->objectManager->get(Session::class)
            ->getQuote();
        return $this->fromWcCart($quote);
    }

    /**
     * @param  \Magento\Quote\Model\Quote $quote
     *
     * @return \MyParcelNL\Pdk\App\Cart\Model\PdkCart
     */
    protected function fromWcCart(Quote $quote): PdkCart
    {
        return $this->retrieve($quote->getId(), function () use ($quote): PdkCart {
            // TODO
            $shipmentPriceAfterVat = 0.0;
            // TODO
            $orderPriceAfterVat    = 0.0;
            $shippingMethod        = $quote->getShippingAddress()
                ->getShippingMethod();

            return new PdkCart([
                'externalIdentifier'    => $quote->getId(),
                // TODO
                'shipmentPrice'         => $this->currencyService->convertToCents($quote->get_shipping_total()),
                'shipmentPriceAfterVat' => $this->currencyService->convertToCents($shipmentPriceAfterVat),
                // TODO
                'shipmentVat'           => $this->currencyService->convertToCents($quote->get_shipping_tax()),
                // TODO
                'orderPrice'            => $this->currencyService->convertToCents($quote->get_cart_contents_total()),
                'orderPriceAfterVat'    => $this->currencyService->convertToCents($orderPriceAfterVat),
                // TODO
                'orderVat'              => $this->currencyService->convertToCents($quote->get_cart_contents_tax()),
                'shippingMethod'        => [
                    'id'              => $shippingMethod,
                    'name'            => $shippingMethod,
                    'shippingAddress' => $this->addressAdapter->fromMagentoQuote(Pdk::get('addressTypeShipping')),
                ],
                'lines'                 => array_map(function (CartItemInterface $item) {
                    $product       = $this->productRepository->getProduct($item->getItemId());
                    $priceAfterVat = $item->getPrice();

                    return [
                        'quantity'      => (int) $item->getQty(),
                        'price'         => $this->currencyService->convertToCents($priceAfterVat),
                        //TODO
                        'vat'           => $this->currencyService->convertToCents(0.0),
                        'priceAfterVat' => $this->currencyService->convertToCents($priceAfterVat),
                        'product'       => $product,
                    ];
                }, $quote->getItems()),
            ]);
        });
    }
}
