<?php

declare(strict_types=1);

use PHPDeployer\Contracts\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

//
// Architecture tests
// ----

arch('commands extend BaseCommand', function () {
    expect('PHPDeployer\\Console\\')
        ->classes()
        ->toHaveSuffix('Command')
        ->toExtend(BaseCommand::class);
});

arch('base command contract', function () {
    expect(BaseCommand::class)
        ->toBeAbstract()
        ->toExtend(Command::class)
        ->toHaveConstructor();
});

arch('commands expose Symfony metadata', function () {
    expect('PHPDeployer\\Console\\')
        ->classes()
        ->toHaveAttribute(AsCommand::class);
});
