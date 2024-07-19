<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup\Methods;

use MyParcelBE\Magento\Setup\Methods\Interfaces\QueryInterface;

class Update implements QueryInterface
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
     * @var array
     */
    private $columns = [];

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
            'UPDATE %s SET %s%s',
            $this->table,
            implode(', ', $this->columns),
            empty($this->conditions)
                ? ''
                : sprintf(' WHERE %s', implode(' AND ', $this->conditions))
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

    /**
     * @param  string $key
     * @param  string $value
     *
     * @return $this
     */
    public function set(string $key, string $value): self
    {
        $this->columns[] = sprintf('%s = %s', $key, $value);

        return $this;
    }
}
