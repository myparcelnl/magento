<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

/*
 * Holds the one API promise InsuranceRange cannot check for itself: the insurance option carries the
 * flat min/max/default Money properties. The fallback to the deprecated insuredAmount wrapper is
 * gone, so a shape change would unbound every merchant's insurance field with no error anywhere.
 *
 * The pinned beta.15 models cannot be the witness — they declare only the wrapper, and the read path
 * uses no generated model at all — so the witness is a captured acceptance response. Refresh it by
 * running the live case:
 *
 *     MYPARCEL_ACCEPTANCE_API_KEY=… vendor/bin/pest --test-directory=Tests --filter=InsuranceShape
 *
 * Helpers live in Tests/Helpers/CapabilitiesFixtures.php.
 */

it('the captured acceptance response carries the flat insurance bounds', function () {
    $path = acceptanceCapabilitiesFixturePath();

    assertFlatInsuranceShape(
        json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
    );
})->skip(
    ! file_exists(acceptanceCapabilitiesFixturePath()),
    'No acceptance fixture captured yet. Run this file with MYPARCEL_ACCEPTANCE_API_KEY set to write '
    . acceptanceCapabilitiesFixturePath()
);

it('acceptance still answers with the flat insurance bounds, and refreshes the fixture', function () {
    $client  = makeAcceptanceCapabilitiesClient();
    $request = CapabilitiesRequest::forCountry('NL')
        ->withPackageType(PackageType::toV2Name(PackageType::PACKAGE_NAME));

    $results = $client->send(
        (string) getenv('MYPARCEL_ACCEPTANCE_API_KEY'),
        $client->serialize($request)
    );

    assertFlatInsuranceShape($results);

    $scrubbed = scrubCapabilitiesResults($results);

    // Assert the scrub kept the shape before it overwrites the committed witness.
    assertFlatInsuranceShape($scrubbed);

    $path = acceptanceCapabilitiesFixturePath();

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents(
        $path,
        json_encode($scrubbed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
})->skip(
    '' === (string) getenv('MYPARCEL_ACCEPTANCE_API_KEY'),
    'Set MYPARCEL_ACCEPTANCE_API_KEY to call acceptance and refresh the fixture.'
);
