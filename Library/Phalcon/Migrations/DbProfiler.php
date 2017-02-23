<?php


namespace Phalcon\Migrations;

use Phalcon\Db\Profiler;
use Phalcon\Script\Color;

/**
 * Phalcon\Profiler\DbProfiler
 *
 * Displays transactions made on the database and the times them taken to execute
 */
class DbProfiler extends Profiler
{

    /**
     * @param $profile DbProfiler
     */
    public function beforeStartProfile($profile)
    {
        if(strpos($profile->getSQLStatement(), 'INSERT INTO `migration`') === 0){
            return;
        }
        print '  ' . Color::colorize(str_replace(array( "\n" , "\t" ) , " " , $profile->getSQLStatement()), Color::FG_GREEN);
    }

    /**
     * @param $profile DbProfiler
     */
    public function afterEndProfile($profile)
    {
        if(strpos($profile->getSQLStatement(), 'INSERT INTO `migration`') === 0){
            return;
        }
        echo ' => Elapsed Time: ' . ($profile->getTotalElapsedSeconds()) . PHP_EOL;
    }

}
