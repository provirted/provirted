<?php
namespace App\Command;

use App\Vps;
use App\Os\Dhcpd;
use App\Os\Xinetd;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'test',
    description: 'Perform various self diagnostics to check on the health and preparedness of the system.'
)]
class TestCommand extends Command
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
                'Type of Virtualization, kvm, openvz, virtuozzo, lxc',
                null
            )
            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS id/name to use')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid     = $input->getArgument('vzid');
        $password = $input->getArgument('password');

        $output->writeln('Running Tests on ' . $vzid);

        $logger = new ActionLogger(fopen('php://stdout', 'w'), new Formatter);

        $options = [
            'verbose' => count($input->getOption('verbose')),
            'virt'    => $input->getOption('virt'),
        ];

        // Virtualization Installed
        $action = $logger->newAction('Virtualization Installed');
        $action->setStatus('checking');
        Vps::init($options, ['vzid' => $vzid]);

        if (!Vps::isVirtualHost()) {
            $action->setStatus('error');
            $output->writeln("<error>This machine does not appear to have any virtualization setup installed.</error>");
            $output->writeln("<error>Check the help to see how to prepare a virtualization environment.</error>");
            return Command::FAILURE;
        }
        $action->setStatus('success');
        $action->done();

        // VPS Exists
        $action = $logger->newAction('VPS Exists');
        if (!Vps::vpsExists($vzid)) {
            $action->setStatus('error');
            $output->writeln("<error>The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.</error>");
            return Command::FAILURE;
        }
        $action->setStatus('success');
        $action->done();

        // VPS Running
        $action = $logger->newAction('VPS Running');
        if (!Vps::isVpsRunning($vzid)) {
            $action->setStatus('error');
            $output->writeln("<error>The VPS '{$vzid}' you specified appears to be stopped.</error>");
            return Command::FAILURE;
        }
        $action->setStatus('success');
        $action->done();

        // DHCP Host Matches
        $action = $logger->newAction('DHCP Host Matches');
        $hosts = Dhcpd::getHosts();
        if (!array_key_exists($vzid, $hosts)) {
            $action->setStatus('error');
            $output->writeln("<error>Dhcpd does not appear to have {$vzid} among the configured hosts (" . implode(', ', array_keys($hosts)) . ")</error>");
            return Command::FAILURE;
        }
        $action->setStatus('success');
        $action->done();

        // DHCP Mac & IP Address Matches
        $action = $logger->newAction('DHCP Mac & IP Adddress Matches');
        $mac = Vps::getVpsMac($vzid);
        $ips = Vps::getVpsIps($vzid);
        foreach ($hosts as $name => $data) {
            if ($name == $vzid) {
                if (!in_array($data['ip'], $ips)) {
                    $action->setStatus('error');
                    $output->writeln("<error>The VPS does not appear to have the right ip in DHCP ({$data['ip']} not in " . implode(', ', $ips) . ")</error>");
                    return Command::FAILURE;
                }
                if ($data['mac'] != $mac) {
                    $action->setStatus('error');
                    $output->writeln("<error>The VPS does not appear to have the right mac in DHCP ({$data['mac']} != {$mac})</error>");
                    return Command::FAILURE;
                }
                $ip = $data['ip'];
            }
        }
        $action->setStatus('success');
        $action->done();

        // DHCP Running
        $action = $logger->newAction('DHCP Running');
        if (!Dhcpd::isRunning()) {
            $action->setStatus('error');
            $output->writeln("<error>Dhcpd does not appear to be running.</error>");
            return Command::FAILURE;
        }
        $action->setStatus('success');
        $action->done();

        // XinetD Running
        $action = $logger->newAction('XinetD Running');
        if (!Xinetd::isRunning()) {
            $action->setStatus('error');
            $output->writeln("<error>XinetD does not appear to be running.</error>");
            return Command::FAILURE;
        }
        $action->done();

        // Host Pings VPS
        $action = $logger->newAction('Host Pings VPS');
        $action->setStatus('request');
        $action->setStatus('pinging');
        if (trim(Vps::runCommand('ping -c 1 ' . $ip . ' -q -n >/dev/null && echo yes')) != 'yes') {
            $action->setStatus('error');
            $output->writeln("<error>Did not respond to ping.</error>");
            return Command::FAILURE;
        }
        $action->done();

        // SSH Methods
        $methods = [
            'hostkey' => 'ssh-rsa',
            'kex' => 'diffie-hellman-group-exchange-sha256',
            'client_to_server' => [
                'crypt' => 'aes256-ctr,aes192-ctr,aes128-ctr,aes256-cbc,aes192-cbc,aes128-cbc,3des-cbc,blowfish-cbc',
                'comp' => 'none'
            ],
            'server_to_client' => [
                'crypt' => 'aes256-ctr,aes192-ctr,aes128-ctr,aes256-cbc,aes192-cbc,aes128-cbc,3des-cbc,blowfish-cbc',
                'comp' => 'none'
            ]
        ];

        // SSH Connection
        $action = $logger->newAction('SSH Connection');
        $action->setStatus('connecting');
        $con = ssh2_connect($ip, 22, $methods);
        if (!$con) {
            $action->setStatus('error');
            $output->writeln('<error>SSH Connection failed to "' . $ip . '": ' . var_export($con, true) . '</error>');
            return Command::FAILURE;
        }
        $action->done();

        // SSH Authentication
        $action = $logger->newAction('SSH Authentication');
        $action->setStatus('authenticating');
        if (!@ssh2_auth_password($con, 'root', $password)) {
            $action->setStatus('error');
            $output->writeln('<error>SSH Password Authentication failed using "' . $password . '": ' . var_export($con, true) . '</error>');
            return Command::FAILURE;
        }
        $action->done();

        // VPS Pings Internet
        $action = $logger->newAction('VPS Pings Internet');
        $cmd = 'ping -c 1 1.1.1.1 -q -n >/dev/null && echo yes';
        $stream = ssh2_exec($con, $cmd);
        stream_set_blocking($stream, true);
        $response = trim(stream_get_contents($stream));
        fclose($stream);
        if ($response != 'yes') {
            $action->setStatus('error');
            $output->writeln('<error>Pinging 1.1.1.1 on VPS via SSH failed and returned: "' . $response . '"</error>');
            return Command::FAILURE;
        }
        $action->done();

        if ($con) {
            ssh2_disconnect($con);
        }

        $output->writeln($vzid . ' passed all tests!');
        return Command::SUCCESS;
    }
}
