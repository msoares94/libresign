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

namespace OCA\Libresign\Service;

use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\IdentifyMethodMapper;
use OCA\Libresign\Db\SignRequest as SignRequestEntity;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\IdentifyMethod\IIdentifyMethod;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\Node;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\UUIDUtil;

class RequestSignatureService {
	use TFile;

	public function __construct(
		protected IL10N $l10n,
		protected IdentifyMethodService $identifyMethod,
		protected SignRequestMapper $signRequestMapper,
		protected IUserManager $userManager,
		protected FileMapper $fileMapper,
		protected IdentifyMethodMapper $identifyMethodMapper,
		protected PdfParserService $pdfParserService,
		protected FileElementService $fileElementService,
		protected FileElementMapper $fileElementMapper,
		protected FolderService $folderService,
		protected IMimeTypeDetector $mimeTypeDetector,
		protected ValidateHelper $validateHelper,
		protected IClientService $client,
		protected LoggerInterface $logger
	) {
	}

	public function save(array $data): FileEntity {
		$file = $this->saveFile($data);
		$this->saveVisibleElements($data, $file);
		$this->associateToSigners($data, $file->getId());
		return $file;
	}

	/**
	 * Save file data
	 *
	 * @param array{userManager: IUser, name: string, callback: string} $data
	 */
	public function saveFile(array $data): FileEntity {
		if (!empty($data['uuid'])) {
			$file = $this->fileMapper->getByUuid($data['uuid']);
			return $this->updateStatus($file, $data['status'] ?? 0);
		}
		$fileId = null;
		if (isset($data['file']['fileNode']) && $data['file']['fileNode'] instanceof Node) {
			$fileId = $data['file']['fileNode']->getId();
		} elseif (!empty($data['file']['fileId'])) {
			$fileId = $data['file']['fileId'];
		}
		if (!is_null($fileId)) {
			try {
				$file = $this->fileMapper->getByFileId($fileId);
				return $this->updateStatus($file, $data['status'] ?? 0);
			} catch (\Throwable $th) {
			}
		}

		$node = $this->getNodeFromData($data);

		$file = new FileEntity();
		$file->setNodeId($node->getId());
		$file->setUserId($data['userManager']->getUID());
		$file->setUuid(UUIDUtil::getUUID());
		$file->setCreatedAt(time());
		$file->setName($data['name']);
		$file->setMetadata($this->getFileMetadata($node));
		if (!empty($data['callback'])) {
			$file->setCallback($data['callback']);
		}
		if (isset($data['status'])) {
			$file->setStatus($data['status']);
		} else {
			$file->setStatus(FileEntity::STATUS_ABLE_TO_SIGN);
		}
		$this->fileMapper->insert($file);
		return $file;
	}

	private function updateStatus(FileEntity $file, int $status): FileEntity {
		if ($status > $file->getStatus()) {
			$file->setStatus($status);
			return $this->fileMapper->update($file);
		}
		return $file;
	}

	public function getFileMetadata(\OCP\Files\Node $node): array {
		$metadata = [
			'extension' => $node->getExtension(),
		];
		if ($metadata['extension'] === 'pdf') {
			$metadata = $this->pdfParserService->getMetadata($node);
		}
		return $metadata;
	}

	private function deleteIdentifyMethodIfNotExits(array $users, int $fileId): void {
		$file = $this->fileMapper->getById($fileId);
		$signRequests = $this->signRequestMapper->getByFileId($fileId);
		foreach ($signRequests as $key => $signRequest) {
			$identifyMethods = $this->identifyMethod->getIdentifyMethodsFromSignRequestId($signRequest->getId());
			if (empty($identifyMethods)) {
				$this->unassociateToUser($file->getNodeId(), $signRequest->getId());
				continue;
			}
			foreach ($identifyMethods as $methodName => $list) {
				foreach ($list as $method) {
					$exists[$key]['identify'][$methodName] = $method->getEntity()->getIdentifierValue();
					if (!$this->identifyMethodExists($users, $method)) {
						$this->unassociateToUser($file->getNodeId(), $signRequest->getId());
						continue 3;
					}
				}
			}
		}
	}

