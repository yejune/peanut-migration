<?php
/**
 * MigrationLib
 *
 * @package    MigrationLib
 */
namespace MigrationLib;

/**
 * Migration Class
 *
 * @author kohkimakimoto <kohki.makimoto@gmail.com>
 */
class Migration
{
  const VERSION = '1.0.0';
  const DEFAULT_CONFIG_FILE = 'migrate.php';

  protected $config;
  protected $arguments;
  protected $command;

  protected $logger;

  protected $conns = array();
  protected $cli_bases = array();


  public function __construct($config = array())
  {
    $this->config = new Config($config);
    $this->initialize();

    $this->logger = new Logger($this->config);
  }

  protected function initialize()
  {
    $config_file = $this->config->get('config_file');
    if ($config_file) {
      if (file_exists($config_file)) {
        $this->config->merge(array_merge(include $config_file, $this->config->getAll()));
      }
    }
  }

  /**
   * Facade method for cli executing.
   * @param unknown $task
   * @param unknown $options
   */
  public function execute($command, $arguments)
  {
    $this->command   = $command;
    $this->arguments = $arguments;

    if ($this->command == 'help') {

      $this->help();

    } elseif ($this->command == 'init') {

      $this->init();

    } elseif ($this->command == 'config') {

        $this->listConfig();

    } elseif ($this->command == 'status') {

      $this->status();

    } elseif ($this->command == 'create') {

      $this->create();

    } elseif ($this->command == 'migrate') {

      $this->migrate();

    } elseif ($this->command == 'up') {

      $this->up();

    } elseif ($this->command == 'down') {

      $this->down();

    } else {
      throw new Exception('Unknown command: '.$this->command);
    }
  }

  /**
   * Run Helps Command
   */
  public function help()
  {
    $this->logger->write("MigrationLib is a minimum migration library and command line tool for MySQL. version ".self::VERSION);
    $this->logger->write("");
    $this->logger->write("Copyright (c) Kohki Makimoto <kohki.makimoto@gmail.com>");
    $this->logger->write("Apache License 2.0");
    $this->logger->write("");
    $this->logger->write("Usage");
    $this->logger->write("  phpmigrate [-h|-d|-c] COMMAND");
    $this->logger->write("");
    $this->logger->write("Options:");
    $this->logger->write("  -d         : Switch the debug mode to output log on the debug level.");
    $this->logger->write("  -h         : List available command line options (this page).");
    $this->logger->write("  -f=FILE    : Specify to load configuration file (default migrate.php).");
    $this->logger->write("  -c         : List configurations.");
    $this->logger->write("");
    $this->logger->write("Commands:");
    $this->logger->write("  create NAME                   : Create new skeleton migration task file.");
    $this->logger->write("  status [DATABASENAME ...]     : List the migrations yet to be executed.");
    $this->logger->write("  migrate [DATABASENAME ...]    : Execute the next migrations up.");
    $this->logger->write("  up [DATABASENAME ...]         : Execute the next migration up.");
    $this->logger->write("  down [DATABASENAME ...]       : Execute the next migration down.");
    $this->logger->write("");
  }

  /**
   * List config
   */
  public function listConfig()
  {
    $largestLength = Utils::arrayKeyLargestLength($this->config->getAllOnFlatArray());
    $this->logger->write("");
    $this->logger->write("Configurations :");
    foreach ($this->config->getAllOnFlatArray() as $key => $val) {
      if ($largestLength === strlen($key)) {
        $sepalator = str_repeat(" ", 0);
      } else {
        $sepalator = str_repeat(" ", $largestLength - strlen($key));
      }

      $message = "  [".$key."] ".$sepalator;
      if (is_array($val)) {
        $message .= "=> array()";
      } else {
        $message .= "=> ".$val;
      }
      $this->logger->write($message);
    }
    $this->logger->write("");
  }

