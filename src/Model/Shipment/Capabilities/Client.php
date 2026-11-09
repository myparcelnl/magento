<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Capabilities;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Configuration;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ObjectSerializer;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesMapper;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Calls the Core API capabilities and contract definitions endpoints for one API key.
 *
 * The SDK builds the request. The response body is decoded as is, because of SDK defects 1 to 3 in
 * docs/sdk-v11.md. serialize() is separate from send() because the cache key hashes the body.
 */
class Client
{
    private const PATH            = '/shipments/capabilities';
    private const PATH_CONTRACTS  = self::PATH . '/contract-definitions';
    private const ACCEPT          = 'application/json;charset=utf-8;version=2';
    private const CONTENT_TYPE    = 'application/json;charset=utf-8';
    private const TIMEOUT_SECONDS = 10;

    /**
     * Separate from the read timeout, and much shorter.
     *
     * An unreachable host is the outage that costs a customer real time: checkout asks two shapes
     * per request, so one budget each is what the page waits. A slow-but-answering API still gets
     * the full read timeout.
     */
    private const CONNECT_TIMEOUT_SECONDS = 2;

    private GuzzleClient       $httpClient;
    private UserAgent          $userAgent;
    private CapabilitiesMapper $mapper;

    public function __construct(
        GuzzleClient        $httpClient,
        UserAgent           $userAgent,
        ?CapabilitiesMapper $mapper = null
    )
    {
        $this->httpClient = $httpClient;
        $this->userAgent  = $userAgent;
        $this->mapper     = $mapper ?? new CapabilitiesMapper();
    }

    /**
     * Make the JSON body of a capabilities request.
     *
     * @throws \InvalidArgumentException for a value the SDK refuses to send
     */
    public function serialize(CapabilitiesRequest $request): string
    {
        $core = $this->mapper->mapToCoreApi($request);

        $this->logDroppedOptions($request, $core);

        return (string) json_encode(ObjectSerializer::sanitizeForSerialization($core));
    }

    /**
     * Post a capabilities body for this API key.
     *
     * @return array the response's `results` entries, verbatim
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    public function send(string $apiKey, string $body): array
    {
        return $this->post($apiKey, $body, self::PATH, 'results', 'capabilities');
    }

    /**
     * Every contract definition the account has, in one call.
     *
     * Sent through this client's own transport rather than the generated
     * postCapabilitiesContractDefinitions(), so both capability calls share the error mapping and
     * the timeouts. The generated method itself is fine: the reversed-argument defect is in
     * HttpCapabilitiesClient, which has no contract-definitions method.
     *
     * @return array the response's `items` entries, verbatim
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    public function sendContractDefinitions(string $apiKey): array
    {
        return $this->post(
            $apiKey,
            '{}',
            self::PATH_CONTRACTS,
            'items',
            'contract definitions'
        );
    }

    /**
     * @param  string $envelope the response key carrying the entries
     * @param  string $label    what to call this endpoint in an error or log line
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    private function post(string $apiKey, string $body, string $path, string $envelope, string $label): array
    {
        $response = $this->request($apiKey, $body, $path);
        $status   = $response->getStatusCode();

        if ($status < 200 || $status > 299) {
            throw new RuntimeException(sprintf('%s responded %d', $label, $status));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded) || ! is_array($decoded[$envelope] ?? null)) {
            throw new RuntimeException(sprintf('%s response carried no %s array', $label, $envelope));
        }

        return $decoded[$envelope];
    }

    /**
     * @throws \RuntimeException on a transport failure
     */
    private function request(string $apiKey, string $body, string $path): ResponseInterface
    {
        try {
            return $this->httpClient->request('POST', $this->url($path), [
                RequestOptions::HEADERS         => [
                    'Authorization' => 'Bearer ' . base64_encode($apiKey),
                    'Content-Type'  => self::CONTENT_TYPE,
                    'Accept'        => self::ACCEPT,
                    'User-Agent'    => $this->userAgent->header(),
                ] + Config::API_FEATURE_FLAGS,
                RequestOptions::BODY            => $body,
                RequestOptions::HTTP_ERRORS     => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::TIMEOUT         => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('capabilities request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * CapabilitiesMapper::mapOptions() skips an option with no setter on the request model without
     * saying so, and a shipment asking about a dropped option gets an answer to a question it did
     * not ask. V2NameMapTest pins which options currently survive the mapping.
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
            $key = ShipmentOption::toV2Name((string) $name);

            if (! array_key_exists($key, $mapped)) {
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

    private function url(string $path): string
    {
        return (new Configuration())->getHost() . $path;
    }
}
