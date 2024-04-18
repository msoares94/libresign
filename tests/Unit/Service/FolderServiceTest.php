<?php

namespace OCA\Libresign\Tests\Unit\Service;

use Exception;
use OCA\Libresign\Service\FolderService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

final class FakeFolder implements ISimpleFolder {
	public Folder $folder;

	public function getName(): string {
		return 'fake';
	}

	public function getDirectoryListing(): array {
		return [];
	}

	public function delete(): void {
	}

	public function fileExists(string $name): bool {
		return false;
	}

	public function getFile(string $name): ISimpleFile {
		throw new Exception('fake class');
	}

	public function newFile(string $name, $content = null): ISimpleFile {
		throw new Exception('fake class');
	}

	public function getFolder(string $name): ISimpleFolder {
		throw new Exception('fake class');
	}

	public function newFolder(string $path): ISimpleFolder {
		throw new Exception('fake class');
	}
}

final class FolderServiceTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private IRootFolder|MockObject $root;
	private IUserMountCache|MockObject $userMountCache;
	private IAppDataFactory|MockObject $appDataFactory;
	private IGroupManager|MockObject $groupManager;
	private IAppConfig|MockObject $appConfig;
	private IL10N|MockObject $l10n;

	public function setUp(): void {
		parent::setUp();
		$this->root = $this->createMock(IRootFolder::class);
		$this->userMountCache = $this->createMock(IUserMountCache::class);
		$this->appDataFactory = $this->createMock(IAppDataFactory::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->l10n = $this->createMock(IL10N::class);
	}

	private function getFolderService(int $userId = 171): FolderSErvice {
		$service = new FolderService(
			$this->root,
			$this->userMountCache,
			$this->appDataFactory,
			$this->groupManager,
			$this->appConfig,
			$this->l10n,
			$userId
		);
		return $service;
	}

	public function testGetFileWithInvalidNodeId() {
		$folder = $this->createMock(\OCP\Files\Folder::class);
		$folder->method('isUpdateable')->willReturn(true);
		$this->root
			->method('getUserFolder')
			->willReturn($folder);

		$service = $this->getFolderService();
		$this->expectExceptionMessage('Invalid node');
		$service->getFileById(171);
	}

	public function testGetFolderWithValidNodeId() {
		$this->userMountCache
			->method('getMountsForFileId')
			->willreturn([]);
		$node = $this->createMock(\OCP\Files\File::class);
		$folder = $this->createMock(\OCP\Files\Folder::class);
		$node->method('getParent')
			->willReturn($folder);
		$this->root->method('getUserFolder')
			->willReturn($node);

		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($folder);
		$fakeFolder = new FakeFolder();
		$fakeFolder->folder = $folder;
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')
			->willReturn($fakeFolder);
		$this->appDataFactory->method('get')
			->willReturn($appData);

		$service = $this->getFolderService(1);
		$actual = $service->getFolder(171);
		$this->assertInstanceOf(\OCP\Files\Folder::class, $actual);
	}
}
