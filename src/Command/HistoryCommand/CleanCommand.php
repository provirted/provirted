<?php
namespace App\Command\History;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Vps;

class CleanCommand extends Command
{
    protected static $defaultName = 'history:clean';

    protected function configure()
    {
        $this
            ->setDescription('cleans up the history log removing certain entries"')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Increase verbosity')
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Virtualization type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Vps::init($input->getOptions(), []);

        $allHistory = file_exists($_SERVER['HOME'].'/.provirted/history.json') ? json_decode(file_get_contents($_SERVER['HOME'].'/.provirted/history.json'), true) : [];
        $newHistory = [];
        $badLines = [
            '/root/cpaneldirect/provirted.phar vnc rebuild',
            '/root/cpaneldirect/provirted.phar cron host-info',
        ];
        if (count($allHistory) == 0) {
            $output->writeln('No history has been logged yet');
            return Command::SUCCESS;
        }
        $updates = 0;
        foreach ($allHistory as $id => $data) {
            if (!in_array($data[0]['text'], $badLines))
                $newHistory[] = $data;
            else
                $updates++;
        }
        if ($updates == 0) {
            $output->wriuteln('No updates / changes to be made');
            return Command::SUCCESS;
        }
        $output->writeln('Dropped '.$updates.' History Entries');
        file_put_contents($_SERVER['HOME'].'/.provirted/history.json', json_encode($newHistory, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
