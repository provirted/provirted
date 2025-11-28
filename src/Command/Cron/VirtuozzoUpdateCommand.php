<?php
namespace App\Command\Cron;

use App\Vps;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VirtuozzoUpdateCommand extends Command
{
    protected static $defaultName = 'cron:virtuozzo-update';
    protected static $defaultDescription = 'lists the history entries';

    protected function configure()
    {
        $this
            ->setDescription(self::$defaultDescription)

            // Keep CLIFramework behavior: integer-stacked verbosity as an option
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_OPTIONAL,
                'increase output verbosity (stacked..use multiple times for even more output)',
                0
            )

            ->addOption(
                'virt',
                't',
                InputOption::VALUE_REQUIRED,
                'Type of Virtualization, kvm, openvz, virtuozzo, lxc'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Preserve exact original option structure
        $options = [
            'verbose' => $input->getOption('verbose'),
            'virt'    => $input->getOption('virt'),
        ];

        Vps::init($options, []);

        // Detect vzpkg availability
        if (trim(Vps::runCommand('which vzpkg')) == '') {
            mail(
                'support@interserver.net',
                'Cannot find vzpkg package for "provirted.phar cron virtuozzo-update" on ' . gethostname(),
                'Cannot find vzpkg package for update_virtuozzo.sh script on ' . gethostname()
            );
        } else {
            // Run exact passthru commands in same order
            passthru('vzpkg update metadata');
            passthru('vzpkg list -O | awk \'{ print $1 }\' | xargs -n 1 vzpkg fetch -O');

            // Weekly cache update check
            if ((time() - intval(filemtime(Vps::$base . '/.cron_weekly.age'))) > 604800) {
                passthru('vzpkg update cache --update-cache');
                touch(Vps::$base . '/.cron_weekly.age');
            }
        }

        return Command::SUCCESS;
    }
}
