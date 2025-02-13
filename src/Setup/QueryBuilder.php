<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup;

use MyParcelNL\Magento\Setup\Methods\Delete;
use MyParcelNL\Magento\Setup\Methods\Insert;
use MyParcelNL\Magento\Setup\Methods\Select;
use MyParcelNL\Magento\Setup\Methods\Update;

class QueryBuilder
{
    /**
     * @param  string ...$select
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Select
     */
    public function select(string ...$select): Select
    {
        return new Select($select);
    }

    /**
     * @param  string $into
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Insert
     */
    public function insert(string $into): Insert
    {
        return new Insert($into);
    }

    /**
     * @param  string $table
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Update
     */
    public function update(string $table): Update
    {
        return new Update($table);
    }

    /**
     * @param  string $table
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Delete
     */
    public function delete(string $table): Delete
    {
        return new Delete($table);
    }
}
