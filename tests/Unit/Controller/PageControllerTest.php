<?php

namespace OCA\Libresign\Tests\Unit;

use OCA\Libresign\Controller\PageController;
use OCA\Libresign\Helper\ValidateHelper;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Service\FileService;
use OCA\Libresign\Service\IdentifyMethodService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group DB
 */
final class PageControllerTest extends TestCase {
	private IRequest|MockObject $request;
	private IUserSession|MockObject $userSession;
	private IInitialState|MockObject $initialState;
	private AccountService|MockObject $accountService;
	private IdentifyMethodService|MockObject $identifyMethodService;
	private IAppConfig|MockObject $appConfig;
	private FileService|MockObject $fileService;
	private ValidateHelper|MockObject $validateHelper;
	private IURLGenerator|MockObject $url;
	private PageController $controller;

	public function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->identifyMethodService = $this->createMock(IdentifyMethodService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->validateHelper = $this->createMock(ValidateHelper::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->controller = new PageController(
			$this->request,
			$this->userSession,
			$this->initialState,
			$this->accountService,
			$this->identifyMethodService,
			$this->appConfig,
			$this->fileService,
			$this->validateHelper,
			$this->url
		);
	}

	public function testIndexScriptsAndTemplate() {
		$this->userSession
			->method('getUser')
			->willReturn($this->createMock(IUser::class));
		$response = $this->controller->index();
		$this->assertEquals('main', $response->getTemplateName());
		$scripts = $this->invokePrivate(\OCP\Util::class, 'scripts');
		$this->assertContains('libresign/js/libresign-main', $scripts['libresign']);
	}

	public function testIndexReturnStatus() {
		$this->userSession
			->method('getUser')
			->willReturn($this->createMock(IUser::class));
		$response = $this->controller->index();
		$this->assertEquals(200, $response->getStatus());
	}

	public function testSignScriptsAndTemplate() {
		$response = $this->controller->sign('uuid');
		$this->assertEquals('external', $response->getTemplateName());
		$scripts = $this->invokePrivate(\OCP\Util::class, 'scripts');
		$this->assertContains('libresign/js/libresign-external', $scripts['libresign']);
	}

	public function testSignPolices() {
		$response = $this->controller->sign('uuid');
		$polices = $response->getContentSecurityPolicy();

		$reflectionClass = new \ReflectionClass($polices);
		$property = $reflectionClass->getProperty('allowedFrameDomains');
		$property->setAccessible(true);
		$allowedFrameDomains = $property->getValue($polices);

		$this->assertCount(1, $allowedFrameDomains);
		$this->assertContains("'self'", $allowedFrameDomains);
	}

	public function testSignReturnStatus() {
		$response = $this->controller->sign('uuid');
		$this->assertEquals(200, $response->getStatus());
	}

	public function testGetPdfNotFound() {
		$this->accountService
			->method('getPdfByUuid')
			->willThrowException($this->createMock(DoesNotExistException::class));

		$response = $this->controller->getPdf('uuid');
		$this->assertInstanceOf(\OCP\AppFramework\Http\DataResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
	}

	public function testGetPdfHeader() {
		$file = $this->createMock(\OCP\Files\File::class);
		$this->accountService
			->method('getPdfByUuid')
			->willReturn($file);

		$response = $this->controller->getPdf('sfsdf');
		$headers = $response->getHeaders();
		$this->assertArrayHasKey('Content-Type', $headers);
		$this->assertEquals('application/pdf', $headers['Content-Type']);
	}

	public function testGetPdfStatusCode() {
		$file = $this->createMock(\OCP\Files\File::class);
		$this->accountService
			->method('getPdfByUuid')
			->willReturn($file);

		$response = $this->controller->getPdf('uuid');
		$this->assertEquals(200, $response->getStatus());
	}

	public function testGetPdfUserNotFound() {
		$response = $this->controller->getPdfUser('uuid');
		$this->assertInstanceOf(\OCP\AppFramework\Http\DataResponse::class, $response);
		$this->assertEquals(404, $response->getStatus());
	}

	public function testGetPdfUserHeaderAndStatusCode() {
		$file = $this->createMock(\OCP\Files\File::class);
		$this->accountService
			->method('getPdfByUuid')
			->willReturn($file);
		$this->accountService
			->method('getConfig')
			->willReturn(['sign' => ['pdf' => ['file' => $file]]]);

		$response = $this->controller->getPdfUser('username');
		$headers = $response->getHeaders();
		$this->assertArrayHasKey('Content-Type', $headers);
		$this->assertEquals('application/pdf', $headers['Content-Type']);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testValidationScriptsAndTemplate() {
		$response = $this->controller->validation();
		$this->assertEquals('validation', $response->getTemplateName());
		$scripts = $this->invokePrivate(\OCP\Util::class, 'scripts');
		$this->assertContains('libresign/js/libresign-validation', $scripts['libresign']);
	}

	public function testValidationReturnStatus() {
		$response = $this->controller->validation();
		$this->assertEquals(200, $response->getStatus());
	}

	public function testResetPasswordScriptsAndTemplate() {
		$response = $this->controller->resetPassword();
		$this->assertEquals('reset_password', $response->getTemplateName());
		$scripts = $this->invokePrivate(\OCP\Util::class, 'scripts');
		$this->assertContains('libresign/js/libresign-main', $scripts['libresign']);
	}

	public function testValidationFileScriptsAndTemplate() {
		$response = $this->controller->validationFile('uuid');
		$this->assertEquals('validation', $response->getTemplateName());
		$scripts = $this->invokePrivate(\OCP\Util::class, 'scripts');
		$this->assertContains('libresign/js/libresign-validation', $scripts['libresign']);
	}

	public function testValidationFileReturnStatus() {
		$response = $this->controller->validationFile('uuid');
		$this->assertEquals(200, $response->getStatus());
	}
}
