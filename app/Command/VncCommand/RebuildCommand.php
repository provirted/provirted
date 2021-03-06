<?php
namespace App\Command\VncCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class RebuildCommand extends Command {
	public function brief() {
		return "cleans up and recreates all the xinetd vps entries";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
		$opts->add('f|force', 'Force rebuilding of all entries, otherwise leave entries that pass tests');
		$opts->add('d|dry', 'perms a dry run, no files removed or written only messages saying they would have been');
        $opts->add('n|no-log', 'Disables the history storing for the command');
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		/** @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection} */
		$opts = $this->getOptions();
		$force = array_key_exists('force', $opts->keys) && $opts->keys['force']->value == 1;
		$dryRun = array_key_exists('dry', $opts->keys) && $opts->keys['dry']->value == 1;
        $useHistory = !(array_key_exists('no-log', $opts->keys) && $opts->keys['no-log']->value == 1);
        if (!$useHistory)
            Vps::getLogger()->disableHistory();
		Xinetd::lock();
		Xinetd::rebuild($dryRun, $force);
		Xinetd::unlock();
		Xinetd::restart();
	}
}
