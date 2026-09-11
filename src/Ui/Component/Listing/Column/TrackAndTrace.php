<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MyParcelNL\Magento\Service\TrackTrace\LinkResolver;

class TrackAndTrace extends Column
{
    public const NAME          = 'track_number';
    public const VALUE_EMPTY   = '–';
    public const VALUE_PRINTED = 'printed';
    public const VALUE_CONCEPT = 'concept';

    /**
     * Values that stand in for a barcode without being one. A track holding one of these is still
     * waiting, which is what the status cron selects on.
     *
     * @var string[]
     */
    public const PLACEHOLDERS = [self::VALUE_EMPTY, self::VALUE_PRINTED];

    /**
     * Script tag to unbind the click event from the td wrapping the barcode link.
     */
    private const SCRIPT_UNBIND_CLICK = "<script type='text/javascript'>jQuery('.myparcel-barcode-link').closest('td').unbind('click');</script>";

    private LinkResolver $links;

    public function __construct(
        ContextInterface   $context,
        UiComponentFactory $uiComponentFactory,
        LinkResolver       $links,
        array              $components = [],
        array              $data = []
    )
    {
        parent::__construct($context, $uiComponentFactory, $components, $data);

        $this->links = $links;
    }

    /**
     * Set column MyParcel barcode to order grid
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        parent::prepareDataSource($dataSource);

        if (! isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $orderIds = array_filter(
            array_map(
                static fn(array $item): int => (int) ($item['entity_id'] ?? 0),
                $dataSource['data']['items']
            )
        );

        $linksByOrder = $this->links->forOrders($orderIds);
        $name         = $this->getData('name');

        foreach ($dataSource['data']['items'] as & $item) {
            $html = $this->links->html($linksByOrder[(int) ($item['entity_id'] ?? 0)] ?? []);

            if ('' === $html) {
                continue;
            }

            // Render the T&T as a link and add the script to remove the click handler.
            $item[$name] = $html . self::SCRIPT_UNBIND_CLICK;
        }

        return $dataSource;
    }
}
