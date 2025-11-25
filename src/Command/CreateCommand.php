<?php
namespace App\Command;

use App\Vps;
use App\Os\Os;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateCommand extends Command
{
    protected static $defaultName = 'create';

    protected function configure()
    {
        $this
            ->setDescription('Creates a Virtual Machine.')
            ->setHelp(
                "Creates a new VPS with the given <hostname> and primary IP address <ip>. The <template> file/url ".
                "is used as the source image.\n\n".
                "EXAMPLES:\n".
                "  create vps1001 vps2.provirted.com 192.168.1.103 centos-7 25 2048 1 password\n".
                "  create --virt=virtuozzo vps1002 vps3.provirted.com 192.168.1.104 ubuntu-20.04 25 2048 1 password\n".
                "  create -vv --order-id=2328714 --add-ip=192.168.1.101 --add-ip=192.168.1.102 ".
                "--client-ip=127.0.0.1 --password=password vps1003 vps3.provirted.com 192.168.1.105 ".
                "ubuntu-20.04 60 4096 2"
            )
            ->addOption('verbose', 'v', InputOption::VALUE_OPTIONAL, 'increase output verbosity (stacked)', null)
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Type of Virtualization', null)
            ->addOption('mac', 'm', InputOption::VALUE_REQUIRED, 'MAC Address')
            ->addOption('order-id', 'o', InputOption::VALUE_REQUIRED, 'Order ID')
            ->addOption('add-ip', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Additional IPs')
            ->addOption('client-ip', 'c', InputOption::VALUE_REQUIRED, 'Client IP')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Use ALL available hardware resources')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password')
            ->addOption('ssh-key', null, InputOption::VALUE_REQUIRED, 'Optional SSH Key to add')
            ->addOption('io-limit', null, InputOption::VALUE_REQUIRED, 'IO Limit bytes/s')
            ->addOption('iops-limit', null, InputOption::VALUE_REQUIRED, 'IO Limit iops')
            ->addOption('ipv6-ip', null, InputOption::VALUE_REQUIRED, 'IPv6 Address')
            ->addOption('ipv6-range', null, InputOption::VALUE_REQUIRED, 'IPv6 Range')

            ->addArgument('vzid', InputArgument::REQUIRED, 'VPS id/name')
            ->addArgument('hostname', InputArgument::REQUIRED, 'Hostname')
            ->addArgument('ip', InputArgument::REQUIRED, 'Primary IP')
            ->addArgument('template', InputArgument::REQUIRED, 'Template name')
            ->addArgument('hd', InputArgument::OPTIONAL, 'HD Size in GB', 25)
            ->addArgument('ram', InputArgument::OPTIONAL, 'RAM in MB', 1024)
            ->addArgument('cpu', InputArgument::OPTIONAL, 'Number of CPUs', 1)
            ->addArgument('password', InputArgument::OPTIONAL, 'Root password', '');
    }

    private function validIp($ip, $supportIpv6 = false)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            if (!$supportIpv6 || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return false;
            }
        }
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vzid     = $input->getArgument('vzid');
        $hostname = $input->getArgument('hostname');
        $ip       = $input->getArgument('ip');
        $template = $input->getArgument('template');
        $hd       = $input->getArgument('hd');
        $ram      = $input->getArgument('ram');
        $cpu      = $input->getArgument('cpu');
        $password = $input->getArgument('password');

        $opts = $input->getOptions();

        Vps::init($opts, [
            'vzid' => $vzid,
            'hostname' => $hostname,
            'ip' => $ip,
            'template' => $template,
            'hd' => $hd,
            'ram' => $ram,
            'cpu' => $cpu,
            'password' => $password
        ]);

        if (!Vps::isVirtualHost()) {
            Vps::getLogger()->writeln("This machine does not appear to have virtualization installed.");
            return Command::FAILURE;
        }

        if (file_exists('/vz/'.$vzid.'/protected')) {
            Vps::getLogger()->error("VPS '{$vzid}' is protected.");
            return Command::FAILURE;
        }

        Vps::getLogger()->info('Initializing Options');

        $useAll     = $input->getOption('all');
        $extraIps   = $input->getOption('add-ip') ?: [];
        $password   = $input->getOption('password') ?: $password;
        $clientIp   = $input->getOption('client-ip') ?: '';
        $orderId    = $input->getOption('order-id') ?: '';
        $mac        = $input->getOption('mac') ?: '';
        $sshKey     = $input->getOption('ssh-key') ?: false;
        $ipv6Ip     = $input->getOption('ipv6-ip') ?: false;
        $ipv6Range  = $input->getOption('ipv6-range') ?: false;

        $ioLimit    = !$useAll ? $input->getOption('io-limit') : false;
        $iopsLimit  = !$useAll ? $input->getOption('iops-limit') : false;

        if (!empty($ip) && !$this->validIp($ip, true)) {
            Vps::getLogger()->error("Invalid IP '{$ip}'.");
            return Command::FAILURE;
        }

        if ($useAll && trim(`virsh list --all | grep qs`) != '') {
            Vps::getLogger()->error("Cannot create an all-resource VPS when one already exists.");
            return Command::FAILURE;
        }

        if ($orderId == '') {
            $orderId = str_replace(['qs','windows','linux','vps'], '', $vzid);
        }

        if ($mac == '' && is_numeric($orderId)) {
            $mac = Vps::convertIdToMac($orderId, $useAll);
        }

        $url = Vps::getUrl();
        $kpartxOpts = preg_match('/sync/', Vps::runCommand("kpartx 2>&1")) ? '-s' : '';

        $ram = $ram * 1024;
        $hd  = $hd * 1024;

        $device = '';
        $pool   = '';

        if ($useAll) {
            $hd  = 'all';
            $ram = Os::getUsableRam();
            $cpu = Os::getCpuCount();
        }

        $maxCpu = $cpu > 8 ? $cpu : 8;
        $maxRam = $ram > 16384000 ? $ram : 16384000;

        if (Vps::getVirtType() == 'kvm') {
            $pool = Vps::getPoolType();
            $device = $pool == 'zfs' ? "/vz/{$vzid}/os.qcow2" : "/dev/vz/{$vzid}";
        }

        $webuzo = false;
        $cpanel = false;

        if (Vps::getVirtType() == 'virtuozzo') {
            if ($template == 'centos-7-x86_64-breadbasket') {
                $template = 'centos-7-x86_64';
                $webuzo = true;
            } elseif ($template == 'centos-7-x86_64-cpanel') {
                $template = 'centos-7-x86_64';
                $cpanel = true;
            }
        }

        $this->progress(5, $url, $orderId);
        Os::checkDeps();
        $this->progress(10, $url, $orderId);

        Vps::setupStorage($vzid, $device, $pool, $hd);
        $this->progress(15, $url, $orderId);

        $error = 0;

        if (!Vps::defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool,
            $ram, $cpu, $hd, $maxRam, $maxCpu, $useAll, $password, $ipv6Ip, $ipv6Range,
            $ioLimit, $iopsLimit)) {
            $error++;
        } else {
            $this->progress(25, $url, $orderId);
        }

        if ($error == 0) {
            if (!Vps::installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts, $ioLimit, $iopsLimit)) {
                $error++;
            } else {
                $this->progress(70, $url, $orderId);
            }

            if (Vps::getVirtType() == 'kvm') {
                $password = escapeshellarg($password);
                $hostname = escapeshellarg($hostname);
                $cmd = "virt-customize -d {$vzid} --root-password password:{$password} --hostname {$hostname}";
                if ($sshKey) {
                    $sshKey = escapeshellarg($sshKey);
                    $cmd .= " --ssh-inject root:string:{$sshKey}";
                }
                Vps::getLogger()->write(Vps::runCommand("{$cmd};"));
            }
        }

        if ($error == 0) {
            Vps::getLogger()->info('Starting VPS');
            Vps::enableAutostart($vzid);
            Vps::startVps($vzid);
            $this->progress(85, $url, $orderId);
        }

        if ($error == 0) {
            if ($webuzo) {
                Vps::setupWebuzo($vzid);
            }

            if ($cpanel) {
                Vps::setupCpanel($vzid);
            }

            Vps::setupCgroups($vzid, $useAll, $cpu);
            $this->progress(90, $url, $orderId);

            Vps::setupRouting($vzid, $ip, $pool, $useAll, $orderId);
            $this->progress(95, $url, $orderId);

            Vps::setupVnc($vzid, $clientIp);
            Vps::vncScreenshot($vzid, $url);

            $this->progress(100, $url, $orderId);
        }

        return Command::SUCCESS;
    }

    private function progress($progress, $url, $orderId)
    {
        $safe = escapeshellarg($progress);
        Vps::runCommand("curl --connect-timeout 10 --max-time 20 -k -d action=install_progress -d progress={$safe} -d server={$orderId} '{$url}' < /dev/null > /dev/null 2>&1;");
        Vps::getLogger()->writeln($safe.'%');
    }
}
