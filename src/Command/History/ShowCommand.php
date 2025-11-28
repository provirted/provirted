<?php
namespace App\Command\History;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Vps;

class ShowCommand extends Command
{
    protected static $defaultName = 'history:show';

    protected function configure()
    {
        $this
            ->setDescription('Displays one of the history entries')
            ->addArgument('id', InputArgument::REQUIRED, 'History id or "last"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        Vps::init($input->getOptions(), ['id' => $id]);

        $file = $_SERVER['HOME'] . '/.provirted/history.json';

        if (!file_exists($file)) {
            $output->writeln('No history has been logged yet');
            return Command::SUCCESS;
        }

        $history = json_decode(file_get_contents($file), true);

        if ($id === 'last' || $id === '-1') {
            $id = count($history) - 1;
        }

        if (!isset($history[$id])) {
            $output->writeln('Invalid ID');
            return Command::FAILURE;
        }

        $entry = $history[$id];
        $lastType = '';

        foreach ($entry as $line) {
            switch ($line['type']) {
                case 'program':
                    $output->writeln("[Command Line] {$line['text']}");
                    $output->writeln("[Started at] " . date('Y-m-d H:i:s', $line['start']));
                    $output->writeln("[Ended at] " . date('Y-m-d H:i:s', $line['end']));
                    $output->writeln("[Ran for] " . ($line['end'] - $line['start']) . " seconds");
                    break;

                case 'output':
                    if ($lastType !== 'output') {
                        $output->writeln("");
                    }
                    $output->write($line['text']);
                    break;

                case 'error':
                    $output->writeln("\n[Error] " . rtrim($line['text']));
                    break;

                case 'command':
                    $output->writeln("\n[Command] {$line['command']} [Return: {$line['return']}] [Output: " . rtrim($line['output']) . "]");
                    if (isset($line['error'])) {
                        $output->writeln(" [Error: " . rtrim($line['error']) . "]");
                    }
                    break;
            }
            $lastType = $line['type'];
        }

        $output->writeln("");
        return Command::SUCCESS;
    }
}
