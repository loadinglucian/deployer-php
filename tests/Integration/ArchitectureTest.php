<?php

declare(strict_types=1);

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

//
// Architecture tests
// -------------------------------------------------------------------------------

arch('commands extend BaseCommand', function () {
    expect('Bigpixelrocket\\DeployerPHP\\Console\\')
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
    expect('Bigpixelrocket\\DeployerPHP\\Console\\')
        ->classes()
        ->toHaveAttribute(AsCommand::class);
});
