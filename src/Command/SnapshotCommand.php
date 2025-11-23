<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotCommand extends Command
{
    protected static $defaultName = 'snapshot';

    protected function configure()
    {
        $this->setDescription("Saves and restores disk/volume snapshots");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('
SYNTAX

  provirted snapshot <subcommand>

SUBCOMMANDS
  snapshot:save <vzid>              Save a new snapshot
  snapshot:restore <vzid> <name>    Restore a snapshot
  snapshot:list [vzid]              List snapshots

EXAMPLES
  provirted snapshot:save vps4000
  provirted snapshot:restore vps4000 first
  provirted snapshot:list
');

        return Command::SUCCESS;
    }
}
