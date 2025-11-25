<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnableCommand extends Command
{
    protected static $defaultName = 'enable';
    protected static $defaultDescription = 'Enables a Virtual Machine.';

    protected function configure()
    {
        $this
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'increase output verbosity (stacked..use multiple times for even more output)')
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS id/name to use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vzid = $input->getArgument('vzid');
        $opts = $input->getOptions();

        Vps::init($opts, ['vzid' => $vzid]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
            return Command::FAILURE;
        }

        Vps::enableAutostart($vzid);
        Vps::startVps($vzid);

        return Command::SUCCESS;
    }
}
