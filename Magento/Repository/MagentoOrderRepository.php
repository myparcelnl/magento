<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Magento\Repository;

use InvalidArgumentException;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use MyParcelNL\Magento\Magento\Contract\MagentoOrderRepositoryInterface;
use MyParcelNL\Pdk\Base\Repository\Repository;
use MyParcelNL\Pdk\Base\Support\Collection;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;

final class MagentoOrderRepository extends Repository implements MagentoOrderRepositoryInterface
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    /**
     * @param  \MyParcelNL\Pdk\Storage\Contract\StorageInterface $storage
     * @param  \Magento\Framework\App\ObjectManager              $objectManager
     */
    public function __construct(StorageInterface $storage, ObjectManager $objectManager)
    {
        parent::__construct($storage);
        $this->objectManager = $objectManager;
    }
    /**
     * @param  int|string|\Magento\Sales\Model\Order $input
     *
     * @return \Magento\Sales\Model\Order
     * @throws \Throwable
     */
    public function get($input): Order
    {
        if (is_object($input) && method_exists($input, 'getId')) {
            $id = $input->getId();
        } else {
            $id = $input;
        }

        if (! is_scalar($id)) {
            throw new InvalidArgumentException('Invalid input');
        }

        return $this->retrieve((string) $id, function () use ($input, $id) {
            if (is_a($input, Order::class)) {
                return $input;
            }

            return (new OrderFactory($this->objectManager))->create(['id' => $id]);
        });
    }

    /**
     * @param  int|string|Collection<\Magento\Sales\Model\Order\Item> $input
     *
     * @return \MyParcelNL\Pdk\Base\Support\Collection
     * @throws \Throwable
     */
    public function getItems($input): Collection
    {
        $order = $this->get($input);

        return $this->retrieve("{$order->getId()}_items", function () use ($order) {
            return new Collection(
                array_map(static function ($item) {
                    $product = $item instanceof Order\Item ? $item->getProduct() : null;

                    return [
                        'item'    => $item,
                        'product' => $product,
                    ];
                }, array_values($order->getItems() ?: []))
            );
        });
    }

    /**
     * @return string
     */
    protected function getKeyPrefix(): string
    {
        return Order::class;
    }
}
