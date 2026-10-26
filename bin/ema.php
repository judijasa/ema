#!/usr/bin/env php
<?php

// Self-locate the package root so the manual require_once paths work whether
// this runs in-tree or via the Composer `vendor/bin/ema` symlink (the shim
// execs this file with EMA_LIB already set; a direct `php bin/ema.php` resolves
// it from __FILE__). EMA_LIB (env) still wins when set.
$emaLib = getenv('EMA_LIB');
if ($emaLib === false || $emaLib === '') {
    $self = realpath(__FILE__);
    if ($self === false) {
        fwrite(STDERR, "Error: cannot resolve ema.php location\n");
        exit(1);
    }
    $emaLib = dirname(dirname($self)) . '/src';
}

require_once $emaLib . '/sort_schemas.php';
require_once $emaLib . '/Cli/Error.php';
require_once $emaLib . '/Cli/Command.php';
require_once $emaLib . '/Cli/Args.php';
require_once $emaLib . '/Cli/Help.php';
require_once $emaLib . '/Cli/AbstractCommand.php';
require_once $emaLib . '/Cli/Application.php';
require_once $emaLib . '/Cli/Support/Guid.php';
require_once $emaLib . '/Cli/Support/Repo.php';
require_once $emaLib . '/Cli/Support/Process.php';
require_once $emaLib . '/Cli/Support/Prompt.php';
require_once $emaLib . '/Cli/Support/Target.php';
require_once $emaLib . '/Cli/Support/Connection.php';
require_once $emaLib . '/Cli/Support/Sql.php';
require_once $emaLib . '/Cli/Support/SandboxServer.php';
require_once $emaLib . '/Cli/Support/ProdServer.php';
require_once $emaLib . '/Cli/Command/Schema.php';
require_once $emaLib . '/Cli/Command/Database.php';
require_once $emaLib . '/Cli/Command/Inspect.php';
require_once $emaLib . '/Cli/Command/Deps.php';
require_once $emaLib . '/Cli/Command/Revision.php';
require_once $emaLib . '/Cli/Command/Sandbox.php';
require_once $emaLib . '/Cli/Command/Start.php';
require_once $emaLib . '/Cli/Command/Stop.php';
require_once $emaLib . '/Cli/Command/Restart.php';
require_once $emaLib . '/Cli/Command/Gc.php';
require_once $emaLib . '/Cli/Command/Status.php';
require_once $emaLib . '/Cli/Command/Mariadb.php';
require_once $emaLib . '/Cli/Command/Create.php';
require_once $emaLib . '/Cli/Command/Values.php';
require_once $emaLib . '/Cli/Command/Upgrade.php';

$application = (new \Ema\Cli\Application())
    ->register(new \Ema\Cli\Command\Schema())
    ->register(new \Ema\Cli\Command\Database())
    ->register(new \Ema\Cli\Command\Inspect())
    ->register(new \Ema\Cli\Command\Deps())
    ->register(new \Ema\Cli\Command\Revision())
    ->register(new \Ema\Cli\Command\Sandbox())
    ->register(new \Ema\Cli\Command\Start())
    ->register(new \Ema\Cli\Command\Stop())
    ->register(new \Ema\Cli\Command\Restart())
    ->register(new \Ema\Cli\Command\Gc())
    ->register(new \Ema\Cli\Command\Status())
    ->register(new \Ema\Cli\Command\Mariadb())
    ->register(new \Ema\Cli\Command\Create())
    ->register(new \Ema\Cli\Command\Values())
    ->register(new \Ema\Cli\Command\Upgrade());

exit($application->run(array_slice($argv, 1)));
