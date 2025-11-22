<?php
namespace App\Command\CdCommand;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisableCommand extends BaseCdCommand
{
    protected static $defaultName = 'cd:disable';

    protected function configure()
    {
        $this
            ->setDescription('Disable the CD-ROM in a Virtual Machine.')
            ->addArgument('vzid', InputArgument::REQUIRED);

        $this->configureBaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        [$io, $logger] = $this->bootstrap($input, $output, [
            'vzid' => $input->getArgument('vzid')
        ]);

        $vzid = $input->getArgument('vzid');

        if (!$this->ensureVpsValid($io, $vzid)) {
            return Command::FAILURE;
        }

        $exists = trim(Vps::runCommand("virsh dumpxml {$vzid} | grep \"disk.*cdrom\""));

        if ($exists === "") {
            $io->warning("No CD-ROM exists in VPS configuration.");
            return Command::SUCCESS;
        }

        $logger->write(Vps::runCommand("virsh detach-disk $vzid hda --config"));
        Vps::restartVps($vzid);

        $base = Vps::$base;
        $logger->write(Vps::runCommand("{$base}/vps_refresh_vnc.sh {$vzid}"));

        $io->success("CD-ROM disabled for VPS '{$vzid}'.");

        return Command::SUCCESS;
    }
}
