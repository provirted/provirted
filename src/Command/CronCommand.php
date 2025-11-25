<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CronCommand extends Command {

    protected static $defaultName = 'CRON';

    protected function configure()
    {
        $this
            ->setDescription('Runs periodic tasks')
            ->setHelp('
SYNTAX

  provirted.phar cron <subcommand>

SUBCOMMANDS
    secure [--dry]            removes old and bad entries to maintain security
    setup <vzid> [ip]         create a new mapping
    remove <vzid>             remove a mapping
    restart                   restart the xinetd service
    rebuild [--dry]           removes old and bad entries to maintain security, and recreates all port mappings

EXAMPLES
    provirted.phar cron:bw-info
    provirted.phar cron:cpu-usage
    provirted.phar cron:host-info
    provirted.phar cron:host-info-extra
    provirted.phar cron:virtuozzo-update
    provirted.phar cron:vps-info
');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->getHelp());
        return Command::SUCCESS;
    }
}
