<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stop',
    description: 'Stops a Virtual Machine.'
)]
class StopCommand extends Command
{
    protected function configure()
    {
        $this
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'increase output verbosity (stacked..use multiple times for even more output)'
            )
            ->addOption(
                'virt',
                't',
                InputOption::VALUE_REQUIRED,
                'Type of Virtualization, kvm, openvz, virtuozzo, lxc'
            )
            ->addOption(
                'fast',
                'f',
                InputOption::VALUE_NONE,
                'Fast shutdown'
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

        $options = [
            'verbose' => count($input->getOption('verbose')),
            'virt' => $input->getOption('virt'),
            'fast' => $input->getOption('fast'),
        ];

        Vps::init($options, ['vzid' => $vzid]);

        $fast = (bool)$input->getOption('fast');

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
            return Command::FAILURE;
        }

        if (!Vps::isVpsRunning($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to be powered on.");
            return Command::FAILURE;
        }

        Vps::stopVps($vzid, $fast);

        return Command::SUCCESS;
    }
}
