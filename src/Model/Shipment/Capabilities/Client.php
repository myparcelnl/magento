<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ApiException;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Configuration;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\CapabilitiesPostContractDefinitionsRequestV2;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ObjectSerializer;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesMapper;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Calls the capabilities and contract definitions endpoints through the SDK's generated ShipmentApi.
 *
 * Returns the raw response arrays, never a model: the cache and the stored answers json_encode()
 * them, and a model with an enum value the SDK does not know throws there. CapabilitySet reads them.
 */
class Client
{
    private const ACCEPT          = 'application/json;charset=utf-8;version=2';
    private const TIMEOUT_SECONDS = 10;

    /** Shorter than the read timeout: a checkout waits this long per request on an unreachable host. */
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

    /** The body of the last response, kept by a middleware because ShipmentApi returns only a model. */
    private ?string $lastBody = null;

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
        return $this->call($apiKey, 'results', 'capabilities', static function (ShipmentApi $api) use ($body): void {
            // The decoded body as is: a model round trip turns an option's {} into [].
            $api->postCapabilities(json_decode($body));
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
        return $this->call($apiKey, 'items', 'contract definitions', static function (ShipmentApi $api): void {
            $api->postCapabilitiesContractDefinitions(new CapabilitiesPostContractDefinitionsRequestV2());
        });
    }

    /**
     * @param  string                     $envelope the response key carrying the entries
     * @param  string                     $label    what to call this endpoint in an error
     * @param  callable(ShipmentApi):void $post
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    private function call(string $apiKey, string $envelope, string $label, callable $post): array
    {
        $this->lastBody = null;

        try {
            $post($this->apiFor($apiKey));
        } catch (ApiException $e) {
            // The SDK throws this for a transport failure (code 0) and an undecodable 2xx body (code 2xx) too.
            $status  = $e->getCode();
            $message = 0 !== $status && ($status < 200 || $status >= 300)
                ? sprintf('%s responded %d', $label, $status)
                : sprintf('%s request failed: %s', $label, $e->getMessage());

            throw new RuntimeException($message, 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('%s request failed: %s', $label, $e->getMessage()), 0, $e);
        }

        $decoded = json_decode((string) $this->lastBody, true);

        if (! is_array($decoded) || ! is_array($decoded[$envelope] ?? null)) {
            throw new RuntimeException(sprintf('%s response carried no %s array', $label, $envelope));
        }

        return $decoded[$envelope];
    }

    /** Built here: ShipmentApiFactory takes no middleware or connect timeout, and reads getenv('API_KEY') for an empty key. */
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
            $stack = HandlerStack::create($this->handler);

            // The generated client asks for version=2.0; keep the v2 header this module and the PDK send.
            $stack->push(Middleware::mapRequest(static function (RequestInterface $request): RequestInterface {
                return $request->withHeader('Accept', self::ACCEPT);
            }));

            $stack->push(Middleware::mapResponse(function (ResponseInterface $response): ResponseInterface {
                $this->lastBody = (string) $response->getBody();

                return $response->withBody(Utils::streamFor($this->lastBody));
            }));

            $this->http = new GuzzleClient([
                'handler'                       => $stack,
                RequestOptions::HEADERS         => Config::API_FEATURE_FLAGS,
                RequestOptions::TIMEOUT         => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);
        }

        return $this->http;
    }
}
