<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Pdk\Webhook;

use MyParcelNL\Pdk\App\Webhook\Repository\AbstractPdkWebhooksRepository;
use MyParcelNL\Pdk\Webhook\Collection\WebhookSubscriptionCollection;

class MagentoWebhooksRepository extends AbstractPdkWebhooksRepository
{
    public function getAll(): WebhookSubscriptionCollection
    {
        // TODO: Implement getAll() method.
    }

    public function getHashedUrl(): ?string
    {
        // TODO: Implement getHashedUrl() method.
    }

    public function remove(string $hook): void
    {
        // TODO: Implement remove() method.
    }

    public function store(WebhookSubscriptionCollection $subscriptions): void
    {
        // TODO: Implement store() method.
    }

    public function storeHashedUrl(string $url): void
    {
        // TODO: Implement storeHashedUrl() method.
    }
}
