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
    name: 'restore',
    description: 'Restores a Virtual Machine from Backup.'
)]
class RestoreCommand extends Command
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
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Use ALL available hardware resources')
            ->addArgument('source', InputArgument::REQUIRED, 'Source Backup Hostname to use')
            ->addArgument('name', InputArgument::REQUIRED, 'Backup Name to restore')
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS id/name to use')
            ->addArgument('id', InputArgument::REQUIRED, 'VPS ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $source = $input->getArgument('source');
        $name   = $input->getArgument('name');
        $vzid   = $input->getArgument('vzid');
        $id     = $input->getArgument('id');
        $useAll     = $input->getOption('all');

        $options = [
            'verbose' => count($input->getOption('verbose')),
            'virt'    => $input->getOption('virt'),
        ];

        Vps::init($options, [
            'source' => $source,
            'name'   => $name,
            'vzid'   => $vzid,
            'id'     => $id
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

        $base = Vps::$base;

        Vps::lock($id, $useAll);
        Vps::getLogger()->write(
            Vps::runCommand(
                "{$base}/vps_swift_restore.sh {$source} {$name} {$vzid} && " .
                "curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$id} https://myvps.interserver.net/vps_queue.php " .
                "|| curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$id} https://myvps.interserver.net/vps_queue.php"
            )
        );
        Vps::unlock($id, $useAll);

        return Command::SUCCESS;
    }
}
