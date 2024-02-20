<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Libresign\Handler\CertificateEngine;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use OC\SystemConfig;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Handler\CfsslServerHandler;
use OCA\Libresign\Helper\ConfigureCheckHelper;
use OCA\Libresign\Service\InstallService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;

/**
 * Class CfsslHandler
 *
 * @package OCA\Libresign\Handler
 *
 * @method CfsslHandler setClient(Client $client)
 */
class CfsslHandler extends AEngineHandler implements IEngineHandler {
	public const CFSSL_URI = 'http://127.0.0.1:8888/api/v1/cfssl/';

	/** @var Client */
	protected $client;
	protected $cfsslUri;
	private string $binary = '';

	public function __construct(
		protected IConfig $config,
		protected IAppConfig $appConfig,
		private SystemConfig $systemConfig,
		private CfsslServerHandler $cfsslServerHandler,
		protected IAppDataFactory $appDataFactory,
	) {
		parent::__construct($config, $appConfig, $appDataFactory);
	}

	private function getClient(): Client {
		if (!$this->client) {
			$this->setClient(new Client(['base_uri' => $this->getCfsslUri()]));
		}
		$this->wakeUp();
		return $this->client;
	}

	public function generateCertificate(string $certificate = '', string $privateKey = ''): string {
		$certKeys = $this->newCert();
		return parent::generateCertificate($certKeys['certificate'], $certKeys['private_key']);
	}

	private function newCert(): array {
		$json = [
			'json' => [
				'profile' => 'CA',
				'request' => [
					'hosts' => $this->getHosts(),
					'CN' => $this->getCommonName(),
					'key' => [
						'algo' => 'rsa',
						'size' => 2048,
					],
					'names' => [],
				],
			],
		];

		$names = $this->getNames();
		if (!empty($names)) {
			$json['json']['request']['names'][] = $names;
		}

		try {
			$response = $this->getClient()
				->request('post',
					'newcert',
					$json
				)
			;
		} catch (RequestException | ConnectException $th) {
			if ($th->getHandlerContext() && $th->getHandlerContext()['error']) {
				throw new \Exception($th->getHandlerContext()['error'], 1);
			}
			throw new LibresignException($th->getMessage(), 500);
		}

		$responseDecoded = json_decode((string) $response->getBody(), true);
		if (!isset($responseDecoded['success']) || !$responseDecoded['success']) {
			throw new LibresignException('Error while generating certificate keys!', 500);
		}

		return $responseDecoded['result'];
	}

	private function isUp(): bool {
		try {
			$client = $this->getClient();
			if (!$this->portOpen()) {
				throw new LibresignException('CFSSL server is down', 500);
			}
			$response = $client
				->request('get',
					'health',
					[
						'base_uri' => $this->getCfsslUri()
					]
				)
			;
		} catch (RequestException | ConnectException $th) {
			switch ($th->getCode()) {
				case 404:
					throw new \Exception('Endpoint /health of CFSSL server not found. Maybe you are using incompatible version of CFSSL server. Use latests version.', 1);
				default:
					if ($th->getHandlerContext() && $th->getHandlerContext()['error']) {
						throw new \Exception($th->getHandlerContext()['error'], 1);
					}
					throw new LibresignException($th->getMessage(), 500);
			}
		}

		$responseDecoded = json_decode((string) $response->getBody(), true);
		if (!isset($responseDecoded['success']) || !$responseDecoded['success']) {
			throw new LibresignException('Error while check cfssl API health!', 500);
		}

		if (empty($responseDecoded['result']) || empty($responseDecoded['result']['healthy'])) {
			return false;
		}

		return (bool) $responseDecoded['result']['healthy'];
	}

	private function wakeUp(): void {
		if ($this->portOpen()) {
			return;
		}
		$binary = $this->getBinary();
		$configPath = $this->getConfigPath();
		if (!$configPath) {
			throw new LibresignException('CFSSL not configured.');
		}
		$cmd = 'nohup ' . $binary . ' serve -address=127.0.0.1 ' .
			'-ca-key ' . $configPath . 'ca-key.pem ' .
			'-ca ' . $configPath . 'ca.pem '.
			'-config ' . $configPath . 'config_server.json > /dev/null 2>&1 & echo $!';
		shell_exec($cmd);
		$loops = 0;
		while (!$this->portOpen() && $loops <= 4) {
			sleep(1);
			$loops++;
		}
	}

	private function portOpen(): bool {
		$host = parse_url($this->getCfsslUri(), PHP_URL_HOST);
		$port = parse_url($this->getCfsslUri(), PHP_URL_PORT);
		try {
			$socket = fsockopen($host, $port, $errno, $errstr, 0.1);
		} catch (\Throwable $th) {
		}
		if (isset($socket) && is_resource($socket)) {
			fclose($socket);
			return true;
		}
		return false;
	}

