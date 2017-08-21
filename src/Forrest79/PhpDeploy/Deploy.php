<?php

namespace Forrest79\PhpDeploy;

use Nette\Utils;


class Deploy
{
	/** @var array */
	protected $config = [];

	/** @var array */
	protected $environment = [];

	/** @var array */
	private $sshConnections = [];


	public function __construct($environment, array $additionalConfig = [])
	{
		if (!isset($this->config[$environment])) {
			throw new \RuntimeException('Environment \'' . $environment . '\' not exists in configuration.');
		}

		$this->environment = array_replace_recursive($this->config[$environment], $additionalConfig);

		$this->setup();
	}


	protected function setup()
	{
	}


	protected function delete($path)
	{
		Utils\FileSystem::delete($path);
	}


	protected function makeDir($path)
	{
		Utils\FileSystem::createDir($path, 0755);
	}


	/** @return bool */
	protected function gitCheckout($gitRootDirectory, $checkoutDirectory, $branch)
	{
		$zipFile = $checkoutDirectory . DIRECTORY_SEPARATOR . uniqid() . '-git.zip';

		$currentDirectory = getcwd();
		chdir(realpath($gitRootDirectory));

		$this->makeDir($checkoutDirectory);

		$success = $this->exec('git archive -o ' . $zipFile . ' ' . $branch . ' && unzip ' . $zipFile . ' -d ' . $checkoutDirectory . ' && rm ' . $zipFile);

		chdir($currentDirectory);

		return $success;
	}


	/** @return bool */
	protected function exec($command, & $stdout = FALSE)
	{
		exec($command, $output, $return);
		if ($output && ($stdout !== FALSE)) {
			$stdout = implode("\n", $output);
		}
		return ($return === 0) ? TRUE : FALSE;
	}


	protected function gzip($sourcePath, $sourceDir, $targetFile)
	{
		exec('tar -C ' . $sourcePath . ' --force-local -zcvf ' . $targetFile . ' ' . $sourceDir, $output, $return);
		if ($return !== 0) {
			throw new \RuntimeException("Can't create tar.gz archive '$targetFile': " . implode("\n", $output));
		}
	}


	/** @return bool */
	protected function ssh($command, $validate = NULL, & $output = NULL, $host = NULL, $port = 22)
	{
		$output = $this->sshExec($this->sshConnect($host, $port), $command . ';echo "[return_code:$?]"');

		preg_match( '/\[return_code:(.*?)\]/', $output, $match);
		$output = preg_replace( '/\[return_code:(.*?)\]/', '', $output);

		if ($match[1] !== '0') {
			$this->log('| SSH error output for command "' . $command . '": ' . $output);
			return FALSE;
		}

		if ($validate) {
			$sucess = strpos($output, $validate) !== FALSE;
			if (!$sucess) {
				$this->log('| SSH validation error: "' . $output . '" doesn\'t contains "' . $validate . '"');
			}
			return $sucess;
		}

		return TRUE;
	}


	protected function scp($localFile, $remoteDirectory, $host = NULL, $port = 22)
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
			return $success;
		}

		return FALSE;
	}


	protected function httpRequest($url, $validate = NULL)
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


	protected function error($message = NULL)
	{
		throw new \RuntimeException($message);
	}


	protected function log($message, $newLine = TRUE)
	{
		echo $message . ($newLine ? "\n" : '');
	}


	private function sshConnect($host, $port = 22)
	{
		if ($host === NULL) {
			$host = $this->environment['ssh']['server'];
		}

		$credentials = $this->environment['ssh'];

		$key = $credentials['username'] . '@' . $host . ':' . $port;

		if (!isset($this->sshConnections[$key])) {
			$connection = ssh2_connect($host, $port, ['hostkey' => 'ssh-rsa']);
			if ($connection === FALSE) {
				throw new \RuntimeException('SSH can\'t connet to host "' . $host . '":' . $port . '.');
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


	private function sshExec($connection, $command)
	{
		$stream = ssh2_exec($connection, $command);
		stream_set_blocking($stream, TRUE);
		$streamOut = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
		return stream_get_contents($streamOut);
	}

}