  /**
   * Run Status Command
   */
  public function status()
  {
    $databases = $this->getValidDatabases($this->arguments);
    foreach ($databases as $database) {
      $version = $this->getSchemaVersion($database);
      if ($version !== null) {
        $this->logger->write("[".$database."] Current schema version is ".$version);
      }

      $files = $this->getValidMigrationUpFileList($version);
      if (count($files) === 0) {
        $this->logger->write("[".$database."] Already up to date.");
        continue;
      }

      $this->logger->write("[".$database."] Your migrations yet to be executed are below.");
      $this->logger->write("");
      foreach ($files as $file) {
        $this->logger->write(basename($file));
      }
      $this->logger->write("");
    }

  }

  /**
   * Run Create Command
   */
  public function create()
  {
    if (count($this->arguments) > 0) {
      $name = $this->arguments[0];
    } else {
      throw new Exception("You need to pass the argument for migration name. (ex php ".basename(__FILE__)." create foo");
    }

    $timestamp = new \DateTime();
    $filename = $timestamp->format('YmdHis')."_".$name.".php";
    $filepath = __DIR__."/".$filename;
    $camelize_name = Utils::camelize($name);

    $content = <<<EOF
<?php
/**
 * Migration Task class.
 */
class $camelize_name
{
  public function preUp()
  {
      // add the pre-migration code here
  }

  public function postUp()
  {
      // add the post-migration code here
  }

  public function preDown()
  {
      // add the pre-migration code here
  }

  public function postDown()
  {
      // add the post-migration code here
  }

  /**
   * Return the SQL statements for the Up migration
   *
   * @return string The SQL string to execute for the Up migration.
   */
  public function getUpSQL()
  {
     return "";
  }

  /**
   * Return the SQL statements for the Down migration
   *
   * @return string The SQL string to execute for the Down migration.
   */
  public function getDownSQL()
  {
     return "";
  }

}
EOF;

    file_put_contents($filename, $content);

    $this->logger->write("Created ".$filename);
  }

  /**
   * Run Migrate Command
   */
  public function migrate()
  {
    $databases = $this->getValidDatabases($this->arguments);
    foreach ($databases as $database) {
      $version = $this->getSchemaVersion($database);

      if ($version !== null) {
        $this->logger->write("[".$database."] Current schema version is ".$version);
      }

      $files = $this->getValidMigrationUpFileList($version);
      if (count($files) === 0) {
        $this->logger->write("[".$database."] Already up to date.");
        continue;
      }

      foreach ($files as $file) {
        $this->migrateUp($file, $database);
      }
    }
  }

  /**
   * Run Up Command
   */
  public function up()
  {
    $databases = $this->getValidDatabases($this->arguments);
    foreach ($databases as $database) {
      $version = $this->getSchemaVersion($database);

      if ($version !== null) {
        $this->logger->write("[".$database."] Current schema version is ".$version);
      }

      $files = $this->getValidMigrationUpFileList($version);
      if (count($files) === 0) {
        $this->logger->write("[".$database."] Already up to date.");
        continue;
      }

      $this->migrateUp($files[0], $database);
    }
  }

  /**
   * Run Down Command
   */
  public function down()
  {
    $databases = $this->getValidDatabases($this->arguments);
    foreach ($databases as $database) {
      $version = $this->getSchemaVersion($database);

      if ($version !== null) {
        $this->logger->write("[".$database."] Current schema version is ".$version);
      }

      $files = $this->getValidMigrationDownFileList($version);
      if (count($files) === 0) {
        $this->logger->write("[".$database."] Not found older migration files than current schema version.");
        continue;
      }

      $prev_version = null;
      if (isset($files[1])) {
        preg_match("/(\d+)_(.*)\.php$/", basename($files[1]), $matches);
        $prev_version    = $matches[1];
      }

      $this->migrateDown($files[0], $prev_version, $database);
    }

  }

