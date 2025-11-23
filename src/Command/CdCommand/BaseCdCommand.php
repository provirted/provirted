<?php
namespace App\Command\CdCommand;

use App\Logger;
use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class BaseCdCommand extends Command
{
    protected function configureBaseOptions()
    {
        $this->addOption(
            'virt',
            't',
            InputOption::VALUE_REQUIRED,
            'Virtualization type (kvm, openvz, virtuozzo, lxc)',
            'kvm'
        );
    }

    protected function bootstrap($input, $output, array $extraInit = [])
    {
        $io = new SymfonyStyle($input, $output);
        $logger = new Logger($io);

        $args = array_merge($extraInit, [
            'virt' => $input->getOption('virt')
        ]);

        Vps::init($args, $extraInit);
        Vps::setLogger($logger);

        return [$io, $logger];
    }

    protected function ensureVpsValid($io, $vzid)
    {
        if (!Vps::isVirtualHost()) {
            $io->error("This machine does not appear to have any virtualization setup installed.");
            return false;
        }

        if (!Vps::vpsExists($vzid)) {
            $io->error("The VPS '{$vzid}' does not appear to exist.");
            return false;
        }

        return true;
    }
}
