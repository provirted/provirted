<?php
namespace App\Command\InternalsCommand;

use Symfony\Component\Console\Command\Command;

class {$class.name}Command extends Command
{
    protected static $defaultName = 'internals:{$class.name}';

    protected function configure()
    {
        $this->setDescription('{$class.name} functionality');
    }
}
