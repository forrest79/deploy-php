<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

use Nette\Utils;
use phpseclib3\Crypt;
use phpseclib3\Exception;
use phpseclib3\Net;
use phpseclib3\System;

/**
 * @phpstan-type EnvironmentType array{server: string, port: int, username: string, private_key?: string, passphrase?: string|callable(static, string): (string|null)|null, ssh_agent?: string|bool}
 */
class Deploy
{
	/** @var array<string, array<string, bool|float|int|string|array<mixed>|null>> */
	protected array $config = [];

	/** @var array<string, EnvironmentType> */
	protected array $environment;

	/** @var array<string, Net\SSH2> */
	private array $sshConnections = [];


	/**
	 * @param array<string, mixed> $additionalConfig
	 */
	public function __construct(string $environment, array $additionalConfig = [])
	{
		if (!isset($this->config[$environment])) {
			throw new Exceptions\DeployException(sprintf('Environment \'%s\' not exists in configuration.', $environment));
		}

		/** @var array<string, EnvironmentType> $environmentConfig */
		$environmentConfig = array_replace_recursive($this->config[$environment], $additionalConfig);
		if ($environmentConfig === null) {
			throw new Exceptions\DeployException('Can\'t prepare environment config.');
		}

		$this->environment = $environmentConfig;

		$this->setup();
	}


	protected function setup(): void
	{
	}


	protected function copy(string $source, string $destination): void
	{
		Utils\FileSystem::copy($source, $destination);
	}


	protected function move(string $source, string $destination): void
	{
		Utils\FileSystem::rename($source, $destination);
	}


	protected function delete(string $path): void
	{
		Utils\FileSystem::delete($path);
	}


	protected function makeDir(string $path): void
	{
		Utils\FileSystem::createDir($path, 0755);
	}


	protected function gitCheckout(string $gitRootDirectory, string $checkoutDirectory, string $branch): bool
	{
		$zipFile = $checkoutDirectory . DIRECTORY_SEPARATOR . uniqid() . '-git.zip';

		$currentDirectory = getcwd();
		if ($currentDirectory === false) {
			throw new Exceptions\DeployException('Can\'t determine current directory');
		}

		$gitRootDirectoryPath = realpath($gitRootDirectory);
		if ($gitRootDirectoryPath === false) {
			throw new Exceptions\DeployException(sprintf('GIT root directory \'%s\' doesn\'t exists', $gitRootDirectory));
		}

		chdir($gitRootDirectoryPath);

		$this->makeDir($checkoutDirectory);

		$success = $this->exec(sprintf('git archive -o %s %s && unzip %s -d %s && rm %s', $zipFile, $branch, $zipFile, $checkoutDirectory, $zipFile));

		chdir($currentDirectory);

		return $success;
	}


	protected function exec(string $command, string|false &$stdout = false): bool
	{
		exec($command, $output, $return);
		if (($output !== []) && ($stdout !== false)) {
			$stdout = implode(PHP_EOL, $output);
		}
		return $return === 0;
	}


	protected function gzip(string $sourcePath, string $sourceDir, string $targetFile): void
	{
		exec(sprintf('tar -C %s --force-local -zcvf %s %s', $sourcePath, $targetFile, $sourceDir), $output, $return);
		if ($return !== 0) {
			throw new Exceptions\DeployException(sprintf('Can\'t create tar.gz archive \'%s\': %s', $targetFile, implode(PHP_EOL, $output)));
		}
	}


	public function validatePrivateKey(string|null $privateKeyFile = null, string|null $passphrase = null): bool
	{
		if (($privateKeyFile === null) && ($passphrase !== null)) {
			throw new Exceptions\DeployException('Can\'t provide passphrase without private key file');
		}

		$credentials = $this->environment['ssh'];

		if ($privateKeyFile === null) {
			if (isset($credentials['private_key'])) {
				$privateKeyFile = $credentials['private_key'];
			} else {
				throw new Exceptions\DeployException('Private key file is not provided and no private_key is defined in environment config');
			}
		}

		$passphrase ??= $credentials['passphrase'] ?? null;
		if (is_callable($passphrase)) {
			throw new Exceptions\DeployException('Private key can\'t be validated with callable passphrase');
		}

		try {
			$this->createPrivateKey($privateKeyFile, $passphrase);
		} catch (Exception\NoKeyLoadedException) {
			return false;
		}

		return true;
	}


