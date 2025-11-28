<?php
namespace App\Command\History;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Vps;

class ListCommand extends Command
{
    protected static $defaultName = 'history:list';

    protected function configure()
    {
        $this
            ->setDescription('Lists the history entries')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Increase verbosity')
            ->addOption('virt', 't', InputOption::VALUE_REQUIRED, 'Virtualization type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Vps::init($input->getOptions(), []);

        $file = $_SERVER['HOME'] . '/.provirted/history.json';

        if (!file_exists($file)) {
            $output->writeln('No history has been logged yet');
            return Command::SUCCESS;
        }

        $history = json_decode(file_get_contents($file), true);
        if (!is_array($history)) {
            $output->writeln('History file invalid');
            return Command::FAILURE;
        }

        foreach ($history as $id => $entry) {
            $output->writeln("{$id}\t{$entry[0]['text']}");
        }

        return Command::SUCCESS;
    }
}
