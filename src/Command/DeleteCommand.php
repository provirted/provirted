<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteCommand extends Command
{
    protected static $defaultName = 'delete';

    protected function configure()
    {
        $this
            ->setDescription('Deletes a Virtual Machine.')
            ->addOption('verbose', 'v', InputOption::VALUE_OPTIONAL, 'Increase verbosity', null)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Virtualization type')
            ->addOption('order-id', 'o', InputOption::VALUE_REQUIRED, 'Order ID')
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS id/name to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid = $input->getArgument('vzid');
        $opts = $input->getOptions();

        Vps::init($opts, ['vzid' => $vzid]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->error("Virtualization not installed.");
            return Command::FAILURE;
        }

        $orderId = $input->getOption('order-id') ?: '';

        if (!Vps::vpsExists($vzid)) {
            Vps::getLogger()->error("VPS '{$vzid}' does not exist.");
            return Command::FAILURE;
        }

        if (file_exists('/vz/'.$vzid.'/protected')) {
            Vps::getLogger()->error("VPS '{$vzid}' is protected.");
            return Command::FAILURE;
        }

        Vps::deleteVps($vzid);

        if ($orderId != '') {
            $url = Vps::getUrl();
            Vps::runCommand("curl --connect-timeout 10 --max-time 20 -k -d action=finished -d command=delete -d service={$orderId} '{$url}' < /dev/null > /dev/null 2>&1;");
        }

        return Command::SUCCESS;
    }
}