	private function getBinary(): string {
		if ($this->binary) {
			return $this->binary;
		}

		$appKeys = $this->appConfig->getAppKeys();
		$binary = '';
		if (in_array('cfssl_bin', $appKeys)) {
			$binary = $this->appConfig->getAppValue('cfssl_bin');
			if (!file_exists($binary)) {
				$this->appConfig->deleteAppValue('cfssl_bin');
			}
		}

		if (!$binary) {
			throw new LibresignException('Binary of CFSSL not found. Install binaries.');
		}

		if (PHP_OS_FAMILY === 'Windows') {
			throw new LibresignException('Incompatible with Windows');
		}

		return $binary;
	}

	private function getCfsslUri(): string {
		if ($this->cfsslUri) {
			return $this->cfsslUri;
		}

		$appKeys = $this->appConfig->getAppKeys();
		if (in_array('cfssl_uri', $appKeys)) {
			if ($uri = $this->appConfig->getAppValue('cfssl_uri')) {
				return $uri;
			}
			// In case config is an empty string
			$this->appConfig->deleteAppValue('cfssl_uri');
		}

		$this->cfsslUri = self::CFSSL_URI;
		return $this->cfsslUri;
	}

	public function setCfsslUri($uri): void {
		if ($uri) {
			$this->appConfig->setAppValue('cfssl_uri', $uri);
		} else {
			$this->appConfig->deleteAppValue('cfssl_uri');
		}
		$this->cfsslUri = $uri;
	}

	private function genkey(): void {
		$binary = $this->getBinary();
		$configPath = $this->getConfigPath();
		$cmd = $binary . ' genkey ' .
			'-initca=true ' . $configPath . 'csr_server.json | ' .
			$binary . 'json -bare ' . $configPath . 'ca;';
		shell_exec($cmd);
	}

	public function generateRootCert(
		string $commonName,
		array $names = []
	): string {
		$key = bin2hex(random_bytes(16));

		$configPath = $this->getConfigPath();
		$this->cfsslServerHandler->createConfigServer(
			$commonName,
			$names,
			$key,
			$configPath
		);

		$this->genkey();

		for ($i = 1; $i <= 4; $i++) {
			if ($this->isUp($this->getCfsslUri())) {
				break;
			}
			sleep(2);
		}

		return $key;
	}

	public function isSetupOk(): bool {
		if (!parent::isSetupOk()) {
			return false;
		};
		try {
			$this->getClient();
			return true;
		} catch (\Throwable $th) {
		}
		return false;
	}

	public function configureCheck(): array {
		$return = $this->checkBinaries();
		$configPath = $this->getConfigPath();
		if (is_dir($configPath)) {
			return array_merge(
				$return,
				[(new ConfigureCheckHelper())
					->setSuccessMessage('Root certificate config files found.')
					->setResource('cfssl-configure')]
			);
		}
		return array_merge(
			$return,
			[(new ConfigureCheckHelper())
			->setErrorMessage('CFSSL (root certificate) not configured.')
			->setResource('cfssl-configure')
			->setTip('Run occ libresign:configure:cfssl --help')]
		);
	}

	private function checkBinaries(): array {
		if (PHP_OS_FAMILY === 'Windows') {
			return [
				(new ConfigureCheckHelper())
					->setErrorMessage('CFSSL is incompatible with Windows')
					->setResource('cfssl'),
			];
		}
		$cfsslInstalled = $this->appConfig->getAppValue('cfssl_bin');
		if (!$cfsslInstalled) {
			return [
				(new ConfigureCheckHelper())
					->setErrorMessage('CFSSL not installed.')
					->setResource('cfssl')
					->setTip('Run occ libresign:install --cfssl'),
			];
		}

		$instanceId = $this->systemConfig->getValue('instanceid', null);
		$binary = $this->systemConfig->getValue('datadirectory', \OC::$SERVERROOT . '/data/') . DIRECTORY_SEPARATOR .
			'appdata_' . $instanceId . DIRECTORY_SEPARATOR .
			Application::APP_ID . DIRECTORY_SEPARATOR .
			'cfssl';
		if (!file_exists($binary)) {
			return [
				(new ConfigureCheckHelper())
					->setErrorMessage('CFSSL not found.')
					->setResource('cfssl')
					->setTip('Run occ libresign:install --cfssl'),
			];
		}
		$return = [];
		$version = str_replace("\n", ', ', trim(`$binary version`));
		if (strpos($version, InstallService::CFSSL_VERSION) === false) {
			return [
				(new ConfigureCheckHelper())
					->setErrorMessage(sprintf(
						'Invalid version. Expected: %s, actual: %s',
						InstallService::CFSSL_VERSION,
						$version
					))
					->setResource('cfssl')
					->setTip('Run occ libresign:install --cfssl')
			];
		}
		$return[] = (new ConfigureCheckHelper())
			->setSuccessMessage('CFSSL binary path: ' . $binary)
			->setResource('cfssl');
		$return[] = (new ConfigureCheckHelper())
			->setSuccessMessage('CFSSL: ' . $version)
			->setResource('cfssl');
		return $return;
	}

	public function toArray(): array {
		$return = parent::toArray();
		if (!empty($return['configPath'])) {
			$return['cfsslUri'] = $this->appConfig->getAppValue('cfssl_uri');
		}
		return $return;
	}
}