<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ApiException;
use Throwable;

/** Why the API refused a chunk, and which orders it named. */
final class Rejection
{
    /** @var array<string,string[]> increment id => every reason the API gave for it */
    private array $reasons;

    private string $summary;

    private bool $retryable;

    /** Whether the API validated the request and refused it, so nothing was created. */
    private bool $refused;

    private function __construct(array $reasons, string $summary, bool $retryable, bool $refused)
    {
        $this->reasons   = $reasons;
        $this->summary   = $summary;
        $this->retryable = $retryable;
        $this->refused   = $refused;
    }

    /**
     * Attach each error from a rejected chunk to the order it belongs to.
     *
     * **The body is RFC 9457 Problem Details, not the documented `message` plus `errors[]`.** The
     * generated model drops `detail` and `instance`, so this decodes getResponseBody() itself.
     * It reads both shapes, because the next SDK regeneration follows the spec. `instance` is a
     * JSON Pointer such as `/data/shipments/0/recipient/postal_code`: the index is the request position.
     *
     * @param BuiltShipment[] $chunk in request order, which is what an index in a pointer refers to
     */
    public static function fromApiException(Throwable $e, array $chunk): self
    {
        $decoded = json_decode(self::bodyOf($e), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $summary = trim((string) ($decoded['detail'] ?? $decoded['message'] ?? ''));
        $summary = '' !== $summary ? $summary : $e->getMessage();

        $errors = $decoded['errors'] ?? [];
        $errors = is_array($errors) ? $errors : [];
        $keyed  = array_keys($errors) !== range(0, count($errors) - 1);

        $reasons    = [];
        $unattached = [];

        foreach ($errors as $key => $error) {
            $text = self::errorText($error);

            if ('' === $text) {
                continue;
            }

            // The pointer is the error's own `instance`; a keyed object's key is the fallback the
            // spec's shape would give us.
            $pointer = (string) (is_array($error) ? ($error['instance'] ?? '') : '');
            $pointer = '' !== $pointer ? $pointer : ($keyed ? (string) $key : '');
            $index   = self::shipmentIndexIn($pointer);

            if (null !== $index && isset($chunk[$index])) {
                // Several rules can break at once, and the merchant has to fix all of them, so the
                // reasons accumulate instead of the last one winning.
                $reasons[$chunk[$index]->getIncrementId()][] = self::withField($pointer, $text);
                continue;
            }

            $unattached[] = $text;
        }

        // An error nobody claimed is about the batch, not about one order, so it joins the summary
        // rather than being pinned on every order in turn.
        if ($unattached) {
            $summary = trim($summary . ' ' . implode('; ', $unattached));
        }

        $refused = self::isSafeToRetry($e);

        return new self(
            $reasons,
            $summary,
            // Retrying is only safe when nothing was created, and only worth it when we know who to
            // leave out.
            [] !== $reasons && $refused,
            $refused
        );
    }

    /** The response body. Describe it with shapeOf() before logging it: it carries recipient data. */
    public static function bodyOf(Throwable $e): string
    {
        return $e instanceof ApiException ? (string) $e->getResponseBody() : '';
    }

    /**
     * Describe the body's shape (keys and JSON Pointers) without its values.
     * `title` and `detail` are left out: they can quote the consumer's address.
     */
    public static function shapeOf(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return sprintf('(unparsable, %d bytes)', strlen($body));
        }

        $errorKeys = [];
        $pointers  = [];
        $errors    = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $errorKeys += array_flip(array_keys($error));
            $pointer   = (string) ($error['instance'] ?? '');

            if ('' !== $pointer) {
                $pointers[] = $pointer;
            }
        }

        $parts = ['keys: ' . implode(',', array_keys($decoded))];

        if ($errorKeys) {
            $parts[] = 'error keys: ' . implode(',', array_keys($errorKeys));
        }

        if ($pointers) {
            $parts[] = 'pointers: ' . implode(' ', array_unique($pointers));
        }

        return implode('; ', $parts);
    }

    /** Check whether one of the API's errors points at this order. */
    public function blames(string $incrementId): bool
    {
        return isset($this->reasons[$incrementId]);
    }

    /**
     * Get every reason the API gave, per order.
     *
     * @return array<string,string[]> increment id => its reasons
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /** Get the API's own message, plus the errors it named no order for. Orders it did not blame fail with this. */
    public function summary(): string
    {
        return $this->summary;
    }

    /** Check whether the chunk can be sent again without the blamed orders. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Check whether the API refused the chunk outright, so nothing shipped.
     * False for a timeout or a 5xx: the call can have been processed, and a second run pays twice.
     */
    public function isRefusal(): bool
    {
        return $this->refused;
    }

    /**
     * Check whether re-sending is safe. Only a 422 guarantees that nothing was created.
     * Anything else can have been processed, and the API deduplicates nothing, so a retry risks a
     * second billable shipment. Add a status only when the same is true for it.
     */
    private static function isSafeToRetry(Throwable $e): bool
    {
        return $e instanceof ApiException && 422 === $e->getCode();
    }

    /** Get the index of the shipment a JSON Pointer points at, or null when it names no shipment. */
    private static function shipmentIndexIn(string $pointer): ?int
    {
        if (preg_match('#shipments\D{0,2}(\d+)#i', $pointer, $matches)) {
            return (int) $matches[1];
        }

        return ctype_digit($pointer) ? (int) $pointer : null;
    }

    /**
     * Get the API's own wording: the `title` category, with `detail` or `message` in parentheses.
     * The detail names the offending value; a title alone is only a category ("Invalid postal code").
     *
     * @param mixed $error
     */
    private static function errorText($error): string
    {
        if (is_string($error)) {
            return trim($error);
        }

        if (! is_array($error)) {
            return '';
        }

        $title  = trim((string) ($error['title'] ?? ''));
        $detail = trim((string) ($error['detail'] ?? $error['message'] ?? ''));

        if ('' === $detail) {
            return $title;
        }

        return '' === $title || $title === $detail ? $detail : sprintf('%s (%s)', $title, $detail);
    }

    /** Append the refused field: `/data/shipments/0/recipient/postal_code` becomes `recipient.postal_code`. */
    private static function withField(string $pointer, string $text): string
    {
        // Slash for a JSON Pointer, dot for the dotted key the documented shape would use.
        if (! preg_match('#shipments[/.]\d+[/.](.+)$#', $pointer, $matches)) {
            return $text;
        }

        return sprintf('%s (%s)', $text, str_replace('/', '.', $matches[1]));
    }
}
