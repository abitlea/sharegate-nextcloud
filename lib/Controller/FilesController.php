<?php

declare(strict_types=1);

namespace OCA\ShareGate\Controller;

use OCA\ShareGate\Service\PublicLinkService;
use OCA\ShareGate\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class FilesController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicLinkService $publicLinkService,
		private ShareService $shareService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function publicLinks(): DataResponse {
		try {
			$userId = $this->shareService->getCurrentUserId();
			if ($userId === null) {
				return new DataResponse(['success' => false, 'error' => '未登录'], 401);
			}

			$limit = min(200, max(1, (int)$this->request->getParam('limit', 50)));
			$offset = max(0, (int)$this->request->getParam('offset', 0));
			$q = trim((string)$this->request->getParam('q', ''));

			$result = $this->publicLinkService->listFiles($userId, $q, $limit, $offset);
			return new DataResponse($result, ($result['success'] ?? false) ? 200 : 400);
		} catch (\Throwable $e) {
			return new DataResponse([
				'success' => false,
				'error' => '你的共享列表失败: ' . $e->getMessage(),
			], 500);
		}
	}
}
