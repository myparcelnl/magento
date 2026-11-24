<?php

declare(strict_types=1);

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/** Runs registration.php in a fresh PHP process without Magento, as on Magento 2.4.6, and returns its output. */
function runRegistrationWithoutMagento(string $check): string
{
    $script = tempnam(sys_get_temp_dir(), 'registration');
    file_put_contents($script, sprintf(<<<'PHP'
<?php
namespace Magento\Framework\Component {
    class ComponentRegistrar
    {
        public const MODULE = 'module';

        public static function register(string $type, string $name, string $path): void
        {
        }
    }
}

namespace {
    require %s;
    %s
}
PHP, var_export(dirname(__DIR__, 2) . '/registration.php', true), $check));

    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    unlink($script);

    return (string) $output;
}

it('provides ResetAfterRequestInterface when Magento does not have it', function () {
    $output = runRegistrationWithoutMagento(<<<'PHP'
$interface = new ReflectionClass('Magento\Framework\ObjectManager\ResetAfterRequestInterface');
echo $interface->isInterface() && $interface->hasMethod('_resetState') ? 'ok' : 'missing';
PHP);

    expect($output)->toBe('ok');
});

// Composer already loaded registration.php through autoload.files, with magento/framework present.
it('keeps Magento\'s own ResetAfterRequestInterface when Magento has it', function () {
    $file = (new ReflectionClass(ResetAfterRequestInterface::class))->getFileName();

    expect($file)->toContain('magento/framework/ObjectManager/ResetAfterRequestInterface.php');
});
