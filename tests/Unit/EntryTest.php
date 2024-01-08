<?php
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

namespace MyParcelNL\Magento\Tests\Mock;

use MyParcelNL\Magento\tests\Uses\UseInstantiatePlugin;
use function MyParcelNL\Pdk\Tests\usesShared;

usesShared(new UseInstantiatePlugin());

it('instantiates the plugin', function (): void {
    $this->expectNotToPerformAssertions();
});

it('throws error is the php version is too low', function (): void {
    $this->expectNotToPerformAssertions();
})->skip('todo');

it('throws error if magento is not enabled', function (): void {
    $this->expectNotToPerformAssertions();
})->skip('todo');

it('activates plugin if prerequisites are met', function (): void {
    $this->expectNotToPerformAssertions();
})->skip('todo');

it('runs uninstall on deactivate', function (): void {
    $this->expectNotToPerformAssertions();
})->skip('todo');

it('adds all hooks on plugin init', function (): void {
    $this->expectNotToPerformAssertions();
})->skip('todo');
