<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'self-update',
    description: 'updates to the latest version'
)]
class SelfUpdateCommand extends Command
{
    protected function configure()
    {
        $this
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'increase output verbosity (stacked..use multiple times for even more output)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Command intentionally empty as original logic contains no functionality
        return Command::SUCCESS;
    }
}
