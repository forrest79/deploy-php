<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

class ComposerMonorepo
{
	private const COLOR_GREEN = "\e[32m";
	private const COLOR_YELLOW = "\e[33m";
	private const COLOR_RED = "\e[31m";
	private const COLOR_RESET = "\e[0m";

	private string $globalComposerJsonFile;

	private string|NULL $gitUpdateParameters;

	/** @var array{require: array<string>} */
	private array $globalComposerJson;


	public function __construct(string $globalComposerJsonFile, string|NULL $gitUpdateParameters = NULL)
	{
		$globalComposer = @file_get_contents($globalComposerJsonFile);
		if ($globalComposer === FALSE) {
			echo self::COLOR_RED . 'No global composer.json' . self::COLOR_RESET . PHP_EOL;
			exit(1);
		}

		$this->globalComposerJsonFile = $globalComposerJsonFile;
		$this->gitUpdateParameters = $gitUpdateParameters;

		/** @var array{require: array<string>} $globalComposerJson */
		$globalComposerJson = json_decode($globalComposer, TRUE); // assign to variable is because of phpstan
		$this->globalComposerJson = $globalComposerJson;
	}


	/**
	 * @param array<string, string> $apps key is application name, values is application local composer.json
	 */
	public function updateSynchronize(array $apps): void
	{
		foreach ($apps as $appName => $appDir) {
			$this->synchronizeApp($appName, $appDir);
		}
	}


	private function synchronizeApp(string $appName, string $localComposerFile): void
	{
		$localComposerData = @file_get_contents($localComposerFile);
		if ($localComposerData === FALSE) {
			echo self::COLOR_RED . sprintf('No local composer.json (%s).', $localComposerFile) . self::COLOR_RESET . PHP_EOL;
			exit(1);
		}

		$appDir = realpath(dirname($localComposerFile));
		if ($appDir === FALSE) {
			echo self::COLOR_RED . sprintf('App directory \'%s\' not exists.', dirname($localComposerFile)) . self::COLOR_RESET . PHP_EOL;
			exit(1);
		}

		echo self::COLOR_GREEN . strtoupper($appName) . ':' . self::COLOR_RESET . PHP_EOL . PHP_EOL;

		/** @var array{require: array<string>} $localComposer */
		$localComposer = json_decode($localComposerData, TRUE);

		self::composerDiff($localComposerFile, 'Local', array_diff_assoc($this->globalComposerJson['require'], $localComposer['require']), FALSE);
		self::composerDiff($localComposerFile, 'Global', array_diff_assoc($localComposer['require'], $this->globalComposerJson['require']), TRUE);

		$updateCommand = 'composer --working-dir=%s update' . ($this->gitUpdateParameters === NULL ? '' : (' ' . $this->gitUpdateParameters));

		$globalDir = realpath(dirname($this->globalComposerJsonFile));

		// Update global vendor
		self::exec(sprintf($updateCommand, $globalDir));

		// Copy global vendor to local vendor
		self::exec(sprintf('cp -n -r %s/vendor/* %s/vendor', $globalDir, $appDir));

		// Update local vendor - to fix composer.lock
		self::exec(sprintf($updateCommand, $appDir));

		// Purge local vendor
		self::exec(sprintf('cd %s/vendor && rm -R -- */', $appDir));

		// Discard local autoload
		self::exec(sprintf('cd %s && git checkout -- apps/%s/vendor/autoload.php', $globalDir, $appName));
	}


	/**
	 * @param array<string, string> $packages
	 */
	private function composerDiff(string $localComposerFile, string $type, array $packages, bool $fatal): void
	{
		if ($packages !== []) {
			echo sprintf(
				$fatal
					? (self::COLOR_RED . 'Synchronize local (%s) and global (%s) composer.json first.')
					: (self::COLOR_YELLOW . 'There are some differences between local (%s) and global (%s) composer.json - this is just info message.'),
				realpath($localComposerFile),
				realpath($this->globalComposerJsonFile),
			) . self::COLOR_RESET . PHP_EOL . $type . ' composer.json has these differences:' . PHP_EOL;

			foreach ($packages as $package => $version) {
				echo sprintf('=> %s [%s]', $package, $version) . PHP_EOL;
			}

			if ($fatal) {
				exit(1);
			} else {
				echo PHP_EOL;
			}
		}
	}


	private static function exec(string $cmd): void
	{
		exec($cmd, $output, $exitCode);
		if ($exitCode !== 0) {
			echo implode(PHP_EOL, $output) . PHP_EOL;
			exit(1);
		}
	}

}
