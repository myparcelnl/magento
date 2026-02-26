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
        500 => 'Internal Server Error',
    ];

    /** @var string|null */
    private $type;

    /** @var int */
    private $status;

    /** @var string */
    private $title;

    /** @var string */
    private $detail;

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
