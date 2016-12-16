<?php

namespace Phalcon\Migrations;


use Phalcon\Di\Injectable;

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
