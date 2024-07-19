<?php

namespace MyParcelBE\Magento\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class DeliveryDate extends Column
{
    const NAME = 'drop_off_day';

    /**
     * Set column MyParcel delivery date to order grid
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        parent::prepareDataSource($dataSource);

        if (! isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as & $item) {
            if (isset($item[self::NAME])) {
                $item[$this->getData('name')] = $item[self::NAME];
            }
        }

        return $dataSource;
    }
}
