<?php
namespace App;

use CLIFramework\Application;
use App\Vps;
use App\Logger;

class Console extends Application
{
    const NAME = 'ProVirted';
    const VERSION = '2.0';

    public function init() {
        $this->enableCommandAutoload();
        parent::init();
        $this->commandGroup('Power', ['stop', 'start', 'restart']);
        $this->commandGroup('Provisioning', ['config', 'create', 'destroy', 'enable', 'delete', 'backup', 'restore', 'test']);
        $this->commandGroup('Maintanance', ['install-cpanel', 'reset-password', 'update', 'cd', 'block-smtp', 'add-ip', 'remove-ip', 'change-ip', 'rebuild-dhcp', 'vnc']);
        $this->commandGroup("Development Commands", ['generate-internals'])->setId('dev');
        $this->topic('basic');
        $this->topic('examples');
        //Vps::setLogger($this->getLogger());
        $args = $_SERVER['argv'];
        array_shift($args);
        Vps::setLogger(new Logger());
        Vps::getLogger()->addHistory(['type' => 'program', 'text' => implode(' ', $args), 'start' => time()]);
        $minimumMemoryLimit = '1G';
        $minimumMemoryLimit = $this->getBytes($minimumMemoryLimit);
        $memoryLimit = $this->getBytes(ini_get('memory_limit'));
        if ($memoryLimit != -1 && $memoryLimit < $minimumMemoryLimit)
            ini_set('memory_limit', $minimumMemoryLimit);
    }

    public function finish() {
        parent::finish();
        if (Vps::getLogger()->isHistoryEnabled()) {
            $history = Vps::getLogger()->getHistory();
            if (count($history) > 1) {
                $history[0]['end'] = time();
                @mkdir($_SERVER['HOME'].'/.provirted', 0750, true);
                $historyFilePath = $_SERVER['HOME'] . '/.provirted/history.json';
                $historyLine = json_encode($history) . PHP_EOL;
                file_put_contents($historyFilePath, $historyLine, FILE_APPEND);
            }
        }
    }

    
    /**
    * gets the value in bytes converted from a human readable string like 10G'
    * 
    * @param mixed $val the human readable/shorthand version of the value
    * @return int the value converted to bytes
    */
    public function getBytes($val) {
        $val = trim($val);
        if ($val == '-1')
            return -1;
        preg_match('/([0-9]+)[\s]*([a-zA-Z]+)/', $val, $matches);
        $value = (isset($matches[1])) ? intval($matches[1]) : 0;
        $metric = (isset($matches[2])) ? strtolower($matches[2]) : 'b';
        switch ($metric) {
            case 'tb':
            case 't':
                $value *= 1024;
            case 'gb':
            case 'g':
                $value *= 1024;
            case 'mb':
            case 'm':
                $value *= 1024;
            case 'kb':
            case 'k':
                $value *= 1024;
        }
        return $value;
    }

}
