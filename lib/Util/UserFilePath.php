<?php

declare(strict_types=1);

namespace OCA\ShareGate\Util;

/**
 * 将 Nextcloud 内部路径转为用户文件夹下的相对路径。
 */
final class UserFilePath {
	public static function toUserRelative(string $userId, string $storedPath): string {
		$path = trim($storedPath, '/');
		if ($path === '') {
			return '';
		}

		$parts = explode('/', $path);
		$filesIdx = array_search('files', $parts, true);
		if ($filesIdx === false) {
			return $path;
		}

		// 标准：…/files/{userId}/relative/path
		if (isset($parts[$filesIdx + 1]) && $parts[$filesIdx + 1] === $userId) {
			$relative = array_slice($parts, $filesIdx + 2);

			return $relative === [] ? '' : implode('/', $relative);
		}

		// 部分 NC 节点：…/files/relative/path（files 后无 userId 段）
		if (isset($parts[$filesIdx + 1])) {
			return implode('/', array_slice($parts, $filesIdx + 1));
		}

		return $path;
	}
}
