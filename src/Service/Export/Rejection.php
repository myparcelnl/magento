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
     * Puts each error from a rejected chunk against the order it belongs to.
     *
     * The API refuses a batch as a whole, so without this every order in the chunk gets the same
     * message and the admin cannot tell which one is at fault.
     *
     * **The body is RFC 9457 Problem Details, which is not what the spec documents.** The Core API
     * spec's `common_responses_user_error` declares `message` plus `errors[]` of
     * `{status, code, title, message}`; what actually arrives is `{type, title, detail, instance}`
     * per error, with the summary in `detail`. The generated model declares neither `detail` nor
     * `instance`, so deserializing loses both the useful text and the only pointer to a shipment —
     * hence json_decode on getResponseBody(). Both shapes are read, because the spec is what the
     * next SDK regeneration will follow.
     *
     * `instance` is a JSON Pointer — `/data/shipments/0/recipient/postal_code` — and the index in it
     * is the position in the request, which is this chunk's own order.
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
                $reasons[$chunk[$index]->incrementId()][] = self::withField($pointer, $text);
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
     * The body's shape, without its values.
     *
     * The shape is not the documented one, so a divergence has to stay visible — but `title` and
     * `detail` are free text from the API and quote the field they refused, which on an address is
     * the consumer's. Keys and JSON Pointers name fields and carry no values, and they are what the
     * next divergence is actually read from.
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

    public function blames(string $incrementId): bool
    {
        return isset($this->reasons[$incrementId]);
    }

    /** @return array<string,string[]> increment id => its reasons */
    public function reasons(): array
    {
        return $this->reasons;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Whether the API refused the chunk outright, which is the difference between "these did not
     * ship" and "nobody knows whether these shipped".
     *
     * False for a timeout or a 5xx: the call may have been processed, and the API deduplicates
     * nothing, so an admin who reads it as a refusal and runs the action again pays twice.
     */
    public function isRefusal(): bool
    {
        return $this->refused;
    }

    /**
     * Whether re-sending is safe, which is a question about what the failed call left behind.
     *
     * A 422 means the API validated the request and refused it, so nothing was created. Anything else
     * — a timeout, a 5xx, a transport error — may have been processed, and the API deduplicates
     * nothing, so a retry would risk a second billable shipment. Widening this beyond 422
     * needs that same argument made for the status being added.
     */
    private static function isSafeToRetry(Throwable $e): bool
    {
        return $e instanceof ApiException && 422 === $e->getCode();
    }

    /** The index of the shipment a JSON Pointer points at, or null when it names no shipment. */
    private static function shipmentIndexIn(string $pointer): ?int
    {
        if (preg_match('#shipments\D{0,2}(\d+)#i', $pointer, $matches)) {
            return (int) $matches[1];
        }

        return ctype_digit($pointer) ? (int) $pointer : null;
    }

    /**
     * The API's own wording, preferring the sentence that names the offending value.
     *
     * `detail` is RFC 9457's; `message` is what the spec declares. `title` alone is a category
     * ("Invalid postal code") and only stands in when there is nothing better.
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

        return '' === $title || $title === $detail ? $detail : $title . ' — ' . $detail;
    }

    /**
     * Keeps the field the API objected to visible, trimmed of the envelope the admin does not need:
     * `/data/shipments/0/recipient/postal_code` reads as `recipient.postal_code`.
     */
    private static function withField(string $pointer, string $text): string
    {
        // Slash for a JSON Pointer, dot for the dotted key the documented shape would use.
        if (! preg_match('#shipments[/.]\d+[/.](.+)$#', $pointer, $matches)) {
            return $text;
        }

        return sprintf('%s (%s)', $text, str_replace('/', '.', $matches[1]));
    }
}
