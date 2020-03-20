<?php


namespace MyParcelNL\Magento\Model\Source;


use Magento\Framework\Option\ArrayInterface;

class CountryOfOrigin implements ArrayInterface
{

    public function toOptionArray()
    {
        return [
            [
                'value' => 'NL',
                'label' => __('NL')
            ],
            [
                'value' => 'DE',
                'label' => __('DE')
            ],
            [
                'value' => 'BE',
                'label' => __('BE')
            ],
            [
                'value' => 'FR',
                'label' => __('FR')
            ],
            [
                'value' => 'ES',
                'label' => __('ES')
            ],
            [
                'value' => 'UK',
                'label' => __('UK')
            ],
            [
                'value' => 'US',
                'label' => __('US')
            ],
            [
                'value' => 'IT',
                'label' => __('IT')
            ],
            [
                'value' => 'CH',
                'label' => __('CH')
            ],
        ];
    }

    public function toArray()
    {
        return [
            'NL' => __('NL'),
            'DE' => __('DE'),
            'BE' => __('BE'),
            'FR' => __('FR'),
            'ES' => __('ES'),
            'UK' => __('UK'),
            'US' => __('US'),
            'IT' => __('IT'),
            'CH' => __('CH'),
        ];
    }

}
