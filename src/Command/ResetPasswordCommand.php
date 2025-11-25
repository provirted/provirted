<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ResetPasswordCommand extends Command
{
    protected static $defaultName = 'reset-password';
    protected static $defaultDescription = 'Resets/Clears a Password on a Virtual Machine.';

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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
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

        if (Vps::isVpsRunning($vzid)) {
            Vps::stopVps($vzid);
        }

        $base = Vps::$base;

        $part = Vps::runCommand("virt-inspector --no-applications -d {$vzid} |grep \"mountpoint.*>/<\"|cut -d\\\" -f2");
        Vps::getLogger()->write(Vps::runCommand("guestfish add-domain {$vzid} : run : ntfsfix {$part} : unmount-all"));

        mkdir('/mntpass');
        Vps::getLogger()->write(Vps::runCommand("guestmount -d {$vzid} -i -w /mntpass"));
        Vps::getLogger()->write(Vps::runCommand("{$base}/enable_user_and_clear_password -u Administrator /mntpass/Windows/System32/config/SAM"));
        Vps::getLogger()->write(Vps::runCommand("guestunmount /mntpass"));
        rmdir('/mntpass');

        Vps::startVps($vzid);

        return Command::SUCCESS;
    }
}
