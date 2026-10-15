<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

use Magento\Config\Model\Config\Source\Yesno;
use MyParcelNL\Magento\Block\System\Config\Form\ApiAccessTokenButton;
use MyParcelNL\Magento\Block\System\Config\Form\DeliveryCostsMatrix;
use MyParcelNL\Magento\Block\System\Config\Form\InsuranceAmount;
use MyParcelNL\Magento\Block\System\Config\Form\OrderManagementInfo;
use MyParcelNL\Magento\Block\System\Config\Form\SettingsButton;
use MyParcelNL\Magento\Block\System\Config\Form\WeightUnitNote;
use MyParcelNL\Magento\Model\Settings\InsuranceAmountSetting;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DigitalStampWeightOptions;
use MyParcelNL\Magento\Model\Source\DropOffDelayDays;
use MyParcelNL\Magento\Model\Source\LargeFormatOptions;
use MyParcelNL\Magento\Model\Source\NumberOfDays;
use MyParcelNL\Magento\Model\Source\PaperType;
use MyParcelNL\Magento\Model\Source\PickupLocationsView;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Model\Source\WeightType;
use MyParcelNL\Magento\Service\Config;

/**
 * The field templates the settings form is built from, and the lists the module owns because
 * capabilities cannot state them.
 *
 * Every label, tooltip and comment is an i18n msgid. Change one and six locales lose their
 * translation, so add the new string to i18n/ in the same change.
 */
final class Catalogue
{
    public const FACT_PACKAGE_TYPE  = 'packageType:';
    public const FACT_DELIVERY_TYPE = 'deliveryType:';
    public const FACT_OPTION        = 'option:';

    /** Options with a checkout surcharge. A price is the merchant's decision, so a new option gets none. */
    public const FEE_OPTIONS = [ShipmentOption::SIGNATURE, ShipmentOption::ONLY_RECIPIENT, ShipmentOption::RECEIPT_CODE];

    /** Options automated above an order total. The rest are automated for every order. */
    public const FROM_PRICE_OPTIONS = [
        ShipmentOption::SIGNATURE,
        ShipmentOption::RECEIPT_CODE,
        ShipmentOption::ONLY_RECIPIENT,
        ShipmentOption::RETURN,
        ShipmentOption::LARGE_FORMAT,
        ShipmentOption::COLLECT,
        ShipmentOption::FRESH_FOOD,
        ShipmentOption::FROZEN,
    ];

    /** The options the checkout widget renders, so the only ones with a `delivery` toggle. */
    public const CHECKOUT_OPTIONS = [ShipmentOption::SIGNATURE, ShipmentOption::RECEIPT_CODE, ShipmentOption::ONLY_RECIPIENT];

    /** Mailbox options, which go in the mailbox group instead of `default_options`. */
    public const MAILBOX_OPTIONS = [ShipmentOption::PRIORITY_DELIVERY];

    /** The order the Automate toggles show in. An option not listed follows, in capabilities order. */
    private const DEFAULT_OPTIONS_ORDER = [
        ShipmentOption::SIGNATURE,
        ShipmentOption::RECEIPT_CODE,
        ShipmentOption::ONLY_RECIPIENT,
        ShipmentOption::RETURN,
        ShipmentOption::LARGE_FORMAT,
        ShipmentOption::COLLECT,
        ShipmentOption::AGE_CHECK,
        ShipmentOption::HIDE_SENDER,
        ShipmentOption::FRESH_FOOD,
        ShipmentOption::FROZEN,
    ];

    /** The settings say "Large format" where the New Shipment form says "Large package". */
    private const SETTINGS_LABELS = [ShipmentOption::LARGE_FORMAT => 'Large format'];

    private const OPTION_TOOLTIPS = [
        ShipmentOption::AGE_CHECK   => 'The age check is intended for parcel shipments for which the recipient must show 18+ by means of a proof of identity. With this shipping option Signature on receipt and Only recipient are included. The age 18+ is further excluded from the delivery options morning and evening delivery.',
        ShipmentOption::HIDE_SENDER => 'Activating the \'hide sender\' option will remove the name and address of the sender from the label',
    ];

