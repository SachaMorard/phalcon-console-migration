<?php


namespace Phalcon\Migrations;

use Phalcon\Db\Profiler;
use Phalcon\Migrations;
use Phalcon\Script\Color;

/**
 * Phalcon\Profiler\DbProfiler
 *
 * Displays transactions made on the database
 */
class DbProfiler extends Profiler
{

    /**
     * @param $profile DbProfiler
     */
    public function beforeStartProfile($profile)
    {
        $sql = $profile->getSQLStatement();
        if ($sql === Migrations::$PREVIOUS_MIG) {
            return;
        }
        Migrations::$PREVIOUS_MIG = $sql;
        if (strpos($sql, 'INSERT INTO `migration`') === 0) {
            return;
        }
        if (strpos($sql, 'INSERT INTO "migration"') === 0) {
            return;
        }
        print '  ' . Color::colorize(str_replace(array("\n", "\t"), " ", $sql) . PHP_EOL, Color::FG_GREEN);
    }

}
