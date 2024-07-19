<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup\Methods;

use MyParcelBE\Magento\Setup\Methods\Interfaces\QueryInterface;

class Delete implements QueryInterface
{
    /**
     * @var string
     */
    private $table;

    /**
     * @var array
     */
    private $conditions = [];

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
            'DELETE FROM %s%s',
            $this->table,
            empty($this->conditions)
                ? ''
                : sprintf(
                    ' WHERE %s',
                    implode(' AND ', $this->conditions)
            )
        );
    }

    /**
     * @param  string ...$where
     *
     * @return $this
     */
    public function where(string ...$where): self
    {
        foreach ($where as $arg) {
            $this->conditions[] = $arg;
        }

        return $this;
    }
}
