<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup;

use MyParcelNL\Magento\Setup\Methods\Select;
use MyParcelNL\Magento\Setup\Methods\Update;
use MyParcelNL\Magento\Setup\Methods\Insert;
use MyParcelNL\Magento\Setup\Methods\Delete;

class QueryBuilder
{
    /**
     * @param  string ...$select
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Select
     */
    public static function select(string ...$select): Select
    {
        return new Select($select);
    }

    /**
     * @param  string $into
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Insert
     */
    public static function insert(string $into): Insert
    {
        return new Insert($into);
    }

    /**
     * @param  string $table
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Update
     */
    public static function update(string $table): Update
    {
        return new Update($table);
    }

    /**
     * @param  string $table
     *
     * @return \MyParcelNL\Magento\Setup\Methods\Delete
     */
    public static function delete(string $table): Delete
    {
        return new Delete($table);
    }
}
