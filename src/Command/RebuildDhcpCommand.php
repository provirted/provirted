<?php
namespace App\Command;

use App\Vps;
use App\Os\Dhcpd;
use App\Os\Dhcpd6;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildDhcpCommand extends Command
{
    protected static $defaultName = 'rebuild-dhcp';
    protected static $defaultDescription = "Regenerates the dhcpd config and host assignments files.\n\n\t<what> can be 'conf', 'vps', or 'all' to regenerate the config file, host assignmetns file, or both (respectivly)";

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
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_NONE,
                'Output the file contents instead of writing it'
            )
            ->addArgument(
                'what',
                InputArgument::REQUIRED,
                "rebuild the dhcpd.conf config (conf), dhcpd.vps host asignments (vps), or both (all)"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $what = $input->getArgument('what');
        $opts = $input->getOptions();

        Vps::init($opts, ['what' => $what]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
            return Command::FAILURE;
        }

        if (!in_array($what, ['conf', 'vps', 'all'])) {
            Vps::getLogger()->error("Invalid or missing <what> value");
            Vps::getLogger()->error("<what> can be 'conf', 'vps', or 'all' to regenerate the config file, host assignmetns file, or both (respectivly)");
            return Command::FAILURE;
        }

        $outputFlag = $input->getOption('output');

        if (in_array($what, ['conf', 'all'])) {
            Dhcpd::rebuildConf($outputFlag);
            Dhcpd6::rebuildConf($outputFlag);
        }

        if (in_array($what, ['vps', 'all'])) {
            Dhcpd::rebuildHosts($outputFlag);
            Dhcpd6::rebuildHosts($outputFlag);
        }

        return Command::SUCCESS;
    }
}
