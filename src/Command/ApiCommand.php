<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;

class ApiCommand extends Command
{
    protected static $defaultName = 'api';

    protected function configure()
    {
        $this->setDescription('Run internal api calls');
    }
}