	protected function ssh(
		string $command,
		string|null $validate = null,
		string|null &$output = null,
		string|null $host = null,
		int|null $port = null,
	): bool
	{
		$output = $this->sshExec($this->sshConnection(Net\SSH2::class, $host, $port), $command . ';echo "[return_code:$?]"');

		preg_match('/\[return_code:(.*?)\]/', $output, $match);
		$output = preg_replace('/\[return_code:(.*?)\]/', '', $output);
		assert(isset($match[1]));

		if ($match[1] !== '0') {
			$this->log(sprintf('SSH error output for command "%s": %s', $command, $output ?? ''));
			return false;
		}

		if ($validate !== null) {
			$success = str_contains($output ?? '', $validate);
			if (!$success) {
				$this->log(sprintf('SSH validation error: "%s" doesn\'t contains "%s"', $output ?? '', $validate));
			}
			return $success;
		}

		return true;
	}


	protected function sftpPut(
		string $localFile,
		string $remoteDirectory,
		string|null $host = null,
		int|null $port = null,
	): bool
	{
		$remoteDirectory = rtrim($remoteDirectory, '/');

		$sftp = $this->sshConnection(Net\SFTP::class, $host, $port);
		assert($sftp instanceof Net\SFTP);

		$this->sshExec($sftp, 'mkdir -p ' . $remoteDirectory); // create remote directory if doesn't
		$remoteAbsoluteDirectory = (str_starts_with($remoteDirectory, '/')) ? $remoteDirectory : (trim($this->sshExec($sftp, 'pwd')) . '/' . $remoteDirectory);
		$remoteFile = $remoteAbsoluteDirectory . '/' . basename($localFile);

		return $sftp->put($remoteFile, $localFile, Net\SFTP::SOURCE_LOCAL_FILE);
	}


	protected function httpRequest(string $url, string|null $validate = null): bool
	{
		$curl = curl_init($url);
		if ($curl === false) {
			throw new Exceptions\DeployException('Can\'t initialize curl');
		}

		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, $validate !== null);

		$returned = curl_exec($curl);

		$errorNo = curl_errno($curl);
		curl_close($curl);

		if ($validate !== null) {
			if (!is_string($returned)) {
				return false;
			}
			return str_contains($returned, $validate);
		}

