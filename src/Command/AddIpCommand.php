<?php
namespace App\Command;

use App\Vps;
use App\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AddIpCommand extends Command
{
    protected static $defaultName = 'ip:add';

    protected function configure()
    {
        $this
            ->setDescription('Adds an IP Address to a Virtual Machine.')
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Virtualization type (auto, kvm, openvz, virtuozzo, lxc)', 'auto')
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS id/name to use')
            ->addArgument('ip', InputArgument::REQUIRED, 'IP Address');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $logger = new Logger($io);
        // Pull arguments
        $vzid = $input->getArgument('vzid');
        $ip   = $input->getArgument('ip');
        // Validate virtualization type
        $virt = $input->getOption('virt');
        $validVirt = ['auto', 'kvm', 'openvz', 'virtuozzo', 'lxc'];
        if (!in_array($virt, $validVirt, true)) {
            $io->error("Invalid virtualization type '{$virt}'");
            return Command::INVALID;
        }
        // Init Vps the same way as old framework
        Vps::init(['virt' => $virt, 'verbosity' => $output->getVerbosity()], ['vzid' => $vzid, 'ip' => $ip]);
        Vps::setLogger($logger);
        // Validations
        if (!Vps::isVirtualHost()) {
            $io->error(["This machine does not appear to have any virtualization setup installed.", "Check the help for preparing a virtualization environment."]);
            return Command::FAILURE;
        }
        if (!Vps::vpsExists($vzid)) {
            $io->error("The VPS '{$vzid}' does not appear to exist.");
            return Command::FAILURE;
        }
        // Main operation
        try {
            Vps::addIp($vzid, $ip);
            $io->success("IP {$ip} added to VPS {$vzid} successfully.");
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
