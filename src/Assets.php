<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

use Nette\Neon;
use Nette\Utils;

class Assets
{
	const DEBUG = 'debug';
	const PRODUCTION = 'production';

	const COPY = 'copy';
	const LESS = 'less';
	const SASS = 'sass';
	const JS = 'js';

	/** @var array */
	private $config;

	/** @var bool */
	private $setup = FALSE;

	/** @var string */
	private $sourceDirectory;

	/** @var string */
	private $destinationDirectory;

	/** @var string */
	private $localSourceDirectory;

	/** @var string */
	private $configFile;


	public function __construct(array $config, array $localConfig = [])
	{
		$this->config = $config;

		if (isset($localConfig['localSourceDirectory'])) {
			$this->localSourceDirectory = rtrim($localConfig['localSourceDirectory'], '\\/');
		}
	}


	public function setup(string $configFile, string $sourceDirectory, string $destinationDirectory): self
	{
		$this->configFile = $configFile;
		$this->sourceDirectory = rtrim($sourceDirectory, '\\/');
		$this->destinationDirectory = rtrim($destinationDirectory, '\\/');

		$this->setup = TRUE;

		return $this;
	}


	public function buildDebug(): void
	{
		if ($this->setup === FALSE) {
			throw new \RuntimeException('Run setup() first.');
		}

		$oldHash = $this->readNeon();

		if (!file_exists($this->sourceDirectory)) {
			throw new \RuntimeException('Assets source directory doesn\'t exists.');
		}

		$files = [];
		foreach ($iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sourceDirectory, \RecursiveDirectoryIterator::SKIP_DOTS)) as $item) {
			if (!$item->isDir()) {
				$files[$item->getPathname()] = $item->getMTime();
			}
		}

		$newHash = md5(serialize($files));

		if ($oldHash !== $newHash) {
			$this->buildAssets(self::DEBUG);
			$this->writeNeon($newHash);
		}
	}


	public function buildProduction(): void
	{
		if ($this->setup === FALSE) {
			throw new \RuntimeException('Run setup() first.');
		}

		if (!file_exists($this->sourceDirectory)) {
			throw new \RuntimeException('Assets source directory doen\'t exists.');
		}

		$this->buildAssets(self::PRODUCTION);

		$contents = '';
		foreach ($iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->sourceDirectory, \RecursiveDirectoryIterator::SKIP_DOTS)) as $item) {
			if (!$item->isDir()) {
				$contents .= file_get_contents($item->getPathname());
			}
		}

		$this->writeNeon(md5($contents));
	}


	private function buildAssets(string $environment): void
	{
		if (file_exists($this->destinationDirectory)) {
			Utils\FileSystem::delete($this->destinationDirectory);
		}

		$isDebug = $environment === self::DEBUG;

		foreach ($this->config as $path => $data) {
			if (is_array($data) && isset($data['env']) && ($data['env'] !== $environment)) {
				continue;
			}

			if (is_array($data) && !isset($data['type'])) {
				throw new \InvalidArgumentException(sprintf('Path \'%s\' has no type defined.', $data['type']));
			}

			$type = is_array($data) ? $data['type'] : $data;

			switch ($type) {
				case self::COPY :
					Utils\FileSystem::copy($this->sourceDirectory . DIRECTORY_SEPARATOR . $path, $this->destinationDirectory . DIRECTORY_SEPARATOR . $path);
					break;

				case self::LESS :
					if (!isset($data['file'])) {
						throw new \InvalidArgumentException(sprintf('No file defined for \'%s\'.', $path));
					}
					$this->compilesLess($data['file'], $path, $isDebug);
					break;

				case self::SASS :
					if (!isset($data['file'])) {
						throw new \InvalidArgumentException(sprintf('No file defined for \'%s\'.', $path));
					}
					$this->compilesSass($data['file'], $path, $isDebug);
					break;

				case self::JS :
					if (!isset($data['files'])) {
						throw new \InvalidArgumentException(sprintf('No files defined for \'%s\'.', $path));
					}
					$this->compilesJs((array) $data['files'], $path, $isDebug);
					break;
			}
		}
	}


	private function compilesLess(string $sourceFile, string $destinationFile, bool $createMap): void
	{
		$sourceFileAbsolute = $this->sourceDirectory . DIRECTORY_SEPARATOR . $sourceFile;
		$destinationFileAbsolute = $this->destinationDirectory . DIRECTORY_SEPARATOR . $destinationFile;

		Utils\FileSystem::createDir(dirname($destinationFileAbsolute));

		$mapCommand = '';
		if ($createMap === TRUE) {
			$sourceMapDirectory = dirname($this->localSourceDirectory ? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $sourceFile) : $sourceFile);
			$mapCommand = sprintf('--source-map --source-map-rootpath=file:///%s ', $sourceMapDirectory);
		}

		$command = sprintf('lessc --clean-css="--keepSpecialComments=0" %s%s %s 2>&1', $mapCommand, $sourceFileAbsolute, $destinationFileAbsolute);

		exec($command, $output, $returnVal);

		if ($returnVal !== 0) {
			throw new \RuntimeException(sprintf('Error while compiling less (%s): %s', $command, implode("\n", $output)));
		}
	}


	private function compilesSass(string $sourceFile, string $destinationDirectory, bool $createMap): void
	{
		$sourceFileAbsolute = $this->sourceDirectory . DIRECTORY_SEPARATOR . $sourceFile;
		$destinationDirectoryAbsolute = $this->destinationDirectory . DIRECTORY_SEPARATOR . $destinationDirectory;

		Utils\FileSystem::createDir(dirname($destinationDirectoryAbsolute));

		$mapCommand = '';
		if ($createMap === TRUE) {
			$sourceMapDirectory = dirname($this->localSourceDirectory ? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $sourceFile) : $sourceFile);
			$mapCommand = sprintf('--source-map true --source-map-root="file:///%s/" ', $sourceMapDirectory);
		}

		$command = sprintf('node-sass %s  --quiet --output-style=compressed --output="%s" %s2>&1', $sourceFileAbsolute, $destinationDirectoryAbsolute, $mapCommand);

		exec($command, $output, $returnVal);

		if ($returnVal !== 0) {
			throw new \RuntimeException(sprintf('Error while compiling sass (%s): %s', $command, implode("\n", $output)));
		}
	}


	private function compilesJs(array $sourceFiles, string $destinationFile, bool $createMap): void
	{
		$destinationFile = $this->destinationDirectory . DIRECTORY_SEPARATOR . $destinationFile;

		Utils\FileSystem::createDir(dirname($destinationFile));

		$mapSources = [];

		array_walk($sourceFiles, function (& $file) use ($createMap, & $mapSources) {
			$fileRelative = $file;
			$file = $this->sourceDirectory . DIRECTORY_SEPARATOR . $file;
			if ($createMap === TRUE) {
				$mapSources[$file] = 'file:///' . ($this->localSourceDirectory ? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $fileRelative) : realpath($file));
			}
		});

		$mapCommand = '';
		if ($createMap === TRUE) {
			$mapCommand = sprintf('--source-map url=%s.map ', basename($destinationFile));
		}

		$command = sprintf('uglifyjs %s -o %s --compress %s2>&1', implode(' ', $sourceFiles), $destinationFile, $mapCommand);

		exec($command, $output, $returnVal);

		if ($returnVal !== 0) {
			throw new \RuntimeException('Error while compiling js (' . $command . '): ' . implode("\n", $output));
		}

		if ($createMap === TRUE) {
			$mapFile = $destinationFile . '.map';
			file_put_contents($mapFile, strtr(file_get_contents($mapFile), $mapSources));
		}
	}


	private function readNeon(): ?string
	{
		if (!file_exists($this->configFile)) {
			return NULL;
		}

		$data = Neon\Neon::decode(file_get_contents($this->configFile));
		if (!isset($data['parameters']['assets']['hash'])) {
			return NULL;
		}

		return $data['parameters']['assets']['hash'];
	}


	private function writeNeon(string $hash): void
	{
		file_put_contents($this->configFile, "parameters:\n\tassets:\n\t\thash: $hash\n");
	}

}
