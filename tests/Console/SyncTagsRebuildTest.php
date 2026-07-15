<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Tests\Console;

use Bimoo\Console\Command\SyncTagsCommand;
use PHPUnit\Framework\TestCase;

class SyncTagsRebuildTest extends TestCase
{
    public function testFirstRebuildAppendsDotOne(): void
    {
        $existing = ['v5.0.7', 'v5.0.8', 'v5.1.0'];

        $this->assertSame('v5.0.8.1', SyncTagsCommand::nextRebuildTag('v5.0.8', $existing));
    }

    public function testNextRebuildIncrementsHighestSuffix(): void
    {
        $existing = ['v5.0.8', 'v5.0.8.1', 'v5.0.8.2'];

        $this->assertSame('v5.0.8.3', SyncTagsCommand::nextRebuildTag('v5.0.8', $existing));
    }

    public function testSuffixOfLongerVersionDoesNotCollide(): void
    {
        // v5.0.8x tags and non-numeric suffixes must not count as rebuilds.
        $existing = ['v5.0.8', 'v5.0.80', 'v5.0.80.4', 'v5.0.8-beta', 'v5.0.8.x'];

        $this->assertSame('v5.0.8.1', SyncTagsCommand::nextRebuildTag('v5.0.8', $existing));
    }
}
