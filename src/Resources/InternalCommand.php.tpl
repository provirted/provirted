<?php
namespace App\Command\InternalsCommand\{$class.name}Command;

use {$class.fullName};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

{if isset($method.summary)}
/**
 * {$method.summary}
 */
{/if}
class {$method.pascal}Command extends Command
{
    protected static $defaultName = 'internals:{$class.name}:{$method.name}';

    protected function configure()
    {
        $this
            ->setDescription("{if isset($method.summary)}{$method.summary}{else}{$class.name}{/if}")
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'increase output verbosity')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'output in JSON format')
            ->addOption('php',  'p', InputOption::VALUE_NONE, 'output in PHP format');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $json = $input->getOption('json');
        $php  = $input->getOption('php');

        $response = {$class.name}::{$method.name}();

        if ($json) {
            $output->writeln(json_encode($response, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if ($php) {
            $output->writeln(var_export($response, true));
            return Command::SUCCESS;
        }

{if isset($method.returnType) && $method.returnType == 'array'}
        if (count($response) === 0) {
            $output->writeln('<error>This machine does not appear to have any virtualization setup installed.</error>');
            return Command::FAILURE;
        }

        $table = new Table($output);
        $table->setHeaders(['vps']);

        foreach ($response as $line) {
            $table->addRow([$line]);
        }

        $table->render();

{elseif isset($method.returnType) && $method.returnType == 'bool'}
        $output->writeln($response === true ? 'true' : 'false');

{else}
        $output->writeln($response);

{/if}

        return Command::SUCCESS;
    }
}