  public function init()
  {
    $cwd = getcwd();
    $configpath = $cwd.'/'.self::DEFAULT_CONFIG_FILE;
    if (file_exists($configpath)) {
      throw new Exception("Exists $configpath");
    }

    $cotent = <<<END
<?php
return array(
  'databases' => array(
    'yourdatabase' => array(
      // PDO Connection settings.
      'database_dsn'      => 'mysql:dbname=yourdatabase;host=localhost',
      'database_user'     => 'user',
      'database_password' => 'password',

      // mysql client command settings.
      'mysql_command_enable'    => true,
      'mysql_command_cli'       => "/usr/bin/mysql",
      'mysql_command_tmpsqldir' => "/tmp",
      'mysql_command_host'      => "localhost",
      'mysql_command_user'      => "user",
      'mysql_command_password'  => "password",
      'mysql_command_database'  => "yourdatabase",
      'mysql_command_options'   => "--default-character-set=utf8",

      // schema version table
      'schema_version_table' => 'schema_version'
    ),
  ),
);

END;

    file_put_contents($configpath, $cotent);
    $this->logger->write("Create configuration file to $configpath");
  }

  protected function migrateUp($file, $database)
  {
    $this->logger->write("[".$database."] Proccesing migrate up by ".basename($file)."");

    require_once $file;

    preg_match("/(\d+)_(.*)\.php$/", basename($file), $matches);
    $version    = $matches[1];
    $class_name = Utils::camelize($matches[2]);

    $migrationInstance = new $class_name();

    if (method_exists($migrationInstance, 'preUp')) {
      $migrationInstance->preUp();
    }

    $sql = $migrationInstance->getUpSQL();
    if (!empty($sql)) {
      if ($this->isCliExecution($database)) {
        // cli
        $this->execUsingCli($sql, $database);

      } else {
        // pdo
        $conn = $this->getConnection($database);
        $conn->exec($sql);
      }
    }

    if (method_exists($migrationInstance, 'postUp')) {
      $migrationInstance->postUp();
    }

    $this->updateSchemaVersion($version, $database);
  }

  protected function migrateDown($file, $prev_version, $database)
  {
    if ($prev_version === null) {
      $prev_version = 0;
    }

    $this->logger->write("[".$database."] Proccesing migrate down to version $prev_version by ".basename($file)."");

    require_once $file;

    preg_match("/(\d+)_(.*)\.php$/", basename($file), $matches);
    $version    = $matches[1];
    $class_name = Utils::camelize($matches[2]);

    $migrationInstance = new $class_name();

    if (method_exists($migrationInstance, 'preDown')) {
      $migrationInstance->preDown();
    }

    $sql = $migrationInstance->getDownSQL();
    if (!empty($sql)) {
      if ($this->isCliExecution($database)) {
        // cli
        $this->execUsingCli($sql, $database);

      } else {
        // pdo
        $conn = $this->getConnection($database);
        $conn->exec($sql);
      }
    }

    if (method_exists($migrationInstance, 'postDown')) {
      $migrationInstance->postDown();
    }

    $this->updateSchemaVersion($prev_version, $database);
  }

  protected function updateSchemaVersion($version, $database)
  {
    if (empty($version)) {
      $version = 0;
    }

    if ($this->isCliExecution($database)) {
      // cli
      $table = $this->config->get('databases/'.$database.'/schema_version_table', 'schema_version');
      $sql = "show tables like '".$table."'";

      $arr = $this->execUsingCli($sql, $database);

      // Create table if it dosen't exist.
      if (count($arr) == 0) {
        $sql =<<<EOF

CREATE TABLE `$table` (
  `version` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`version`) )
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8
COLLATE = utf8_bin;

EOF;
        $this->execUsingCli($sql, $database);
      }

      // Insert initial record if it dosen't exist.
      $sql = "select * from ".$table;
      $arr = $this->execUsingCli($sql, $database);
      if (count($arr) == 0) {
        $sql = "insert into ".$table."(version) values ('$version')";
        $this->execUsingCli($sql, $database);
      }

      // Update version.
      $sql = "update ".$table." set version = '$version'";
      $this->execUsingCli($sql, $database);

    } else {
      // pdo
      $conn = $this->getConnection($database);

      $table = $this->config->get('databases/'.$database.'/schema_version_table', 'schema_version');
      $sql = "show tables like '".$table."'";
      $stmt = $conn->prepare($sql);
      $stmt->execute();

      $arr = $stmt->fetchAll();

      // Create table if it dosen't exist.
      if (count($arr) == 0) {
        $sql =<<<EOF

CREATE TABLE `$table` (
  `version` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`version`) )
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8
COLLATE = utf8_bin;

EOF;
        $stmt = $conn->prepare($sql);
        $stmt ->execute();
      }

