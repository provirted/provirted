<?php
namespace App\Command\HistoryCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ShowCommand extends Command {
	public function brief() {
		return "displays one of the history entries, -1 is the always the latest entry";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('id')->desc('History id to use or "last" for the latest entry')->isa('string')->validValues([Vps::class, 'getHistoryChoices']);
	}

	public function execute($id) {
		Vps::init($this->getOptions(), ['id' => $id]);
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

        $id = isset($id) ? $id : 'last';
        $currentId = 0;

        while (($line = fgets($fileHandle)) !== false) {
            if ($id === 'last' || $currentId == $id) {
                $data = json_decode(trim($line), true);
                $lastType = '';
                foreach ($data as $idx => $line) {
                    if ($line['type'] == 'program') {
                        echo "[Command Line] {$line['text']}\n";
                        echo "[Started at] " . date('Y-m-d H:i:s', $line['start']) . "\n";
                        echo "[Ended at] " . date('Y-m-d H:i:s', $line['end']) . "\n";
                        echo "[Ran for] " . ($line['end'] - $line['start']) . " seconds\n";
                    } elseif ($line['type'] == 'output') {
                        if ($lastType != 'output')
                            echo "\n";
                        echo $line['text'];
                    } elseif ($line['type'] == 'error') {
                        echo "\n[Error] " . rtrim($line['text']);
                    } elseif ($line['type'] == 'command') {
                        echo "\n[Command] {$line['command']} [Return: {$line['return']}] [Output: " . rtrim($line['output']) . "]";
                        if (isset($line['error']))
                            echo " [Error: " . rtrim($line['error']) . "]";
                    }
                    $lastType = $line['type'];
                }
                if ($lastType != 'output' || rtrim($line['text']) == $line['text'])
                    echo "\n";
                fclose($fileHandle);
                return;
            }
            $currentId++;
        }

        fclose($fileHandle);
        echo 'Invalid ID' . PHP_EOL;
    }
}
