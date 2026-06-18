<?php

declare(strict_types=1);

namespace OCA\ShareGate\Util;

use OCA\ShareGate\Db\Share;
use OCA\ShareGate\Db\ShareMapper;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Resolve the Nextcloud file behind a paid share (file_id first, path fallback).
 */
class ShareFileResolver {
	public function __construct(
		private IRootFolder $rootFolder,
		private ShareMapper $shareMapper,
	) {
	}

	/**
	 * @throws NotFoundException
	 */
	public function resolve(Share $share): File {
		$userId = $share->getCreatedBy();
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$fileId = $share->getFileId();
		if ($fileId > 0) {
			$nodes = $userFolder->getById($fileId);
			$node = $nodes[0] ?? null;
			if ($node instanceof File) {
				return $node;
			}
		}

		$relative = UserFilePath::toUserRelative($userId, $share->getFilePath());
		if ($relative === '') {
			$stored = $share->getFilePath();
			if (preg_match('#/files/(.+)$#', $stored, $matches)) {
				$relative = $matches[1];
			} else {
				$relative = ltrim($stored, '/');
			}
		}

		$node = $userFolder->get($relative);
		if (!$node instanceof File) {
			throw new NotFoundException('文件不存在或不是文件');
		}

		return $node;
	}

	public function tryResolve(Share $share): ?File {
		try {
			return $this->resolve($share);
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Keep cached path/name/size/file_id aligned after rename or move.
	 */
	public function syncDisplayFields(Share $share, File $file): void {
		$path = '/' . ltrim($file->getPath(), '/');
		$name = $file->getName();
		$size = (int)$file->getSize();
		$id = (int)$file->getId();

		$dirty = false;
		if ($id > 0 && $share->getFileId() !== $id) {
			$share->setFileId($id);
			$dirty = true;
		}
		if ($share->getFilePath() !== $path) {
			$share->setFilePath($path);
			$dirty = true;
		}
		if ($share->getFileName() !== $name) {
			$share->setFileName($name);
			$dirty = true;
		}
		if ($share->getFileSize() !== $size) {
			$share->setFileSize($size);
			$dirty = true;
		}

		if (!$dirty) {
			return;
		}

		try {
			$this->shareMapper->update($share);
		} catch (Exception) {
			// metadata sync must not block download
		}
	}
}
