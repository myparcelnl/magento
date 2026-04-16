<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

class ProblemDetails implements \JsonSerializable
{
    public const CONTENT_TYPE = 'application/problem+json; charset=utf-8';

    private const TITLE_MAP = [
        400 => 'Invalid Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Order Not Found',
        406 => 'Unsupported API Version',
        409 => 'Incompatible Version Headers',
        500 => 'Internal Server Error',
    ];

    private ?string $type;
    private int $status;
    private string $title;
    private string $detail;

    public function __construct(?string $type, int $status, string $title, string $detail)
    {
        $this->type   = $type;
        $this->status = $status;
        $this->title  = $title;
        $this->detail = $detail;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public static function titleForStatus(int $status): string
    {
        return self::TITLE_MAP[$status] ?? 'Error';
    }

    public static function fromStatus(int $status, string $detail, ?string $type = null): self
    {
        return new self($type, $status, self::titleForStatus($status), $detail);
    }

    public function toJsonString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): array
    {
        return [
            'type'   => $this->type,
            'status' => $this->status,
            'title'  => $this->title,
            'detail' => $this->detail,
        ];
    }
}
