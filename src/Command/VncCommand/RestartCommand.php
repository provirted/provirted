<?php
namespace App\Command\Vnc;

use App\Vps;
use App\Os\Xinetd;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RestartCommand extends Command
{
    protected static $defaultName = 'vnc:restart';

    protected function configure()
    {
        $this
            ->setDescription("Restart the Xinetd service")
            ->addOption('verbose-level', 'v', InputOption::VALUE_NONE)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Vps::init($input->getOptions(), []);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            return Command::FAILURE;
        }

        Xinetd::restart();
        return Command::SUCCESS;
    }
}
