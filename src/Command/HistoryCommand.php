<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HistoryCommand extends Command
{
    protected static $defaultName = 'history';

    protected function configure()
    {
        $this
            ->setDescription('Display previously run commands and get detailed information on the output and commands run')
            ->setHelp('
SYNTAX

  provirted.phar history <subcommand>

SUBCOMMANDS
  list                      lists the history entries
  show <id>                 displays one of the history entries, -1 is always the latest entry
  clean                     cleans up the history log removing certain entries
');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->getHelp());
        return Command::SUCCESS;
    }
}
