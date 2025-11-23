<?php
namespace App\Command\Snapshot;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
    protected static $defaultName = 'snapshot:list';

    protected function configure()
    {
        $this
            ->setDescription('Displays a listing of the ZFS snapshots')
            ->addArgument('vzid', InputArgument::OPTIONAL, 'Filter snapshots by VPS ID')
            ->addOption('verbose-level', 'v', InputOption::VALUE_NONE)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED)
            ->addOption('dry', 'd', InputOption::VALUE_NONE, 'Dry run — no changes made');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid   = $input->getArgument('vzid');
        $dryRun = (bool)$input->getOption('dry');

        Vps::init($input->getOptions(), ['vzid' => $vzid]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
            return Command::FAILURE;
        }
        if (Vps::getPoolType() !== 'zfs') {
            Vps::getLogger()->error("This system is not setup for ZFS");
            return Command::FAILURE;
        }

        $suffixes = [
            'B' => 1,
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            'T' => 1024 * 1024 * 1024 * 1024,
        ];

        $raw = shell_exec('zfs list -t snapshot -o name,used,creation');
        $pattern = '/^vz\/(?P<vps>[^@]+)@(?P<name>\S+)\s+(?P<used>[\d\.]+)(?P<suffix>[BKMGT])\s+(?P<date>.+)$/mu';

        if (preg_match_all($pattern, $raw, $matches)) {

            $output->writeln(str_pad("VPS", 20) . str_pad("Snapshot", 20) . str_pad("Size", 15) . "Created");

            foreach ($matches['vps'] as $idx => $vpsName) {

                if ($vzid && $vzid !== $vpsName) {
                    continue;
                }

                $name = $matches['name'][$idx];
                $size = ceil(floatval($matches['used'][$idx]) * $suffixes[$matches['suffix'][$idx]]);
                $date = date('Y-m-d H:i:s', strtotime($matches['date'][$idx]));

                $output->writeln(
                    str_pad($vpsName, 20) .
                    str_pad($name, 20) .
                    str_pad($size, 15) .
                    $date
                );
            }
        }

        return Command::SUCCESS;
    }
}
