<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup;

use MyParcelBE\Magento\Setup\Methods\Select;
use MyParcelBE\Magento\Setup\Methods\Update;
use MyParcelBE\Magento\Setup\Methods\Insert;
use MyParcelBE\Magento\Setup\Methods\Delete;

class QueryBuilder
{
    /**
     * @param  string ...$select
     *
     * @return \MyParcelBE\Magento\Setup\Methods\Select
     */
    public function select(string ...$select): Select
    {
        return new Select($select);
    }

    /**
     * @param  string $into
     *
     * @return \MyParcelBE\Magento\Setup\Methods\Insert
     */
    public function insert(string $into): Insert
    {
        return new Insert($into);
    }

    /**
     * @param  string $table
     *
     * @return \MyParcelBE\Magento\Setup\Methods\Update
     */
    public function update(string $table): Update
    {
        return new Update($table);
    }

    /**
     * @param  string $table
     *
     * @return \MyParcelBE\Magento\Setup\Methods\Delete
     */
    public function delete(string $table): Delete
    {
        return new Delete($table);
    }
}