    /** The from-price fields that have a tooltip. It names the option, so a new one would be a new msgid. */
    private const FROM_PRICE_TOOLTIP_OPTIONS = [
        ShipmentOption::SIGNATURE,
        ShipmentOption::ONLY_RECIPIENT,
        ShipmentOption::RETURN,
        ShipmentOption::LARGE_FORMAT,
    ];

    /** Each title, and the facts that bring it in. No facts means always. */
    private const TITLES = [
        'delivery_title'                 => ['Delivery title', null, []],
        'standard_delivery_title'        => ['Standard delivery Title', self::TIMES_TOOLTIP, []],
        'signature_title'                => ['Signature on receipt title', null, [self::FACT_OPTION . ShipmentOption::SIGNATURE]],
        'receipt_code_title'             => ['Receipt code title', null, [self::FACT_OPTION . ShipmentOption::RECEIPT_CODE]],
        'hide_sender_title'              => ['Hide sender title', null, [self::FACT_OPTION . ShipmentOption::HIDE_SENDER]],
        'only_recipient_title'           => ['Only recipient title', null, [self::FACT_OPTION . ShipmentOption::ONLY_RECIPIENT]],
        'priority_delivery_title'        => ['Priority delivery title', null, [self::FACT_OPTION . ShipmentOption::PRIORITY_DELIVERY]],
        'morning_title'                  => ['Morning title', self::TIMES_TOOLTIP, [self::FACT_DELIVERY_TYPE . DeliveryType::MORNING_NAME]],
        'evening_title'                  => ['Evening title', self::TIMES_TOOLTIP, [self::FACT_DELIVERY_TYPE . DeliveryType::EVENING_NAME]],
        'mailbox_title'                  => ['Mailbox title', null, [self::FACT_PACKAGE_TYPE . PackageType::MAILBOX_NAME]],
        'digital_stamp_title'            => ['Digital stamp title', null, [self::FACT_PACKAGE_TYPE . PackageType::DIGITAL_STAMP_NAME]],
        'package_small_title'            => ['Packet title', null, [self::FACT_PACKAGE_TYPE . PackageType::PACKAGE_SMALL_NAME]],
        'same_day_title'                 => [
            'Same day title',
            null,
            [self::FACT_DELIVERY_TYPE . DeliveryType::SAME_DAY_NAME, self::FACT_OPTION . ShipmentOption::SAME_DAY_DELIVERY],
        ],
        'pickup_title'                   => ['Pickup title', null, [self::FACT_DELIVERY_TYPE . DeliveryType::PICKUP_NAME]],
        'pickup_list_button_title'       => ['Pickup list button text', null, []],
        'pickup_map_button_title'        => ['Pickup map button text', null, []],
        'header_delivery_options'        => ['Delivery options header', 'Leave empty to hide the header above the delivery options.', []],
        'compact_back_to_overview_title' => ['Compact mode back to overview text', null, []],
        'compact_delivery_title'         => ['Compact mode delivery title', null, []],
        'compact_pickup_title'           => ['Compact mode pickup title', null, []],
        'pop_up_map_title'               => ['Pop-up map title', null, []],
        'pop_up_map_open_title'          => ['Pop-up map open button text', null, []],
        'pop_up_map_confirm_title'       => ['Pop-up map confirm button text', null, []],
    ];

    private const TIMES_TOOLTIP      = 'The times will be visible when nothing is filled in';
    private const FEE_TOOLTIP        = 'This will be added to the regular shipping price';
    private const PICKUP_FEE_TOOLTIP = 'Enter an amount that is either positive or negative. For example, do you want to give a discount for using this function or do you want to charge extra for this delivery option.';
    private const PRICE_VALIDATE     = 'validate-number validate-zero-or-greater';
    private const NOT_EXPORTABLE     = 'This carrier needs a module update before orders can ship with it.';

