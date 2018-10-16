<?php declare(strict_types=1);

namespace Forrest79\DeployPhp;

use Nette\Utils;

class Deploy
{
	/** @var array */
	protected $config = [];

	/** @var array */
	protected $environment = [];

	/** @var array */
	private $sshConnections = [];


	public function __construct(string $environment, array $additionalConfig = [])
	{
		if (!isset($this->config[$environment])) {
			throw new \RuntimeException(sprintf('Environment \'%s\' not exists in configuration.', $environment));
		}

		$this->environment = array_replace_recursive($this->config[$environment], $additionalConfig);

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
		chdir(realpath($gitRootDirectory));

		$this->makeDir($checkoutDirectory);

		$success = $this->exec(sprintf('git archive -o %s %s && unzip %s -d %s && rm %s', $zipFile, $branch, $zipFile, $checkoutDirectory, $zipFile));

		chdir($currentDirectory);

		return $success;
	}


	protected function exec(string $command, & $stdout = FALSE): bool
	{
		exec($command, $output, $return);
		if ($output && ($stdout !== FALSE)) {
			$stdout = implode("\n", $output);
		}
		return ($return === 0) ? TRUE : FALSE;
	}


	protected function gzip(string $sourcePath, string $sourceDir, string $targetFile): void
	{
		exec(sprintf('tar -C %s --force-local -zcvf %s %s', $sourcePath, $targetFile, $sourceDir), $output, $return);
		if ($return !== 0) {
			throw new \RuntimeException(sprintf('Can\'t create tar.gz archive \'%s\': %s', $targetFile, implode(PHP_EOL, $output)));
		}
	}


	protected function ssh(string $command, ?string $validate = NULL, & $output = NULL, ?string $host = NULL, int $port = 22): bool
	{
		$output = $this->sshExec($this->sshConnect($host, $port), $command . ';echo "[return_code:$?]"');

		preg_match( '/\[return_code:(.*?)\]/', $output, $match);
		$output = preg_replace( '/\[return_code:(.*?)\]/', '', $output);

		if ($match[1] !== '0') {
			$this->log(sprintf('| SSH error output for command "%s": %s', $command, $output));
			return FALSE;
		}

		if ($validate) {
			$success = strpos($output, $validate) !== FALSE;
			if (!$success) {
				$this->log(sprintf('| SSH validation error: "%s" doesn\'t contains "%s"', $output, $validate));
			}
			return $success;
		}

		return TRUE;
	}


	protected function scp(string $localFile, string $remoteDirectory, ?string $host = NULL, int $port = 22): bool
	{
		$remoteDirectory = rtrim($remoteDirectory, '/');

		$connection = $this->sshConnect($host, $port);
		$this->sshExec($connection, 'mkdir -p ' . $remoteDirectory); // create remote directory if doesn't
		$remoteAbsoluteDirectory = (substr($remoteDirectory, 0, 1) === '/') ? $remoteDirectory : (trim($this->sshExec($connection, 'pwd')) . '/' . $remoteDirectory);
		$remoteFile = $remoteAbsoluteDirectory . '/' . basename($localFile);

		$sftp = ssh2_sftp($connection);
		if ($stream = fopen("ssh2.sftp://" . intval($sftp) . "/$remoteFile", 'wb')) {
			$file = file_get_contents($localFile);
			$success = fwrite($stream, $file);
			fclose($stream);
			return (bool) $success;
		}

		return FALSE;
	}


	protected function httpRequest(string $url, ?string $validate = NULL): bool
	{
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_HEADER, FALSE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, $validate !== NULL);

		$returned = curl_exec($curl);
		$errorNo = curl_errno($curl);
		curl_close($curl);

		if ($validate !== NULL) {
			return strpos($returned, $validate) !== FALSE;
		}

		return $errorNo === 0;
	}


	protected function error(?string $message = NULL): void
	{
		throw new \RuntimeException($message);
	}


	protected function log(string $message, bool $newLine = TRUE): void
	{
		echo $message . ($newLine ? PHP_EOL : '');
	}


	/**
	 * @param string $host
	 * @param int $port
	 * @return resource
	 */
	private function sshConnect(?string $host, int $port = 22)
	{
		if ($host === NULL) {
			$host = $this->environment['ssh']['server'];
		}

		$credentials = $this->environment['ssh'];

		$key = sprintf('%s@%s:%d', $credentials['username'], $host, $port);

		if (!isset($this->sshConnections[$key])) {
			$connection = ssh2_connect($host, $port, ['hostkey' => 'ssh-rsa']);
			if ($connection === FALSE) {
				throw new \RuntimeException(sprintf('SSH can\'t connect to host "%s":%d.', $host, $port));
			}

			if (isset($credentials['public_key'])) {
				if (!ssh2_auth_pubkey_file($connection, $credentials['username'], $credentials['public_key'], $credentials['private_key'], !empty($credentials['passphrase']) ? $credentials['passphrase'] : NULL)) {
					throw new \RuntimeException('SSH can\'t authenticate with public key.');
				}
			} else {
				throw new \RuntimeException('Unsupported authentication type for SSH.');
			}

			$this->sshConnections[$key] = $connection;
		}

		return $this->sshConnections[$key];
	}


	/**
	 * @param resource $connection
	 * @param string $command
	 * @return bool|string
	 */
	private function sshExec($connection, string $command)
	{
		$stream = ssh2_exec($connection, $command);
		stream_set_blocking($stream, TRUE);
		$streamOut = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
		return stream_get_contents($streamOut);
	}


	public static function getResponse(): string
	{
		return stream_get_line(STDIN, 1024, PHP_EOL);
	}


	/**
	 * Taken from Symfony/Console.
	 */
	public static function getHiddenResponse(): string
	{
		if ('\\' === DIRECTORY_SEPARATOR) {
			return rtrim(shell_exec(__DIR__ . '/../bin/hiddeninput.exe'));
		}

		if (self::hasSttyAvailable()) {
			$sttyMode = shell_exec('stty -g');

			shell_exec('stty -echo');
			$value = fgets(STDIN, 4096);
			shell_exec(sprintf('stty %s', $sttyMode));

			if ($value === FALSE) {
				throw new \RuntimeException('Hidden response aborted.');
			}

			$value = trim($value);

			return $value;
		}

		if (($shell = self::getShell()) !== FALSE) {
			$readCmd = 'csh' === $shell ? 'set mypassword = $<' : 'read -r mypassword';
			$command = sprintf("/usr/bin/env %s -c 'stty -echo; %s; stty echo; echo \$mypassword'", $shell, $readCmd);
			$value = rtrim(shell_exec($command));

			return $value;
		}

		throw new \RuntimeException('Unable to hide the response.');
	}


	private static function getShell()
	{
		$shell = FALSE;

		if (file_exists('/usr/bin/env')) {
			// handle other OSs with bash/zsh/ksh/csh if available to hide the answer
			$test = "/usr/bin/env %s -c 'echo OK' 2> /dev/null";
			foreach (['bash', 'zsh', 'ksh', 'csh'] as $sh) {
				if (rtrim(shell_exec(sprintf($test, $sh))) === 'OK') {
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
		return 0 === $exitcode;
	}

}
