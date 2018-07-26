<?php

namespace Phalcon\Commands\Builtin;

use Phalcon\Script\Color;
use Phalcon\Commands\Command;
use Phalcon\Migrations;

/**
 * Migration Command
 *
 * Generates/Run a migration
 *
 * @package Phalcon\Commands\Builtin
 */
class Migration extends Command
{
    /**
     * @return array
     */
    public function getPossibleParams()
    {
        return [
            'action=s' => 'Generates a Migration [generate|run]',
            'version=s' => "Version to migrate.",
            'help' => 'Shows this help [optional]',
        ];
    }

    /**
     * @param array $parameters
     * @throws \Exception
     */
    public function run(array $parameters)
    {

        $config = $this->getConfig();
        $migrationsDir = $config->application->migrationsDir;

        $action = $this->getOption(['action', 1]);
        $version = $this->getOption('version');

        $mig = new Migrations($config, $migrationsDir);

        switch ($action) {
            case 'gen':
                $mig->generate();
                break;
            case 'run':
                $mig->run($version);
                break;
            case 'up':
                $mig->run('up');
                break;
            case 'down':
                $mig->run('down');
                break;
            case 'diff':
                $mig->diff();
                break;
            case 'status':
                $mig->status();
                break;
        }
    }

    /**
     * @return array
     */
    public function getCommands()
    {
        return ['mig', 'migration'];
    }

    /**
     * @return bool
     */
    public function canBeExternal()
    {
        return true;
    }

    /**
     *
     */
    public function getHelp()
    {
        print Color::head('Help:') . PHP_EOL;
        print Color::colorize('  Migration Commands') . PHP_EOL . PHP_EOL;

        print Color::head('Usage: Generate a Migration') . PHP_EOL;
        print Color::colorize('  mig gen', Color::FG_GREEN) . PHP_EOL . PHP_EOL;

        print Color::head('Usage: Run all available Migrations') . PHP_EOL;
        print Color::colorize('  mig run', Color::FG_GREEN) . PHP_EOL . PHP_EOL;

        print Color::head('Usage: Run just one migration up') . PHP_EOL;
        print Color::colorize('  mig up', Color::FG_GREEN) . PHP_EOL . PHP_EOL;

        print Color::head('Usage: Run just one migration down') . PHP_EOL;
        print Color::colorize('  mig down', Color::FG_GREEN) . PHP_EOL . PHP_EOL;

        print Color::head('Usage: Generate migration file with Diff beetween Models and your Databases') . PHP_EOL;
        print Color::colorize('  mig diff', Color::FG_GREEN) . PHP_EOL . PHP_EOL;

        print Color::head('Usage: Show migration status') . PHP_EOL;
        print Color::colorize('  mig status', Color::FG_GREEN) . PHP_EOL . PHP_EOL;

        print Color::head('Arguments:') . PHP_EOL;
        print Color::colorize('  help', Color::FG_GREEN);
        print Color::colorize("\tShows this help text") . PHP_EOL . PHP_EOL;

        $this->printParameters($this->getPossibleParams());
    }

    /**
     * @return int
     */
    public function getRequiredParams()
    {
        return 1;
    }
}
