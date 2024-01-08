<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\src\Pdk\Webhook;

use MyParcelNL\Pdk\App\Webhook\Repository\AbstractPdkWebhooksRepository;
use MyParcelNL\Pdk\Facade\Pdk;
use MyParcelNL\Pdk\Storage\Contract\StorageInterface;
use MyParcelNL\Pdk\Webhook\Collection\WebhookSubscriptionCollection;
use MyParcelNL\Pdk\Webhook\Repository\WebhookSubscriptionRepository;

class MagentoWebhooksRepository extends AbstractPdkWebhooksRepository
{
    /** @var $magentoStorageInterface */
    private $magentoStorageInterface;

    public function __construct(
        StorageInterface              $storage,
        WebhookSubscriptionRepository $subscriptionRepository
    )
    {
        $this->magentoStorageInterface = $storage;
        parent::__construct($storage, $subscriptionRepository);
    }

    /**
     * @return \MyParcelNL\Pdk\Webhook\Collection\WebhookSubscriptionCollection
     */
    public function getAll(): WebhookSubscriptionCollection
    {
        $key = Pdk::get('settingKeyWebhooks');

        return $this->retrieve($key, function () use ($key) {
            $items = $this->magentoStorageInterface->get($key, null);

            return new WebhookSubscriptionCollection($items);
        });
    }

    /**
     * @return null|string
     */
    public function getHashedUrl(): ?string
    {
       return $this->magentoStorageInterface->get($this->getKey(Pdk::get('settingKeyWebhookHash')), null);
    }

    /**
     * @param string $hook
     */
    public function remove(string $hook): void
    {
        $this->magentoStorageInterface->delete($this->getKey($hook));
    }

    /**
     * @param \MyParcelNL\Pdk\Webhook\Collection\WebhookSubscriptionCollection $subscriptions
     */
    public function store(WebhookSubscriptionCollection $subscriptions): void
    {
        $this->magentoStorageInterface->set(Pdk::get('settingKeyWebhooks'), $subscriptions->toArray());
    }

    /**
     * @param string $url
     */
    public function storeHashedUrl(string $url): void
    {
        $this->magentoStorageInterface->set($this->getKey(Pdk::get('settingKeyWebhookHash')), $url);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function getKey(string $key): string
    {
        return Pdk::get('settingKeyWebhooks') . '_' . $key;
    }
}
