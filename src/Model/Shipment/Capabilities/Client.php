<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Configuration;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ObjectSerializer;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesMapper;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use RuntimeException;
use Throwable;

/**
 * Calls the Core API capabilities endpoint for one API key and returns the response body decoded.
 *
 * The SDK builds the request — CapabilitiesRequest and CapabilitiesMapper carry domain knowledge we
 * would otherwise rediscover — but not the response: a generated response model reads only its own
 * declared properties, so it drops any key the SDK release does not know about. FR-000010 forbids
 * that, so the body is decoded as-is and the module's own value objects read it.
 *
 * SDK\Services\Capabilities\CapabilitiesService is unusable here for two separate reasons: it can be
 * given no API key, and its mapper discards every per-option value including insurance bounds.
 *
 * serialize() and send() are separate because the request body is also what the cache id hashes.
 * Building it twice would map the request twice and log the dropped options twice.
 *
 * @todo revisit when myparcelnl/sdk issues 1-3 land; this layer may shrink to a thin wrapper.
 */
class Client
{
    private const PATH            = '/shipments/capabilities';
    private const ACCEPT          = 'application/json;charset=utf-8;version=2';
    private const CONTENT_TYPE    = 'application/json;charset=utf-8';
    private const TIMEOUT_SECONDS = 10;

    private GuzzleClient       $httpClient;
    private Config             $config;
    private CapabilitiesMapper $mapper;
    private ?string            $host;

    /**
     * @param string|null $host overrides the SDK's production host; the SDK knows no other, so this
     *                          is the only seam for testing against acceptance.
     */
    public function __construct(
        GuzzleClient        $httpClient,
        Config              $config,
        ?CapabilitiesMapper $mapper = null,
        ?string             $host = null
    )
    {
        $this->httpClient = $httpClient;
        $this->config     = $config;
        $this->mapper     = $mapper ?? new CapabilitiesMapper();
        $this->host       = $host;
    }

    /**
     * @throws \InvalidArgumentException for a value the SDK refuses to send — write strictly, per
     *                                   TR-000007
     */
    public function serialize(CapabilitiesRequest $request): string
    {
        $core = $this->mapper->mapToCoreApi($request);

        $this->logDroppedOptions($request, $core);

        return (string) json_encode(ObjectSerializer::sanitizeForSerialization($core));
    }

    /**
     * @return array the response's `results` entries, verbatim
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    public function send(string $apiKey, string $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url(), [
                RequestOptions::HEADERS         => [
                    'Authorization' => 'Bearer ' . base64_encode($apiKey),
                    'Content-Type'  => self::CONTENT_TYPE,
                    'Accept'        => self::ACCEPT,
                    'User-Agent'    => $this->userAgent(),
                ],
                RequestOptions::BODY            => $body,
                RequestOptions::HTTP_ERRORS     => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::TIMEOUT         => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('capabilities request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status > 299) {
            throw new RuntimeException(sprintf('capabilities responded %d', $status));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            throw new RuntimeException('capabilities response carried no results array');
        }

        return $decoded['results'];
    }

    /**
     * CapabilitiesMapper::mapOptions() skips an option with no setter on the request model without
     * saying so. fresh_food and frozen are two the model cannot carry today, so a shipment asking
     * about either would get an answer to a question it did not ask.
     */
    private function logDroppedOptions(CapabilitiesRequest $request, object $core): void
    {
        $sent = $request->getOptions();

        if (empty($sent)) {
            return;
        }

        $mapped  = (array) ObjectSerializer::sanitizeForSerialization($core->getOptions());
        $dropped = [];

        foreach (array_keys($sent) as $name) {
            $key = ShipmentOption::toV2Key((string) $name);

            if (null === $key || ! array_key_exists($key, $mapped)) {
                $dropped[] = (string) $name;
            }
        }

        if ($dropped) {
            Logger::notice(sprintf(
                'Capabilities request dropped option(s) the SDK cannot carry: %s',
                implode(', ', $dropped)
            ));
        }
    }

    private function url(): string
    {
        return ($this->host ?? (new Configuration())->getHost()) . self::PATH;
    }

    private function userAgent(): string
    {
        return sprintf('MyParcelMagento/%s; PHP/%s', $this->config->getVersion(), PHP_VERSION);
    }
}
