<?php
namespace App\Command\Cron;

use App\Vps;
use App\XmlToArray;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VpsInfoCommand extends Command {
    protected static $defaultName = 'cron:vps-info';

    protected function configure()
    {
        $this
            ->setDescription('Gather VPS information and send to central server (converted from CLIFramework)')
            ->addOption('verbose', 'v', InputOption::VALUE_OPTIONAL, 'increase output verbosity (stacked..use multiple times for even more output). Use numeric value e.g. --verbose=2', 0)
            ->addOption('virt', 't', InputOption::VALUE_OPTIONAL, 'Type of Virtualization, kvm, openvz, virtuozzo, lxc', null)
            ->addOption('json', 'j', InputOption::VALUE_OPTIONAL, 'Display data in JSON format', 0)
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Use All Available HD, CPU Cores, and 70% RAM')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Build an opts object compatible with the original code's expectations ($opts->keys[...] -> value)
        $opts = new \stdClass();
        $opts->keys = [];

        $opts->keys['all'] = new \stdClass();
        $opts->keys['all']->value = $input->getOption('all') ? 1 : 0;

        $opts->keys['json'] = new \stdClass();
        // Accept both --json and --json=1
        $jsonOpt = $input->getOption('json');
        $opts->keys['json']->value = ($jsonOpt !== null && ($jsonOpt === 1 || $jsonOpt === '1' || $jsonOpt === 'true' || $jsonOpt === '')) ? 1 : (int)$jsonOpt;

        $opts->keys['virt'] = new \stdClass();
        $opts->keys['virt']->value = $input->getOption('virt');

        $opts->keys['verbose'] = new \stdClass();
        $v = $input->getOption('verbose');
        // If numeric provided, use it. If not, set 0.
        $opts->keys['verbose']->value = is_numeric($v) ? (int)$v : 0;

        // Initialize Vps with the crafted options object (preserve original call)
        Vps::init($opts, []);

        // Local flags used by the original code
        $useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']->value == 1;
        $dispJson = array_key_exists('json', $opts->keys) && $opts->keys['json']->value == 1;

        $dir = Vps::$base;
        $module = $useAll === true ? 'quickservers' : 'vps';
        Vps::getLogger()->disableHistory();
        $url = 'https://myvps.interserver.net/'.($module == 'quickservers' ? 'qs' : 'vps').'_queue.php';
        $curl_cmd = '';
        $servers = array();
        $ips = array();

        // Helper wrapper for shell execution (used instead of backticks)
        $sh = function(string $cmd) {
            // keep behavior similar to backticks: return raw output (string)
            return shell_exec($cmd);
        };

        if (file_exists('/usr/bin/lxc')) {
            $lines = trim($sh('lxc list -c ns4,volatile.eth0.hwaddr:MAC --format csv'));
            if ($lines != '') {
                $lines = explode("\n", $lines);
                foreach ($lines as $line) {
                    $parts = explode(',', $line);
                    $server = array(
                        'type' => 'lxc',
                        'veid' => $parts[0],
                        'status' => isset($parts[1]) ? strtolower($parts[1]) : 'stopped',
                    );
                    if (isset($parts[2])) {
                        $ipparts = explode(" ", $parts[2]);
                        $server['ip'] = $ipparts[0];
                        $ips[$parts[0]] = $ipparts[0];
                    }
                    if (isset($parts[3]) && trim($parts[3]) != '') {
                        $server['mac'] = trim($parts[3]);
                    }
                    $servers[$parts[0]] = $server;
                }
            }
        }

        if (file_exists('/usr/bin/virsh')) {
            $cmd = 'export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh list --all | grep -v -e "State$" -e "------$" -e "^$" | awk "{ print \$2 \" \" \$3 }"';
            $out = trim($sh($cmd));
            $lines = explode("\n", $out);
            $cmd_accumulator = '';
            foreach ($lines as $serverline) {
                if (trim($serverline) != '') {
                    $parts = explode(' ', $serverline);
                    $name = $parts[0];
                    $veid = $name;
                    $status = $parts[1];
                    $server = array(
                        'type' => 'kvm',
                        'veid' => $veid,
                        'status' => $status,
                        'hostname' => $name,
                    );
                    $out2 = $sh('export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh dumpxml '.$name);
                    if (trim($out2) != '') {
                        $xml = XmlToArray::go($out2, 1, 'attribute');
                        $server['kmemsize'] = $xml['domain']['memory']['value'] ?? null;
                        if (isset($xml['domain']['devices']['interface'])) {
                            if (isset($xml['domain']['devices']['interface']['mac']['attr']['address'])) {
                                $server['mac'] = $xml['domain']['devices']['interface']['mac']['attr']['address'];
                            } elseif (isset($xml['domain']['devices']['interface'][0]['mac']['attr'])) {
                                $server['mac'] = $xml['domain']['devices']['interface'][0]['mac']['attr']['address'];
                            }
                        }
                        if (isset($xml['domain']['devices']['graphics']['attr']['port'])) {
                            $server['vnc'] = (int)$xml['domain']['devices']['graphics']['attr']['port'];
                        } elseif (isset($xml['domain']['devices']['graphics'][0]['attr']['port'])) {
                            foreach ($xml['domain']['devices']['graphics'] as $idx => $graphics) {
                                if (isset($graphics['attr']['port'])) {
                                    $server[$graphics['attr']['type']] = (int)$graphics['attr']['port'];
                                }
                            }
                        }
                        if ($status == 'running') {
                            // preserved commented disk-check code
                            if (isset($server['vnc'])) {
                                $port = $server['vnc'];
                                if ($port >= 5900) {
                                    // preserved commented vncsnapshot block
                                }
                            }
                        }
                    }
                    $servers[$veid] = $server;
                }
            }

            // Gather IP mapping from various possible sources
            if (file_exists('/etc/dhcp/dhcpd.vps')) {
                $ipcmd = 'grep host /etc/dhcp/dhcpd.vps |sed s#"^.*host \([^ ]*\) .*fixed-address \([0-9\.]*\);.*$"#"\1:\2"#g';
                $lines = explode("\n", trim($sh($ipcmd)));
            } elseif (file_exists('/etc/dhcpd.vps')) {
                $ipcmd = 'grep host /etc/dhcpd.vps |sed s#"^.*host \([^ ]*\) .*fixed-address \([0-9\.]*\);.*$"#"\1:\2"#g';
                $lines = explode("\n", trim($sh($ipcmd)));
            } elseif (file_exists($dir.'/vps.mainips')) {
                $lines = explode("\n", trim(file_get_contents($dir.'/vps.mainips')));
            } else {
                $lines = array();
            }
            $ipIds = array();
            foreach ($lines as $line) {
                if (trim($line) != '') {
                    list($id, $ip) = explode(':', $line);
                    $ipIds[$ip] = $id;
                    $ips[$id] = array();
                    $ips[$id][] = $ip;
                }
            }
            $lines = trim(@file_get_contents($dir.'/vps.ipmap'));
            if ($lines != '') {
                $lines = explode("\n", $lines);
                foreach ($lines as $line) {
                    list($mainIp, $addonIp) = explode(':', $line);
                    if (array_key_exists($mainIp, $ipIds) && $addonIp != $mainIp) {
                        $ips[$ipIds[$mainIp]][] = $addonIp;
                    }
                }
            }
            $curl_cmd = '$(for i in shot_*jpg; do if [ "$i" != "shot_*jpg" ]; then p=$(echo $i | cut -c5-9); gzip -9 -f $i; echo -n " -F shot$p=@${i}.gz"; fi; done;)';
            // leftover cmd accumulator not used except in final curl_cmd insertion
        }

        if (file_exists('/usr/sbin/vzctl') || file_exists('/usr/bin/prlctl')) {
            if (file_exists('/usr/bin/prlctl')) {
                $type = 'virtuozzo';
                $cmd = 'export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -a -o uuid,ctid,name,numproc,status,ip,hostname,swappages,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H;';
                $out = $sh($cmd);
                preg_match_all('/^\s*(?P<uuid>[^\s]+)\s+(?P<vzid>[^\s]+)\s+(?P<ctid>[^\s]+)\s+(?P<numproc>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<vswap>[^\s]+)\s+(?P<layout>[^\s]+)\s+(?P<kmemsize>[^\s]+)\s+(?P<kmemsize_f>[^\s]+)\s+(?P<lockedpages>[^\s]+)\s+(?P<lockedpages_f>[^\s]+)\s+(?P<privvmpages>[^\s]+)\s+(?P<privvmpages_f>[^\s]+)\s+(?P<shmpages>[^\s]+)\s+(?P<shmpages_f>[^\s]+)\s+(?P<numproc_f>[^\s]+)\s+(?P<physpages>[^\s]+)\s+(?P<physpages_f>[^\s]+)\s+(?P<vmguarpages>[^\s]+)\s+(?P<vmguarpages_f>[^\s]+)\s+(?P<oomguarpages>[^\s]+)\s+(?P<oomguarpages_f>[^\s]+)\s+(?P<numtcpsock>[^\s]+)\s+(?P<numtcpsock_f>[^\s]+)\s+(?P<numflock>[^\s]+)\s+(?P<numflock_f>[^\s]+)\s+(?P<numpty>[^\s]+)\s+(?P<numpty_f>[^\s]+)\s+(?P<numsiginfo>[^\s]+)\s+(?P<numsiginfo_f>[^\s]+)\s+(?P<tcpsndbuf>[^\s]+)\s+(?P<tcpsndbuf_f>[^\s]+)\s+(?P<tcprcvbuf>[^\s]+)\s+(?P<tcprcvbuf_f>[^\s]+)\s+(?P<othersockbuf>[^\s]+)\s+(?P<othersockbuf_f>[^\s]+)\s+(?P<dgramrcvbuf>[^\s]+)\s+(?P<dgramrcvbuf_f>[^\s]+)\s+(?P<numothersock>[^\s]+)\s+(?P<numothersock_f>[^\s]+)\s+(?P<dcachesize>[^\s]+)\s+(?P<dcachesize_f>[^\s]+)\s+(?P<numfile>[^\s]+)\s+(?P<numfile_f>[^\s]+)\s+(?P<numiptent>[^\s]+)\s+(?P<numiptent_f>[^\s]+)\s+(?P<diskspace>[^\s]+)\s+(?P<diskspace_s>[^\s]+)\s+(?P<diskspace_h>[^\s]+)\s+(?P<diskinodes>[^\s]+)\s+(?P<diskinodes_s>[^\s]+)\s+(?P<diskinodes_h>[^\s]+)\s+(?P<laverage>[^\s]+)/m', $out, $matches);
            } else {
                $type = 'openvz';
                $cmd = 'export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin";if [ "$(vzlist -L |grep vswap)" = "" ]; then vzlist -a -o ctid,numproc,status,ip,hostname,swappages,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H; else vzlist -a -o ctid,numproc,status,ip,hostname,vswap,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H; fi;';
                $out = $sh($cmd);
                preg_match_all('/\s+(?P<ctid>[^\s]+)\s+(?P<numproc>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<vswap>[^\s]+)\s+(?P<layout>[^\s]+)\s+(?P<kmemsize>[^\s]+)\s+(?P<kmemsize_f>[^\s]+)\s+(?P<lockedpages>[^\s]+)\s+(?P<lockedpages_f>[^\s]+)\s+(?P<privvmpages>[^\s]+)\s+(?P<privvmpages_f>[^\s]+)\s+(?P<shmpages>[^\s]+)\s+(?P<shmpages_f>[^\s]+)\s+(?P<numproc_f>[^\s]+)\s+(?P<physpages>[^\s]+)\s+(?P<physpages_f>[^\s]+)\s+(?P<vmguarpages>[^\s]+)\s+(?P<vmguarpages_f>[^\s]+)\s+(?P<oomguarpages>[^\s]+)\s+(?P<oomguarpages_f>[^\s]+)\s+(?P<numtcpsock>[^\s]+)\s+(?P<numtcpsock_f>[^\s]+)\s+(?P<numflock>[^\s]+)\s+(?P<numflock_f>[^\s]+)\s+(?P<numpty>[^\s]+)\s+(?P<numpty_f>[^\s]+)\s+(?P<numsiginfo>[^\s]+)\s+(?P<numsiginfo_f>[^\s]+)\s+(?P<tcpsndbuf>[^\s]+)\s+(?P<tcpsndbuf_f>[^\s]+)\s+(?P<tcprcvbuf>[^\s]+)\s+(?P<tcprcvbuf_f>[^\s]+)\s+(?P<othersockbuf>[^\s]+)\s+(?P<othersockbuf_f>[^\s]+)\s+(?P<dgramrcvbuf>[^\s]+)\s+(?P<dgramrcvbuf_f>[^\s]+)\s+(?P<numothersock>[^\s]+)\s+(?P<numothersock_f>[^\s]+)\s+(?P<dcachesize>[^\s]+)\s+(?P<dcachesize_f>[^\s]+)\s+(?P<numfile>[^\s]+)\s+(?P<numfile_f>[^\s]+)\s+(?P<numiptent>[^\s]+)\s+(?P<numiptent_f>[^\s]+)\s+(?P<diskspace>[^\s]+)\s+(?P<diskspace_s>[^\s]+)\s+(?P<diskspace_h>[^\s]+)\s+(?P<diskinodes>[^\s]+)\s+(?P<diskinodes_s>[^\s]+)\s+(?P<diskinodes_h>[^\s]+)\s+(?P<laverage>[^\s]+)/m', $out, $matches);
            }

            // Build servers list from matches
            if (!empty($matches) && isset($matches['ctid'])) {
                foreach ($matches['ctid'] as $key => $id) {
                    if ($id == '-' && isset($matches['vzid'][$key])) {
                        $id = $matches['vzid'][$key];
                    }
                    $server = array(
                        'type' => $type,
                        'veid' => $id,
                        'numproc' => $matches['numproc'][$key] ?? null,
                        'status' => $matches['status'][$key] ?? null,
                        'ip' => $matches['ip'][$key] ?? null,
                        'hostname' => $matches['hostname'][$key] ?? null,
                        'vswap' => $matches['vswap'][$key] ?? null,
                        'layout' => $matches['layout'][$key] ?? null,
                        'kmemsize' => $matches['kmemsize'][$key] ?? null,
                        'kmemsize_f' => $matches['kmemsize_f'][$key] ?? null,
                        'lockedpages' => $matches['lockedpages'][$key] ?? null,
                        'lockedpages_f' => $matches['lockedpages_f'][$key] ?? null,
                        'privvmpages' => $matches['privvmpages'][$key] ?? null,
                        'privvmpages_f' => $matches['privvmpages_f'][$key] ?? null,
                        'shmpages' => $matches['shmpages'][$key] ?? null,
                        'shmpages_f' => $matches['shmpages_f'][$key] ?? null,
                        'numproc_f' => $matches['numproc_f'][$key] ?? null,
                        'physpages' => $matches['physpages'][$key] ?? null,
                        'physpages_f' => $matches['physpages_f'][$key] ?? null,
                        'vmguarpages' => $matches['vmguarpages'][$key] ?? null,
                        'vmguarpages_f' => $matches['vmguarpages_f'][$key] ?? null,
                        'oomguarpages' => $matches['oomguarpages'][$key] ?? null,
                        'oomguarpages_f' => $matches['oomguarpages_f'][$key] ?? null,
                        'numtcpsock' => $matches['numtcpsock'][$key] ?? null,
                        'numtcpsock_f' => $matches['numtcpsock_f'][$key] ?? null,
                        'numflock' => $matches['numflock'][$key] ?? null,
                        'numflock_f' => $matches['numflock_f'][$key] ?? null,
                        'numpty' => $matches['numpty'][$key] ?? null,
                        'numpty_f' => $matches['numpty_f'][$key] ?? null,
                        'numsiginfo' => $matches['numsiginfo'][$key] ?? null,
                        'numsiginfo_f' => $matches['numsiginfo_f'][$key] ?? null,
                        'tcpsndbuf' => $matches['tcpsndbuf'][$key] ?? null,
                        'tcpsndbuf_f' => $matches['tcpsndbuf_f'][$key] ?? null,
                        'tcprcvbuf' => $matches['tcprcvbuf'][$key] ?? null,
                        'tcprcvbuf_f' => $matches['tcprcvbuf_f'][$key] ?? null,
                        'othersockbuf' => $matches['othersockbuf'][$key] ?? null,
                        'othersockbuf_f' => $matches['othersockbuf_f'][$key] ?? null,
                        'dgramrcvbuf' => $matches['dgramrcvbuf'][$key] ?? null,
                        'dgramrcvbuf_f' => $matches['dgramrcvbuf_f'][$key] ?? null,
                        'numothersock' => $matches['numothersock'][$key] ?? null,
                        'numothersock_f' => $matches['numothersock_f'][$key] ?? null,
                        'dcachesize' => $matches['dcachesize'][$key] ?? null,
                        'dcachesize_f' => $matches['dcachesize_f'][$key] ?? null,
                        'numfile' => $matches['numfile'][$key] ?? null,
                        'numfile_f' => $matches['numfile_f'][$key] ?? null,
                        'numiptent' => $matches['numiptent'][$key] ?? null,
                        'numiptent_f' => $matches['numiptent_f'][$key] ?? null,
                        'diskspace' => $matches['diskspace'][$key] ?? null,
                        'diskspace_s' => $matches['diskspace_s'][$key] ?? null,
                        'diskspace_h' => $matches['diskspace_h'][$key] ?? null,
                        'diskinodes' => $matches['diskinodes'][$key] ?? null,
                        'diskinodes_s' => $matches['diskinodes_s'][$key] ?? null,
                        'diskinodes_h' => $matches['diskinodes_h'][$key] ?? null,
                        'laverage' => $matches['laverage'][$key] ?? null,
                    );
                    if (isset($matches['uuid'][$key])) {
                        $server['uuid'] = $matches['uuid'][$key];
                        $server['vzid'] = $matches['vzid'][$key] ?? null;
                    }
                    $servers[$id] = $server;
                }
            }

            if (file_exists('/usr/bin/prlctl')) {
                $json_servers = json_decode($sh('prlctl list -a -i -j'), true);
                if (is_array($json_servers)) {
                    foreach ($json_servers as $json_server) {
                        $servers[$json_server['Name']]['ip'] = isset($json_server['Hardware']['venet0']['ips']) ? explode(' ', str_replace('/255.255.255.0', '', trim($json_server['Hardware']['venet0']['ips'])))[0] : [];
                        if (isset($json_server['Remote display']) && isset($json_server['Remote display']['port'])) {
                            $servers[$json_server['Name']]['vnc'] = $json_server['Remote display']['port'];
                        }
                    }
                }
            }

            // For each server, attempt to determine disk usage from ploop/vzquota output
            foreach ($servers as $id => $server) {
                if ($id == 0) {
                    continue;
                }
                unset($file);
                if (file_exists('/vz/private/'.$id.'/root.hdd/DiskDescriptor.xml')) {
                    $file = '/vz/private/'.$id.'/root.hdd/DiskDescriptor.xml';
                } elseif (isset($servers[$id]['uuid']) && file_exists('/vz/private/'.$servers[$id]['uuid'].'/root.hdd/DiskDescriptor.xml')) {
                    $file = '/vz/private/'.$servers[$id]['uuid'].'/root.hdd/DiskDescriptor.xml';
                }
                if (isset($file)) {
                    $cmd = "export PATH=\"/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin\";if [ -e {$file} ];then ploop info {$file} 2>/dev/null | grep blocks | awk '{ print \$3 \" \" \$2 }'; else vzquota stat $id 2>/dev/null | grep blocks | awk '{ print \$2 \" \" \$3 }'; fi;";
                    $out = trim($sh($cmd));
                    if ($out != '') {
                        $disk = explode(' ', $out);
                        $servers[$id]['diskused'] = $disk[0];
                        $servers[$id]['diskmax'] = $disk[1];
                    }
                }
            }
        }

        // Network statistics collection from /proc/net/dev
        if (preg_match_all("/^[ ]*([\w]+):\s*([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]*$/im", @file_get_contents('/proc/net/dev'), $matches)) {
            $bw = array(time(), 0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0);
            foreach ($matches[1] as $idx => $dev) {
                if (substr($dev, 0, 3) == 'eth') {
                    for ($x = 1; $x < 16; $x++) {
                        $bw[$x] += $matches[$x+1][$idx];
                    }
                }
            }
            $bw_usage = array(
                'time' => $bw[0],
                'bytes_in' => $bw[1],
                'packets_in' => $bw[2],
                'bytes_sec_in' => 0,
                'packets_sec_in' => 0,
                'bytes_out' => $bw[9],
                'packets_out' => $bw[10],
                'bytes_sec_out' => 0,
                'packets_sec_out' => 0,
                'bytes_total' => $bw[1] + $bw[9],
                'packets_total' => $bw[2] + $bw[10],
                'bytes_sec_total' => 0,
                'packets_sec_total' => 0,
            );
            if (file_exists('/root/.bw_usage.last')) {
                $bw_last = unserialize(file_get_contents('/root/.bw_usage.last'));
                $bw_usage_last = array(
                    'time' => $bw_last[0],
                    'bytes_in' => $bw_last[1],
                    'packets_in' => $bw_last[2],
                    'bytes_sec_in' => 0,
                    'packets_sec_in' => 0,
                    'bytes_out' => $bw_last[9],
                    'packets_out' => $bw_last[10],
                    'bytes_sec_out' => 0,
                    'packets_sec_out' => 0,
                    'bytes_total' => $bw_last[1] + $bw_last[9],
                    'packets_total' => $bw_last[2] + $bw_last[10],
                    'bytes_sec_total' => 0,
                    'packets_sec_total' => 0,
                );
                $time_diff = $bw[0] - $bw_last[0];
                if ($time_diff > 0.00) {
                    foreach (array('bytes', 'packets') as $stat) {
                        foreach (array('in','out','total') as $dir) {
                            $bw_usage[$stat.'_sec_'.$dir] = ($bw_usage[$stat.'_'.$dir] - $bw_usage_last[$stat.'_'.$dir]) / $time_diff;
                        }
                    }
                }
            }
            file_put_contents('/root/.bw_usage.last', serialize($bw));
            $servers[0]['bw_usage'] = $bw_usage;
        }

        // ensure ethtool is installed (preserve the original inline shell logic)
        $sh('if ! which ethtool 2>/dev/null; then if [ -e /etc/redhat-release ]; then yum install -y ethtool; else apt-get install -y ethtool; fi; fi;');

        // determine interface to check speed on
        if (in_array(trim($sh('hostname')), array("kvm1.trouble-free.net", "kvm2.interserver.net", "kvm50.interserver.net"))) {
            $eth = 'eth1';
        } elseif (file_exists('/etc/debian_version')) {
            if (file_exists('/sys/class/net/p2p1')) {
                $eth = 'p2p1';
            } elseif (file_exists('/sys/class/net/em1')) {
                $eth = 'em1';
            } else {
                $eth = trim($sh('ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#" "#g'));
                $eth = str_replace(' ', '', $eth);
            }
        } else {
            $eth = trim($sh('ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#" "#g'));
            $eth = str_replace(' ', '', $eth);
        }

        $cmd = 'ethtool '.$eth.' 2>/dev/null |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
        $speed = trim($sh($cmd));
        if ($speed == '') {
            $cmd = 'ethtool $(brctl show $(ip route |grep ^default | sed s#"^.*dev \([^ ]*\) .*$"#"\1"#g) 2>/dev/null |grep -v "bridge id" | awk \'{ print $4 }\') |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
            $speed = trim($sh($cmd));
        }

        // collect cpu flags
        $cpuinfo = explode("\n", @file_get_contents('/proc/cpuinfo'));
        $found = false;
        $lines = sizeof($cpuinfo);
        $line = 0;
        $flags = [];
        while ($found != true && $line < $lines) {
            $cpuline = $cpuinfo[$line];
            if (substr($cpuline, 0, 5) == 'flags') {
                $flags = explode(' ', trim(substr($cpuline, strpos($cpuline, ':') + 1)));
                $found = true;
            } else {
                $line++;
            }
        }
        sort($flags);
        $flagsnew = implode(' ', $flags);
        $flags = $flagsnew;
        unset($flagsnew);

        if (file_exists('/etc/redhat-release')) {
            preg_match('/^(?P<distro>[\w]+)( Linux)? release (?P<version>[\S]+)( .*)*$/i', file_get_contents('/etc/redhat-release'), $matches);
        } else {
            preg_match('/DISTRIB_ID=(?P<distro>[^<]+)<br>DISTRIB_RELEASE=(?P<version>[^<]+)<br>/i', str_replace("\n", '<br>', @file_get_contents('/etc/lsb-release')), $matches);
        }

        $servers[0]['os_info'] = array(
            'distro' => $matches['distro'] ?? null,
            'version' => $matches['version'] ?? null,
            'speed' => $speed,
            'cpu_flags' => $flags,
        );

        $suffixes = [
            'B' => 1,
            'K' => 1024,
            'M' => 1024*1024,
            'G' => 1024*1024*1024,
            'T' => 1024*1024*1024*1024,
        ];

        if (Vps::getPoolType() == 'zfs') {
            $zfs_out = $sh('zfs list -t snapshot -o name,used,creation 2>/dev/null');
            if (preg_match_all('/^vz\/(?P<vps>[^@]+)@(?P<name>\S+)\s+(?P<used>[\d\.]+)(?P<suffix>[BKMGT])\s+(?P<date>\S+\s+\S+\s+\S+\s+\S+\s+\S+)$/muU', $zfs_out, $matches)) {
                foreach ($matches['vps'] as $idx => $vps) {
                    if (isset($servers[$vps])) {
                        if (strpos($matches['name'][$idx], 'syncoid') !== false) {
                            continue;
                        }
                        if (!isset($servers[$vps]['snapshots'])) {
                            $servers[$vps]['snapshots'] = [];
                        }
                        $servers[$vps]['snapshots'][] = [
                            'name' => $matches['name'][$idx],
                            'used' => ceil(floatval($matches['used'][$idx]) * $suffixes[$matches['suffix'][$idx]]),
                            'date' => strtotime($matches['date'][$idx]),
                        ];
                    }
                }
            }
        }

        if ($dispJson) {
            $output->writeln(json_encode([
                'servers' => $servers,
                'ips' => $ips
            ], JSON_PRETTY_PRINT));
        }

        $cmd = 'curl --connect-timeout 60 --max-time 600 -k -d module='.$module.
            ' -d action=server_list -d servers="'.urlencode(base64_encode(gzcompress(json_encode($servers), 9))).'"  '.
            (isset($ips) ? ' -d ips="'.urlencode(base64_encode(gzcompress(json_encode($ips), 9))).'" ' : '').
            $curl_cmd.' "'.$url.'" 2>/dev/null; /bin/rm -f shot_*jpg shot_*jpg.gz 2>/dev/null;';

        $result = trim($sh($cmd));
        $output->writeln($result);

        return Command::SUCCESS;
    }
}
