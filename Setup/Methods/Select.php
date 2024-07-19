<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup\Methods;

use MyParcelBE\Magento\Setup\Methods\Interfaces\QueryInterface;

class Select implements QueryInterface
{
    /**
     * @var array
     */
    private $fields = [];

    /**
     * @var array
     */
    private $conditions = [];

    /**
     * @var array
     */
    private $order = [];

    /**
     * @var array
     */
    private $from = [];

    /**
     * @var array
     */
    private $join = [];

    /**
     * @var array
     */
    private $groupBy = [];

    /**
     * @var
     */
    private $limit;

    /**
     * @var bool
     */
    private $distinct = false;

    /**
     * @param  array $select
     */
    public function __construct(array $select)
    {
        $this->fields = $select;
    }

    /**
     * @param  string ...$select
     *
     * @return $this
     */
    public function select(string ...$select): self
    {
        foreach ($select as $arg) {
            $this->fields[] = $arg;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return trim(
            sprintf(
                'SELECT %s%s FROM %s%s%s%s%s%s',
                $this->distinct ? 'DISTINCT ' : '',
                implode(', ', $this->fields),
                implode(', ', $this->from),
                empty($this->join)
                    ? ''
                    : implode(' ', $this->join),
                empty($this->conditions)
                    ? ''
                    : sprintf(' WHERE %s', implode(' AND ', $this->conditions)),
                empty($this->groupBy)
                    ? ''
                    : sprintf(' GROUP BY %s', implode(', ', $this->groupBy)),
                empty($this->order)
                    ? ''
                    : sprintf(implode(', ', $this->order)),
                empty($this->limit)
                    ? ''
                    : sprintf(' LIMIT %s', $this->limit)
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

    /**
     * @param  string      $table
     * @param  null|string $alias
     *
     * @return $this
     */
    public function from(string $table, ?string $alias = null): self
    {
        $this->from[] = null === $alias ? $table : "{$table} AS {$alias}";

        return $this;
    }

    /**
     * @param  int $limit
     *
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * @param  string ...$order
     *
     * @return $this
     */
    public function orderBy(string ...$order): self
    {
        foreach ($order as $arg) {
            $this->order[] = $arg;
        }

        return $this;
    }

    /**
     * @param  string ...$join
     *
     * @return $this
     */
    public function innerJoin(string ...$join): self
    {
        foreach ($join as $arg) {
            $this->join[] = sprintf('INNER JOIN %s', $arg);
        }

        return $this;
    }

    /**
     * @param  string ...$join
     *
     * @return $this
     */
    public function leftJoin(string ...$join): self
    {
        foreach ($join as $arg) {
            $this->join[] = sprintf('LEFT JOIN %s', $arg);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * @param  string ...$groupBy
     *
     * @return $this
     */
    public function groupBy(string ...$groupBy): self
    {
        foreach ($groupBy as $arg) {
            $this->groupBy[] = $arg;
        }

        return $this;
    }
}
