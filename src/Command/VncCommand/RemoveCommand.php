<?php
namespace App\Command\Vnc;

use App\Vps;
use App\Os\Xinetd;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveCommand extends Command
{
    protected static $defaultName = 'vnc:remove';

    protected function configure()
    {
        $this
            ->setDescription("Remove a VNC mapping for a VPS")
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS ID')
            ->addOption('verbose-level', 'v', InputOption::VALUE_NONE, 'Verbosity')
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Virtualization type');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid = $input->getArgument('vzid');
        Vps::init($input->getOptions(), ['vzid' => $vzid]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            return Command::FAILURE;
        }

        Xinetd::lock();
        Xinetd::remove($vzid);
        Xinetd::unlock();
        Xinetd::restart();

        return Command::SUCCESS;
    }
}
