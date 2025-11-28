<?php
namespace App\Command\Snapshot;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SaveCommand extends Command
{
    protected static $defaultName = 'snapshot:save';

    protected function configure()
    {
        $this
            ->setDescription('Create a new ZFS snapshot of a VPS disk')
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS ID')
            ->addOption('verbose-level', 'v', InputOption::VALUE_NONE)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid = $input->getArgument('vzid');

        Vps::init($input->getOptions(), ['vzid' => $vzid]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have virtualization installed.");
            return Command::FAILURE;
        }
        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("VPS '{$vzid}' does not exist.");
            return Command::FAILURE;
        }
        if (Vps::getPoolType() !== 'zfs') {
            Vps::getLogger()->error("This system is not setup for ZFS.");
            return Command::FAILURE;
        }

        Vps::stopVps($vzid);

        Vps::getLogger()->error("Creating vz/{$vzid}@first snapshot");

        // Rotate snapshots
        Vps::getLogger()->write(Vps::runCommand("zfs destroy vz/{$vzid}@third"));
        Vps::getLogger()->write(Vps::runCommand("zfs rename vz/{$vzid}@second vz/{$vzid}@third"));
        Vps::getLogger()->write(Vps::runCommand("zfs rename vz/{$vzid}@first vz/{$vzid}@second"));
        Vps::getLogger()->write(Vps::runCommand("zfs snapshot vz/{$vzid}@first"));

        Vps::startVps($vzid);

        return Command::SUCCESS;
    }
}