    /** @param string[]|null $facts null offers every title */
    public static function generalSection(?array $facts): Section
    {
        return new Section(rtrim(Config::XML_PATH_GENERAL, '/'), 'General settings', [
            self::apiGroup(),
            self::matrixGroup(),
            self::dateSettingsGroup(),
            self::printGroup(),
            self::emptyPackageWeightGroup(),
            self::shippingMethodsGroup(),
            self::deliveryTitlesGroup($facts),
            self::apiAccessGroup(),
            self::accountGroup(),
        ]);
    }

    public static function carrierSection(
        string       $carrier,
        CarrierShape $shape,
        bool         $exportable,
        bool         $internationalMailbox,
        array        $insuranceZones
    ): Section
    {
        $path   = Config::carrierPath($carrier);
        $groups = [
            self::deliveryGroup($carrier, $shape, $exportable),
            self::dropOffDaysGroup($path . 'drop_off_days/'),
            self::defaultOptionsGroup($carrier, $shape, $insuranceZones),
        ];

        if ($shape->hasPackageType(PackageType::DIGITAL_STAMP_NAME)) {
            $groups[] = self::digitalStampGroup($path . 'digital_stamp/');
        }

        if ($shape->hasPackageType(PackageType::MAILBOX_NAME)) {
            $groups[] = self::mailboxGroup($shape, $path . 'mailbox/', $internationalMailbox);
        }

        if ($shape->hasPackageType(PackageType::PACKAGE_SMALL_NAME)) {
            $groups[] = self::packageSmallGroup($path . 'package_small/');
        }

        if ($shape->hasDeliveryType(DeliveryType::MORNING_NAME)) {
            $groups[] = self::timedDeliveryGroup($path . 'morning/', 'morning', 'Morning delivery');
        }

        if ($shape->hasDeliveryType(DeliveryType::EVENING_NAME)) {
            $groups[] = self::timedDeliveryGroup($path . 'evening/', 'evening', 'Evening delivery');
        }

        if ($shape->hasDeliveryType(DeliveryType::PICKUP_NAME)) {
            $groups[] = self::pickupGroup($path . 'pickup/', $exportable);
        }

        return self::section($carrier, $groups);
    }

    /** The option's label in the settings, in English. */
    public static function labelFor(string $option): string
    {
        return self::SETTINGS_LABELS[$option] ?? ShipmentOption::labelFor($option);
    }

    /** @param Group[] $groups */
    private static function section(string $carrier, array $groups): Section
    {
        return new Section(rtrim(Config::carrierPath($carrier), '/'), Carrier::humanFor($carrier) . ' settings', $groups);
    }

    private static function deliveryGroup(string $carrier, CarrierShape $shape, bool $exportable): Group
    {
        $path   = Config::carrierPath($carrier) . 'delivery/';
        $active = self::activation(Field::select($path . 'active', 'Delivery enabled', Yesno::class)->withDefault('0'), $exportable);
        $fields = [$active];

        foreach ($shape->checkoutOptions() as $option) {
            $label  = self::labelFor($option);
            array_push($fields, ...self::toggleWithFee(
                $path,
                $active,
                $option,
                $label,
                in_array($option, self::FEE_OPTIONS, true)
            ));
        }

        return new Group('delivery', 'Delivery settings', $fields);
    }

    /**
     * A Yesno toggle shown while $parent is on, with a fee shown while both are.
     *
     * @return Field[]
     */
    private static function toggleWithFee(string $path, Field $parent, string $name, string $label, bool $withFee): array
    {
        $toggle = Field::select("{$path}{$name}_active", $label, Yesno::class)->withDefault('0')->dependsOn($parent);

        if (! $withFee) {
            return [$toggle];
        }

        return [
            $toggle,
            Field::text("{$path}{$name}_fee", "$label fee")
                ->withTooltip(self::FEE_TOOLTIP)
                ->withDefault('0')
                ->dependsOn($parent)
                ->dependsOn($toggle),
        ];
    }

