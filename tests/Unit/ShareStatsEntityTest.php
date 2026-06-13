<?php

declare(strict_types=1);

namespace OCA\ShareGate\Tests\Unit;

use OCA\ShareGate\Db\ShareStats;
use PHPUnit\Framework\TestCase;

class ShareStatsEntityTest extends TestCase {
	public function testDefaultsAreZero(): void {
		$stats = new ShareStats();
		$this->assertSame(0, $stats->getPreviewCount());
		$this->assertSame(0, $stats->getSaveCount());
		$this->assertSame(0, $stats->getDownloadCount());
	}

	public function testSetters(): void {
		$stats = new ShareStats();
		$stats->setShareId('abc123');
		$stats->setPreviewCount(3);
		$stats->setSaveCount(1);
		$stats->setDownloadCount(7);
		$this->assertSame('abc123', $stats->getShareId());
		$this->assertSame(3, $stats->getPreviewCount());
		$this->assertSame(1, $stats->getSaveCount());
		$this->assertSame(7, $stats->getDownloadCount());
	}
}
