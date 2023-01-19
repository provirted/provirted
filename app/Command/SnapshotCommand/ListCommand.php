<?php
namespace App\Command\SnapshotCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Component\Table\Table;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ListCommand extends Command {
	public function brief() {
		return "displays a listing of the zfs snapshots";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
		$opts->add('d|dry', 'perms a dry run, no files removed or written only messages saying they would have been');
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		/** @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection} */
		$opts = $this->getOptions();
		$dryRun = array_key_exists('dry', $opts->keys) && $opts->keys['dry']->value == 1;
        $suffixes = [
            'B' => 1,
            'K' => 1024,
            'M' => 1024*1024,
            'G' => 1024*1024*1024,
            'T' => 1024*1024*1024*1024,
        ];
        if (Vps::getPoolType() == 'zfs' && preg_match_all('/^vz\/(?P<vps>[^@]+)@(?P<name>\S+)\s+(?P<used>[\d\.]+)(?P<suffix>[BKMGT])\s+(?P<date>\S+\s+\S+\s+\S+\s+\S+\s+\S+)$/muU', `zfs list -t snapshot -o name,used,creation`, $matches)) {
            $table = new Table;
            $table->setHeaders(['VPS', 'Snapshot Name', 'Size', 'Created']);
            $servers = [];
            foreach ($matches['vps'] as $idx => $vps) {
                if (!isset($servers[$vps]))
                    $servers[$vps] = [];
                $name = $matches['name'][$idx];
                $size = ceil(floatval($matches['used'][$idx]) * $suffixes[$matches['suffix'][$idx]]);
                $date = strtotime($matches['date'][$idx]);
                $servers[$vps][$name] = [
                    'size' => $size,
                    'date' => $date
                ];
                $table->addRow([$vps, $name, $size, $date]);
            }
            echo $table->render();
        } else {
            echo "This system does not support snapshots\n";
        }
	}
}
