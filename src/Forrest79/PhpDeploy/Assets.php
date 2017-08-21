<?php

namespace Forrest79\PhpDeploy;

use Nette\Neon;
use Nette\Utils;


class Assets
{
	const DEBUG = 'debug';
	const PRODUCTION = 'production';

	const COPY = 'copy';
	const LESS = 'less';
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


	public function setup($configFile, $sourceDirectory, $destinationDirectory)
	{
		$this->configFile = $configFile;
		$this->sourceDirectory = rtrim($sourceDirectory, '\\/');
		$this->destinationDirectory = rtrim($destinationDirectory, '\\/');

		$this->setup = TRUE;

		return $this;
	}


	public function buildDebug()
	{
		if ($this->setup === FALSE) {
			throw new \RuntimeException('Run setup() first.');
		}

		$oldHash = $this->readNeon();

		if (!file_exists($this->sourceDirectory)) {
			throw new \RuntimeException('Assets source directory doen\'t exists.');
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


	public function buildProduction()
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


	private function buildAssets($environment)
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
				throw new \InvalidArgumentException('Path \'' . $data['type'] . '\' has no type defined.');
			}

			$type = is_array($data) ? $data['type'] : $data;

			switch ($type) {
				case self::COPY :
					Utils\FileSystem::copy($this->sourceDirectory . DIRECTORY_SEPARATOR . $path, $this->destinationDirectory . DIRECTORY_SEPARATOR . $path);
					break;

				case self::LESS :
					if (!isset($data['file'])) {
						throw new \InvalidArgumentException('No file defined for \'' . $path . '\'.');
					}
					$this->compilesLess($data['file'], $path, $isDebug);
					break;

				case self::JS :
					if (!isset($data['files'])) {
						throw new \InvalidArgumentException('No files defined for \'' . $path . '\'.');
					}
					$this->compilesJs((array) $data['files'], $path, $isDebug);
					break;
			}
		}
	}


	/**
	 * @param string $sourceFile
	 * @param string $destinationFile
	 * @param bool $createMap
	 */
	private function compilesLess($sourceFile, $destinationFile, $createMap)
	{
		$sourceFileAbsolute = $this->sourceDirectory . DIRECTORY_SEPARATOR . $sourceFile;
		$destinationFileAbsolute = $this->destinationDirectory . DIRECTORY_SEPARATOR . $destinationFile;

		Utils\FileSystem::createDir(dirname($destinationFileAbsolute));

		$mapCommand = '';
		if ($createMap === TRUE) {
			$sourceMapDirectory = dirname($this->localSourceDirectory ? ($this->localSourceDirectory . DIRECTORY_SEPARATOR . $sourceFile) : $sourceFile);
			$mapCommand = '--source-map --source-map-rootpath=file:///' . $sourceMapDirectory . ' ';
		}
		exec($command = 'lessc --clean-css="--keepSpecialComments=0" ' . $mapCommand . $sourceFileAbsolute . ' ' . $destinationFileAbsolute . ' 2>&1', $output, $returnVal);
		if ($returnVal !== 0) {
			throw new \RuntimeException('Error while compiling less (' . $command . '): ' . implode("\n", $output));
		}
	}


	/**
	 * @param array $sourceFiles
	 * @param string $destinationFile
	 * @param bool $createMap
	 */
	private function compilesJs(array $sourceFiles, $destinationFile, $createMap)
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
			$mapCommand = '--source-map url=' . basename($destinationFile) . '.map ';
		}
		exec($command = 'uglifyjs ' . implode(' ', $sourceFiles) . ' -o ' . $destinationFile . ' --compress ' .  $mapCommand . '2>&1', $output, $returnVal);
		if ($returnVal !== 0) {
			throw new \RuntimeException('Error while compiling js (' . $command . '): ' . implode("\n", $output));
		}
		if ($createMap === TRUE) {
			$mapFile = $destinationFile . '.map';
			file_put_contents($mapFile, strtr(file_get_contents($mapFile), $mapSources));
		}
	}


	/** @return string|NULL */
	private function readNeon()
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


	private function writeNeon($hash)
	{
		file_put_contents($this->configFile, "parameters:\n\tassets:\n\t\thash: $hash\n");
	}

}
