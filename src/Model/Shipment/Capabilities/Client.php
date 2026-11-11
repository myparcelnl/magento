<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Configuration;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\CapabilitiesPostContractDefinitionsRequestV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ObjectSerializer;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesMapper;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Calls the capabilities and contract definitions endpoints with requests built by the SDK's ShipmentApi.
 *
 * Sends them on its own Guzzle client and returns the raw response arrays, never a model: the cache
 * and the stored answers json_encode() them, and a model with an enum value the SDK does not know
 * throws there. One malformed entry must not fail the whole answer; CapabilitySet skips it.
 */
class Client
{
    /** A checkout waits this long per request on an unreachable host. */
    private const CONNECT_TIMEOUT_SECONDS = 2;

    private UserAgent          $userAgent;
    private CapabilitiesMapper $mapper;

    /** @var callable|null the innermost Guzzle handler; null is Guzzle's default */
    private $handler;

    private ?GuzzleClient $http = null;

    /** @var array<string,ShipmentApi> keyed by API key */
    private array $apis = [];

    /** Null is the SDK's default host. */
    private ?string $host;

    public function __construct(
        UserAgent           $userAgent,
        ?CapabilitiesMapper $mapper = null,
        ?callable           $handler = null,
        ?string             $host = null
    )
    {
        $this->userAgent = $userAgent;
        $this->mapper    = $mapper ?? new CapabilitiesMapper();
        $this->handler   = $handler;
        $this->host      = $host;
    }

    /**
     * Make the JSON body of a capabilities request.
     *
     * @throws \InvalidArgumentException for a value the SDK refuses to send
     */
    public function serialize(CapabilitiesRequest $request): string
    {
        return (string) json_encode(ObjectSerializer::sanitizeForSerialization($this->mapper->mapToCoreApi($request)));
    }

    /**
     * Post a capabilities body for this API key.
     *
     * @return array the response's `results` entries, verbatim
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    public function send(string $apiKey, string $body): array
    {
        return $this->call($apiKey, 'results', 'capabilities', static function (ShipmentApi $api) use ($body): RequestInterface {
            // The decoded body as is: a model round trip turns an option's {} into [].
            return $api->postCapabilitiesRequest(json_decode($body));
        });
    }

    /**
     * Get every contract definition of the account, in one call.
     *
     * @return array the response's `items` entries, verbatim
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    public function sendContractDefinitions(string $apiKey): array
    {
        return $this->call($apiKey, 'items', 'contract definitions', static function (ShipmentApi $api): RequestInterface {
            return $api->postCapabilitiesContractDefinitionsRequest(new CapabilitiesPostContractDefinitionsRequestV2());
        });
    }

    /**
     * @param  string                                 $envelope the response key carrying the entries
     * @param  string                                 $label    what to call this endpoint in an error
     * @param  callable(ShipmentApi):RequestInterface $build
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    private function call(string $apiKey, string $envelope, string $label, callable $build): array
    {
        try {
            $response = $this->http()->send($build($this->apiFor($apiKey)));
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('%s request failed: %s', $label, $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('%s responded %d', $label, $status));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException(sprintf('%s response is not JSON: %s', $label, json_last_error_msg()));
        }

        if (! is_array($decoded) || ! is_array($decoded[$envelope] ?? null)) {
            throw new RuntimeException(sprintf('%s response carried no %s array', $label, $envelope));
        }

        return $decoded[$envelope];
    }

    /** Built here: ShipmentApiFactory takes no connect timeout. */
    private function apiFor(string $apiKey): ShipmentApi
    {
        if ('' === $apiKey) {
            throw new InvalidArgumentException('no API key');
        }

        if (! isset($this->apis[$apiKey])) {
            $configuration = new Configuration();
            $configuration->setAccessToken(base64_encode($apiKey));
            $configuration->setUserAgent($this->userAgent->header());

            if (null !== $this->host) {
                $configuration->setHost($this->host);
            }

            $this->apis[$apiKey] = new ShipmentApi($this->http(), $configuration);
        }

        return $this->apis[$apiKey];
    }

    private function http(): GuzzleClient
    {
        if (null === $this->http) {
            $this->http = new GuzzleClient([
                'handler'                       => HandlerStack::create($this->handler),
                RequestOptions::HEADERS         => Config::API_FEATURE_FLAGS,
                RequestOptions::TIMEOUT         => ShipmentApiFactory::DEFAULT_HTTP_TIMEOUT,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS     => false,
            ]);
        }

        return $this->http;
    }
}
