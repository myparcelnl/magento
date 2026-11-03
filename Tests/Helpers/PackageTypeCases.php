<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\Config;

/**
 * One table of package-type decisions.
 *
 * It was written against PackageRepository and run green there before PackageTypeResolver existed,
 * then run unchanged against the resolver. Because the expected values were never transcribed a
 * second time, the table records what the module did, not what the rewrite intended it to do. Treat
 * a change to an expected value as a behaviour change that needs saying out loud.
 *
 * Each case:
 *   items      - qty, weight and the myparcel_* attributes the product carries. An attribute left
 *                out has no row at all, which is not the same as a row holding '0'.
 *   country    - the destination, which decides mailbox and digital stamp eligibility.
 *   carrier    - module carrier name; its config path is derived.
 *   groups     - the carrier's config groups, exactly as Magento stores them. A group read whole and
 *                a child read by path are the same tree, so both drivers resolve both from this.
 *   weightUnit - myparcelnl_magento_general/print/weight_indication.
 *   candidates - what capabilities plus the forced options allow, before config is consulted.
 *   account    - the account's general settings, for postnl mailbox international.
 *
 * @return array<string, array<string, mixed>>
 */
function packageTypeCases(): array
{
    $allActive = [
        'mailbox'       => ['active' => '1', 'weight' => '2000'],
        'digital_stamp' => ['active' => '1'],
        'package_small' => ['active' => '1', 'weight' => '2000'],
    ];
    $all  = ['digital_stamp' => true, 'mailbox' => true, 'package_small' => true];
    $none = ['digital_stamp' => false, 'mailbox' => false, 'package_small' => false];

    $case = static function (array $overrides) use ($allActive, $all): array {
        return array_replace([
            'items'      => [],
            'country'    => 'NL',
            'carrier'    => 'postnl',
            'groups'     => $allActive,
            'weightUnit' => 'gram',
            'candidates' => $all,
            'account'    => ['postnl_mailbox_international' => false],
            'expected'   => PackageType::PACKAGE_NAME,
        ], $overrides);
    };

    $item = static function (float $qty, float $weight, array $attributes = []): array {
        return ['qty' => $qty, 'weight' => $weight, 'attributes' => $attributes];
    };

    $mailboxOnly    = ['digital_stamp' => false, 'mailbox' => true, 'package_small' => false];
    $noPackageSmall = ['digital_stamp' => true, 'mailbox' => true, 'package_small' => false];

    return [
        'a cart that qualifies for everything ships as a digital stamp' => $case([
            'items'    => [$item(1.0, 1.0, ['digital_stamp' => '1', 'fit_in_mailbox' => '1'])],
            'expected' => PackageType::DIGITAL_STAMP_NAME,
        ]),

        'one item that is not a digital stamp drops the whole cart to mailbox' => $case([
            // Two per mailbox each, so the pair fills exactly one and the percentage lands on 100.
            'items'    => [
                $item(1.0, 1.0, ['digital_stamp' => '1', 'fit_in_mailbox' => '2']),
                $item(1.0, 1.0, ['fit_in_mailbox' => '2']),
            ],
            'expected' => PackageType::MAILBOX_NAME,
        ]),

        'a mailbox percentage above 100 falls through to package small' => $case([
            'items'    => [$item(2.0, 1.0, ['fit_in_mailbox' => '1'])],
            'expected' => PackageType::PACKAGE_SMALL_NAME,
        ]),

        'no candidate at all leaves the fallback package' => $case([
            'items'      => [$item(1.0, 1.0, ['digital_stamp' => '1', 'fit_in_mailbox' => '1'])],
            'candidates' => $none,
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'fit_in_mailbox of -1 rules the mailbox out' => $case([
            'items'      => [$item(1.0, 1.0, ['fit_in_mailbox' => '-1'])],
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'fit_in_mailbox of 0 derives the count from the weight' => $case([
            'items'      => [$item(1.0, 1000.0, ['fit_in_mailbox' => '0'])],
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'fit_in_mailbox of 0 on a weightless item leaves the percentage alone' => $case([
            'items'      => [$item(1.0, 0.0, ['fit_in_mailbox' => '0'])],
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'a product with no fit_in_mailbox row reads as -1' => $case([
            'items'      => [$item(1.0, 1.0)],
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'an item with qty below one contributes neither weight nor percentage' => $case([
            'items'      => [
                $item(0.0, 5000.0, ['fit_in_mailbox' => '-1']),
                $item(1.0, 1.0, ['fit_in_mailbox' => '1']),
            ],
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'the percentage stops accumulating past 100 while the weight keeps summing' => $case([
            'items'      => [
                $item(3.0, 1.0, ['fit_in_mailbox' => '1']),
                $item(1.0, 2.0, ['fit_in_mailbox' => '1']),
            ],
            'groups'     => [
                'mailbox'       => ['active' => '1', 'weight' => '2000'],
                'digital_stamp' => ['active' => '1'],
                'package_small' => ['active' => '1', 'weight' => '4'],
            ],
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'a kilo unit reads the configured mailbox weight as kilos' => $case([
            'items'      => [$item(1.0, 1.5, ['fit_in_mailbox' => '1'])],
            'groups'     => ['mailbox' => ['active' => '1', 'weight' => '2']],
            'weightUnit' => 'kilo',
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'a kilo unit refuses a cart above the configured kilos' => $case([
            'items'      => [$item(1.0, 2.5, ['fit_in_mailbox' => '1'])],
            'groups'     => ['mailbox' => ['active' => '1', 'weight' => '2']],
            'weightUnit' => 'kilo',
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'a kilo unit with no configured weight falls back to two kilos' => $case([
            'items'      => [$item(1.0, 1.0, ['fit_in_mailbox' => '1'])],
            'groups'     => ['mailbox' => ['active' => '1', 'weight' => '']],
            'weightUnit' => 'kilo',
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'a gram unit with no configured weight falls back to two thousand grams' => $case([
            'items'      => [$item(1.0, 1500.0, ['fit_in_mailbox' => '1'])],
            'groups'     => ['mailbox' => ['active' => '1', 'weight' => '']],
            'candidates' => $mailboxOnly,
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'postnl abroad may use a mailbox when the account allows it' => $case([
            'items'      => [$item(1.0, 1.0, ['fit_in_mailbox' => '1'])],
            'country'    => 'DE',
            'groups'     => ['mailbox' => ['active' => '1', 'international_active' => '1', 'weight' => '2000']],
            'candidates' => $mailboxOnly,
            'account'    => ['postnl_mailbox_international' => true],
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'postnl abroad may not use a mailbox when the account forbids it' => $case([
            'items'      => [$item(1.0, 1.0, ['fit_in_mailbox' => '1'])],
            'country'    => 'DE',
            'groups'     => ['mailbox' => ['active' => '1', 'international_active' => '1', 'weight' => '2000']],
            'candidates' => $mailboxOnly,
            'account'    => ['postnl_mailbox_international' => false],
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'another carrier abroad never gets a mailbox' => $case([
            'items'      => [$item(1.0, 1.0, ['fit_in_mailbox' => '1'])],
            'country'    => 'DE',
            'carrier'    => 'dpd',
            'groups'     => ['mailbox' => ['active' => '1', 'international_active' => '1', 'weight' => '2000']],
            'candidates' => $mailboxOnly,
            'account'    => ['postnl_mailbox_international' => true],
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'a mailbox off at home but on abroad keeps a maximum weight of zero' => $case([
            'items'      => [$item(1.0, 0.0, ['fit_in_mailbox' => '0'])],
            'country'    => 'DE',
            'groups'     => ['mailbox' => ['active' => '0', 'international_active' => '1', 'weight' => '2000']],
            'candidates' => $mailboxOnly,
            'account'    => ['postnl_mailbox_international' => true],
            'expected'   => PackageType::MAILBOX_NAME,
        ]),

        'that zero maximum weight refuses anything that weighs something' => $case([
            'items'      => [$item(1.0, 1.0, ['fit_in_mailbox' => '0'])],
            'country'    => 'DE',
            'groups'     => ['mailbox' => ['active' => '0', 'international_active' => '1', 'weight' => '2000']],
            'candidates' => $mailboxOnly,
            'account'    => ['postnl_mailbox_international' => true],
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'a weightless cart still fits a digital stamp, because the conversion floors to a kilo' => $case([
            'items'    => [$item(1.0, 0.0, ['digital_stamp' => '1', 'fit_in_mailbox' => '-1'])],
            'expected' => PackageType::DIGITAL_STAMP_NAME,
        ]),

        'an inactive digital stamp group keeps a maximum weight of zero and is refused' => $case([
            'items'      => [$item(1.0, 0.0, ['digital_stamp' => '1', 'fit_in_mailbox' => '-1'])],
            'groups'     => ['digital_stamp' => ['active' => '0']],
            'candidates' => $noPackageSmall,
            'expected'   => PackageType::PACKAGE_NAME,
        ]),

        'capabilities refusing a digital stamp beats the config that allows it' => $case([
            'items'      => [$item(1.0, 0.0, ['digital_stamp' => '1', 'fit_in_mailbox' => '-1'])],
            'candidates' => ['digital_stamp' => false, 'mailbox' => true, 'package_small' => false],
            'expected'   => PackageType::PACKAGE_NAME,
        ]),
    ];
}

/**
 * packageTypeCases() as a Pest dataset. Each case is wrapped in its own array, because Pest spreads
 * a dataset entry across the test's arguments and every driver wants the case whole.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function packageTypeDataset(): array
{
    return array_map(static fn(array $case): array => [$case], packageTypeCases());
}

/** The carrier's config path for a case's carrier name. */
function packageTypeCarrierPath(string $carrier): string
{
    return Config::CARRIERS_XML_PATH_MAP[$carrier];
}

/**
 * Every config path a case answers, group reads and child reads alike — Magento resolves both from
 * one tree, so a driver must too.
 *
 * @return array<string, mixed>
 */
function packageTypeConfigMap(array $case): array
{
    $carrierPath = packageTypeCarrierPath($case['carrier']);
    $map         = [Config::XML_PATH_GENERAL . 'print/weight_indication' => $case['weightUnit']];

    foreach ($case['groups'] as $group => $children) {
        $map[$carrierPath . $group] = $children;

        foreach ($children as $key => $value) {
            $map[$carrierPath . $group . '/' . $key] = $value;
        }
    }

    return $map;
}

/**
 * The product attribute rows a case describes, keyed by product id and prefixed the way the
 * collection stores them.
 *
 * @return array<int, array<string, string>>
 */
function packageTypeProductRows(array $items): array
{
    $rows = [];

    foreach ($items as $index => $item) {
        $row = [];

        foreach ($item['attributes'] as $column => $value) {
            $row['myparcel_' . $column] = $value;
        }

        $rows[$index + 1] = $row;
    }

    return $rows;
}

/**
 * The quote items a case describes, numbered to match packageTypeProductRows().
 *
 * @return object[]
 */
function packageTypeQuoteItems(array $items): array
{
    $quoteItems = [];

    foreach ($items as $index => $item) {
        $quoteItems[] = quoteItemFor($index + 1, $item['qty'], $item['weight']);
    }

    return $quoteItems;
}