    /** A carrier the module cannot export shows its switch, but nobody can turn it on. */
    private static function activation(Field $toggle, bool $exportable): Field
    {
        return $exportable ? $toggle : $toggle->asDisabled()->withComment(self::NOT_EXPORTABLE);
    }

    private static function dropOffDaysGroup(string $path): Group
    {
        $days   = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
        $fields = [];

        foreach ($days as $number => $day) {
            $active   = Field::select("{$path}day_{$number}_active", $day, Yesno::class)
                ->withTooltip('Whether you drop off packages on this day.')
                ->withDefault('0');
            $fields[] = $active;
            $fields[] = Field::time("{$path}cutoff_time_{$number}", 'Cut-off time')
                ->withTooltip('For orders before this time, the drop-off is considered done on this day.')
                ->withDefault('15,30,00')
                ->dependsOn($active);
        }

        return new Group(
            'drop_off_days',
            'Drop-off days',
            $fields,
            'Specify the days you hand in your parcels for this carrier. Specify the latest time a customer can order for you to deliver the package in time for shipment that day.'
        );
    }

    private static function defaultOptionsGroup(string $carrier, CarrierShape $shape, array $insuranceZones): Group
    {
        $path   = Config::carrierPath($carrier) . 'default_options/';
        $fields = [];

        foreach (self::ordered($shape->defaultOptions()) as $option) {
            if (ShipmentOption::INSURANCE !== $option) {
                array_push($fields, ...self::automateToggle($path, $option));
            }
        }

        if ($shape->hasInsurance()) {
            $fields = array_merge($fields, self::insuranceFields($path, $insuranceZones));
        }

        return new Group(
            'default_options',
            'Default shipping options',
            $fields,
            'Fill in your preferences for a shipment. These settings will only apply for the mass actions in the order grid. When creating a single shipment, these settings can be changed manually. These settings will activate based on the order total amount.'
        );
    }

    /**
     * @param  string[] $options
     * @return string[]
     */
    private static function ordered(array $options): array
    {
        $known = array_values(array_intersect(self::DEFAULT_OPTIONS_ORDER, $options));

        return array_merge($known, array_values(array_diff($options, $known)));
    }

    /** @return Field[] */
    private static function automateToggle(string $path, string $option): array
    {
        $label       = self::labelFor($option);
        $largeFormat = ShipmentOption::LARGE_FORMAT === $option;
        $toggle      = Field::select(
            "{$path}{$option}_active",
            "Automate '$label'",
            $largeFormat ? LargeFormatOptions::class : Yesno::class
        )->withDefault('0');

        if (isset(self::OPTION_TOOLTIPS[$option])) {
            $toggle = $toggle->withTooltip(self::OPTION_TOOLTIPS[$option]);
        }

        if (! in_array($option, self::FROM_PRICE_OPTIONS, true)) {
            return [$toggle];
        }

        $fromPrice = Field::text("{$path}{$option}_from_price", 'From price')
            ->withValidate(self::PRICE_VALIDATE)
            ->withDefault('1')
            ->dependsOn($toggle, $largeFormat ? 'price' : '1');

        if (in_array($option, self::FROM_PRICE_TOOLTIP_OPTIONS, true)) {
            $fromPrice = $fromPrice->withTooltip("'$label' operates above a certain order total amount");
        }

        return [$toggle, $fromPrice];
    }

