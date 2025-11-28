<?php
namespace App\Command\CronCommand;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class HostInfoExtraCommand extends Command
{
    protected static $defaultName = 'cron:host-info-extra';
    protected static $defaultDescription = 'lists the history entries';

    protected function configure()
    {
        $this
            ->setDescription(self::$defaultDescription)

            // --verbose | -v  (incremental)
            // CLIFramework allowed this to be stacked and treated as a number.
            // Symfony uses built-in verbosity levels, but to maintain exact behavior,
            // we expose an integer option (default 0) that the user can increase manually.
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_OPTIONAL,
                'increase output verbosity (stacked..use multiple times for even more output)',
                0
            )

            // --virt=kvm|openvz|virtuozzo|lxc
            ->addOption(
                'virt',
                't',
                InputOption::VALUE_REQUIRED,
                'Type of Virtualization, kvm, openvz, virtuozzo, lxc'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Preserve CLIFramework behavior exactly:
        // Pass options as an array identical to $this->getOptions() structure.
        $options = [
            'verbose' => $input->getOption('verbose'),
            'virt'    => $input->getOption('virt'),
        ];

        Vps::init($options, []);

        $servers = [];

        // Ensure ethtool exists (keep original shell logic exactly)
        `if ! which ethtool 2>/dev/null; then if [ -e /etc/redhat-release ]; then yum install -y ethtool; else apt-get install -y ethtool; fi; fi;`;

        // Determine network interface name (same logic preserved)
        $hostname = trim(`hostname`);
        if (in_array($hostname, ["kvm1.trouble-free.net", "kvm2.interserver.net", "kvm50.interserver.net"])) {
            $eth = 'eth1';
        } elseif (file_exists('/etc/debian_version')) {
            if (file_exists('/sys/class/net/p2p1')) {
                $eth = 'p2p1';
            } elseif (file_exists('/sys/class/net/em1')) {
                $eth = 'em1';
            } else {
                $eth = trim(`ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#""#g`);
            }
        } else {
            $eth = trim(`ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#""#g`);
        }

        // Get link speed
        $cmd = 'ethtool '.$eth.' |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
        $speed = trim(`{$cmd}`);
        $servers['speed'] = $speed;

        // Extract CPU flags
        if (preg_match_all('/^flags\s*:\s*(.*)$/m', file_get_contents('/proc/cpuinfo'), $matches)) {
            $flags = explode(' ', trim($matches[1][0]));
            sort($flags);
            $flags = implode(' ', $flags);
            $servers['cpu_flags'] = $flags;
        }

        // Send data to API
        $url = 'https://myvps.interserver.net/vps_queue.php';
        $cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=server_info_extra -d servers="'.
               urlencode(base64_encode(serialize($servers))).'" "'.$url.'" 2>/dev/null;';

        echo trim(`$cmd`);

        return Command::SUCCESS;
    }
}
