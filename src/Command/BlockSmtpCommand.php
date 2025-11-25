<?php
namespace App\Command;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BlockSmtpCommand extends Command
{
    protected static $defaultName = 'block-smtp';

    protected function configure()
    {
        $this
            ->setDescription('Blocks SMTP on a Virtual Machine')
            ->addOption(
                'virt',
                't',
                InputOption::VALUE_REQUIRED,
                'Type of Virtualization, kvm, openvz, virtuozzo, lxc'
            )
            ->addArgument(
                'vzid',
                InputArgument::REQUIRED,
                'VPS id/name to use'
            )
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'VPS ID'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid = $input->getArgument('vzid');
        $id   = $input->getArgument('id');

        // Init
        Vps::init($input, ['vzid' => $vzid, 'id' => $id]);

        if (!Vps::isVirtualHost()) {
            $output->writeln("<error>This machine does not appear to have any virtualization setup installed.</error>");
            $output->writeln("<error>Check the help to see how to prepare a virtualization environment.</error>");
            return Command::FAILURE;
        }

        if (!Vps::vpsExists($vzid)) {
            $output->writeln("<error>The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.</error>");
            return Command::FAILURE;
        }

        if ($id === null || $id === '') {
            $id = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $vzid);
        }

        if (!is_numeric($id)) {
            $output->writeln("<error>Either no ID was passed and we could not guess the ID from the Hostname, or a non-numeric ID was passed.</error>");
            return Command::FAILURE;
        }

        Vps::blockSmtp($vzid, $id);

        return Command::SUCCESS;
    }
}
