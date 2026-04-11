<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Tests\Git;

use Bimoo\Git\MoodleBranchManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class MoodleBranchManagerTest extends TestCase
{
    public function testFilterBranchesMatchesPattern(): void
    {
        $branches = [
            'MOODLE_401_STABLE',
            'MOODLE_402_STABLE',
            'MOODLE_403_STABLE',
            'MOODLE_404_STABLE',
            'MOODLE_405_STABLE',
            'MOODLE_500_STABLE',
            'MOODLE_501_STABLE',
            'master',
            'integration',
        ];

        $result = MoodleBranchManager::filterBranches($branches, 'MOODLE_40*');

        $this->assertCount(5, $result);
        $this->assertContains('MOODLE_401_STABLE', $result);
        $this->assertContains('MOODLE_405_STABLE', $result);
        $this->assertNotContains('master', $result);
    }

    public function testFilterBranchesDefaultIncludesStableAndMaster(): void
    {
        $branches = [
            'MOODLE_401_STABLE',
            'MOODLE_405_STABLE',
            'master',
            'integration',
            'some-feature',
        ];

        $result = MoodleBranchManager::filterBranches($branches, '*');

        $this->assertContains('MOODLE_401_STABLE', $result);
        $this->assertContains('MOODLE_405_STABLE', $result);
        $this->assertContains('master', $result);
        $this->assertNotContains('integration', $result);
        $this->assertNotContains('some-feature', $result);
    }

    public function testBuildCommitMessage(): void
    {
        $message = MoodleBranchManager::buildCommitMessage('MOODLE_405_STABLE');

        $this->assertStringContainsString('MOODLE_405_STABLE', $message);
        $this->assertStringContainsString('bimoo', $message);
    }

    public function testFilterTagsByMinVersion(): void
    {
        $tags = [
            'v3.9.0', 'v3.11.2', 'v4.0.0', 'v4.0.1',
            'v4.3.0', 'v4.3.2', 'v5.0.0', 'v5.1.0',
            'weekly-release', 'v4.0.0-rc1',
        ];

        $result = MoodleBranchManager::filterTags($tags, '4.0.0');

        $this->assertContains('v4.0.0', $result);
        $this->assertContains('v4.0.1', $result);
        $this->assertContains('v4.3.0', $result);
        $this->assertContains('v5.0.0', $result);
        $this->assertContains('v5.1.0', $result);
        $this->assertNotContains('v3.9.0', $result);
        $this->assertNotContains('v3.11.2', $result);
        $this->assertNotContains('weekly-release', $result);
        $this->assertNotContains('v4.0.0-rc1', $result);
    }

    public function testFilterTagsSortedByVersion(): void
    {
        $tags = ['v5.0.0', 'v4.3.2', 'v4.0.0', 'v4.3.0', 'v4.0.1'];

        $result = MoodleBranchManager::filterTags($tags, '4.0.0');

        $this->assertSame(['v4.0.0', 'v4.0.1', 'v4.3.0', 'v4.3.2', 'v5.0.0'], $result);
    }

    public function testTagToBranch(): void
    {
        $this->assertSame('MOODLE_400_STABLE', MoodleBranchManager::tagToBranch('v4.0.0'));
        $this->assertSame('MOODLE_400_STABLE', MoodleBranchManager::tagToBranch('v4.0.1'));
        $this->assertSame('MOODLE_403_STABLE', MoodleBranchManager::tagToBranch('v4.3.2'));
        $this->assertSame('MOODLE_500_STABLE', MoodleBranchManager::tagToBranch('v5.0.0'));
        $this->assertSame('MOODLE_501_STABLE', MoodleBranchManager::tagToBranch('v5.1.0'));
        $this->assertNull(MoodleBranchManager::tagToBranch('invalid'));
        $this->assertNull(MoodleBranchManager::tagToBranch('weekly-release'));
    }

    public function testFilterTagsWithHigherMinVersion(): void
    {
        $tags = ['v4.0.0', 'v4.1.0', 'v4.2.0', 'v4.3.0', 'v5.0.0'];

        $result = MoodleBranchManager::filterTags($tags, '4.3.0');

        $this->assertCount(2, $result);
        $this->assertContains('v4.3.0', $result);
        $this->assertContains('v5.0.0', $result);
        $this->assertNotContains('v4.0.0', $result);
    }

    public function testWriteAndReadMoodleSha(): void
    {
        $fs = new Filesystem();
        $tempDir = sys_get_temp_dir() . '/bimoo_sha_test_' . uniqid();
        $fs->mkdir($tempDir);

        try {
            $manager = new MoodleBranchManager('', '', $tempDir);
            $sha = 'abc123def456789';
            $manager->writeMoodleSha($tempDir, $sha);

            $this->assertFileExists($tempDir . '/.bimoo-moodle-sha');
            $this->assertSame($sha, trim(file_get_contents($tempDir . '/.bimoo-moodle-sha')));
        } finally {
            $fs->remove($tempDir);
        }
    }
}
