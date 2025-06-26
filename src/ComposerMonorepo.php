<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

use Nette\Neon;

class ComposerMonorepo
{
	private const COLOR_GREEN = "\e[32m";
	private const COLOR_YELLOW = "\e[33m";
	private const COLOR_RED = "\e[31m";
	private const COLOR_RESET = "\e[0m";

	private string $globalComposerFile;

	private string|NULL $gitUpdateParameters;

	/** @var array{require: array<string, string>} */
	private array $globalComposerData;


	public function __construct(string $globalComposerFile, string|NULL $gitUpdateParameters = NULL)
	{
		$globalComposer = @file_get_contents($globalComposerFile);
		if ($globalComposer === FALSE) {
			echo self::COLOR_RED . 'Global composer definition not exists: ' . $globalComposerFile . self::COLOR_RESET . PHP_EOL;
			exit(1);
		}

		$composerExt = strtolower(pathinfo($globalComposerFile, PATHINFO_EXTENSION));

		$this->globalComposerFile = $globalComposerFile;
		$this->gitUpdateParameters = $gitUpdateParameters;

		if ($composerExt === 'neon') {
			if (!class_exists(Neon\Neon::class)) {
				echo self::COLOR_RED . 'You need nette\neon library to load composer in neon format' . self::COLOR_RESET . PHP_EOL;
				exit(1);
			}

			/** @var array{require: array<string, string>} $globalComposerData */
			$globalComposerData = Neon\Neon::decode($globalComposer);
		} else {
			/** @var array{require: array<string, string>} $globalComposerData */
			$globalComposerData = json_decode($globalComposer, TRUE);
		}

		$this->globalComposerData = $globalComposerData;
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

		/** @var array{require: array<string, string>} $localComposer */
		$localComposer = json_decode($localComposerData, TRUE);

		self::composerDiff($localComposerFile, 'Local', array_diff_assoc($this->globalComposerData['require'], $localComposer['require']), FALSE);
		self::composerDiff($localComposerFile, 'Global', array_diff_assoc($localComposer['require'], $this->globalComposerData['require']), TRUE);

		$updateCommand = 'composer --working-dir=%s update' . ($this->gitUpdateParameters === NULL ? '' : (' ' . $this->gitUpdateParameters));

		$globalDir = realpath(dirname($this->globalComposerFile));

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
				realpath($this->globalComposerFile),
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
