<?php
namespace App;

class Logger
{
    public $logLevels = [
        'critical' => 1,
        'error'    => 2,
        'warn'     => 3,
        'info'     => 4,
        'info2'    => 5,
        'debug'    => 6,
        'debug2'   => 7,
    ];

    public $level = 4;
    protected $indent = 0;
    protected $indentCharacter = '  ';
    protected $history = [];
    protected $enabled = true;

    public function __construct() {}

    public function addHistory($data)
    {
        if (count($this->history) > 0 &&
            $this->history[count($this->history) - 1]['type'] === $data['type'] &&
            in_array($data['type'], ['output', 'error'])
        ) {
            $this->history[count($this->history) - 1]['text'] .= $data['text'];
        } else {
            $this->history[] = $data;
        }
    }

    public function getHistory() { return $this->history; }
    public function clearHistory() { $this->history = []; }
    public function enableHistory() { $this->enabled = true; }
    public function disableHistory() { $this->enabled = false; }
    public function isHistoryEnabled() { return $this->enabled; }

    public function error($msg)
    {
        $this->addHistory(['type' => 'error', 'text' => $msg]);
        if ($this->logLevels['error'] > $this->level) return;
        fwrite(STDERR, $msg . PHP_EOL);
    }

    public function __call($method, $args)
    {
        $msg = $args[0];
        $level = $this->logLevels[$method] ?? null;
        if (!$level || $level > $this->level) return;

        $this->writeln(is_object($msg) || is_array($msg) ? print_r($msg, 1) : $msg);
    }

    public function writef($format)
    {
        $args = func_get_args();
        $this->write(call_user_func_array('sprintf', $args));
    }

    public function write($text)
    {
        if ($text !== '') {
            $this->addHistory(['type' => 'output', 'text' => $text]);
            echo $text;
        }
    }

    public function writeln($text)
    {
        $this->write($text . PHP_EOL);
    }

    public function newline()
    {
        $this->writeln('');
    }
}
