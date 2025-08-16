<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Eduframe\Client;
use Eduframe\Connection;

function envx(string $key, $default = null) {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    if (isset($_ENV[$key])    && $_ENV[$key]    !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    return $default;
}

Dotenv::createImmutable(__DIR__)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '1');
$token = envx('EDUFRAME_TOKEN');
print("hoi2");
$sinceDate = (new DateTimeImmutable('-7 days'))->format('Y-m-d');

$connection = new Connection();
$connection->setAccessToken($token);
$client = new Client($connection);

$x = $client->planned_courses()->get();
print_r($x);