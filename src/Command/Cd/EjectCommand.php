<?php
namespace App\Command\Cd;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EjectCommand extends BaseCdCommand
{
    protected static $defaultName = 'cd:eject';

    protected function configure()
    {
        $this
            ->setDescription('Eject a CD from a Virtual Machine.')
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

        $logger->write(Vps::runCommand("virsh change-media $vzid hda --eject --live"));
        $logger->write(Vps::runCommand("virsh change-media $vzid hda --eject --config"));

        $io->success("CD ejected from VPS '{$vzid}'.");

        return Command::SUCCESS;
    }
}
