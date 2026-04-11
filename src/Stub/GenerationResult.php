<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Stub;

class GenerationResult
{
    public function __construct(
        public readonly int $filesProcessed = 0,
        public readonly int $stubsGenerated = 0,
        public readonly int $filesSkipped = 0,
        public readonly int $constantsCollected = 0,
        public readonly int $globalVarsGenerated = 0,
        public readonly int $errors = 0,
    ) {
    }
}
