<?php
namespace App\Command\HistoryCommand;

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
		return "lists the history entries";
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
        $historyFilePath = $_SERVER['HOME'] . '/.provirted/history.json';

        if (!file_exists($historyFilePath)) {
            echo 'No history has been logged yet' . PHP_EOL;
            return;
        }

        $fileHandle = fopen($historyFilePath, 'r');
        if ($fileHandle === false) {
            echo 'Failed to open history file' . PHP_EOL;
            return;
        }

        $id = 0;
        while (($line = fgets($fileHandle)) !== false) {
            $data = json_decode(trim($line), true);
            echo "{$id}\t{$data[0]['text']}\n";
            $id++;
        }

        fclose($fileHandle);
    }
}
