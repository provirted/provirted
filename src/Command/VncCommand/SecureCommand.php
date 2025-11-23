<?php
namespace App\Command\Vnc;

use App\Vps;
use App\Os\Xinetd;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SecureCommand extends Command
{
    protected static $defaultName = 'vnc:secure';

    protected function configure()
    {
        $this
            ->setDescription("Cleans up bad or invalid xinetd entries")
            ->addOption('verbose-level', 'v', InputOption::VALUE_NONE)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED)
            ->addOption('dry', 'd', InputOption::VALUE_NONE, 'Dry run');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Vps::init($input->getOptions(), []);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            return Command::FAILURE;
        }

        $dryRun = (bool)$input->getOption('dry');

        Xinetd::lock();
        Xinetd::secure($dryRun);
        Xinetd::unlock();
        Xinetd::restart();

        return Command::SUCCESS;
    }
}
