<?php

namespace MyParcelNL\Magento\Setup\Methods;

use MyParcelNL\Magento\Setup\Methods\Interfaces\QueryInterface;

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
        return 'UPDATE ' . $this->table
            . ' SET ' . implode(', ', $this->columns)
            . ($this->conditions === [] ? '' : ' WHERE ' . implode(' AND ', $this->conditions));
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
     * @param  string ...$columns
     *
     * @return $this
     */
    public function set(string $key, string $value): self
    {
        $this->columns[] = $key . ' = ' . $value;
        return $this;
    }
}