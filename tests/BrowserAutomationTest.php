<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XReplyAgent\Domain\BrowserAutomation;

final class BrowserAutomationTest extends TestCase
{
    public function testParsesVisibleMetricsFromBrowserText(): void
    {
        $metrics = BrowserAutomation::parseMetrics('12 Replies 34 Reposts 56 Likes 78 Views 9 Bookmarks');

        $this->assertSame(56, $metrics['likes']);
        $this->assertSame(12, $metrics['replies_received']);
        $this->assertSame(34, $metrics['reposts']);
        $this->assertSame(78, $metrics['impressions']);
        $this->assertSame(9, $metrics['bookmarks']);
    }
}
