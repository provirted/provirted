<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use CLIFramework\OptionPrinter;
use CLIFramework\Corrector;
use CLIFramework\Formatter;

/**
 * Recreates the CLIFramework custom help system inside a Symfony Command.
 *
 * This attempts to preserve the original layoutCommands, topic display,
 * option printing, and command aggregation behavior. If the Application
 * provided to Symfony implements the same helper methods/props used
 * by the original CLIFramework app (getTopic, topics, aggregate, getCommand,
 * getOptionCollection, getArgInfoList, getAllCommandPrototype, etc.) this
 * command will behave similarly to the original.
 */
class HelperCommand extends Command
{
    protected static $defaultName = 'helper';
    protected static $defaultDescription = 'Show help message of a command';

    // provide the 'dev' option like original
    protected function configure()
    {
        $this
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Show development commands.')
            ->addArgument('command', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Command or topic to show help for (subcommand or topic)')
            ;
    }

    protected function displayTopic($topic, OutputInterface $output)
    {
        $formatter = new Formatter;
        $output->writeln($formatter->format('TOPIC', 'strong_white'));
        $output->writeln("\t" . $topic->getTitle() . "\n");
        $output->writeln($formatter->format('DESCRIPTION', 'strong_white'));
        $output->writeln($topic->getContent() . "\n");
        if ($footer = $topic->getFooter()) {
            $output->writeln($formatter->format('MORE', 'strong_white'));
            $output->writeln($footer . "\n");
        }
    }

    protected function calculateColumnWidth(array $words, $min = 0)
    {
        $maxWidth = $min;
        foreach ($words as $word) {
            if (strlen($word) > $maxWidth) {
                $maxWidth = strlen($word);
            }
        }
        return $maxWidth;
    }

    protected function layoutCommands(array $commands, OutputInterface $output, $indent = 4)
    {
        $cmdNames = array_filter(array_keys($commands), function ($n) {
            return !preg_match('#^_#', $n);
        });
        $maxWidth = $this->calculateColumnWidth($cmdNames, 12);
        $formatter = new Formatter;
        foreach ($commands as $name => $class) {
            // attempt to instantiate command class for brief() method
            $brief = '';
            try {
                if (class_exists($class)) {
                    $cmd = new $class();
                    if (method_exists($cmd, 'brief')) {
                        $brief = $cmd->brief();
                    } elseif (property_exists($cmd, 'description')) {
                        $brief = $cmd->description;
                    }
                }
            } catch (\Throwable $e) {
                $brief = '';
            }
            $output->writeln(str_repeat(' ', $indent) . sprintf('%' . ($maxWidth + $indent) . 's    %s', $name, $brief));
        }
        $output->writeln('');
    }

    /**
     * Main execution. Behaves similarly to original HelperCommand::execute.
     *
     * - If a single argument matches a topic, display topic.
     * - If the argument(s) refer to a command, display command help (attempting to call original helpers).
     * - Otherwise display aggregated command list and topics as the original did.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->getApplication();
        $formatter = new Formatter;
        $printer = new OptionPrinter();
        $corrector = new Corrector([]);

        $commandNames = $input->getArgument('command') ?: [];
        $showDev = (bool)$input->getOption('dev');

        // Try to use original app topics if available
        $hasTopics = (is_object($app) && property_exists($app, 'topics')) || (is_object($app) && method_exists($app, 'getTopic'));

        // If exactly one token and it maps to a topic, show it
        if (count($commandNames) === 1 && $hasTopics) {
            $candidate = $commandNames[0];
            // Prefer a getTopic method if present
            if (method_exists($app, 'getTopic')) {
                $topic = $app->getTopic($candidate);
                if ($topic) {
                    $this->displayTopic($topic, $output);
                    return Command::SUCCESS;
                }
            }
            // fallback to topics property if exists
            if (property_exists($app, 'topics') && isset($app->topics[$candidate])) {
                $this->displayTopic($app->topics[$candidate], $output);
                return Command::SUCCESS;
            }
            // correction attempt if available
            if (property_exists($app, 'topics')) {
                $corrector = new Corrector(array_keys($app->topics));
                if ($match = $corrector->correct($candidate)) {
                    $this->displayTopic($app->topics[$match], $output);
                    return Command::SUCCESS;
                }
            }
        }

        // If there are command tokens, attempt to show that command's help using original helpers
        if (count($commandNames) > 0) {
            // Try to traverse nested commands using original getCommand if present
            $cmd = null;
            if (is_object($app) && method_exists($app, 'getCommand')) {
                $cmd = $app;
                for ($i = 0; $i < count($commandNames); $i++) {
                    $name = $commandNames[$i];
                    $cmd = $cmd->getCommand($name);
                    if (!$cmd) {
                        break;
                    }
                }
            } else {
                // fallback: try to find Symfony command with that name
                $fullname = implode(' ', $commandNames);
                try {
                    $cmd = $this->getApplication()->find($fullname);
                } catch (\Exception $e) {
                    $cmd = null;
                }
            }

            if (!$cmd) {
                $output->writeln("<error>Command entry " . implode(' ', $commandNames) . " not found</error>");
                return Command::FAILURE;
            }

            // Attempt to reproduce original formatted help using available methods
            $usage = '';
            if (method_exists($cmd, 'usage')) {
                $usage = $cmd->usage();
            } elseif (method_exists($cmd, 'getSynopsis')) {
                $usage = $cmd->getSynopsis();
            }
            if (method_exists($cmd, 'brief') && $brief = $cmd->brief()) {
                $output->writeln($formatter->format('NAME', 'strong_white'));
                $nameDisplay = method_exists($cmd, 'getName') ? $cmd->getName() : (property_exists($cmd, 'name') ? $cmd->name : '');
                $output->writeln("\t" . $formatter->format($nameDisplay, 'strong_white') . ' - ' . $brief . "\n");
            }
            if (method_exists($cmd, 'aliases') && $aliases = $cmd->aliases()) {
                $output->writeln($formatter->format('ALIASES', 'strong_white'));
                $output->writeln("\t" . $formatter->format(implode(', ', $aliases), 'strong_white') . "\n");
            }
            if ($usage = trim($usage)) {
                $output->writeln($formatter->format('USAGE', 'strong_white'));
                $output->writeln("\t" . $usage . "\n");
            }

            $output->writeln($formatter->format('SYNOPSIS', 'strong_white'));
            if (method_exists($cmd, 'getAllCommandPrototype')) {
                $prototypes = $cmd->getAllCommandPrototype();
                foreach ($prototypes as $prototype) {
                    $output->writeln("\t " . $prototype);
                }
            } else {
                // fallback: print Symfony synopsis and description
                if (method_exists($cmd, 'getSynopsis')) {
                    $output->writeln("\t " . $cmd->getSynopsis());
                }
            }
            $output->writeln("");

            // Print options - attempt to use original getOptionCollection and printer if available
            if (method_exists($cmd, 'getOptionCollection')) {
                $optionLines = $printer->render($cmd->getOptionCollection());
                if ($optionLines) {
                    $output->writeln($formatter->format('OPTIONS', 'strong_white'));
                    $output->writeln($optionLines . "\n");
                }
            } else {
                // fallback: Symfony InputDefinition options for this command
                if (method_exists($cmd, 'getDefinition')) {
                    $def = $cmd->getDefinition();
                    $output->writeln($formatter->format('OPTIONS', 'strong_white'));
                    foreach ($def->getOptions() as $opt) {
                        $names = '--' . $opt->getName();
                        $output->writeln("\t" . $names . "\t" . $opt->getDescription());
                    }
                    $output->writeln("");
                }
            }

            // Help text / long description
            if (method_exists($cmd, 'getFormattedHelpText')) {
                $output->writeln($cmd->getFormattedHelpText());
            } elseif (method_exists($cmd, 'getHelp')) {
                $output->writeln($cmd->getHelp());
            } else {
                if (method_exists($cmd, 'getDescription')) {
                    $output->writeln($cmd->getDescription());
                }
            }

            return Command::SUCCESS;
        }

        // No specific command requested: show aggregated list like original
        // Attempt to use original parent/aggregate methods
        $cmd = $this->getApplication();

        // If the application has brief() and usage() like original, use them
        if (is_object($cmd) && method_exists($cmd, 'brief')) {
            $output->writeln($formatter->format(ucfirst($cmd->brief()), 'strong_white') . "\n");
        } else {
            $output->writeln($formatter->format("Available commands", 'strong_white') . "\n");
        }

        // usage
        if (is_object($cmd) && method_exists($cmd, 'usage') && $usage = trim($cmd->usage())) {
            $output->writeln($formatter->format('USAGE', 'strong_white'));
            $output->writeln($usage . "\n");
        }

        // SYNOPSIS
        $output->write($formatter->format('SYNOPSIS', 'strong_white') . PHP_EOL);
        $progname = basename($this->getApplication()->getName() ?: 'app');
        $output->write("\t" . $progname);
        // options present?
        if (is_object($cmd) && method_exists($cmd, 'getOptionCollection')) {
            $optCol = $cmd->getOptionCollection();
            if (!empty($optCol->options)) {
                $output->write(' [options]');
            }
        } else {
            $output->write(' [options]');
        }
        if (is_object($cmd) && method_exists($cmd, 'hasCommands') && $cmd->hasCommands()) {
            $output->write(' <command>');
        } else {
            // fallback prints nothing special
        }
        $output->write(PHP_EOL . PHP_EOL);

        // Print application options (best-effort)
        $output->writeln($formatter->format('OPTIONS', 'strong_white'));
        if (is_object($cmd) && method_exists($cmd, 'getOptionCollection')) {
            $output->write($printer->render($cmd->getOptionCollection()));
        } else {
            // fallback: none
            $output->writeln("\t(no application options available)\n");
        }

        // Attempt to aggregate commands using original aggregate() if present
        $ret = ['groups' => [], 'commands' => []];
        if (is_object($app) && method_exists($app, 'aggregate')) {
            $ret = $app->aggregate();
        } else {
            // fallback: use Symfony command list grouped by namespace
            $symCommands = $this->getApplication()->all();
            $groups = [];
            foreach ($symCommands as $name => $scmd) {
                $ns = strpos($name, ':') !== false ? explode(':', $name)[0] : '_general';
                $groups[$ns][$name] = get_class($scmd);
            }
            $ret['groups'] = [];
            $ret['commands'] = $groups['_general'] ?? $groups;
            foreach ($groups as $ns => $cmds) {
                $grp = new class($ns, $ns) {
                    protected $id; protected $name; protected $commands;
                    public function __construct($id, $name) { $this->id = $id; $this->name = $name; $this->commands = []; }
                    public function getId() { return $this->id; }
                    public function getName() { return $this->name; }
                    public function getCommands() { return $this->commands; }
                    public function setCommands($c) { $this->commands = $c; }
                };
                $grp->setCommands($cmds);
                $ret['groups'][] = $grp;
            }
        }

        // show "General commands" title if multiple groups
        if (count($ret['groups']) > 1 || $showDev) {
            $output->writeln('  ' . $formatter->format('General Commands', 'strong_white'));
        }

        // layout top-level commands
        $this->layoutCommands($ret['commands'] ?? [], $output);

        foreach ($ret['groups'] as $group) {
            if (!$showDev && method_exists($group, 'getId') && $group->getId() === 'dev') {
                continue;
            }
            $output->writeln('  ' . $formatter->format($group->getName(), 'strong_white'));
            $this->layoutCommands(method_exists($group, 'getCommands') ? $group->getCommands() : [], $output);
        }

        // Topics listing if available
        if (is_object($app) && property_exists($app, 'topics') && $app->topics) {
            $output->writeln($formatter->format("TOPICS", 'strong_white'));
            $maxWidth = $this->calculateColumnWidth(array_keys($app->topics), 8);
            foreach ($app->topics as $topicId => $topic) {
                $output->write(sprintf('%' . ($maxWidth + 8) . "s    %s\n", $topicId, $topic->getTitle()));
            }
            $output->writeln('');
        }

        // HELP text
        $output->writeln($formatter->format("HELP", 'strong_white'));
        $output->writeln(wordwrap(
            "\t'{$progname} help' lists available subcommands and some topics. See '{$progname} help <command>' or '{$progname} help <topic>' to read about a specific subcommand or topic.",
            70,
            "\n\t"
        ));

        // App signature if present
        if (is_object($app) && property_exists($app, 'showAppSignature') && $app->showAppSignature) {
            $output->writeln('');
            $output->writeln($formatter->format("{$app->getName()} {$app->getVersion()}", 'gray'));
            $output->writeln($formatter->format("\t\tpowered by https://github.com/c9s/CLIFramework", 'gray'));
        }

        return Command::SUCCESS;
    }
}