    /** @return Field[] */
    private static function insuranceFields(string $path, array $zones): array
    {
        $zoneTexts = [
            'local'   => ['Insure orders up to', 'This setting applies to domestic shipments only'],
            'belgium' => ['Insure orders up to (BE)', 'A custom be insurance price within a range of possibilities.'],
            'eu'      => ['Insure orders up to (EU)', 'A custom eu insurance price within a range of possibilities.'],
            'row'     => ['Insure orders up to (ROW)', 'A custom row insurance price within a range of possibilities.'],
        ];

        $fields = [
            Field::text($path . 'insurance_from_price', 'Insure orders from (€)')
                ->withTooltip('The minimum amount from when insurance is active.')
                ->withValidate(self::PRICE_VALIDATE)
                ->withDefault('0'),
        ];

        foreach ($zones as $zone) {
            [$label, $tooltip] = $zoneTexts[$zone];
            $fields[]          = Field::text($path . InsuranceAmountSetting::fieldOf($zone), $label)
                ->withTooltip($tooltip)
                ->withFrontendModel(InsuranceAmount::class)
                ->withDefault('0');
        }

        $fields[] = Field::text($path . 'insurance_percentage', 'Insure orders for percentage')
            ->withTooltip('Use percentage of total order amount for insurance.')
            ->withValidate('validate-number validate-number-range number-range-0-100')
            ->withDefault('0');

        return $fields;
    }

    private static function digitalStampGroup(string $path): Group
    {
        $active = Field::select($path . 'active', 'Automate digital stamp', Yesno::class)
            ->withTooltip('Select automatically digital stamp packages based on weight')
            ->withDefault('0');

        return new Group('digital_stamp', 'Digital stamp settings', [
            $active,
            Field::select($path . 'default_weight', 'Default weight', DigitalStampWeightOptions::class)
                ->withTooltip('Price depends on the weight. Are the weights correctly filled in for all products? Choose \'No standard weight\' to let MyParcel calculate the weight itself. Select a standard weight here when the products do not contain correct weights.')
                ->withDefault('0')
                ->dependsOn($active),
        ]);
    }

    private static function mailboxGroup(CarrierShape $shape, string $path, bool $international): Group
    {
        $active = Field::select($path . 'active', 'Automate mailbox', Yesno::class)
            ->withTooltip('Select automatically mailbox packages based on weight or volume')
            ->withDefault('0');
        $fields = [
            $active,
            Field::text($path . 'weight', 'Mailbox weight')
                ->withTooltip('To use this optimally, set a weight or \'Fit in mailbox\' volume of each product. Regardless, shipments heavier than the weight specified here will not be mailbox.')
                ->withDefault('2000')
                ->dependsOn($active),
        ];

        foreach ($shape->mailboxOptions() as $option) {
            array_push($fields, ...self::toggleWithFee($path, $active, $option, self::labelFor($option), true));
        }

        // An account flag, not a capability: see InternationalMailbox.
        if ($international) {
            $fields[] = Field::select($path . 'international_active', 'International mailbox', Yesno::class)
                ->withTooltip('Only available for certain contracts. If this is not in your contract, the setting has no effect.')
                ->withDefault('0')
                ->dependsOn($active);
        }

        return new Group('mailbox', 'Mailbox settings', $fields);
    }

    private static function packageSmallGroup(string $path): Group
    {
        $active = Field::select($path . 'active', 'Automate Small Package', Yesno::class)
            ->withTooltip('Automatically select package type \'Small Package\' for orders under 2000 grams. Package type will be \'Small Package\' when a product has setting \'Fit in mailbox\' set to 0 and the weight is under 2000 grams.')
            ->withDefault('0');

        return new Group('package_small', 'Small Package settings', [
            $active,
            Field::text($path . 'weight', 'Small Package weight')
                ->withTooltip('Shipments heavier than the weight specified here will not be of package type \'Small Package\'.')
                ->withDefault('2000')
                ->dependsOn($active),
        ]);
    }

    private static function timedDeliveryGroup(string $path, string $id, string $label): Group
    {
        $active = Field::select($path . 'active', "$label active", Yesno::class)
            ->withTooltip("If age check is active then the $id delivery is not possible")
            ->withDefault('0');

        return new Group($id, $label, [
            $active,
            Field::text($path . 'fee', "$label fee")
                ->withTooltip(self::FEE_TOOLTIP)
                ->withDefault('0')
                ->dependsOn($active),
        ]);
    }

