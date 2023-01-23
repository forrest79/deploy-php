<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

use Closure;
use Nette\Utils;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type AssetsConfig array<string, array{type: string|NULL, file?: string|NULL, files?: array<string>, env?: string}|string>
 */
class Assets
{
	public const DEBUG = 'debug';
	public const PRODUCTION = 'production';

	public const COPY = 'copy';
	public const LESS = 'less';
	public const SASS = 'sass';
	public const UGLIFYJS = 'uglifyjs';
	public const ROLLUP = 'rollup';

	private const DEFAULT_SYSTEM_BIN_PATH = '/usr/bin:/bin';

	/** @phpstan-var AssetsConfig */
	private array $config;

	/** function (string $configFile): ?string */
	private Closure $readHash;

	/** function (string $configFile, string $hash): void */
	private Closure $writeHash;

	private string $sourceDirectory;

	private string $destinationDirectory;

	private string $systemBinPath = self::DEFAULT_SYSTEM_BIN_PATH;

	private ?string $localSourceDirectory = NULL;

	private string $configFile;

	private string $lockFile;

	/** @var resource */
	private $lockHandle;


	/**
	 * @phpstan-param AssetsConfig $config
	 * @param array<string, string> $localConfig
	 */
	public function __construct(
		string $tempDirectory,
		string $sourceDirectory,
		array $config,
		Closure $readHash,
		Closure $writeHash,
		array $localConfig = []
	)
	{
		$this->sourceDirectory = rtrim($sourceDirectory, '\\/');

		$this->config = $config;
		$this->readHash = $readHash;
		$this->writeHash = $writeHash;

		if (isset($localConfig['systemBinPath'])) {
			$this->systemBinPath = trim($localConfig['systemBinPath'], ':');
		} else if (isset($localConfig['localSourceDirectory'])) {
			$this->localSourceDirectory = rtrim($localConfig['localSourceDirectory'], '\\/');
		}

		$this->lockFile = $tempDirectory . DIRECTORY_SEPARATOR . 'assets.lock';
	}