      // Insert initial record if it dosen't exist.
      $sql = "select * from ".$table;
      $stmt = $conn->prepare($sql);
      $stmt->execute();
      $arr = $stmt->fetchAll();
      if (count($arr) == 0) {
        $sql = "insert into ".$table."(version) values (:version)";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array(':version' => $version));
      }

      // Update version.
      $sql = "update ".$table." set version = :version";
      $stmt = $conn->prepare($sql);
      $stmt->execute(array(':version' => $version));
    }
  }

  protected function getValidDatabases($databases)
  {
    $valid_databases = array();
    if (!$databases) {
      $valid_databases = $this->getDatabaseNames();
    } else {
      $this->validateDatabaseNames($databases);
      $valid_databases = $this->arguments;
    }
    return $valid_databases;
  }

  protected function getDatabaseNames()
  {
    $database = $this->config->get('databases');
    if (!$database) {
      throw new Exception("Database settings are not found.");
    }

    return array_keys($database);
  }

  protected function validateDatabaseNames($databases)
  {
    $definedDatabaseNames = $this->getDatabaseNames();
    foreach ($databases as $dbname) {
      if (array_search($dbname, $definedDatabaseNames) === false) {
        throw new Exception("Database '".$dbname."' is not defined.");
      }
    }
  }

  protected function getSchemaVersion($database)
  {
    $this->logger->write("Getting schema version from '$database'", "debug");
    if ($this->isCliExecution($database)) {
      // cli
      $table = $this->config->get('databases/'.$database.'/schema_version_table', 'schema_version');
      $sql = "show tables like '".$table."'";

      $arr = $this->execUsingCli($sql, $database);

      // Check to exist table.
      if (count($arr) == 0) {
        $this->logger->write("Table [".$table."] is not found. This schema hasn't been managed yet by PHPMigrate.", "debug");
        return null;
      }

      $sql = "select version from ".$table."";
      $arr = $this->execUsingCli($sql, $database);
      if (count($arr) > 0) {
        return $arr[0];
      } else {
        return null;
      }

    } else {
      // pdo

      $conn = $this->getConnection($database);

      $table = $this->config->get('databases/'.$database.'/schema_version_table', 'schema_version');
      $sql = "show tables like '".$table."'";
      $stmt = $conn->prepare($sql);
      $stmt->execute();

      $arr = $stmt->fetchAll();

      // Check to exist table.
      if (count($arr) == 0) {
        $this->logger->write("Table [".$table."] is not found. This schema hasn't been managed yet by PHPMigrate.", "debug");
        return null;
      }

      $sql = "select version from ".$table."";
      $stmt = $conn->prepare($sql);
      $stmt->execute();

      $arr = $stmt->fetchAll();
      if (count($arr) > 0) {
        return $arr[0]['version'];
      } else {
        return null;
      }
    }
  }


  /**
   * Get PDO connection
   * @return PDO
   */
  protected function getConnection($database)
  {
    if (!@$this->conns[$database]) {
      $dsn      = $this->config->get('databases/'.$database.'/database_dsn');
      $user     = $this->config->get('databases/'.$database.'/database_user');
      $password = $this->config->get('databases/'.$database.'/database_password');

      $this->conns[$database] = new PDO($dsn, $user, $password);
      $this->conns[$database]->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    return $this->conns[$database];
  }


  /**
   * Get mysql command base string.
   * @return Ambigous <string, unknown>
   */
  protected function getCliBase($database)
  {
    if (!@$this->cli_bases[$database]) {
      $this->cli_bases[$database] =
      $this->config->get('databases/'.$database.'/mysql_command_cli', 'mysql')
      ." -u".$this->config->get('databases/'.$database.'/mysql_command_user')
      ." -p".$this->config->get('databases/'.$database.'/mysql_command_password')
      ." -h".$this->config->get('databases/'.$database.'/mysql_command_host')
      ." --batch -N"
          ." ".$this->config->get('databases/'.$database.'/mysql_command_options')
          ." ".$this->config->get('databases/'.$database.'/mysql_command_database')
          ;
    }

    return $this->cli_bases[$database];
  }

  /**
   * Return ture, if it use mysql command to execute migration.
   */
  protected function isCliExecution($database)
  {
    $ret = $this->config->get('databases/'.$database.'/mysql_command_enable', false);
    if ($ret) {
      if (!$this->config->get('databases/'.$database.'/mysql_command_user')) {
        throw new Exception("You are using mysql_command. so config [mysql_command_user] is required.");
      }
      if (!$this->config->get('databases/'.$database.'/mysql_command_host')) {
        throw new Exception("You are using mysql_command. so config [mysql_command_host] is required.");
      }
      if (!$this->config->get('databases/'.$database.'/mysql_command_password')) {
        throw new Exception("You are using mysql_command. so config [mysql_command_password] is required.");
      }
      if (!$this->config->get('databases/'.$database.'/mysql_command_database')) {
        throw new Exception("You are using mysql_command. so config [mysql_command_database] is required.");
      }
    }

    return $ret;
  }

  protected function getTmpSqlFilePath($sql, $database)
  {
    $dir = $this->config->get('databases/'.$database.'/mysql_command_tmpdir', '/tmp');
    $prefix = $database.'_'.md5($sql);
    $uniqid = uniqid();

    $sqlfile = basename(__FILE__).".".$prefix.".".$uniqid.".sql";
    $path = $dir."/".$sqlfile;

    return $path;
  }

  protected function execUsingCli($sql, $database)
  {
    $path = $this->getTmpSqlFilePath($sql, $database);

    $this->logger->write("Executing sql is the following \n".$sql, "debug");
    $this->logger->write("Creating temporary sql file to [".$path."]", "debug");
    file_put_contents($path, $sql);

    $clibase = $this->getCliBase($database);

    $cmd = $clibase." < ".$path."  2>&1";
    $this->logger->write("Executing command is [".$cmd."]", "debug");

    //$output = shell_exec($cmd);
    exec($cmd, $output, $return_var);

    unlink($path);

    if ($return_var !== 0) {
      // SQL Error
      $err = '';
      foreach ($output as $str) {
        $err .= $str."\n";
      }
      throw new Exception($err);
    }

    return $output;
  }



  protected function getValidMigrationUpFileList($version)
  {
    $valid_files = array();

    $files = $this->getMigrationFileList();
    foreach ($files as $file) {
      preg_match ("/^\d+/", basename($file), $matches);
      $timestamp = $matches[0];

      if ($timestamp > $version) {
        $valid_files[] = $file;
      }
    }

    return $valid_files;
  }

  protected function getValidMigrationDownFileList($version)
  {
    $valid_files = array();

    $files = $this->getMigrationFileList();
    rsort($files);
    foreach ($files as $file) {
      preg_match ("/^\d+/", basename($file), $matches);
      $timestamp = $matches[0];

      if ($timestamp <= $version) {
        $valid_files[] = $file;
      }
    }

    return $valid_files;
  }

  protected function getMigrationFileList()
  {
    $files = array();
    $classes = array();
    $gfiles = glob('*');
    foreach ($gfiles as $file) {
      if (preg_match("/^\d+_.+\.php$/", $file)) {

        preg_match("/(\d+)_(.*)\.php$/", basename($file), $matches);
        $version    = $matches[1];
        $class_name = Utils::camelize($matches[2]);

        // Check to exist same class name.
        if (array_key_exists($class_name, $classes)) {
          // Can't use same class name to migration tasks.
          throw new Exception("Can't use same class name to migration tasks. Duplicate migration task name [".$classes[$class_name]."] and [".$file."].");
        }

        $classes[$class_name] = $file;
        $files[] = $file;
      }
    }

    sort($files);
    return $files;
  }

}









