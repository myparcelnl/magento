<?php

namespace MyParcelBE\Magento\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class ShippingStatus extends Column
{
    const NAME = 'track_status';

    /**
     * Set column MyParcel shipping status to order grid
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
