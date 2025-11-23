<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VncCommand extends Command
{
    protected static $defaultName = 'vnc';

    protected function configure()
    {
        $this->setDescription("Displays help for VNC-related subcommands");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('
SYNTAX

  provirted vnc <subcommand>

SUBCOMMANDS
  vnc:secure [--dry]            Remove old and bad entries to maintain security
  vnc:setup <vzid> [ip]         Create a new mapping
  vnc:remove <vzid>             Remove a mapping
  vnc:restart                   Restart the xinetd service
  vnc:rebuild [--dry]           Clean and recreate all mappings

EXAMPLES
  provirted vnc:setup vps4000 8.8.8.8
  provirted vnc:remove vps4000
  provirted vnc:secure
  provirted vnc:restart
  provirted vnc:rebuild --dry
  provirted vnc:rebuild
');
        return Command::SUCCESS;
    }
}
