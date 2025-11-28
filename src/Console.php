<?php
namespace App;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Logger;
use App\Vps;

class Console extends Application
{
    const NAME = 'ProVirted';
    const VERSION = '2.0';

    protected $logger;

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
        $this->logger = new Logger();
        Vps::setLogger($this->logger);
        $this->initializeHistory();
        $this->checkMemoryLimit();
        $this->registerCommands();
    }

    /**
     * Symfony's entry point: override run() to wrap before/after
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $result = parent::run($input, $output);
        $this->finish();
        return $result;
    }

    private function initializeHistory()
    {
        $args = $_SERVER['argv'];
        array_shift($args);
        $this->logger->addHistory([
            'type'  => 'program',
            'text'  => implode(' ', $args),
            'start' => time(),
        ]);
    }

    private function checkMemoryLimit()
    {
        $minimum = $this->getBytes('1G');
        $current = $this->getBytes(ini_get('memory_limit'));
        if ($current !== -1 && $current < $minimum) {
            ini_set('memory_limit', '1G');
        }
    }

    /**
     * Explicit command registration replaces CLIFramework auto-discovery.
     *
     * You can also implement directory auto-discovery if you want.
     */
    private function registerCommands()
    {
        // --- Command discovery ---
        $commandDir = __DIR__ . '/Command';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($commandDir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $class = $this->discoverCommandClass($file->getPathname());
                if ($class && is_subclass_of($class, \Symfony\Component\Console\Command\Command::class)) {
                    $reflection = new \ReflectionClass($class);
                    if (!$reflection->isAbstract()) {
                        $this->add(new $class());
                    }
                }
            }
        }
    }

    // --------------- HELPERS --------------- //
    public function discoverCommandClass(string $filePath): ?string
    {
        // Convert path -> FQCN using PSR-4 rules (assuming src/ is the PSR-4 root)
        $relative = str_replace(realpath(__DIR__) . DIRECTORY_SEPARATOR, '', realpath($filePath));
        $class = '\\App\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
        // Return only if class exists
        return class_exists($class) ? $class : null;
    }

    public function finish()
    {
        if ($this->logger->isHistoryEnabled()) {
            $history = $this->logger->getHistory();
            if (count($history) > 1) {
                $history[0]['end'] = time();
                @mkdir($_SERVER['HOME'] . '/.provirted', 0750, true);
                $file = $_SERVER['HOME'] . '/.provirted/history.json';
                file_put_contents($file, json_encode($history) . PHP_EOL, FILE_APPEND);
            }
        }
    }

    /**
     * Convert "1G", "512M" etc. to bytes
     */
    public function getBytes($val)
    {
        $val = trim($val);
        if ($val === '-1') {
            return -1;
        }
        preg_match('/([0-9]+)\s*([a-zA-Z]+)/', $val, $matches);
        $value = isset($matches[1]) ? intval($matches[1]) : 0;
        $unit = isset($matches[2]) ? strtolower($matches[2]) : 'b';
        switch ($unit) {
            case 't':
            case 'tb': $value *= 1024;
            case 'g':
            case 'gb': $value *= 1024;
            case 'm':
            case 'mb': $value *= 1024;
            case 'k':
            case 'kb': $value *= 1024;
        }
        return $value;
    }
}
