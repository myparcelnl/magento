<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Writes the values etc/config.xml supplied, as default-scope rows, where the install has no row.
 *
 * config.xml is gone, and GeneratedDefaults answers off and zero, so without these rows an
 * existing shop would lose every carrier, option and fee it never saved. Run it on an existing
 * install only: a new install starts from the generated defaults. Idempotent.
 */
class LegacyConfigDefaults
{
    /** etc/config.xml as it was removed, for the paths the settings form still offers. */
    public const VALUES = [
        'myparcelnl_magento_general/date_settings/deliverydays_window'                      => '7',
        'myparcelnl_magento_general/date_settings/dropoff_delay'                            => '0',
        'myparcelnl_magento_general/matrix/use_free_shipping'                               => '1',
        'myparcelnl_magento_general/shipping_methods/show_details_in_summary'               => '1',
        'myparcelnl_magento_general/shipping_methods/pickup_locations_view_change_allowed'  => '1',
        'myparcelnl_magento_general/shipping_methods/compact_view'                          => '0',
        'myparcelnl_magento_general/shipping_methods/pop_up_map'                            => '0',
        'myparcelnl_magento_general/print/paper_type'                                       => 'A4',
        'myparcelnl_magento_general/print/label_description'                                => '%order_nr%',
        'myparcelnl_magento_general/print/country_of_origin'                                => 'NL',
        'myparcelnl_magento_general/print/create_concept_after_invoice'                     => '0',
        'myparcelnl_magento_general/delivery_titles/delivery_title'                         => 'Thuis of op het werk bezorgd',
        'myparcelnl_magento_general/delivery_titles/standard_delivery_title'                => 'Standaardlevering',
        'myparcelnl_magento_general/delivery_titles/signature_title'                        => 'Handtekening voor ontvangst',
        'myparcelnl_magento_general/delivery_titles/only_recipient_title'                   => 'Niet bij de buren bezorgen',
        'myparcelnl_magento_general/delivery_titles/morning_title'                          => 'Ochtendlevering',
        'myparcelnl_magento_general/delivery_titles/evening_title'                          => 'Avondlevering',
        'myparcelnl_magento_general/delivery_titles/mailbox_title'                          => 'Brievenbuspakje',
        'myparcelnl_magento_general/delivery_titles/digital_stamp_title'                    => 'Digitale postzegel',
        'myparcelnl_magento_general/delivery_titles/pickup_title'                           => 'Ophalen bij een PostNL locatie',
        'myparcelnl_magento_general/delivery_titles/pickup_list_button_title'               => 'Lijst',
        'myparcelnl_magento_general/delivery_titles/pickup_map_button_title'                => 'Kaart',
        'myparcelnl_magento_postnl_settings/default_options/insurance_from_price'           => '0',
        'myparcelnl_magento_postnl_settings/default_options/insurance_local_amount'         => '0',
        'myparcelnl_magento_postnl_settings/default_options/insurance_belgium_amount'       => '0',
        'myparcelnl_magento_postnl_settings/default_options/insurance_eu_amount'            => '0',
        'myparcelnl_magento_postnl_settings/default_options/insurance_row_amount'           => '0',
        'myparcelnl_magento_postnl_settings/default_options/insurance_percentage'           => '0',
        'myparcelnl_magento_postnl_settings/default_options/signature_active'               => '0',
        'myparcelnl_magento_postnl_settings/default_options/signature_from_price'           => '1',
        'myparcelnl_magento_postnl_settings/default_options/only_recipient_active'          => '0',
        'myparcelnl_magento_postnl_settings/default_options/only_recipient_from_price'      => '1',
        'myparcelnl_magento_postnl_settings/default_options/return_active'                  => '0',
        'myparcelnl_magento_postnl_settings/default_options/return_from_price'              => '1',
        'myparcelnl_magento_postnl_settings/default_options/large_format_active'            => '0',
        'myparcelnl_magento_postnl_settings/default_options/large_format_from_price'        => '1',
        'myparcelnl_magento_postnl_settings/default_options/age_check_active'               => '0',
        'myparcelnl_magento_postnl_settings/delivery/active'                                => '1',
        'myparcelnl_magento_postnl_settings/delivery/signature_active'                      => '1',
        'myparcelnl_magento_postnl_settings/delivery/signature_fee'                         => '0.36',
        'myparcelnl_magento_postnl_settings/delivery/only_recipient_active'                 => '1',
        'myparcelnl_magento_postnl_settings/delivery/only_recipient_fee'                    => '0.29',
        'myparcelnl_magento_postnl_settings/digital_stamp/active'                           => '0',
        'myparcelnl_magento_postnl_settings/digital_stamp/default_weight'                   => '0',
        'myparcelnl_magento_postnl_settings/mailbox/active'                                 => '0',
        'myparcelnl_magento_postnl_settings/mailbox/weight'                                 => '2000',
        'myparcelnl_magento_postnl_settings/morning/active'                                 => '1',
        'myparcelnl_magento_postnl_settings/morning/fee'                                    => '10',
        'myparcelnl_magento_postnl_settings/evening/active'                                 => '1',
        'myparcelnl_magento_postnl_settings/evening/fee'                                    => '1.25',
        'myparcelnl_magento_postnl_settings/pickup/active'                                  => '1',
        'myparcelnl_magento_postnl_settings/pickup/fee'                                     => '0',
        'myparcelnl_magento_dhlforyou_settings/default_options/signature_active'            => '0',
        'myparcelnl_magento_dhlforyou_settings/default_options/signature_from_price'        => '1',
        'myparcelnl_magento_dhlforyou_settings/default_options/only_recipient_active'       => '0',
        'myparcelnl_magento_dhlforyou_settings/default_options/only_recipient_from_price'   => '1',
        'myparcelnl_magento_dhlforyou_settings/default_options/age_check_active'            => '0',
        'myparcelnl_magento_dhlforyou_settings/default_options/insurance_from_price'        => '0',
        'myparcelnl_magento_dhlforyou_settings/default_options/insurance_local_amount'      => '0',
        'myparcelnl_magento_dhlforyou_settings/default_options/insurance_belgium_amount'    => '0',
        'myparcelnl_magento_dhlforyou_settings/default_options/insurance_percentage'        => '0',
        'myparcelnl_magento_dhlforyou_settings/delivery/active'                             => '1',
        'myparcelnl_magento_dhlforyou_settings/delivery/signature_active'                   => '1',
        'myparcelnl_magento_dhlforyou_settings/delivery/signature_fee'                      => '0.36',
        'myparcelnl_magento_dhlforyou_settings/delivery/only_recipient_active'              => '1',
        'myparcelnl_magento_dhlforyou_settings/delivery/only_recipient_fee'                 => '0.29',
        'myparcelnl_magento_dhlforyou_settings/mailbox/active'                              => '0',
        'myparcelnl_magento_dhlforyou_settings/mailbox/weight'                              => '2000',
        'myparcelnl_magento_dhlforyou_settings/pickup/active'                               => '1',
        'myparcelnl_magento_dhlforyou_settings/pickup/fee'                                  => '0',
        'myparcelnl_magento_dhleuroplus_settings/default_options/insurance_from_price'      => '0',
        'myparcelnl_magento_dhleuroplus_settings/default_options/insurance_local_amount'    => '0',
        'myparcelnl_magento_dhleuroplus_settings/default_options/insurance_belgium_amount'  => '0',
        'myparcelnl_magento_dhleuroplus_settings/default_options/insurance_eu_amount'       => '0',
        'myparcelnl_magento_dhleuroplus_settings/default_options/insurance_row_amount'      => '0',
        'myparcelnl_magento_dhleuroplus_settings/default_options/insurance_percentage'      => '0',
        'myparcelnl_magento_dhleuroplus_settings/delivery/active'                           => '1',
        'myparcelnl_magento_dhlparcelconnect_settings/default_options/insurance_from_price' => '0',
        'myparcelnl_magento_dhlparcelconnect_settings/default_options/insurance_eu_amount'  => '0',
        'myparcelnl_magento_dhlparcelconnect_settings/default_options/insurance_row_amount' => '0',
        'myparcelnl_magento_dhlparcelconnect_settings/default_options/insurance_percentage' => '0',
        'myparcelnl_magento_dhlparcelconnect_settings/delivery/active'                      => '1',
        'myparcelnl_magento_dhlparcelconnect_settings/pickup/active'                        => '1',
        'myparcelnl_magento_dhlparcelconnect_settings/pickup/fee'                           => '0',
        'myparcelnl_magento_upsstandard_settings/default_options/insurance_from_price'      => '0',
        'myparcelnl_magento_upsstandard_settings/default_options/insurance_local_amount'    => '0',
        'myparcelnl_magento_upsstandard_settings/default_options/insurance_percentage'      => '0',
        'myparcelnl_magento_upsstandard_settings/delivery/active'                           => '1',
        'myparcelnl_magento_dpd_settings/delivery/active'                                   => '1',
        'myparcelnl_magento_dpd_settings/pickup/active'                                     => '1',
        'myparcelnl_magento_dpd_settings/pickup/fee'                                        => '0',
        'myparcelnl_magento_dpd_settings/mailbox/active'                                    => '0',
        'myparcelnl_magento_dpd_settings/mailbox/weight'                                    => '2000',
        'myparcelnl_magento_gls_settings/default_options/insurance_from_price'              => '0',
        'myparcelnl_magento_gls_settings/default_options/insurance_local_amount'            => '100',
        'myparcelnl_magento_gls_settings/default_options/insurance_belgium_amount'          => '0',
        'myparcelnl_magento_gls_settings/default_options/insurance_eu_amount'               => '100',
        'myparcelnl_magento_gls_settings/default_options/insurance_row_amount'              => '100',
        'myparcelnl_magento_gls_settings/default_options/insurance_percentage'              => '0',
        'myparcelnl_magento_gls_settings/delivery/active'                                   => '1',
        'myparcelnl_magento_gls_settings/delivery/signature_active'                         => '1',
        'myparcelnl_magento_gls_settings/delivery/signature_fee'                            => '0.36',
        'myparcelnl_magento_gls_settings/delivery/only_recipient_active'                    => '1',
        'myparcelnl_magento_gls_settings/delivery/only_recipient_fee'                       => '0.29',
        'myparcelnl_magento_gls_settings/pickup/active'                                     => '1',
        'myparcelnl_magento_gls_settings/pickup/fee'                                        => '0',
        'myparcelnl_magento_trunkrs_settings/default_options/signature_active'              => '0',
        'myparcelnl_magento_trunkrs_settings/default_options/signature_from_price'          => '1',
        'myparcelnl_magento_trunkrs_settings/default_options/only_recipient_active'         => '0',
        'myparcelnl_magento_trunkrs_settings/default_options/only_recipient_from_price'     => '1',
        'myparcelnl_magento_trunkrs_settings/default_options/age_check_active'              => '0',
        'myparcelnl_magento_trunkrs_settings/default_options/receipt_code_active'           => '0',
        'myparcelnl_magento_trunkrs_settings/default_options/receipt_code_from_price'       => '1',
        'myparcelnl_magento_trunkrs_settings/default_options/fresh_food_active'             => '0',
        'myparcelnl_magento_trunkrs_settings/default_options/fresh_food_from_price'         => '1',
        'myparcelnl_magento_trunkrs_settings/default_options/frozen_active'                 => '0',
        'myparcelnl_magento_trunkrs_settings/default_options/frozen_from_price'             => '1',
        'myparcelnl_magento_trunkrs_settings/delivery/active'                               => '1',
        'myparcelnl_magento_trunkrs_settings/delivery/signature_active'                     => '1',
        'myparcelnl_magento_trunkrs_settings/delivery/signature_fee'                        => '0.36',
        'myparcelnl_magento_trunkrs_settings/delivery/only_recipient_active'                => '1',
        'myparcelnl_magento_trunkrs_settings/delivery/only_recipient_fee'                   => '0.29',
        'myparcelnl_magento_trunkrs_settings/delivery/receipt_code_active'                  => '1',
        'myparcelnl_magento_trunkrs_settings/delivery/receipt_code_fee'                     => '0',
    ];

    private CollectionFactory $collectionFactory;
    private WriterInterface   $configWriter;

    public function __construct(CollectionFactory $collectionFactory, WriterInterface $configWriter)
    {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
    }

    public function run(): void
    {
        $stored = [];
        $rows   = $this->collectionFactory->create()
            ->addFieldToFilter('scope', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->addFieldToFilter('path', ['in' => array_keys(self::VALUES)])
            ->getItems();

        foreach ($rows as $row) {
            $stored[(string) $row->getData('path')] = true;
        }

        foreach (self::VALUES as $path => $value) {
            if (! isset($stored[$path])) {
                $this->configWriter->save($path, $value);
            }
        }
    }
}
