<?php

namespace Phalcon\Migrations;


use Phalcon\Db\Adapter\Cassandra;
use Phalcon\Db\Adapter\Pdo\Mysql;
use Phalcon\Di\Injectable;
use Phalcon\Events\Manager;

class MigrationScript extends Injectable
{
    /**
     * @var \Phalcon\Config
     */
    protected $config;

    /**
     * @var $migrationsDir
     */
    protected $migrationsDir;

    /**
     * @var null
     */
    protected $version;

    /**
     * @var Cassandra
     */
    public $dbCassandra;

    /**
     * @var Mysql
     */
    public $dbMysql;

    /**
     * @param \Phalcon\Config $config
     * @param null $version
     */
    public function __construct(\Phalcon\Config $config, $version = null)
    {
        $this->version = $version;
        $this->config = $config;
        $this->config->application->dbProfiler = true;
        $this->migrationsDir = $this->config->application->migrationsDir;

        if ($this->migrationsDir && !file_exists($this->migrationsDir)) {
            mkdir($this->migrationsDir);
        }

        $this->dbCassandra = $this->getDb('cassandra');
        $this->dbMysql = $this->getDb('mysql');
        $this->dbPostgresql = $this->getDb('postgresql');
    }

    public function getDb($dbName)
    {
        if (strpos($dbName, 'db') === 0) {
            $dbName = $dbName;
        } else {
            $dbName = 'db' . ucfirst($dbName);
        }
        if(!$this->getDI()->has($dbName)){
            return null;
        }
        $db = $this->getDI()->get($dbName);

        $profiler = new DbProfiler();
        $newEventManager = new Manager();

        /** @var Mysql $mysql */
        $eventsManager = $db->getEventsManager();
        if($eventsManager === null){
            $eventsManager = $newEventManager;
            $db->setEventsManager($eventsManager);
        }
        $eventsManager->attach('db', function ($event, $connection) use ($profiler) {

            if ($event->getType() == 'beforeQuery') {
                $profiler->startProfile($connection->getSQLStatement());
            }
            if ($event->getType() == 'afterQuery') {
                $profiler->stopProfile();
            }
        });

        return $db;
    }


    /**
     * Inserts data from a data migration file in a table
     *
     * @param string $tableName
     * @param string $fields
     */
    public function batchInsert(\Phalcon\Db\Adapter $connection, $tableName, $fields)
    {
        $migrationData = $this->migrationsDir.'/'.$this->version.'/'.$tableName.'.dat';
        if (file_exists($migrationData)) {
            $connection->begin();
            $batchHandler = fopen($migrationData, 'r');
            while (($line = fgets($batchHandler)) !== false) {
                $connection->insert($tableName, explode('|', rtrim($line)), $fields, false);
                unset($line);
            }
            fclose($batchHandler);
            $connection->commit();
        }
    }

}
