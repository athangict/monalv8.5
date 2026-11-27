<?php
/**
 * Local Configuration Override
 * This configuration override file is for overriding environment-specific and
 * security-sensitive configuration information. Copy this file without the
 * .dist extension at the end and populate values as needed.
 *
 * NOTE: This file is ignored from Git by default with the .gitignore included
 * in laminas-mvc-skeleton. This is a good practice, as it prevents sensitive
 * credentials from accidentally being committed into version control.
 */
use Laminas\DB\Adapter;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Doctirine\DBAL\Driver\PDOMysqlDriver as PDOMysqlDriver;

$env = static function (string $key, $default = null) {
	$value = getenv($key);
	return ($value === false || $value === '') ? $default : $value;
};

return [
    'db'=>[
		'driver'=>'Pdo',
		'dsn'=>sprintf(
			'mysql:dbname=%s;hostname=%s;port=%s;charset=utf8mb4',
			$env('DB_NAME', 'monal_erp_2026'),
			$env('DB_HOST', '127.0.0.1'),
			$env('DB_PORT', '3306')
		),
		'driver-options'=>[
			(defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND) => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
		],
		'username'=> $env('DB_USER', 'root'),
		'password' =>$env('DB_PASS', ''),
	],
]; 