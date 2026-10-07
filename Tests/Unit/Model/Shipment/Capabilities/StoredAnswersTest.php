<?php

declare(strict_types=1);

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\FlagManager;
use MyParcelNL\Magento\Model\Shipment\Capabilities\StoredAnswers;
use MyParcelNL\Magento\Service\Hash\Fingerprint;

const STORED_ANSWERS_KEY = 'stored-answers-key-do-not-log';

/**
 * StoredAnswers over a flag table holding $codes, keyed by flag id.
 *
 * @param  array<int, string> $codes
 * @return array{answers: StoredAnswers, flags: FlagManager, connection: AdapterInterface}
 */
function makeStoredAnswers(array $codes = []): array
{
    $select = Mockery::mock(Select::class);
    $select->shouldReceive('from', 'where')->andReturnSelf();

    $connection = Mockery::spy(AdapterInterface::class);
    $connection->shouldReceive('select')->andReturn($select);
    $connection->shouldReceive('fetchPairs')->andReturn($codes);

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnArg(0);

    $flags = Mockery::spy(FlagManager::class);

    return [
        'answers'    => new StoredAnswers($flags, $resource, new Fingerprint()),
        'flags'      => $flags,
        'connection' => $connection,
    ];
}

function storedAnswerCode(string $apiKey, string $shape = 'shape'): string
{
    return 'myparcel_capabilities_' . (new Fingerprint())->of($apiKey) . '_' . $shape;
}

it('names the flag by the key fingerprint and the shape, never the key itself', function () {
    $s = makeStoredAnswers();

    $s['answers']->save(STORED_ANSWERS_KEY, 'shape', [['carrier' => 'POSTNL']]);

    $s['flags']->shouldHaveReceived('saveFlag')->once()->with(
        Mockery::on(static fn (string $code): bool => storedAnswerCode(STORED_ANSWERS_KEY) === $code
            && false === strpos($code, STORED_ANSWERS_KEY)),
        [['carrier' => 'POSTNL']]
    );
});

it('reads a flag that holds no answer as no answer', function ($data) {
    $s = makeStoredAnswers();
    $s['flags']->shouldReceive('getFlagData')->andReturn($data);

    expect($s['answers']->load(STORED_ANSWERS_KEY, 'shape'))->toBeNull();
})->with([null, 'not an array']);

it('deletes the answers of dead keys only, and leaves a lookalike code alone', function () {
    $s = makeStoredAnswers([
        1 => storedAnswerCode('live-key'),
        2 => storedAnswerCode('dead-key'),
        3 => 'myparcelXcapabilities_other_module_flag',
    ]);

    $s['answers']->deleteExcept([(new Fingerprint())->of('live-key')]);

    $s['connection']->shouldHaveReceived('delete')->once()->with('flag', ['flag_id IN (?)' => [2]]);
});

it('deletes nothing when every stored answer belongs to a live key', function () {
    $s = makeStoredAnswers([1 => storedAnswerCode('live-key')]);

    $s['answers']->deleteExcept([(new Fingerprint())->of('live-key')]);

    $s['connection']->shouldNotHaveReceived('delete');
});

it('deletes every stored answer on uninstall', function () {
    $s = makeStoredAnswers([1 => storedAnswerCode('one'), 2 => storedAnswerCode('two')]);

    $s['answers']->deleteAll();

    $s['connection']->shouldHaveReceived('delete')->once()->with('flag', ['flag_id IN (?)' => [1, 2]]);
});
