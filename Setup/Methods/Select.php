<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Methods;

use MyParcelNL\Magento\Setup\Methods\Interfaces\QueryInterface;

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
                "SELECT %s%s FROM %s%s%s%s%s%s",
                true === $this->distinct ? 'DISTINCT ' : '',
                implode(', ', $this->fields),
                implode(', ', $this->from),
                $this->join===[] ? '' : implode(' ', $this->join),
                $this->conditions===[] ? '' : ' WHERE ' . implode(' AND ', $this->conditions),
                $this->groupBy===[] ? '' : ' GROUP BY ' . implode(', ', $this->groupBy),
                $this->order===[] ? '' : ' ORDER BY ' . implode(', ', $this->order),
                $this->limit===null ? '' : ' LIMIT ' . $this->limit
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
        $this->from[] = null === $alias ? $table : "${table} AS ${alias}";

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
            $this->join[] = "INNER JOIN $arg";
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
            $this->join[] = "LEFT JOIN $arg";
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
