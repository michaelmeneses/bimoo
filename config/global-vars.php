<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

/**
 * Known Moodle global variables and their types.
 *
 * Used by GlobalVarCollector to generate typed global declarations in stubs.
 */
return [
    '$DB' => '\\moodle_database',
    '$CFG' => '\\stdClass',
    '$USER' => '\\stdClass',
    '$PAGE' => '\\moodle_page',
    '$OUTPUT' => '\\core_renderer',
    '$SESSION' => '\\stdClass',
    '$COURSE' => '\\stdClass',
    '$SITE' => '\\stdClass',
    '$FULLME' => 'string',
    '$ME' => 'string',
];
