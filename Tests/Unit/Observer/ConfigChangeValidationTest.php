<?php

declare(strict_types=1);

use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
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

/**
 * A pool the observer can iterate over without reaching a cache backend. Not a Mockery double: the
 * observer foreaches it, and a double answers the iteration protocol with an unexpected-call error
 * that the observer then reports as a failed save.
 */
function emptyCacheFrontendPool(): Pool
{
    return new class extends Pool {
        public function __construct()
        {
        }

        #[\ReturnTypeWillChange]
        public function current()
        {
            return null;
        }

        #[\ReturnTypeWillChange]
        public function key()
        {
            return null;
        }

        #[\ReturnTypeWillChange]
        public function next()
        {
        }

        #[\ReturnTypeWillChange]
        public function rewind()
        {
        }

        #[\ReturnTypeWillChange]
        public function valid()
        {
            return false;
        }
    };
}

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
 * @return array{writer: WriterInterface, messages: ManagerInterface}
 */
function saveDynamicSettings(array $posted, array $validators = []): array
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->with('scope', Mockery::any())->andReturn('default');
    $request->shouldReceive('getParam')->with('scope_id', Mockery::any())->andReturn(0);
    $request->shouldReceive('getParam')->with('config', Mockery::any())->andReturn($posted);

    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturn(null);

    $settings = Mockery::mock(Settings::class);
    $settings->shouldReceive('getAllFieldPaths')->andReturn(array_keys($posted));

    $writer   = Mockery::spy(WriterInterface::class);
    $messages = Mockery::spy(ManagerInterface::class);

    $importer = Mockery::mock(Importer::class);
    $importer->shouldReceive('hasSettingsFor')->andReturn(true);

    (new ConfigChange(
        $request,
        $writer,
        Mockery::spy(TypeListInterface::class),
        emptyCacheFrontendPool(),
        $settings,
        $messages,
        $scopeConfig,
        $importer,
        Mockery::spy(AccountSettingsMaintenance::class),
        $validators
    ))->execute(Mockery::mock(EventObserver::class));

    return ['writer' => $writer, 'messages' => $messages];
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