    private static function pickupGroup(string $path, bool $exportable): Group
    {
        $active = self::activation(Field::select($path . 'active', 'Pickup active', Yesno::class)->withDefault('0'), $exportable);

        return new Group('pickup', 'Pickup locations', [
            $active,
            Field::text($path . 'fee', 'Pickup fee')
                ->withTooltip(self::PICKUP_FEE_TOOLTIP)
                ->withDefault('0')
                ->dependsOn($active),
        ]);
    }

    /** @param string[]|null $facts */
    private static function deliveryTitlesGroup(?array $facts): Group
    {
        $path   = Config::XML_PATH_GENERAL . 'delivery_titles/';
        $fields = [];

        foreach (self::TITLES as $id => [$label, $tooltip, $bringsIn]) {
            if (null !== $facts && [] !== $bringsIn && [] === array_intersect($bringsIn, $facts)) {
                continue;
            }

            $field    = Field::text($path . $id, $label);
            $fields[] = null === $tooltip ? $field : $field->withTooltip($tooltip);
        }

        return new Group('delivery_titles', 'Titles', $fields);
    }

    private static function apiGroup(): Group
    {
        $path = Config::XML_PATH_GENERAL . 'api/';

        return new Group('api', 'API settings', [
            Field::text($path . 'key', 'API key')
                ->withTooltip('The API Key'),
            Field::button($path . 'button_id', 'Import MyParcel Backoffice settings', SettingsButton::class)
                ->withTooltip('Clicking this button will fetch settings from the MyParcel Backoffice and overwrite the current settings.'),
        ], 'Go to the general settings in the back office of MyParcel to generate the API Key.');
    }

    private static function matrixGroup(): Group
    {
        $path = Config::XML_PATH_GENERAL . 'matrix/';

        return new Group('matrix', 'Delivery costs', [
            Field::text($path . 'delivery_costs_ui', '')
                ->withFrontendModel(DeliveryCostsMatrix::class),
            Field::textarea($path . 'delivery_costs', ''),
            Field::select($path . 'use_free_shipping', 'Use Free Shipping', Yesno::class)
                ->withTooltip('Whether the MyParcel delivery costs are 0 when the Free Shipping delivery method is available.')
                ->withDefault('1'),
        ]);
    }

    private static function dateSettingsGroup(): Group
    {
        $path = Config::XML_PATH_GENERAL . 'date_settings/';

        return new Group('date_settings', 'Date settings', [
            Field::select($path . 'deliverydays_window', 'Number of days', NumberOfDays::class)
                ->withTooltip('Amount of days in the future customers can choose from in the checkout.')
                ->withDefault('7'),
            Field::select($path . 'dropoff_delay', 'Drop-off delay', DropOffDelayDays::class)
                ->withTooltip('This option allows you to set the number of days it takes you to pick, pack and hand in your parcels when ordered before the cutoff time.')
                ->withDefault('0'),
        ]);
    }

    private static function printGroup(): Group
    {
        $path = Config::XML_PATH_GENERAL . 'print/';

        return new Group('print', 'Print settings', [
            Field::select($path . 'paper_type', 'Paper type', PaperType::class)
                ->withTooltip('Select a standard orientation for printing labels.')
                ->withDefault('A4'),
            Field::text($path . 'label_description', 'Label description')
                ->withTooltip('This description will appear on the shipment label. The following parts can be used: %order_nr%, %delivery_date%, %product_id%, %product_name%, %product_qty%.')
                ->withDefault('%order_nr%'),
            // No default: an empty value is the account's home country, read at export.
            Field::text($path . 'country_of_origin', 'Country of origin')
                ->withTooltip('This country will appear on the international consignment labels. This is where your products are shipped from. You can use NL, BE, DE etc. This will be overridden by country of manufacture on product level.'),
            Field::select($path . 'create_concept_after_invoice', 'Create Concept', Yesno::class)
                ->withTooltip('Enable create label concept, when invoice is printed.')
                ->withDefault('0'),
            Field::select($path . 'weight_indication', 'I use the following weight type', WeightType::class)
                ->withTooltip('This is the type of weight that I use with my products.')
                ->withNoteModel(WeightUnitNote::class)
                ->inDefaultScopeOnly(),
            Field::text($path . 'export_chunk_size', 'Records per export request')
                ->withTooltip('How many shipments or orders are sent to MyParcel in one request. Leave empty for 20. Lower this if large exports time out. The maximum is 100.')
                ->withValidate('validate-digits validate-digits-range digits-range-1-100'),
        ]);
    }

