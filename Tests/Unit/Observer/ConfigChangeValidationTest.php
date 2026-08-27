<?php

declare(strict_types=1);

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Model\Settings\Validator\SettingValidatorInterface;
use MyParcelNL\Magento\Observer\ConfigChange;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\AccountSettings\Maintenance as AccountSettingsMaintenance;
use MyParcelNL\Magento\Service\Settings;

/**
 * The observer's side of the bargain: it asks each validator whether a path is its business, refuses
 * that one field when a validator objects, and knows nothing about any particular setting. What each
 * validator decides is its own test's business.
 */
const VALIDATED_PATH = 'myparcelnl_magento_postnl_settings/default_options/insurance_local_amount';
const OTHER_PATH     = 'myparcelnl_magento_postnl_settings/default_options/insurance_percentage';
const API_KEY_PATH   = \MyParcelNL\Magento\Service\Config::XML_PATH_API_KEY;

/** A validator claiming exactly $handles, answering $rejection for every value it is given. */
function stubValidator(string $handles, ?string $rejection = null): SettingValidatorInterface
{
    return new class($handles, $rejection) implements SettingValidatorInterface {
        public array $seen = [];

        private string  $handles;
        private ?string $rejection;

        public function __construct(string $handles, ?string $rejection)
        {
            $this->handles   = $handles;
            $this->rejection = $rejection;
        }

        public function handles(string $path): bool
        {
            return $path === $this->handles;
        }

        public function validate(string $path, $value, string $scopeName, int $scopeId): ?Phrase
        {
            $this->seen[] = $path;

            return null === $this->rejection ? null : new Phrase($this->rejection);
        }
    };
}

/**
 * @param  array<string, array<string, string>> $posted
 * @param  SettingValidatorInterface[]          $validators
 * @param  array<string, string|null>           $stored what already sits at this scope, by path
 * @return array{writer: WriterInterface, messages: ManagerInterface, caches: TypeListInterface, appConfig: ReinitableConfigInterface}
 */
function saveDynamicSettings(array $posted, array $validators = [], array $stored = []): array
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->with('scope', Mockery::any())->andReturn('default');
    $request->shouldReceive('getParam')->with('scope_id', Mockery::any())->andReturn(0);
    $request->shouldReceive('getParam')->with('config', Mockery::any())->andReturn($posted);

    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturn(null);

    $settings = Mockery::mock(Settings::class);
    $settings->shouldReceive('getAllFieldPaths')->andReturn(array_keys($posted));
    $settings->shouldReceive('storedValuesAtScope')->andReturn($stored);

    $writer    = Mockery::spy(WriterInterface::class);
    $messages  = Mockery::spy(ManagerInterface::class);
    $caches    = Mockery::spy(TypeListInterface::class);
    $appConfig = Mockery::spy(ReinitableConfigInterface::class);

    $importer = Mockery::mock(Importer::class);
    $importer->shouldReceive('hasSettingsFor')->andReturn(true);

    (new ConfigChange(
        $request,
        $writer,
        $caches,
        $settings,
        $messages,
        $scopeConfig,
        $appConfig,
        $importer,
        Mockery::spy(AccountSettingsMaintenance::class),
        $validators
    ))->execute(Mockery::mock(EventObserver::class));

    return ['writer' => $writer, 'messages' => $messages, 'caches' => $caches, 'appConfig' => $appConfig];
}

it('saves every field when no validator objects', function () {
    $result = saveDynamicSettings(
        [VALIDATED_PATH => ['value' => '2500'], OTHER_PATH => ['value' => '80']],
        [stubValidator(VALIDATED_PATH)]
    );

    $result['writer']->shouldHaveReceived('save')->with(VALIDATED_PATH, '2500');
    $result['writer']->shouldHaveReceived('save')->with(OTHER_PATH, '80');
    $result['messages']->shouldNotHaveReceived('addErrorMessage');
});

it('refuses only the field its validator objected to, and says why', function () {
    $result = saveDynamicSettings(
        [VALIDATED_PATH => ['value' => '9000'], OTHER_PATH => ['value' => '80']],
        [stubValidator(VALIDATED_PATH, 'nope')]
    );

    // Every field is posted on every submit, so failing the lot would let one bad value block every
    // other change.
    $result['writer']->shouldNotHaveReceived('save', [VALIDATED_PATH, '9000']);
    $result['writer']->shouldHaveReceived('save')->with(OTHER_PATH, '80');
    $result['messages']->shouldHaveReceived('addErrorMessage');
});