		return $errorNo === 0;
	}


	protected function error(string|null $message = null): void
	{
		throw new Exceptions\DeployException($message ?? '');
	}


	protected function log(string $message, bool $newLine = true): void
	{
		echo $message . ($newLine ? PHP_EOL : '');
	}


	/**
	 * @param class-string<Net\SSH2> $class
	 */
	private function sshConnection(string $class, string|null $host, int|null $port): Net\SSH2
	{
		if ($host === null) {
			$host = $this->environment['ssh']['server'];
		}
		if ($port === null) {
			$port = $this->environment['ssh']['port'] ?? 22;
		}

		$credentials = $this->environment['ssh'];

		$keySuffix = sprintf('|%s@%s:%d', $credentials['username'], $host, $port);

		if (($class === Net\SSH2::class) && isset($this->sshConnections[Net\SFTP::class . $keySuffix])) {
			// try to load Net\SFTP cached connection that can be used as Net\SSH2
			$key = Net\SFTP::class . $keySuffix;
		} else {
			$key = $class . $keySuffix;
		}

		if (!isset($this->sshConnections[$key])) {
			$sshConnection = self::createSshConnection($class, $host, $port);

			$isConnectedByAgent = false;
			$sshAgent = null;
			if (isset($credentials['ssh_agent']) && (bool) $credentials['ssh_agent']) {
				$sshAgent = new System\SSH\Agent($credentials['ssh_agent'] === true ? null : $credentials['ssh_agent']);
				$isConnectedByAgent = $sshConnection->login($credentials['username'], $sshAgent);
			}

			if (!$isConnectedByAgent) {
				if (isset($credentials['private_key'])) {
					$passphrase = null;
					if (isset($credentials['passphrase'])) {
						if (is_callable($credentials['passphrase'])) {
							$passphrase = call_user_func($credentials['passphrase'], $this, $credentials['private_key']);
							assert(is_string($passphrase));
							$this->environment['ssh']['passphrase'] = $passphrase;
						} else {
							$passphrase = $credentials['passphrase'];
						}
					}

					$privateKey = $this->createPrivateKey($credentials['private_key'], $passphrase);
					$sshConnection = new $class($host, $port, 0);
					if (!$sshConnection->login($credentials['username'], $privateKey)) {
						throw new Exceptions\DeployException(sprintf('SSH can\'t authenticate with private key \'%s\'%s', $credentials['private_key'], $sshAgent === null ? '' : ' (ssh-agent was also tried)'));
					}
				} else {
					throw new Exceptions\DeployException('Unsupported authentication type for SSH.');
				}
			}

			$this->sshConnections[$key] = $sshConnection;
		}

		return $this->sshConnections[$key];
	}


	/**
	 * Temporary till Net\SSH2 will be fixed in phpseclib - every login attempt should have reset algorithms.
	 *
	 * @param class-string<Net\SSH2> $class
	 */
	private static function createSshConnection(string $class, string $host, int $port): Net\SSH2
	{
		return new $class($host, $port, 0);
	}


	private function sshExec(Net\SSH2 $sshConnection, string $command): string
	{
		$result = $sshConnection->exec($command);
		if ($result === false) {
			throw new Exceptions\DeployException(sprintf('SSH command \'%s\' failed', $command));
		}

		assert(is_string($result));
		return $result;
	}


	private function createPrivateKey(string $privateKeyFile, string|null $passphrase): Crypt\RSA\PrivateKey
	{
		$privateKeyContents = file_get_contents($privateKeyFile);
		if ($privateKeyContents === false) {
			throw new Exceptions\DeployException(sprintf('SSH can\'t load private key \'%s\'', $privateKeyFile));
		}

		// this if is for PHPStan, we can do also `Crypt\RSA::load($privateKeyContents, $passphrase ?? false)` and ignore PHPStan error
		$privateKey = $passphrase === null
			? Crypt\RSA::load($privateKeyContents)
			: Crypt\RSA::load($privateKeyContents, $passphrase);
		assert($privateKey instanceof Crypt\RSA\PrivateKey);

		return $privateKey;
	}


	public static function getResponse(): string
	{
		$response = stream_get_line(STDIN, 1024, PHP_EOL);
		if ($response === false) {
			throw new Exceptions\DeployException('Can\'t get response');
		}
		return $response;
	}


	/**
	 * Taken from Symfony/Console.
	 */
	public static function getHiddenResponse(): string
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			exec(__DIR__ . '/../bin/hiddeninput.exe', $output, $resultCode);
			if ($resultCode !== 0) {
				throw new Exceptions\DeployException('Unable to hide the response on Windows');
			}
			return rtrim(implode(PHP_EOL, $output));
		}

		if (self::hasSttyAvailable()) {
			$sttyMode = exec('stty -g');
			assert($sttyMode !== false);

			exec('stty -echo');
			$value = fgets(STDIN, 4096);
			exec(sprintf('stty %s', $sttyMode));

			if ($value === false) {
				throw new Exceptions\DeployException('Hidden response aborted.');
			}

			return trim($value);
		}

		$shell = self::getShell();
		if ($shell !== null) {
			$readCmd = ($shell === 'csh') ? 'set mypassword = $<' : 'read -r mypassword';
			$command = sprintf("/usr/bin/env %s -c 'stty -echo; %s; stty echo; echo \$mypassword'", $shell, $readCmd);
			exec($command, $output, $resultCode);
			if ($resultCode !== 0) {
				throw new Exceptions\DeployException('Unable to hide the response on Shell');
			}
			return rtrim(implode(PHP_EOL, $output));
		}

		throw new Exceptions\DeployException('Unable to hide the response');
	}


	private static function getShell(): string|null
	{
		$shell = null;

		if (file_exists('/usr/bin/env')) {
			// handle other OSs with bash/zsh/ksh/csh if available to hide the answer
			$test = "/usr/bin/env %s -c 'echo OK' 2> /dev/null";
			foreach (['bash', 'zsh', 'ksh', 'csh'] as $sh) {
				exec(sprintf($test, $sh), $output, $resultCode);
				if ($resultCode !== 0) {
					throw new Exceptions\DeployException('Unable to get shell');
				}
				if (rtrim(implode(PHP_EOL, $output)) === 'OK') {
					$shell = $sh;
					break;
				}
			}
		}

		return $shell;
	}


	private static function hasSttyAvailable(): bool
	{
		exec('stty 2>&1', $output, $exitcode);
		return $exitcode === 0;
	}

}
