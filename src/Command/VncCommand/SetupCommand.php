<?php
namespace App\Command\Vnc;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SetupCommand extends Command
{
    protected static $defaultName = 'vnc:setup';

    protected function configure()
    {
        $this
            ->setDescription("Setup VNC allowed IP for a VPS")
            ->addArgument('vzid', InputArgument::REQUIRED)
            ->addArgument('ip', InputArgument::OPTIONAL)
            ->addOption('verbose-level', 'v', InputOption::VALUE_NONE)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid = $input->getArgument('vzid');
        $ip   = $input->getArgument('ip');

        Vps::init($input->getOptions(), ['vzid' => $vzid, 'ip' => $ip]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' does not exist.");
            return Command::FAILURE;
        }

        Vps::setupVnc($vzid, $ip);

        return Command::SUCCESS;
    }
}
