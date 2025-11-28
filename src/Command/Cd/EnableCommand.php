<?php
namespace App\Command\CdCommand;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnableCommand extends BaseCdCommand
{
    protected static $defaultName = 'cd:enable';

    protected function configure()
    {
        $this
            ->setDescription('Enable the CD-ROM and optionally insert a CD in a Virtual Machine.')
            ->addArgument('vzid', InputArgument::REQUIRED)
            ->addArgument('url', InputArgument::OPTIONAL);

        $this->configureBaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        [$io, $logger] = $this->bootstrap($input, $output, [
            'vzid' => $input->getArgument('vzid'),
            'url'  => $input->getArgument('url') ?: ''
        ]);

        $vzid = $input->getArgument('vzid');
        $url  = $input->getArgument('url') ?: '';

        if (!$this->ensureVpsValid($io, $vzid)) {
            return Command::FAILURE;
        }

        $exists = trim(Vps::runCommand("virsh dumpxml {$vzid}|grep \"disk.*cdrom\""));

        if ($exists !== "") {
            $io->warning("CD-ROM already exists in VPS configuration.");
            return Command::SUCCESS;
        }

        if ($url === '') {
            $logger->write(Vps::runCommand("virsh attach-disk $vzid - hda --targetbus ide --type cdrom --sourcetype file --config"));
            $logger->write(Vps::runCommand("virsh change-media $vzid hda --eject --config"));
        } else {
            $logger->write(Vps::runCommand("virsh attach-disk $vzid \"$url\" hda --targetbus ide --type cdrom --sourcetype file --config"));
        }

        Vps::restartVps($vzid);

        $io->success("CD-ROM enabled for VPS '{$vzid}'.");

        return Command::SUCCESS;
    }
}
