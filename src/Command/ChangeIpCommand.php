<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeIpCommand extends Command
{
    protected static $defaultName = 'change-ip';

    protected function configure()
    {
        $this
            ->setDescription('Changes one of the IP addresses of a VPS')
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
                'Old IP Address'
            )
            ->addArgument(
                'ipNew',
                InputArgument::REQUIRED,
                'New IP Address'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid   = $input->getArgument('vzid');
        $ip     = $input->getArgument('ip');
        $ipNew  = $input->getArgument('ipNew');

        // manual validation
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $output->writeln("<error>Invalid IP address: {$ip}</error>");
            return Command::FAILURE;
        }

        if (!filter_var($ipNew, FILTER_VALIDATE_IP)) {
            $output->writeln("<error>Invalid new IP address: {$ipNew}</error>");
            return Command::FAILURE;
        }

        // init
        Vps::init($input, ['vzid' => $vzid, 'ip' => $ip, 'ipNew' => $ipNew]);

        if (!Vps::isVirtualHost()) {
            $output->writeln("<error>This machine does not appear to have any virtualization setup installed.</error>");
            $output->writeln("<error>Check the help to see how to prepare a virtualization environment.</error>");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            $output->writeln("<error>The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.</error>");
            return Command::FAILURE;
        }

        Vps::changeIp($vzid, $ip, $ipNew);

        return Command::SUCCESS;
    }
}