	private function identifyMethodExists(array $users, IIdentifyMethod $identifyMethod): bool {
		foreach ($users as $user) {
			if (!empty($user['identifyMethods'])) {
				foreach ($user['identifyMethods'] as $data) {
					if ($identifyMethod->getEntity()->getIdentifierKey() !== $data['method']) {
						continue;
					}
					if ($identifyMethod->getEntity()->getIdentifierValue() === $data['value']) {
						return true;
					}
				}
			} else {
				foreach ($user['identify'] as $method => $value) {
					if ($identifyMethod->getEntity()->getIdentifierKey() !== $method) {
						continue;
					}
					if ($identifyMethod->getEntity()->getIdentifierValue() === $value) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @return SignRequestEntity[]
	 *
	 * @psalm-return list<SignRequestEntity>
	 */
	private function associateToSigners(array $data, int $fileId): array {
		$return = [];
		if (!empty($data['users'])) {
			$this->deleteIdentifyMethodIfNotExits($data['users'], $fileId);
			foreach ($data['users'] as $user) {
				if (isset($user['identifyMethods'])) {
					foreach ($user['identifyMethods'] as $identifyMethod) {
						$return[] = $this->associateToSigner(
							identifyMethods: [
								$identifyMethod['method'] => $identifyMethod['value'],
							],
							displayName: $user['displayName'] ?? '',
							description: $user['description'] ?? '',
							notify: $user['notify'] ?? true,
							fileId: $fileId,
						);
					}
				} else {
					$return[] = $this->associateToSigner(
						identifyMethods: $user['identify'],
						displayName: $user['displayName'] ?? '',
						description: $user['description'] ?? '',
						notify: $user['notify'] ?? true,
						fileId: $fileId,
					);
				}
			}
		}
		return $return;
	}

	private function associateToSigner(array $identifyMethods, string $displayName, string $description, bool $notify, int $fileId): SignRequestEntity {
		$identifyMethodsIncances = $this->identifyMethod->getByUserData($identifyMethods);
		$signRequest = $this->getSignRequestByIdentifyMethod(
			current($identifyMethodsIncances),
			$fileId
		);
		$displayName = $this->getDisplayNameFromIdentifyMethodIfEmpty($identifyMethodsIncances, $displayName);
		$this->setDataToUser($signRequest, $displayName, $description, $fileId);
		$this->saveSignRequest($signRequest);
		foreach ($identifyMethodsIncances as $identifyMethod) {
			$identifyMethod->getEntity()->setSignRequestId($signRequest->getId());
			$identifyMethod->willNotifyUser($notify);
			$identifyMethod->save();
		}
		return $signRequest;
	}

	/**
	 * @param IIdentifyMethod[] $identifyMethodsIncances
	 * @param string $displayName
	 * @return string
	 */
	private function getDisplayNameFromIdentifyMethodIfEmpty(array $identifyMethodsIncances, string $displayName): string {
		if (!empty($displayName)) {
			return $displayName;
		}
		foreach ($identifyMethodsIncances as $identifyMethod) {
			if ($identifyMethod->getName() === 'account') {
				return $this->userManager->get($identifyMethod->getEntity()->getIdentifierValue())->getDisplayName();
			}
		}
		foreach ($identifyMethodsIncances as $identifyMethod) {
			if ($identifyMethod->getName() !== 'account') {
				return $identifyMethod->getEntity()->getIdentifierValue();
			}
		}
		return '';
	}

	private function saveVisibleElements(array $data, FileEntity $file): array {
		if (empty($data['visibleElements'])) {
			return [];
		}
		$elements = $data['visibleElements'];
		foreach ($elements as $key => $element) {
			$element['fileId'] = $file->getId();
			$elements[$key] = $this->fileElementService->saveVisibleElement($element);
		}
		return $elements;
	}

	public function validateNewRequestToFile(array $data): void {
		$this->validateNewFile($data);
		$this->validateUsers($data);
		$this->validateHelper->validateFileStatus($data);
	}

	public function validateNewFile(array $data): void {
		if (empty($data['name'])) {
			throw new \Exception($this->l10n->t('Name is mandatory'));
		}
		$this->validateHelper->validateNewFile($data);
	}

	public function validateUsers(array $data): void {
		if (empty($data['users'])) {
			throw new \Exception($this->l10n->t('Empty users list'));
		}
		if (!is_array($data['users'])) {
			// TRANSLATION This message will be displayed when the request to API with the key users has a value that is not an array
			throw new \Exception($this->l10n->t('User list needs to be an array'));
		}
		foreach ($data['users'] as $user) {
			if (!array_key_exists('identify', $user)) {
				throw new \Exception('Identify key not found');
			}
			$this->identifyMethod->setAllEntityData($user);
		}
	}

	public function saveSignRequest(SignRequestEntity $signRequest): void {
		if ($signRequest->getId()) {
			$this->signRequestMapper->update($signRequest);
		} else {
			$this->signRequestMapper->insert($signRequest);
		}
	}

	/**
	 * @psalm-suppress MixedMethodCall
	 */
	private function setDataToUser(SignRequestEntity $signRequest, string $displayName, string $description, int $fileId): void {
		$signRequest->setFileId($fileId);
		if (!$signRequest->getUuid()) {
			$signRequest->setUuid(UUIDUtil::getUUID());
		}
		if (!empty($displayName)) {
			$signRequest->setDisplayName($displayName);
		}
		if (!empty($description)) {
			$signRequest->setDescription($description);
		}
		if (!$signRequest->getId()) {
			$signRequest->setCreatedAt(time());
		}
	}

	private function getSignRequestByIdentifyMethod(IIdentifyMethod $identifyMethod, int $fileId): SignRequestEntity {
		try {
			$signRequest = $this->signRequestMapper->getByIdentifyMethodAndFileId($identifyMethod, $fileId);
		} catch (DoesNotExistException $e) {
			$signRequest = new SignRequestEntity();
		}
		return $signRequest;
	}

	public function unassociateToUser(int $fileId, int $signRequestId): void {
		$signRequest = $this->signRequestMapper->getByFileIdAndSignRequestId($fileId, $signRequestId);
		try {
			$this->signRequestMapper->delete($signRequest);
			$groupedIdentifyMethods = $this->identifyMethod->getIdentifyMethodsFromSignRequestId($signRequestId);
			foreach ($groupedIdentifyMethods as $identifyMethods) {
				foreach ($identifyMethods as $identifyMethod) {
					$identifyMethod->delete();
				}
			}
			$visibleElements = $this->fileElementMapper->getByFileIdAndSignRequestId($fileId, $signRequestId);
			foreach ($visibleElements as $visibleElement) {
				$this->fileElementMapper->delete($visibleElement);
			}
		} catch (\Throwable $th) {
		}
	}

	public function deleteRequestSignature(array $data): void {
		if (!empty($data['uuid'])) {
			$signatures = $this->signRequestMapper->getByFileUuid($data['uuid']);
			$fileData = $this->fileMapper->getByUuid($data['uuid']);
		} elseif (!empty($data['file']['fileId'])) {
			$signatures = $this->signRequestMapper->getByNodeId($data['file']['fileId']);
			$fileData = $this->fileMapper->getByFileId($data['file']['fileId']);
		} else {
			throw new \Exception($this->l10n->t('Inform or UUID or a File object'));
		}
		foreach ($signatures as $signRequest) {
			$this->signRequestMapper->delete($signRequest);
		}
		$this->fileMapper->delete($fileData);
		$this->fileElementService->deleteVisibleElements($fileData->getId());
	}
}