    private static function emptyPackageWeightGroup(): Group
    {
        $path = Config::XML_PATH_GENERAL . 'empty_package_weight/';

        // Whole grams: Weight::getEmptyPackageWeightInGrams() reads "1.000" as 1.
        return new Group('empty_package_weight', 'Empty package weight', [
            Field::text($path . 'digital_stamp', 'Digital stamp')
                ->withValidate('validate-digits'),
            Field::text($path . 'mailbox', 'Mailbox')
                ->withValidate('validate-digits'),
            Field::text($path . 'package_small', 'Small package')
                ->withValidate('validate-digits'),
            Field::text($path . 'package', 'Package')
                ->withValidate('validate-digits'),
        ], 'Fill in weight in grams, regardless of \'weight type\' settings. This weight is added to the order weight. Exception: digital stamp will use the range class exactly when specified.');
    }

    private static function shippingMethodsGroup(): Group
    {
        $path = Config::XML_PATH_GENERAL . 'shipping_methods/';

        return new Group('shipping_methods', 'Delivery methods', [
            Field::select($path . 'show_details_in_summary', 'Show details in summary', Yesno::class)
                ->withTooltip('Where the shipping method is displayed, show the currently known details (yes) or the method title (no).')
                ->withDefault('1'),
            Field::select($path . 'pickup_locations_view', 'Preferred pickup locations view', PickupLocationsView::class)
                ->withTooltip('When pickup locations are enabled, the user can choose between map or list view. This setting decides which option will be selected first, upon opening the pickup locations.'),
            Field::select($path . 'pickup_locations_view_change_allowed', 'Switching the view is allowed', Yesno::class)
                ->withDefault('1'),
            Field::select($path . 'delivery_options_prices', 'Price shown in delivery options', PriceDeliveryOptionsView::class)
                ->withTooltip('This determines the way the price of delivery is shown to the customer through the delivery options. The price can be shown as a total for each delivery option or as a surchage on top of the regular shipping price.'),
            Field::select($path . 'exclude_parcel_lockers', 'Exclude parcel lockers', Yesno::class)
                ->withTooltip('When enabled, parcel lockers will be excluded from pickup locations for all products. Only physical pickup points will be shown.'),
            Field::select($path . 'compact_view', 'Compact view', Yesno::class)
                ->withTooltip('When enabled, the delivery options widget is rendered in compact mode.')
                ->withDefault('0'),
            Field::select($path . 'pop_up_map', 'Pop-up map', Yesno::class)
                ->withTooltip('When enabled, the pickup location map is displayed in a pop-up.')
                ->withDefault('0'),
        ]);
    }

    private static function accountGroup(): Group
    {
        return new Group('account', 'Account', [
            Field::button(Config::XML_PATH_GENERAL . 'account/order_management', 'Account features', OrderManagementInfo::class),
        ]);
    }

    private static function apiAccessGroup(): Group
    {
        return new Group('api_access', 'API Access', [
            Field::button(Config::XML_PATH_GENERAL . 'api_access_token', 'API access token', ApiAccessTokenButton::class),
        ], 'Generate a token for REST API access at this scope. The plaintext token is shown once, copy it before navigating away. Only its hash is stored. To rotate, click Generate again; the previous token at this scope stops working immediately. To clear a token (releasing its stores back to a parent-scope token if one exists), click Revoke.');
    }
}