	public function buildDebug(string $configFile, string $destinationDirectory): void
	{
		$lockFile = $this->lock();

		$this->setup($configFile, $destinationDirectory);

		$oldHash = call_user_func($this->readHash, $this->configFile);

		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sourceDirectory, RecursiveDirectoryIterator::SKIP_DOTS)) as $item) {
			assert($item instanceof \SplFileInfo);
			if ($item->isDir() || (realpath($item->getPathname()) === $lockFile)) {
				continue;
			}
			$files[$item->getPathname()] = $item->getMTime();
		}

		$newHash = md5(serialize($this->config) . ($this->localSourceDirectory ?? '') . serialize($files));

		if ($oldHash !== $newHash) {
			$this->buildAssets(self::DEBUG);
			call_user_func($this->writeHash, $this->configFile, $newHash);
		}

		$this->unlock();
	}


	public function buildProduction(string $configFile, string $destinationDirectory): void
	{
		$lockFile = $this->lock();

		$this->setup($configFile, $destinationDirectory);

		$this->buildAssets(self::PRODUCTION);

		$contents = '';
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sourceDirectory, RecursiveDirectoryIterator::SKIP_DOTS)) as $item) {
			assert($item instanceof \SplFileInfo);
			if ($item->isDir() || (realpath($item->getPathname()) === $lockFile)) {
				continue;
			}
			$contents .= file_get_contents($item->getPathname());
		}

		call_user_func($this->writeHash, $this->configFile, md5($contents));

		$this->unlock();
	}


	private function setup(string $configFile, string $destinationDirectory): void
	{
		if (!file_exists($this->sourceDirectory)) {
			throw new Exceptions\AssetsException('Assets source directory doesn\'t exists.');
		}

		$this->configFile = $configFile;
		$this->destinationDirectory = rtrim($destinationDirectory, '\\/');
	}


	private function buildAssets(string $environment): void
	{
		if (file_exists($this->destinationDirectory)) {
			Utils\FileSystem::delete($this->destinationDirectory);
		}

		$isDebug = $environment === self::DEBUG;

		foreach ($this->config as $path => $data) {
			if ($data === self::COPY) {
				Utils\FileSystem::copy($this->sourceDirectory . DIRECTORY_SEPARATOR . $path, $this->destinationDirectory . DIRECTORY_SEPARATOR . $path);
				continue;
			}

			assert(is_array($data));

			if (isset($data['env']) && ($data['env'] !== $environment)) {
				continue;
			}

			if (!isset($data['type'])) {
				throw new \InvalidArgumentException(sprintf('Path \'%s\' has no type defined.', $path));
			}

			switch ($data['type']) {
				case self::LESS:
					if (!isset($data['file'])) {
						throw new \InvalidArgumentException(sprintf('No file defined for \'%s\'.', $path));
					}
					$this->compilesLess($data['file'], $path, $isDebug);
					break;

				case self::SASS:
					if (!isset($data['file']) && !isset($data['files'])) {
						throw new \InvalidArgumentException(sprintf('No file or files defined for \'%s\'.', $path));
					}
					foreach ($data['files'] ?? [$data['file']] as $file) {
						assert($file !== NULL);
						$this->compilesSass($file, $path, $isDebug);
					}
					break;

				case self::UGLIFYJS:
					if (!isset($data['files'])) {
						throw new \InvalidArgumentException(sprintf('No files defined for \'%s\'.', $path));
					}
					$this->compilesJs($data['files'], $path, $isDebug);
					break;

				case self::ROLLUP:
					if (!isset($data['file'])) {
						throw new \InvalidArgumentException(sprintf('No file defined for \'%s\'.', $path));
					}
					$this->compilesRollup($data['file'], $path, $isDebug);
					break;
			}
		}
	}


	private function compilesLess(string $sourceFile, string $destinationFile, bool $createMap): void
	{
		$mapCommand = '';
		if ($createMap === TRUE) {
			$sourceMapDirectory = dirname($this->localSourceDirectory !== NULL ? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $sourceFile) : $sourceFile);
			$mapCommand = sprintf('--source-map --source-map-rootpath=file:///%s ', $sourceMapDirectory);
		}

		$this->exec(sprintf(
			'%s --clean-css="--keepSpecialComments=0" %s%s %s',
			$this->npxCommand('node-sass'),
			$mapCommand,
			$sourceFile,
			$this->prepareDestinationPath($destinationFile),
		), 'css-less');
	}


	private function compilesSass(string $sourceFile, string $destinationDirectory, bool $createMap): void
	{
		$mapCommand = '';
		if ($createMap === TRUE) {
			$mapCommand = sprintf(' --source-map true --source-map-contents true');
		}

		$this->exec(sprintf(
			'%s %s --quiet --output-style=compressed --output="%s"%s',
			$this->npxCommand('node-sass'),
			$sourceFile,
			$this->prepareDestinationPath($destinationDirectory),
			$mapCommand,
		), 'css-sass');
	}


	/**
	 * @param array<string> $sourceFiles
	 */
	private function compilesJs(array $sourceFiles, string $destinationFile, bool $createMap): void
	{
		$destinationFile = $this->prepareDestinationPath($destinationFile);

		$mapSources = [];

		if ($createMap === TRUE) {
			foreach ($sourceFiles as $sourceFile) {
				$sourcePath = $this->sourceDirectory . DIRECTORY_SEPARATOR . $sourceFile;
				$mapSources[$sourcePath] = 'file:///' . ($this->localSourceDirectory !== NULL
					? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $sourceFile)
					: realpath($sourcePath));
			}
		}

		$mapCommand = '';
		if ($createMap === TRUE) {
			$mapCommand = sprintf('--source-map url=%s.map ', basename($destinationFile));
		}

		$this->exec(sprintf(
			'%s %s -o %s --compress %s',
			$this->npxCommand('uglifyjs'),
			implode(' ', $sourceFiles),
			$destinationFile,
			$mapCommand,
		), 'js-uglifyjs');

		if ($createMap === TRUE) {
			$mapFile = $destinationFile . '.map';
			$mapContents = file_get_contents($mapFile);
			if ($mapContents === FALSE) {
				throw new Exceptions\AssetsException(sprintf('Map file \'%s\' doesn\'t exists', $mapFile));
			}
			file_put_contents($mapFile, strtr($mapContents, $mapSources));
		}
	}


	private function compilesRollup(string $sourceFile, string $destinationFile, bool $createMap): void
	{
		$this->exec(sprintf(
			'INPUT_FILE=%s OUTPUT_FILE=%s SOURCE_MAP=%d %s -c',
			$sourceFile,
			$this->prepareDestinationPath($destinationFile),
			$createMap ? 1 : 0,
			$this->npxCommand('rollup'),
		), 'js-rollup');
	}


	private function prepareDestinationPath(string $relativePath): string
	{
		$destinationPath = $this->destinationDirectory . DIRECTORY_SEPARATOR . $relativePath;

		Utils\FileSystem::createDir(dirname($destinationPath));

		return $destinationPath;
	}


	private function npxCommand(string $cmd): string
	{
		return sprintf('PATH=%s npx %s', $this->systemBinPath, $cmd);
	}


	private function exec(string $command, string $type): void
	{
		$command = sprintf('(cd %s && %s 2>&1)', $this->sourceDirectory, $command);

		exec($command, $output, $returnVal);

		if ($returnVal !== 0) {
			throw new Exceptions\AssetsException(sprintf("Error while compiling %s. Command:\n\n%s\n\nError:\n\n%s", $type, $command, implode(PHP_EOL, $output)));
		}
	}


	private function lock(): string
	{
		$handle = @fopen($this->lockFile, 'c+'); // intentionally @
		if ($handle === FALSE) {
			throw new Exceptions\AssetsException(sprintf('Unable to create file \'%s\' %s', $this->lockFile, error_get_last()['message'] ?? 'unknown'));
		} elseif (!@flock($handle, LOCK_EX)) { // intentionally @
			throw new Exceptions\AssetsException(sprintf('Unable to acquire exclusive lock on \'%s\' %s', $this->lockFile, error_get_last()['message'] ?? 'unknown'));
		}
		$this->lockHandle = $handle;

		$lockPath = realpath($this->lockFile);
		if ($lockPath === FALSE) {
			throw new Exceptions\AssetsException('Lock file not exists');
		}

		return $lockPath;
	}


	private function unlock(): void
	{
		@flock($this->lockHandle, LOCK_UN); // intentionally @
		@fclose($this->lockHandle); // intentionally @
	}

}
