<?php
namespace App\Command\Vnc;

use App\Vps;
use App\Os\Xinetd;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildCommand extends Command
{
    protected static $defaultName = 'vnc:rebuild';

    protected function configure()
    {
        $this
            ->setDescription("Cleans up and recreates all the xinetd VNC entries")
            ->addOption('verbose-level', 'v', InputOption::VALUE_OPTIONAL | InputOption::VALUE_NONE, 'Increase output verbosity')
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Virtualization type', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force rebuild all entries')
            ->addOption('dry', 'd', InputOption::VALUE_NONE, 'Perform dry run')
            ->addOption('no-log', 'n', InputOption::VALUE_NONE, 'Disable logger history');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Vps::init($input->getOptions(), []);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
            return Command::FAILURE;
        }

        $force = (bool)$input->getOption('force');
        $dryRun = (bool)$input->getOption('dry');

        if ($input->getOption('no-log')) {
            Vps::getLogger()->disableHistory();
        }

        Xinetd::lock();
        Xinetd::rebuild($dryRun, $force);
        Xinetd::unlock();
        Xinetd::restart();

        return Command::SUCCESS;
    }
}
