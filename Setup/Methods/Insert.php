<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup\Methods;

use MyParcelBE\Magento\Setup\Methods\Interfaces\QueryInterface;

class Insert implements QueryInterface
{
    /**
     * @var string
     */
    private $table;

    /**
     * @var array
     */
    private $columns = [];

    /**
     * @var array
     */
    private $values = [];

    /**
     * @param  string $table
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $this->columns),
            implode(', ', $this->values)
        );
    }

    /**
     * @param  string ...$columns
     *
     * @return $this
     */
    public function columns(string ...$columns): self
    {
        $this->columns = $columns;

        foreach ($columns as $column) {
            $this->values[] = sprintf(':%s', $column);
        }

        return $this;
    }
}
