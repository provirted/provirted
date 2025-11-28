<?php
namespace App\Command\Cd;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InsertCommand extends BaseCdCommand
{
    protected static $defaultName = 'cd:insert';

    protected function configure()
    {
        $this
            ->setDescription('Load a CD image into an existing CD-ROM in a Virtual Machine.')
            ->addArgument('vzid', InputArgument::REQUIRED)
            ->addArgument('url', InputArgument::REQUIRED);

        $this->configureBaseOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        [$io, $logger] = $this->bootstrap($input, $output, [
            'vzid' => $input->getArgument('vzid'),
            'url'  => $input->getArgument('url')
        ]);

        $vzid = $input->getArgument('vzid');
        $url  = $input->getArgument('url');

        if (!$this->ensureVpsValid($io, $vzid)) {
            return Command::FAILURE;
        }

        $base  = Vps::$base;
        $parts = parse_url($url);

        if (!isset($parts['port'])) {
            $parts['port'] = trim(Vps::runCommand(
                "grep \"^{$parts['scheme']}\\s\" /etc/services |grep \"/tcp\\s\"|cut -d/ -f1|awk \"{ print \\$2 }\""
            ));
        }

        $xml = <<<XML
<disk type='network' device='cdrom'>
  <driver name='qemu' type='raw'/>
  <target dev='hda' bus='ide'/>
  <readonly/>
  <source protocol='{$parts['scheme']}' name='{$parts['path']}'>
    <host name='{$parts['host']}' port='{$parts['port']}'/>
  </source>
</disk>
XML;

        file_put_contents("$base/disk.xml", $xml);

        $logger->write(Vps::runCommand("virsh update-device $vzid $base/disk.xml --live"));
        $logger->write(Vps::runCommand("virsh update-device $vzid $base/disk.xml --config"));
        $logger->write(Vps::runCommand("rm -f $base/disk.xml"));
        $logger->write(Vps::runCommand("virsh reboot $vzid"));

        $io->success("CD image loaded and VM rebooted.");

        return Command::SUCCESS;
    }
}
