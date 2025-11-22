<?php
namespace App\Command\HistoryCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class CleanCommand extends Command {
	public function brief() {
		return "cleans up the history log removing certain entries";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
        $allHistory = file_exists($_SERVER['HOME'].'/.provirted/history.json') ? json_decode(file_get_contents($_SERVER['HOME'].'/.provirted/history.json'), true) : [];
        $newHistory = [];
        $badLines = [
            '/root/cpaneldirect/provirted.phar vnc rebuild',
            '/root/cpaneldirect/provirted.phar cron host-info',        
        ];
        if (count($allHistory) == 0) {
			echo 'No history has been logged yet'.PHP_EOL;
			return;                         
        }
        $updates = 0;
        foreach ($allHistory as $id => $data) {
            if (!in_array($data[0]['text'], $badLines))
                $newHistory[] = $data;
            else
                $updates++;
        }
        if ($updates == 0) {
            echo 'No updates / changes to be made'.PHP_EOL;
            return;
        }
        echo 'Dropped '.$updates.' History Entries'.PHP_EOL;
        file_put_contents($_SERVER['HOME'].'/.provirted/history.json', json_encode($newHistory, JSON_PRETTY_PRINT));
	}
}
