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
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Calls the Core API capabilities endpoints for one API key and returns the response body decoded.
 *
 * Two endpoints, one transport: shipment capabilities answer for a shipment shape, contract
 * definitions answer per carrier with no shipment in hand. They differ only in path, request body
 * and envelope key, so they share the retry ladder, auth and host override.
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
    private const PATH_CONTRACTS  = self::PATH . '/contract-definitions';
    private const ACCEPT          = 'application/json;charset=utf-8;version=2';
    private const CONTENT_TYPE    = 'application/json;charset=utf-8';
    private const TIMEOUT_SECONDS = 10;

    /** Statuses that mean "ask again", as opposed to "this request is wrong". */
    private const RETRYABLE_STATUSES = [429, 503, 529];

    /**
     * Longest we will hold a page open waiting out a throttle. A Retry-After beyond this is honoured
     * by not retrying at all: the negative cache entry the Repository writes is the better answer
     * than a page that hangs.
     */
    private const MAX_RETRY_WAIT_SECONDS = 2.0;

    /** Used when a throttling response names no Retry-After. */
    private const DEFAULT_RETRY_WAIT_SECONDS = 0.5;

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
        return $this->post($apiKey, $body, self::PATH, 'results', 'capabilities');
    }

    /**
     * Contract definitions for one carrier. The request carries no country and the response no zone,
     * so this answers what the account's contract allows at all — not what a shipment may have.
     *
     * The body is built here rather than through the SDK: CapabilitiesPostContractDefinitionsRequestV2
     * is a single string property, so there is no domain knowledge to borrow, and
     * postCapabilitiesContractDefinitions() carries the same reversed-argument defect as
     * postCapabilities() (DR-15).
     *
     * @param  string $v2Carrier the V2 wire name, from Carrier::toV2Name()
     * @return array the response's `items` entries, verbatim
     * @throws \RuntimeException on a transport failure, a non-2xx status or an undecodable body
     */
    public function sendContractDefinitions(string $apiKey, string $v2Carrier): array
    {
        return $this->post(
            $apiKey,
            (string) json_encode(['carrier' => $v2Carrier]),
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

        if (in_array($status, self::RETRYABLE_STATUSES, true)) {
            $wait = $this->retryWaitFor($response);

            if (null === $wait) {
                throw new RuntimeException(sprintf(
                    '%s responded %d and asked to wait longer than %.1fs',
                    $label,
                    $status,
                    self::MAX_RETRY_WAIT_SECONDS
                ));
            }

            Logger::notice(sprintf(
                '%s throttled with %d; retrying once after %.2fs.',
                ucfirst($label),
                $status,
                $wait
            ));

            if ($wait > 0.0) {
                usleep((int) round($wait * 1000000));
            }

            // One retry only. A second would double a latency budget the admin form already spends
            // once per package type.
            $response = $this->request($apiKey, $body, $path);
            $status   = $response->getStatusCode();
        }

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
                    'User-Agent'    => $this->userAgent(),
                ],
                RequestOptions::BODY            => $body,
                RequestOptions::HTTP_ERRORS     => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::TIMEOUT         => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
            ]);
        } catch (Throwable $e) {
            // A timeout is deliberately not retried: it has already spent the whole budget, and a
            // second attempt would double the worst case rather than improve it.
            throw new RuntimeException('capabilities request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Seconds to wait before the single retry, or null when the API asks for longer than we are
     * willing to hold a page open.
     *
     * Retry-After is either a number of seconds or an HTTP date; both are accepted, and anything
     * unparseable falls back to the default rather than being treated as zero.
     */
    private function retryWaitFor(ResponseInterface $response): ?float
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        if ('' === $header) {
            return self::DEFAULT_RETRY_WAIT_SECONDS;
        }

        if (is_numeric($header)) {
            $wait = (float) $header;
        } else {
            $at = strtotime($header);

            if (false === $at) {
                return self::DEFAULT_RETRY_WAIT_SECONDS;
            }

            $wait = (float) ($at - time());
        }

        if ($wait <= 0.0) {
            return 0.0;
        }

        return $wait > self::MAX_RETRY_WAIT_SECONDS ? null : $wait;
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

    private function url(string $path): string
    {
        return ($this->host ?? (new Configuration())->getHost()) . $path;
    }

    private function userAgent(): string
    {
        return sprintf('MyParcelMagento/%s; PHP/%s', $this->config->getVersion(), PHP_VERSION);
    }
}
