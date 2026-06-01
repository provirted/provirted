<?php
/**
 * Append the PHP 8.1+ TUI dependency bundle (vendor-tui/) into an already
 * built provirted.phar.
 *
 * The CLIFramework `archive` command only traces autoloads from the main
 * composer.json (which is pinned to the PHP 7.4 platform and must NOT
 * reference the ^8.3 SugarCraft packages). So we build the core phar as
 * usual, then graft the separately-installed vendor-tui/ tree in here.
 * At runtime App\Command\HistoryCommand\TuiCommand requires
 * phar://provirted.phar/vendor-tui/autoload.php only after gating on
 * PHP 8.1+, so 7.4 users never touch any of it.
 *
 * Usage:  php -d phar.readonly=0 bin/bundle-tui.php [provirted.phar] [vendor-tui]
 */

$pharFile = isset($argv[1]) ? $argv[1] : 'provirted.phar';
$srcDir   = isset($argv[2]) ? $argv[2] : 'vendor-tui';

if (ini_get('phar.readonly')) {
	fwrite(STDERR, "phar.readonly is on; re-run with: php -d phar.readonly=0 " . $argv[0] . "\n");
	exit(1);
}
if (!file_exists($pharFile)) {
	fwrite(STDERR, "phar '$pharFile' not found (run `make phar` first)\n");
	exit(1);
}
if (!is_dir($srcDir)) {
	fwrite(STDERR, "'$srcDir' not found (run `make tui-deps` first)\n");
	exit(1);
}

$base = realpath('.');
$phar = new Phar($pharFile);
$phar->startBuffering();

$iter = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::LEAVES_ONLY
);

$added   = 0;
$skipped = 0;
foreach ($iter as $file) {
	if ($file->isDir()) {
		continue;
	}
	$path = $file->getPathname();

	// Drop VCS metadata, test suites and docs to keep the phar small.
	if (preg_match('#(?:^|/)(?:\.git|\.github|tests?|Tests?|docs?|examples?)(?:/|$)#i', $path)
		|| preg_match('#\.(?:md|markdown|dist|yml|yaml|neon|lock)$#i', $path)) {
		$skipped++;
		continue;
	}

	$real  = realpath($path);
	$local = ltrim(str_replace($base, '', $real), '/\\');
	$phar->addFile($real, $local);
	$added++;
}

$phar->stopBuffering();
echo "Bundled $added TUI files (skipped $skipped) from $srcDir/ into $pharFile\n";
