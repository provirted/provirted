<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveIpCommand extends Command
{
    protected static $defaultName = 'remove-ip';
    protected static $defaultDescription = 'Removes an IP Address from a Virtual Machine.';

    protected function configure()
    {
        $this
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_NONE,
                'increase output verbosity (stacked..use multiple times for even more output)'
            )
            ->addOption(
                'virt',
                't',
                InputOption::VALUE_REQUIRED,
                'Type of Virtualization, kvm, openvz, virtuozzo, lxc'
            )
            ->addArgument(
                'vzid',
                InputArgument::REQUIRED,
                'VPS id/name to use'
            )
            ->addArgument(
                'ip',
                InputArgument::REQUIRED,
                'IP Address'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid = $input->getArgument('vzid');
        $ip = $input->getArgument('ip');
        $opts = $input->getOptions();

        Vps::init($opts, ['vzid' => $vzid, 'ip' => $ip]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
            return Command::FAILURE;
        }

        Vps::removeIp($vzid, $ip);

        return Command::SUCCESS;
    }
}
