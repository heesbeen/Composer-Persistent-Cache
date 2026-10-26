<?php

declare(strict_types=1);

namespace HoarseLeg\PersistentCache;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

final class CommandProvider implements CommandProviderCapability
{
    private Composer $composer;

    /**
     * @param array{composer: Composer, io: IOInterface} $args
     */
    public function __construct(array $args)
    {
        $this->composer = $args['composer'];
    }

    public function getCommands(): array
    {
        return [
            new ValidateCommand($this->composer),
            new PackCommand($this->composer),
        ];
    }
}
