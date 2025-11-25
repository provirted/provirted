<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCpanelCommand extends Command
{
    protected static $defaultName = 'install-cpanel';
    protected static $defaultDescription = 'Runs the CPanel Installation on a Virtual Machine.';

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
                'email',
                InputArgument::REQUIRED,
                'Email Address'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid = $input->getArgument('vzid');
        $email = $input->getArgument('email');
        $opts = $input->getOptions();

        Vps::init($opts, ['vzid' => $vzid, 'email' => $email]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
            return Command::FAILURE;
        }

        if (Vps::getVirtType() == 'virtuozzo') {
            $email = escapeshellarg($email);
            Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'if [ ! -e /usr/bin/screen ]; then yum -y install screen; fi'"));
            Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'if [ ! -e /admin/cpanelinstall ]; then rsync -a rsync://mirror.trouble-free.net/admin /admin; fi'"));
            Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} '/admin/cpanelinstall {$email};'"));
        } elseif (Vps::getVirtType() == 'openvz') {
            $email = escapeshellarg($email);
            Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'if [ ! -e /usr/bin/screen ]; then yum -y install screen; fi'"));
            Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'if [ ! -e /admin/cpanelinstall ]; then rsync -a rsync://mirror.trouble-free.net/admin /admin; fi'"));
            Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/admin/cpanelinstall {$email};'"));
        }

        return Command::SUCCESS;
    }
}
