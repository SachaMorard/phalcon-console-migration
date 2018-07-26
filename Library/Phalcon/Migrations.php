<?php

namespace Phalcon;

use Phalcon\Annotations\ModelStrategy;
use Phalcon\Db\Adapter\Cassandra;
use Phalcon\Db\Adapter\Pdo\Mysql;
use Phalcon\Events\Manager;
use Phalcon\Logger\Adapter\File;
use Phalcon\Migrations\DbProfiler;
use Phalcon\Script\Color;
use Phalcon\Migrations\Version;
use Phalcon\Commands\CommandsException;
use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Di\Injectable;

class Migrations extends Injectable
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
     * @var array
     */
    protected $adapters = [];

    /**
     * @var
     */
    protected $migrationAdapter;

    /**
     * @var
     */
    protected $migrationSchemaName;

    /**
     * @var
     */
    public static $PREVIOUS_MIG;


    /**
     * Migrations constructor.
     * @param Config $config
     * @param $migrationsDir
     * @throws \Exception
     */
    public function __construct(\Phalcon\Config $config, $migrationsDir)
    {
        $this->config = $config;
        $this->config->application->dbProfiler = false;
        $this->config->application->debug = true;
        $this->migrationsDir = $migrationsDir;

        $adapters = [];
        try {
            $connection = $this->getDI()->get('dbMysql');
            $this->adapters['dbMysql'] = true;
            $this->migrationAdapter = 'dbMysql';
            $this->migrationSchemaName = $connection->getDescriptor()['dbname'];
        } catch (\Exception $e) {
        }
        try {
            $connection = $this->getDI()->get('dbPostgresql');
            $this->adapters['dbPostgresql'] = true;
            $this->migrationAdapter = 'dbPostgresql';
            $this->migrationSchemaName = $connection->getDescriptor()['schema'];
        } catch (\Exception $e) {
        }
        try {
            $connection = $this->getDI()->get('dbCassandra');
            $this->adapters['dbCassandra'] = true;
        } catch (\Exception $e) {
        }

        if ($this->migrationSchemaName === null) {
            throw new \Exception('You need at least Mysql or Postgresql to enable migration feature');
        }

        if ($this->migrationsDir && !file_exists($this->migrationsDir)) {
            mkdir($this->migrationsDir);
        }
    }

    /**
     * Run Migrations
     *
     * @param $version
     * @throws CommandsException
     */
    public function run($version)
    {
        $versions = array();
        $iterator = new \DirectoryIterator($this->migrationsDir);
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isDir()) {
                $versions[] = str_replace('.php', '', $fileinfo->getFilename());
            }
        }

        /** @var Mysql $connection */
        $connection = $this->getDI()->get($this->migrationAdapter);
        try {
            $lastMig = $connection->fetchOne('SELECT * FROM migration ORDER BY id DESC', \PDO::FETCH_ASSOC);
        } catch (\PDOException $ex) {
            if ($this->migrationAdapter === 'dbMysql') {
                $connection->query('CREATE TABLE migration (`id` INT NOT NULL AUTO_INCREMENT,`version` VARCHAR(45) NOT NULL,`run_at` DATETIME NOT NULL, PRIMARY KEY (`id`))');
            } else {
                $connection->query('CREATE TABLE "public"."migration" ("id" SERIAL NOT NULL,"version" CHARACTER VARYING(45),"run_at" TIMESTAMP,PRIMARY KEY ("id"));');
            }

        }

        if (isset($lastMig)) {
            $fromVersion = $lastMig['version'];
        } else {
            $fromVersion = '0';
        }

        $toZero = false;
        if (count($versions) == 0) {
            throw new CommandsException('Migrations were not found at ' . $this->migrationsDir);
        } else {
            if ($version === null) {
                $version = Version::maximum($versions);
            } else {
                if ($version == '0') {
                    $toZero = true;
                    $versionsSorted = Version::sortAsc($versions);
                    $version = $versionsSorted[0];

                } elseif ($version === 'up') {
                    $versionsSorted = Version::sortAsc($versions);
                    foreach ($versionsSorted as $k => $v) {
                        if ($v === $fromVersion) {
                            $currentK = $k;
                        }
                    }
                    $version = isset($versionsSorted[$currentK + 1]) ? $versionsSorted[$currentK + 1] : Version::maximum($versions);
                } elseif ($version === 'down') {
                    $versionsSorted = Version::sortAsc($versions);
                    foreach ($versionsSorted as $k => $v) {
                        if ($v === $fromVersion) {
                            $currentK = $k;
                        }
                    }
                    $version = isset($versionsSorted[$currentK - 1]) ? $versionsSorted[$currentK - 1] : $versionsSorted[0];
                }
                $migrationPath = $this->migrationsDir . '/' . $version . '.php';
                if (!file_exists($migrationPath)) {
                    throw new CommandsException('Migration class was not found ' . $migrationPath);
                }
            }
        }

        $versionsBetween = Version::between($fromVersion, $version, $versions);
        if ($toZero === true) {
            $theTwoFirstMigrations = [];
            $theTwoFirstMigrations[] = $versionsBetween[count($versionsBetween) - 1];
            $versionsBetween = $theTwoFirstMigrations;
        }


        try {
            $direction = 'up';
            foreach ($versionsBetween as $k => $v) {
                $migrationPath = $this->migrationsDir . '/' . $v['version'] . '.php';
                $this->_migrateFile((string)$v['version'], $migrationPath, $v['direction']);
                if ($v['direction'] === 'down' && $k === 0) {
                    $direction = $v['direction'];
                    continue;
                } else {
                    $connection->insert("migration", array((string)$v['version'], date('Y-m-d H:i:s')), array("version", "run_at"));
                }
                $direction = $v['direction'];

            }
            if (count($versionsBetween) > 0 && $direction === 'down') {
                $connection->insert("migration", array((string)$version, date('Y-m-d H:i:s')), array("version", "run_at"));
            } elseif (count($versionsBetween) === 0) {
                print Color::colorize('No migration to run' . PHP_EOL . PHP_EOL, Color::FG_GREEN);
            }
            exit(0);
        } catch (\Throwable $e){
            print PHP_EOL . Color::error($e->getMessage()) . PHP_EOL;
            exit(1);
        }

    }

    /**
     * Migrate Single File Up or Down
     *
     * @param $version
     * @param $filePath
     * @param string $direction
     * @throws CommandsException
     */
    protected function _migrateFile($version, $filePath, $direction = 'up')
    {
        $classVersion = preg_replace('/[^0-9A-Za-z]/', '', $version);
        $className = 'Migration_' . $classVersion;
        if (file_exists($filePath)) {
            require_once $filePath;
            if (class_exists($className)) {
                $migration = new $className($this->config, $version);
                if ($direction === 'up') {
                    $migration->up();
                    if (method_exists($migration, 'afterUp')) {
                        $migration->afterUp();
                    }
                    print PHP_EOL . Color::success('Upgrade Version ' . $version . ' was successfully migrated') . PHP_EOL;
                } elseif ($direction === 'down') {
                    $migration->down();
                    if (method_exists($migration, 'afterDown')) {
                        $migration->afterDown();
                    }
                    print PHP_EOL . Color::info('Downgrade Version ' . $version . ' was successfully migrated') . PHP_EOL;
                }
            }
        } else {
            throw new CommandsException('Migration class cannot be found ' . $className . ' at ' . $filePath);
        }
    }


    /**
     * Generate Empty Migration file
     */
    public function generate()
    {
        $version = date('YmdHis');

        $this->_generateMigrationFile($version);
        print PHP_EOL . Color::success('Version ' . $version . ' was successfully generated') . PHP_EOL;
    }


    /**
     * Generate Migration file
     *
     * @param $version
     * @param string $contentUp
     * @param string $contentDown
     * @return mixed|string
     */
    protected function _generateMigrationFile($version, $contentUp = "", $contentDown = "")
    {
        $classVersion = preg_replace('/[^0-9A-Za-z]/', '', $version);
        $className = 'Migration_' . $classVersion;
        $classData = "<?php

use Phalcon\Migrations\MigrationScript as Migration;

class " . $className . " extends Migration\n" .
            "{\n\n" .
            "\tpublic function up()\n" .
            "\t{\n" . $contentUp .
            "\n\t}" .
            "\n\n" .
            "\tpublic function down()\n" .
            "\t{\n" . $contentDown .
            "\n\t}" .
            "\n}\n";
        $classData = str_replace("\t", "    ", $classData);

        file_put_contents($this->migrationsDir . '/' . $version . '.php', $classData);

        return $classData;
    }

    /**
     * Migration Status
     */
    public function status()
    {
        $connection = $this->getDI()->get($this->migrationAdapter);

        $lastMig = $connection->fetchOne('SELECT * FROM migration ORDER BY id DESC', \PDO::FETCH_ASSOC);

        print PHP_EOL . Color::info('Current Version : ' . $lastMig['version']) . PHP_EOL;
    }

    /**
     * @throws \ReflectionException
     */
    public function diff()
    {
        $version = date('YmdHis');
        $modelsManager = $this->_getModelsManager();
        $sql = array();
        $globalTablesDetails = array();


        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->application->modelsDir), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isDir()) {
                $modelName = '\\Models\\' . str_replace(['/', '.php'], ['\\', ''], substr($fileinfo->getPathname(), strlen($this->config->application->modelsDir)));

                $reflectionClass = new \ReflectionClass($modelName);
                if ($reflectionClass->isAbstract() === true) {
                    print Color::info('Abstract class ' . $modelName . ' was ignored during the generation of diff') . PHP_EOL;
                    continue;
                }
                $model = $modelsManager->load($modelName, true);

                if ($model->getReadConnectionService() === 'dbMysql') {
                    $tableDetails = $this->_detailMysqlTable($modelName, $model);
                } elseif ($model->getReadConnectionService() === 'dbCassandra') {
                    $tableDetails = $this->_detailCassandraTable($modelName, $model);
                } elseif ($model->getReadConnectionService() === 'dbPostgresql') {
                    $tableDetails = $this->_detailPostgresqlTable($modelName, $model);
                }

                $globalTablesDetails[] = $tableDetails;

                $sqlInstruction = $this->_morphTable($tableDetails['table'], array("columns" => $tableDetails['tableDefinition'], "indexes" => $tableDetails['indexesDefinition']), $tableDetails['foreignKeys'], $tableDetails['dbAdapter']);

                $sql[] = implode("\n        ", str_replace("\t", '', str_replace("\n", '', $sqlInstruction))) . "\n        ";
            }
        }

        /**
         * DROP UNUSED TABLES
         */
        $dropSql = $this->_dropUnusedTables($globalTablesDetails);
        if ($dropSql) {
            foreach ($dropSql as $drop) {
                $sql[] = $drop;
            }
        }

        $this->_generateMigrationFile($version, "        " . implode(array_unique($sql)));
        print PHP_EOL . Color::success('Diff Version ' . $version . ' was successfully generated') . PHP_EOL;

    }

    /**
     * @param $modelName
     * @param $model
     * @return array
     * @throws CommandsException
     */
    protected function _detailMysqlTable($modelName, $model)
    {
        $modelsMetadata = $this->_getModelsMetadata();
        $modelsManager = $this->_getModelsManager();

        $table = $model->getSource();
        $schema = $model->getSchema();
        $foreignKeys = array();
        $tableDefinition = array();

        $oldColumn = null;
        $fieldTypes = $modelsMetadata->getDataTypes($model);
        $notNullFields = $modelsMetadata->getNotNullAttributes($model);
        $identityField = $modelsMetadata->getIdentityField($model);
        $sizesFields = $modelsMetadata->readMetaDataIndex($model, ModelStrategy::METADATA_SIZES_OF_FIELDS);
        $indexesFields = $modelsMetadata->readMetaDataIndex($model, ModelStrategy::METADATA_TABLE_INDEXES);

        // We NEED to load the model before calling the getRelations method if we want it to work properly
        $modelsManager->load(substr($modelName, 1));
        $referencesFields = $modelsManager->getRelations(substr($modelName, 1));

        if ($referencesFields) {
            foreach ($referencesFields as $referenceField) {
                if ($referenceField->getType() === \Phalcon\Mvc\Model\Relation::HAS_MANY) {
                    continue;
                }

                $fields = $referenceField->getFields();
                if (is_string($fields)) {
                    $fields = array($fields);
                }

                $c = $modelsMetadata->readColumnMap($model);
                $relation = array('table' => $table);
                foreach ($fields as $f) {
                    $relation['fields'][] = $c[1][$f];
                }

                $fields = $referenceField->getReferencedFields();
                if (is_string($fields)) {
                    $fields = array($fields);
                }
                $m = $modelsManager->load($referenceField->getReferencedModel());
                $relation['referencedTable'] = $m->getSource();
                $c = $modelsMetadata->readColumnMap($m);
                foreach ($fields as $f) {
                    $relation['referencedFields'][] = $c[1][$f];
                }

                $relation['action'] = $referenceField->getOption('action') ? $referenceField->getOption('action') : 'RESTRICT';

                $defaultName = 'FK_' . strtoupper($relation['table'] . '_' . $relation['referencedTable']);
                $relation['name'] = $referenceField->getOption('name') ? $referenceField->getOption('name') : $defaultName;

                $foreignKeys[] = $relation;
            }
        }


        foreach ($fieldTypes as $fieldName => $type) {
            $fieldDefinition = array();
            $fieldDefinition['type'] = $type;

            if (in_array($fieldName, $notNullFields)) {
                $fieldDefinition['notNull'] = true;
            }

            if (isset($sizesFields[$fieldName])) {
                $fieldDefinition['size'] = $sizesFields[$fieldName];
            }

            if ($identityField === $fieldName) {
                $fieldDefinition['autoIncrement'] = true;
            }

            if ($oldColumn !== null) {
                $fieldDefinition['after'] = $oldColumn;
            } else {
                $fieldDefinition['first'] = true;
            }

            $oldColumn = $fieldName;
            $tableDefinition[] = new Column($fieldName, $fieldDefinition);
        }

        $indexes = $modelsMetadata->getPrimaryKeyAttributes($model);
        $indexesDefinition = array_merge(array(new Index('PRIMARY', $indexes)), $indexesFields);

        return array(
            'table' => $table,
            'schema' => $schema,
            'tableDefinition' => $tableDefinition,
            'indexesDefinition' => $indexesDefinition,
            'foreignKeys' => $foreignKeys,
            'dbAdapter' => 'dbMysql'
        );
    }

    /**
     * @param $modelName
     * @param $model
     * @return array
     */
    protected function _detailPostgresqlTable($modelName, $model)
    {
        $modelsMetadata = $this->_getModelsMetadata();
        $modelsManager = $this->_getModelsManager();

        $table = $model->getSource();
        $schema = $model->getSchema();
        $foreignKeys = array();
        $tableDefinition = array();

        $oldColumn = null;
        $fieldTypes = $modelsMetadata->getDataTypes($model);
        $notNullFields = $modelsMetadata->getNotNullAttributes($model);
        $identityField = $modelsMetadata->getIdentityField($model);
        $sizesFields = $modelsMetadata->readMetaDataIndex($model, ModelStrategy::METADATA_SIZES_OF_FIELDS);
        $indexesFields = $modelsMetadata->readMetaDataIndex($model, ModelStrategy::METADATA_TABLE_INDEXES);

        // We NEED to load the model before calling the getRelations method if we want it to work properly
        $modelsManager->load(substr($modelName, 1));
        $referencesFields = $modelsManager->getRelations(substr($modelName, 1));

        if ($referencesFields) {
            foreach ($referencesFields as $referenceField) {
                if ($referenceField->getType() === \Phalcon\Mvc\Model\Relation::HAS_MANY) {
                    continue;
                }

                $fields = $referenceField->getFields();
                if (is_string($fields)) {
                    $fields = array($fields);
                }

                $c = $modelsMetadata->readColumnMap($model);
                $relation = array('table' => $table);
                foreach ($fields as $f) {
                    $relation['fields'][] = $c[1][$f];
                }

                $fields = $referenceField->getReferencedFields();
                if (is_string($fields)) {
                    $fields = array($fields);
                }
                $m = $modelsManager->load($referenceField->getReferencedModel());
                $relation['referencedTable'] = $m->getSource();
                $c = $modelsMetadata->readColumnMap($m);
                foreach ($fields as $f) {
                    $relation['referencedFields'][] = $c[1][$f];
                }

                $relation['action'] = $referenceField->getOption('action') ? $referenceField->getOption('action') : 'RESTRICT';

                $defaultName = 'FK_' . strtoupper($relation['table'] . '_' . $relation['referencedTable']);
                $relation['name'] = $referenceField->getOption('name') ? $referenceField->getOption('name') : $defaultName;
                $foreignKeys[] = $relation;
            }
        }

        $primaryKeys = $modelsMetadata->getPrimaryKeyAttributes($model);
        foreach ($fieldTypes as $fieldName => $type) {
            $fieldDefinition = array();
            $fieldDefinition['type'] = $type;

            if (in_array($fieldName, $notNullFields)) {
                $fieldDefinition['notNull'] = true;
            }

            if (in_array($fieldName, $primaryKeys)) {
                $fieldDefinition['primary'] = true;
            }

            if (isset($sizesFields[$fieldName])) {
                $fieldDefinition['size'] = $sizesFields[$fieldName];
            }

            if ($identityField === $fieldName) {
                $fieldDefinition['autoIncrement'] = true;
            }

            if ($oldColumn !== null) {
                $fieldDefinition['after'] = $oldColumn;
            } else {
                $fieldDefinition['first'] = true;
            }

            $oldColumn = $fieldName;
            $tableDefinition[] = new Column($fieldName, $fieldDefinition);
        }

        $primaryKeyName = $table . '_pkey';
        $indexesDefinition = array_merge(array(new Index($primaryKeyName, $primaryKeys)), $indexesFields);

        return array(
            'table' => $table,
            'schema' => $schema,
            'tableDefinition' => $tableDefinition,
            'indexesDefinition' => $indexesDefinition,
            'foreignKeys' => $foreignKeys,
            'dbAdapter' => 'dbPostgresql'
        );
    }

    /**
     * @param $modelName
     * @param $model
     * @return array
     * @throws CommandsException
     */
    protected function _detailCassandraTable($modelName, $model)
    {
        $modelsMetadata = $this->_getModelsMetadata();
        $table = $model->getSource();
        $schema = $model->getSchema();
        $tableDefinition = array();
        $fieldTypes = $modelsMetadata->getDataTypes($model);
        $indexesFields = $modelsMetadata->readMetaDataIndex($model, ModelStrategy::METADATA_TABLE_INDEXES);
        $indexes = $modelsMetadata->getPrimaryKeyAttributes($model);
        foreach ($fieldTypes as $fieldName => $type) {
            $fieldDefinition = array();
            $fieldDefinition['type'] = $type;
            if (in_array($fieldName, $indexes)) {
                $fieldDefinition['primary'] = true;
            }

            $tableDefinition[] = new Column($fieldName, $fieldDefinition);
        }

        $indexesDefinition = array_merge(array(new Index('PRIMARY', $indexes)), $indexesFields);

        return array(
            'table' => $table,
            'schema' => $schema,
            'tableDefinition' => $tableDefinition,
            'indexesDefinition' => $indexesDefinition,
            'foreignKeys' => [],
            'dbAdapter' => 'dbCassandra'
        );
    }


    /**
     * Look for table definition modifications and apply to real table
     *
     * @param $tableName
     * @param $definition
     * @param $foreignKeys
     * @return array
     * @throws Exception
     */
    protected function _morphTable($tableName, $definition, $foreignKeys, $dbAdapter)
    {
        $ignoreDropForeignKeys = array();
        /** @var \Phalcon\Db\Adapter $connection */
        $connection = $this->getDI()->get($dbAdapter);
        if ($dbAdapter === 'dbCassandra') {
            $schema = $connection->getDescriptor()['keyspace'];
        } elseif ($dbAdapter === 'dbPostgresql') {
            $schema = $connection->getDescriptor()['schema'];
        } else {
            $schema = $connection->getDescriptor()['dbname'];
        }

        $sql = array();
        $tableExists = $connection->tableExists($tableName, $schema);
        if (isset($definition['columns'])) {

            if (count($definition['columns']) == 0) {
                throw new Exception('Table must have at least one column');
            }

            $fields = array();
            foreach ($definition['columns'] as $tableColumn) {
                if (!is_object($tableColumn)) {
                    throw new Exception('Table must have at least one column');
                }
                $fields[$tableColumn->getName()] = $tableColumn;
            }

            if ($tableExists) {

                $localFields = array();
                $description = $connection->describeColumns($tableName, $schema);
                foreach ($description as $field) {
                    $localFields[$field->getName()] = $field;
                }

                foreach ($fields as $fieldName => $tableColumn) {
                    if ($dbAdapter === 'dbPostgresql') {
                        $schema = $connection->getDescriptor()['schema'];
                    }


                    if (!isset($localFields[$fieldName])) {
                        /**
                         * ADD COLUMN
                         */
                        $rawSql = $connection->getDialect()->addColumn($tableName, $tableColumn->getSchemaName(), $tableColumn);
                        $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                    } else {

                        /**
                         * ALTER TABLE
                         */
                        $changed = false;

                        if ($localFields[$fieldName]->getType() != $tableColumn->getType()) {
                            // If we are on MySQL DB, and the current column in DB is INT(1)
                            // and current annotation is "boolean", we don't notify change
                            // because when we save a boolean column in MySQL it's converted to INT(1)
                            // It prevents executing useless DB updates on each run
                            if ($dbAdapter !== 'dbMysql' || $localFields[$fieldName]->getType() !== \Phalcon\Db\Column::TYPE_INTEGER
                                || $localFields[$fieldName]->getSize() !== 1 || $tableColumn->getType() !== \Phalcon\Db\Column::TYPE_BOOLEAN
                            ) {
                                $changed = true;
                            }
                        }

                        if ($tableColumn->isNotNull() != $localFields[$fieldName]->isNotNull()) {
                            $changed = true;
                        }

                        if ($tableColumn->getSize() && $tableColumn->getSize() != $localFields[$fieldName]->getSize()) {
                            $changed = true;
                        }

                        if ($changed == true) {
                            $existingForeignKeys = [];

                            // We check if there is a foreign key constraint
                            if ($dbAdapter === 'dbMysql') {
                                $results = $connection->query("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '" . $connection->getDescriptor()['dbname'] . "' AND REFERENCED_TABLE_NAME = '" . $tableName . "' AND REFERENCED_COLUMN_NAME = '" . $tableColumn->getName() . "'");
                                foreach ($results->fetchAll() as $r) {
                                    $rules = $connection->query('SELECT UPDATE_RULE, DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_NAME="' . $r['CONSTRAINT_NAME'] . '" AND CONSTRAINT_SCHEMA ="' . $r['TABLE_SCHEMA'] . '"');
                                    $rules = $rules->fetch();
                                    $r['UPDATE_RULE'] = $rules['UPDATE_RULE'];
                                    $r['DELETE_RULE'] = $rules['DELETE_RULE'];

                                    /**
                                     * DROP FOREIGN KEY BECAUSE WE CHANGE THE CURRENT COLUMN
                                     */
                                    $rawSql = $connection->getDialect()->dropForeignKey($r['TABLE_NAME'], $r['TABLE_SCHEMA'], $r['CONSTRAINT_NAME']);
                                    $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                                    $existingForeignKeys[] = $r;
                                }
                            } elseif ($dbAdapter === 'dbPostgresql') {
                                $sqlconstraint = $this->getPGSQLConstraint($tableName, $tableColumn->getName());
                                $results = $connection->query($sqlconstraint);
                                foreach ($results->fetchAll() as $r) {

                                    $r['UPDATE_RULE'] = $r['on_update'];
                                    $r['DELETE_RULE'] = $r['on_delete'];
                                    $r['TABLE_NAME'] = $r['table_name'];
                                    $r['TABLE_SCHEMA'] = $r['constraint_schema'];
                                    $r['CONSTRAINT_NAME'] = $r['constraint_name'];
                                    $r['REFERENCED_TABLE_SCHEMA'] = $r['constraint_schema'];
                                    $r['REFERENCED_TABLE_NAME'] = $r['references_table'];
                                    $r['REFERENCED_COLUMN_NAME'] = $r['references_field'];
                                    $r['COLUMN_NAME'] = $r['column_name'];

                                    /**
                                     * DROP FOREIGN KEY BECAUSE WE CHANGE THE CURRENT COLUMN
                                     */
                                    $rawSql = $connection->getDialect()->dropForeignKey($r['TABLE_NAME'], $r['TABLE_SCHEMA'], $r['CONSTRAINT_NAME']);
                                    $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                                    $existingForeignKeys[] = $r;
                                }
                            }

                            /**
                             * ALTER TABLE
                             */
                            if ($dbAdapter === 'dbPostgresql') {
                                $rawSql = $connection->getDialect()->modifyColumn($tableName, $tableColumn->getSchemaName(), $tableColumn, $localFields[$fieldName]);
                            } else {
                                $rawSql = $connection->getDialect()->modifyColumn($tableName, $tableColumn->getSchemaName(), $tableColumn);
                            }
                            $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';

                            if ($existingForeignKeys) {
                                foreach ($existingForeignKeys as $r) {
                                    /**
                                     * ADD FOREIGN KEY AFTER DROP ONE (TO CHANGE IT)
                                     */
                                    $rawSql = $connection->getDialect()->addForeignKey(
                                        $r['TABLE_NAME'],
                                        $r['TABLE_SCHEMA'],
                                        new Reference(
                                            $r['CONSTRAINT_NAME'],
                                            array(
                                                "referencedSchema" => $r['REFERENCED_TABLE_SCHEMA'],
                                                "referencedTable" => $r['REFERENCED_TABLE_NAME'],
                                                "columns" => array($r['COLUMN_NAME']),
                                                "referencedColumns" => array($r['REFERENCED_COLUMN_NAME']),
                                                'onUpdate' => $r['UPDATE_RULE'],
                                                'onDelete' => $r['DELETE_RULE']
                                            )
                                        ));
                                    $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                                }
                            }
                        }
                    }
                }

                /**
                 * DROP COLUMN (and foreign key)
                 */
                foreach ($localFields as $fieldName => $localField) {
                    if (!isset($fields[$fieldName])) {
                        if ($dbAdapter === 'dbMysql') {
                            // We check if there is a foreign key constraint
                            $results = $connection->query("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '" . $schema . "' AND TABLE_NAME = '" . $tableName . "' AND COLUMN_NAME = '" . $fieldName . "'");
                            foreach ($results->fetchAll() as $r) {
                                $ignoreDropForeignKeys[] = $r['CONSTRAINT_NAME'];
                                $rawSql = $connection->getDialect()->dropForeignKey($r['TABLE_NAME'], $r['TABLE_SCHEMA'], $r['CONSTRAINT_NAME']);
                                $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                            }
                        } elseif ($dbAdapter === 'dbPostgresql') {
                            $sqlconstraint = $this->getPGSQLConstraint($tableName, $fieldName);
                            $results = $connection->query($sqlconstraint);
                            foreach ($results->fetchAll() as $r) {
                                $ignoreDropForeignKeys[] = $r['CONSTRAINT_NAME'];
                                $rawSql = $connection->getDialect()->dropForeignKey($r['table_name'], $r['constraint_schema'], $r['constraint_name']);
                                $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                            }
                        }
                        $rawSql = $connection->getDialect()->dropColumn($tableName, $schema, $fieldName);
                        $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                    }
                }
            } else {
                /**
                 * CREATE TABLE IF NOT EXISTS
                 */
                $rawSql = $connection->getDialect()->createTable($tableName, $schema, $definition);
                if ($dbAdapter === 'dbPostgresql') {
                    $sqlInstructions = explode(';', $rawSql);
                    foreach ($sqlInstructions as $instruction) {
                        if ($instruction !== "" && strpos($instruction, '_pkey" ON') === false) {
                            $sql[] = '$this->' . $dbAdapter . '->query(\'' . $instruction . '\');';
                        }
                    }
                } else {
                    $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                }

            }
        }

        /**
         * DROP FOREIGN KEY
         */
        if ($tableExists === true && ($dbAdapter === 'dbMysql' || $dbAdapter === 'dbPostgresql')) {
            $actualReferences = $connection->describeReferences($tableName, $schema);
            /* @var $actualReference \Phalcon\Db\Reference */
            foreach ($actualReferences as $actualReference) {
                $foreignKeyExists = false;

                for ($i = count($foreignKeys) - 1; $i >= 0; --$i) {
                    if ($dbAdapter === 'dbMysql') {
                        $rules = $connection->query('SELECT UPDATE_RULE, DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_NAME="' . $actualReference->getName() . '" AND CONSTRAINT_SCHEMA ="' . $actualReference->getReferencedSchema() . '"');
                        $rules = $rules->fetch();

                        if ($tableName === $foreignKeys[$i]['table']
                            && $actualReference->getReferencedTable() === $foreignKeys[$i]['referencedTable']
                            && count(array_diff($actualReference->getColumns(), $foreignKeys[$i]['fields'])) === 0
                            && count(array_diff($actualReference->getReferencedColumns(), $foreignKeys[$i]['referencedFields'])) === 0
                            // TODO : réactiver cette ligne si Phalcon prend en compte la méthode : && $actualReference->getOnUpdate() === $foreignKeys[$i]['action']
                            && $rules['UPDATE_RULE'] === $foreignKeys[$i]['action']
                            // TODO : réactiver cette ligne si Phalcon prend en compte la méthode : && $actualReference->getOnDelete() === $foreignKeys[$i]['action']) {
                            && $rules['DELETE_RULE'] === $foreignKeys[$i]['action']
                        ) {
                            $foreignKeyExists = true;
                            array_splice($foreignKeys, $i, 1);
                            break;
                        }
                    } else {
                        if ($tableName === $foreignKeys[$i]['table']
                            && $actualReference->getReferencedTable() === $foreignKeys[$i]['referencedTable']
                            && count(array_diff($actualReference->getColumns(), $foreignKeys[$i]['fields'])) === 0
                            && count(array_diff($actualReference->getReferencedColumns(), $foreignKeys[$i]['referencedFields'])) === 0
                        ) {
                            $foreignKeyExists = true;
                            array_splice($foreignKeys, $i, 1);
                            break;
                        }
                    }
                }

                if (!$foreignKeyExists && !in_array($actualReference->getName(), $ignoreDropForeignKeys)) {
                    $rawSql = $connection->getDialect()->dropForeignKey(
                        $tableName,
                        $actualReference->getReferencedSchema(),
                        $actualReference->getName()
                    );
                    $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                }
            }
        }

        /**
         * ADD FOREIGN KEY
         */
        if ($foreignKeys) {
            foreach ($foreignKeys as $foreignKey) {
                $rawSql = $connection->getDialect()->addForeignKey(
                    $tableName,
                    $connection->getDescriptor()['dbname'],
                    new Reference(
                        $foreignKey['name'],
                        array(
                            "referencedSchema" => $connection->getDescriptor()['dbname'],
                            "referencedTable" => $foreignKey['referencedTable'],
                            "columns" => $foreignKey['fields'],
                            "referencedColumns" => $foreignKey['referencedFields'],
                            'onUpdate' => $foreignKey['action'],
                            'onDelete' => $foreignKey['action']
                        )
                    ));
                $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
            }
        }

        /**
         * INDEXES
         */
        if (isset($definition['indexes'])) {
            if ($tableExists == true) {

                $indexes = array();
                foreach ($definition['indexes'] as $tableIndex) {
                    $indexes[$tableIndex->getName()] = $tableIndex;
                }

                if ($dbAdapter === 'dbPostgresql') {
                    $rawSql = $connection->getDialect()->modifyColumn($tableName, $tableColumn->getSchemaName(), $tableColumn, $localFields[$fieldName]);
                }

                $localIndexes = array();
                $actualIndexes = $connection->describeIndexes($tableName, $schema);
                foreach ($actualIndexes as $actualIndex) {
                    $deleted = true;

                    foreach ($definition['indexes'] as $tableIndex) {
                        // hack for encoging problem
                        $tableIndexName = $tableIndex->getName();
                        $actualIndexName = $actualIndex->getName();
                        if ($tableIndexName === $actualIndexName) {
                            $deleted = false;
                            $localIndexes[$actualIndex->getName()] = $actualIndex->getColumns();
                            break;
                        } elseif (substr($actualIndex->getName(), 0, 3) !== 'IDX' && ($dbAdapter !== 'dbCassandra') && ($dbAdapter !== 'dbPostgresql')) {
                            $deleted = false;
                            break;
                        }
                    }


                    if ($deleted) {
                        $rawSql = $connection->getDialect()->dropIndex($tableName, $tableColumn->getSchemaName(), $actualIndexName);
                        $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                    }
                }

                foreach ($definition['indexes'] as $tableIndex) {
                    $tableIndexName = $tableIndex->getName();
                    if (!isset($localIndexes[$tableIndexName])) {
                        if ($tableIndexName == 'PRIMARY') {
                            $rawSql = $connection->getDialect()->addPrimaryKey($tableName, $tableColumn->getSchemaName(), $tableIndex);
                            $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                        } else {
                            $rawSql = $connection->getDialect()->addIndex($tableName, $tableColumn->getSchemaName(), $tableIndex);
                            $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                        }
                    } else {
                        $changed = false;
                        if (count($tableIndex->getColumns()) != count($localIndexes[$tableIndexName])) {
                            $changed = true;
                        } else {
                            foreach ($tableIndex->getColumns() as $columnName) {
                                if (!in_array($columnName, $localIndexes[$tableIndexName])) {
                                    $changed = true;
                                    break;
                                }
                            }
                        }
                        if ($changed == true) {
                            if ($tableIndex->getName() == 'PRIMARY') {
                                $rawSql = $connection->getDialect()->dropPrimaryKey($tableName, $tableColumn->getSchemaName());
                                $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';

                                $rawSql = $connection->getDialect()->addPrimaryKey($tableName, $tableColumn->getSchemaName(), $tableIndex);
                                $sql[] = '$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');';
                            }
                        }
                    }
                }
            }

        }

        return $sql;

    }

    /**
     * @param $tableName
     * @param $fieldName
     * @return string
     */
    public function getPGSQLConstraint($tableName, $fieldName)
    {
        $sqlconstraint = <<<EOT
SELECT 
tc.constraint_name, 
tc.constraint_schema, 
tc.table_name, 
kcu.column_name, 
rc.update_rule AS on_update, 
rc.delete_rule AS on_delete,
ccu.table_name AS references_table,
ccu.column_name AS references_field
FROM information_schema.table_constraints tc
LEFT JOIN information_schema.key_column_usage kcu
  ON tc.constraint_catalog = kcu.constraint_catalog
  AND tc.constraint_schema = kcu.constraint_schema
  AND tc.constraint_name = kcu.constraint_name
LEFT JOIN information_schema.referential_constraints rc
  ON tc.constraint_catalog = rc.constraint_catalog
  AND tc.constraint_schema = rc.constraint_schema
  AND tc.constraint_name = rc.constraint_name
LEFT JOIN information_schema.constraint_column_usage ccu
  ON rc.unique_constraint_catalog = ccu.constraint_catalog
  AND rc.unique_constraint_schema = ccu.constraint_schema
  AND rc.unique_constraint_name = ccu.constraint_name
WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name='$tableName' AND kcu.column_name='$fieldName'
EOT;
        return $sqlconstraint;
    }

    /**
     * @param $tableDetails
     * @return array
     */
    protected function _dropUnusedTables($tableDetails)
    {
        $sql = array();


        foreach ($this->adapters as $dbAdapter => $bool) {
            $connection = $this->getDI()->get($dbAdapter);
            if ($dbAdapter === 'dbPostgresql') {
                $schema = $connection->getDescriptor()['schema'];
            } elseif ($dbAdapter === 'dbCassandra') {
                $schema = $connection->getDescriptor()['keyspace'];
            } else {
                $schema = $connection->getDescriptor()['dbname'];
            }
            $existingTables = $connection->listTables();

            foreach ($existingTables as $existingTable) {
                if ($existingTable === 'migration') {
                    continue;
                }

                $tableDropped = true;
                foreach ($tableDetails as $tableDetail) {
                    if ($tableDetail['table'] === $existingTable) {
                        $tableDropped = false;
                        break;
                    }
                }

                if ($tableDropped) {
                    $rawSql = $connection->getDialect()->dropTable($existingTable, $schema);

                    $sqlInstruction = ['$this->' . $dbAdapter . '->query(\'' . $rawSql . '\');'];
                    $sql[] = implode("\n        ", str_replace("\t", '', str_replace("\n", '', $sqlInstruction))) . "\n        ";
                }
            }
        }

        return $sql;
    }

    /**
     * @return \Phalcon\Mvc\Model\Manager
     */
    protected function _getModelsManager()
    {
        return $this->getDI()->get('modelsManager');
    }

    /**
     * @return \Phalcon\Mvc\Model\MetaData\Memory
     */
    protected function _getModelsMetadata()
    {
        return $this->getDI()->get('modelsMetadata');
    }
}