it('never asks a validator about a path it does not claim', function () {
    $validator = stubValidator(VALIDATED_PATH);

    saveDynamicSettings(
        [VALIDATED_PATH => ['value' => '1'], OTHER_PATH => ['value' => '2']],
        [$validator]
    );

    expect($validator->seen)->toBe([VALIDATED_PATH]);
});

it('saves everything when no validator is configured at all', function () {
    $result = saveDynamicSettings([VALIDATED_PATH => ['value' => 'anything at all']]);

    $result['writer']->shouldHaveReceived('save')->with(VALIDATED_PATH, 'anything at all');
    $result['messages']->shouldNotHaveReceived('addErrorMessage');
});

it('refuses to be built with something that is not a validator', function () {
    expect(fn () => saveDynamicSettings([], [new stdClass()]))
        ->toThrow(InvalidArgumentException::class, 'Setting validators must implement');
});

it('leaves a field alone when its stored value is what was posted', function () {
    $result = saveDynamicSettings(
        [VALIDATED_PATH => ['value' => '2500'], OTHER_PATH => ['value' => '80']],
        [],
        [VALIDATED_PATH => '2500', OTHER_PATH => '80']
    );

    // Every field is posted on every submit, and each write costs a select, an update and a
    // poison-pill write, so an untouched form must cost nothing.
    $result['writer']->shouldNotHaveReceived('save');
});

it('writes only the field that changed', function () {
    $result = saveDynamicSettings(
        [VALIDATED_PATH => ['value' => '3000'], OTHER_PATH => ['value' => '80']],
        [],
        [VALIDATED_PATH => '2500', OTHER_PATH => '80']
    );

    $result['writer']->shouldHaveReceived('save')->with(VALIDATED_PATH, '3000');
    $result['writer']->shouldNotHaveReceived('save', [OTHER_PATH, '80']);
});

it('writes a value that matches the stored one at a scope that has no row of its own', function () {
    // The admin unticked Use Default and left the inherited value: same string, but the point of the
    // save is to create the row. Comparing against a cascaded read would skip it.
    $result = saveDynamicSettings([VALIDATED_PATH => ['value' => '2500']], [], []);

    $result['writer']->shouldHaveReceived('save')->with(VALIDATED_PATH, '2500');
});

it('still reports a stored value that has become invalid, without rewriting it', function () {
    $result = saveDynamicSettings(
        [VALIDATED_PATH => ['value' => '9000']],
        [stubValidator(VALIDATED_PATH, 'nope')],
        [VALIDATED_PATH => '9000']
    );

    $result['messages']->shouldHaveReceived('addErrorMessage');
    $result['writer']->shouldNotHaveReceived('save');
});

it('reloads nothing when the save moved nothing', function () {
    $result = saveDynamicSettings(
        [VALIDATED_PATH => ['value' => '2500']],
        [],
        [VALIDATED_PATH => '2500']
    );

    // Reloading the merged config is the expensive half of a save, so an untouched form must not
    // trigger it.
    $result['appConfig']->shouldNotHaveReceived('reinit');
    $result['caches']->shouldNotHaveReceived('cleanType');
    $result['caches']->shouldNotHaveReceived('invalidate');
});

it('reloads the merged config once when a field changed', function () {
    $result = saveDynamicSettings([VALIDATED_PATH => ['value' => '3000']], [], [VALIDATED_PATH => '2500']);

    $result['appConfig']->shouldHaveReceived('reinit');
    $result['caches']->shouldHaveReceived('invalidate')->with(['block_html', 'full_page']);
});

it('keeps the capability cache when the api key did not change', function () {
    $result = saveDynamicSettings([VALIDATED_PATH => ['value' => '3000']], [], [VALIDATED_PATH => '2500']);

    // Capability entries are keyed on the api key, so dropping them for any other setting only buys
    // an API round trip on the next screen that needs them.
    $result['caches']->shouldNotHaveReceived('cleanType');
});

it('drops the capability cache when the api key changed', function () {
    $result = saveDynamicSettings(
        [API_KEY_PATH => ['value' => 'new-key']],
        [],
        [API_KEY_PATH => 'old-key']
    );

    $result['caches']->shouldHaveReceived('cleanType')->with(CapabilitiesCache::TYPE_IDENTIFIER);
});
