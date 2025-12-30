<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupCommand extends Command
{
    protected static $defaultName = 'backup';

    protected function configure()
    {
        $this
            ->setDescription('Creates a Backup of a Virtual Machine.')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Increase output verbosity')
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Use ALL available hardware resources')
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS id/name to use')
            ->addArgument('id', InputArgument::REQUIRED, 'VPS ID')
            ->addArgument('email', InputArgument::REQUIRED, 'Email Address to notify when done');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid  = $input->getArgument('vzid');
        $id    = $input->getArgument('id');
        $email = $input->getArgument('email');
        $useAll     = $input->getOption('all');

        Vps::init([
            'verbose' => $input->getOption('verbose') ? 1 : 0,
            'virt'    => $input->getOption('virt'),
        ], [
            'vzid'  => $vzid,
            'id'    => $id,
            'email' => $email,
        ]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
            return Command::FAILURE;
        }

        $emailEsc = escapeshellarg($email);
        Vps::lock($id, $useAll);
        Vps::getLogger()->write(Vps::runCommand("/admin/swift/vpsbackup {$id} email {$emailEsc}"));
        Vps::unlock($id, $useAll);

        return Command::SUCCESS;
    }
}
