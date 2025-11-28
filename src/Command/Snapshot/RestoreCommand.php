<?php
namespace App\Command\Snapshot;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RestoreCommand extends Command
{
    protected static $defaultName = 'snapshot:restore';

    protected function configure()
    {
        $this
            ->setDescription('Restore a saved snapshot to a VPS')
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS ID')
            ->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot name')
            ->addOption('verbose-level', 'v', InputOption::VALUE_NONE)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid     = $input->getArgument('vzid');
        $snapshot = $input->getArgument('snapshot');

        Vps::init($input->getOptions(), ['vzid' => $vzid, 'snapshot' => $snapshot]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have virtualization installed.");
            return Command::FAILURE;
        }
        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("The VPS '{$vzid}' does not exist.");
            return Command::FAILURE;
        }
        if (Vps::getPoolType() !== 'zfs') {
            Vps::getLogger()->error("This system is not setup for ZFS.");
            return Command::FAILURE;
        }

        $exists = trim(Vps::runCommand("zfs list -t snapshot vz/{$vzid}@{$snapshot} 2>/dev/null || echo no"));

        if ($exists === 'no') {
            Vps::getLogger()->error("Snapshot '{$snapshot}' does not exist for VPS '{$vzid}'");
            return Command::FAILURE;
        }

        Vps::stopVps($vzid, true);

        Vps::getLogger()->error("Restoring vz/{$vzid}@{$snapshot}");
        Vps::getLogger()->write(Vps::runCommand("zfs rollback vz/{$vzid}@{$snapshot}"));

        Vps::startVps($vzid);

        return Command::SUCCESS;
    }
}
