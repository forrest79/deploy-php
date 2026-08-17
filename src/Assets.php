<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

use Closure;
use Nette\Utils;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type AssetsConfig array<string, array{type: string|null, file?: string|null, files?: list<string>, env?: string, tsconfig?: string|null}|string>
 */
class Assets
{
	public const string DEBUG = 'debug';
	public const string PRODUCTION = 'production';

	public const string COPY = 'copy';
	public const string LESS = 'less';
	public const string SASS = 'sass';
	public const string UGLIFYJS = 'uglifyjs';
	public const string ROLLUP = 'rollup';
	public const string ESBUILD = 'esbuild';

	public const string CONFIG_SYSTEM_BIN_PATH = 'systemBinPath';
	public const string CONFIG_LOCAL_SOURCE_DIRECTORY = 'localSourceDirectory';

	private const string DEFAULT_SYSTEM_BIN_PATH = '/usr/bin:/bin';

	/** @phpstan-var AssetsConfig */
	private array $config;

	/** function (string $configFile): ?string */
	private Closure $readHash;

	/** function (string $configFile, string $hash): void */
	private Closure $writeHash;

	/** @var list<string> */
	private array $watch;

	/** @var list<string> */
	private array $unwatch;

	private string $sourceDirectory;

	private string $destinationDirectory;

	private string $systemBinPath = self::DEFAULT_SYSTEM_BIN_PATH;

	private string|null $localSourceDirectory = null;

	private string $configFile;

	private string $lockFile;

	/** @var list<string>|null */
	private array|null $unwatchPathsResolved = null;

	/** @var resource */
	private $lockHandle;


	/**
	 * @phpstan-param AssetsConfig $config
	 * @param list<string> $watch
	 * @param list<string> $unwatch
	 * @param array<string, string> $localConfig
	 */
	public function __construct(
		string $tempDirectory,
		string $sourceDirectory,
		array $config,
		Closure $readHash,
		Closure $writeHash,
		array $watch = [],
		array $unwatch = [],
		array $localConfig = [],
	)
	{
		$this->sourceDirectory = rtrim($sourceDirectory, '\\/');

		$this->config = $config;
		$this->watch = $watch;
		$this->unwatch = $unwatch;
		$this->readHash = $readHash;
		$this->writeHash = $writeHash;

		if (isset($localConfig[self::CONFIG_SYSTEM_BIN_PATH])) {
			$this->systemBinPath = trim($localConfig[self::CONFIG_SYSTEM_BIN_PATH], ':');
		} else if (isset($localConfig[self::CONFIG_LOCAL_SOURCE_DIRECTORY])) {
			$this->localSourceDirectory = rtrim($localConfig[self::CONFIG_LOCAL_SOURCE_DIRECTORY], '\\/');
		}

		$this->lockFile = $tempDirectory . DIRECTORY_SEPARATOR . 'assets.lock';
	}


	public function buildDebug(string $configFile, string $destinationDirectory): void
	{
		$lockFile = $this->lock();

		$this->setup($configFile, $destinationDirectory);

		$oldHash = call_user_func($this->readHash, $this->configFile);

		$files = $this->collectMTimes($this->sourceDirectory, $lockFile);

		foreach ($this->watch as $watchPath) {
			$files += $this->collectMTimes($watchPath);
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

		$contents = $this->collectContents($this->sourceDirectory, $lockFile);
		foreach ($this->watch as $watchPath) {
			$contents .= $this->collectContents($watchPath);
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
						assert($file !== null);
						$this->compilesSass($file, $path . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '.css', $isDebug);
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

				case self::ESBUILD:
					if (!isset($data['file'])) {
						throw new \InvalidArgumentException(sprintf('No file defined for \'%s\'.', $path));
					}
					$this->compilesEsbuild($data['file'], $path, $data['tsconfig'] ?? null, $isDebug);
					break;
			}
		}
	}


