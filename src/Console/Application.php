<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Console;

use Bimoo\Console\Command\DiscoverCommand;
use Bimoo\Console\Command\GenerateCommand;
use Bimoo\Console\Command\SyncCommand;
use Bimoo\Console\Command\SyncTagsCommand;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('bimoo', '2.0.0-dev');

        $this->add(new DiscoverCommand());
        $this->add(new GenerateCommand());
        $this->add(new SyncCommand());
        $this->add(new SyncTagsCommand());
    }
}
