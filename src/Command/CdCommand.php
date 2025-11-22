<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CdCommand extends Command
{
    protected static $defaultName = 'cd';

    protected function configure()
    {
        $this->setDescription('CD Drive/Image functionality (parent namespace command)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Use one of: cd:insert, cd:enable, cd:eject, cd:disable");
        return Command::SUCCESS;
    }
}