	private function compilesLess(string $sourceFile, string $destinationFile, bool $createMap): void
	{
		$mapCommand = '';
		if ($createMap) {
			$sourceMapDirectory = dirname($this->localSourceDirectory !== null ? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $sourceFile) : $sourceFile);
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


	private function compilesSass(string $sourceFile, string $destinationFile, bool $createMap): void
	{
		$this->exec(sprintf(
			'%s --quiet --style=compressed %s %s %s',
			$this->npxCommand('sass'),
			$createMap ? '--embed-source-map --embed-sources' : '--no-source-map',
			$sourceFile,
			$this->prepareDestinationPath($destinationFile),
		), 'css-sass');
	}


	/**
	 * @param array<string> $sourceFiles
	 */
	private function compilesJs(array $sourceFiles, string $destinationFile, bool $createMap): void
	{
		$destinationFile = $this->prepareDestinationPath($destinationFile);

		$mapSources = [];

		if ($createMap) {
			foreach ($sourceFiles as $sourceFile) {
				$sourcePath = $this->sourceDirectory . DIRECTORY_SEPARATOR . $sourceFile;
				$mapSources[$sourcePath] = 'file:///' . ($this->localSourceDirectory !== null
					? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $sourceFile)
					: realpath($sourcePath));
			}
		}

		$mapCommand = '';
		if ($createMap) {
			$mapCommand = sprintf('--source-map url=%s.map ', basename($destinationFile));
		}

		$this->exec(sprintf(
			'%s %s -o %s --compress %s',
			$this->npxCommand('uglifyjs'),
			implode(' ', $sourceFiles),
			$destinationFile,
			$mapCommand,
		), 'js-uglifyjs');

		if ($createMap) {
			$mapFile = $destinationFile . '.map';
			$mapContents = file_get_contents($mapFile);
			if ($mapContents === false) {
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


	private function compilesEsbuild(
		string $sourceFile,
		string $destinationFile,
		string|null $tsconfig,
		bool $createMap,
	): void
	{
		$this->exec(sprintf(
			'%s %s --bundle --format=iife %s--outfile=%s%s',
			$this->npxCommand('esbuild'),
			$sourceFile,
			$tsconfig !== null ? sprintf('--tsconfig=%s ', $tsconfig) : '',
			$this->prepareDestinationPath($destinationFile),
			$createMap ? ' --sourcemap' : ' --minify',
		), 'js-esbuild');
	}


	/**
	 * @return array<string, int>
	 */
	private function collectMTimes(string $path, string|null $lockFile = null): array
	{
		if (is_file($path)) {
			if ($this->isUnwatched(realpath($path))) {
				return [];
			}

			return [$path => (int) filemtime($path)];
		}

		if (!is_dir($path)) {
			throw new Exceptions\AssetsException(sprintf('Watched path \'%s\' doesn\'t exists.', $path));
		}

		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS)) as $item) {
			assert($item instanceof \SplFileInfo);

			$realPath = realpath($item->getPathname());
			if ($item->isDir() || ($realPath === $lockFile) || $this->isUnwatched($realPath)) {
				continue;
			}

			$files[$item->getPathname()] = $item->getMTime();
		}

		return $files;
	}


	private function collectContents(string $path, string|null $lockFile = null): string
	{
		if (is_file($path)) {
			if ($this->isUnwatched(realpath($path))) {
				return '';
			}

			return (string) file_get_contents($path);
		}

		if (!is_dir($path)) {
			throw new Exceptions\AssetsException(sprintf('Watched path \'%s\' doesn\'t exists.', $path));
		}

		$contents = '';
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS)) as $item) {
			assert($item instanceof \SplFileInfo);

			$realPath = realpath($item->getPathname());
			if ($item->isDir() || ($realPath === $lockFile) || $this->isUnwatched($realPath)) {
				continue;
			}

			$contents .= file_get_contents($item->getPathname());
		}

		return $contents;
	}


	private function isUnwatched(string|false $realPath): bool
	{
		if ($realPath !== false) {
			foreach ($this->unwatchPaths() as $unwatchPath) {
				if ($realPath === $unwatchPath) {
					return true;
				} else if (str_ends_with($unwatchPath, DIRECTORY_SEPARATOR) && str_starts_with($realPath, $unwatchPath)) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * @return list<string>
	 */
	private function unwatchPaths(): array
	{
		if ($this->unwatchPathsResolved === null) {
			$paths = [];
			foreach ($this->unwatch as $path) {
				$realPath = realpath($path);
				if ($realPath === false) {
					throw new Exceptions\AssetsException(sprintf('Unwatched path \'%s\' doesn\'t exists.', $path));
				}

				$paths[] = is_dir($realPath) ? ($realPath . DIRECTORY_SEPARATOR) : $realPath;
			}

			$this->unwatchPathsResolved = $paths;
		}

		return $this->unwatchPathsResolved;
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
		if ($handle === false) {
			throw new Exceptions\AssetsException(sprintf('Unable to create file \'%s\' %s', $this->lockFile, error_get_last()['message'] ?? 'unknown'));
		} elseif (!@flock($handle, LOCK_EX)) { // intentionally @
			throw new Exceptions\AssetsException(sprintf('Unable to acquire exclusive lock on \'%s\' %s', $this->lockFile, error_get_last()['message'] ?? 'unknown'));
		}
		$this->lockHandle = $handle;

		$lockPath = realpath($this->lockFile);
		if ($lockPath === false) {
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
