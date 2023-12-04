<?php

/**
 * Pork History Syncer
 *
 * @author Nadyita <nadyita@hodorraid.org>
*/

declare(strict_types=1);

namespace PorkSyncer;

require_once __DIR__ . '/../vendor/autoload.php';

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Log\ConsoleFormatter;
use Amp\ByteStream;
use Amp\Log\StreamHandler;
use ErrorException;
use Exception;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Throwable;

use function Safe\json_decode;

set_error_handler(
	function ($level, $error, $file, $line): bool {
		if (0 === error_reporting()) {
			return false;
		}
		throw new ErrorException($error, -1, $level, $file, $line);
	},
	E_ALL
);

$handler = new StreamHandler(ByteStream\getStdout());
$handler->setFormatter(new ConsoleFormatter());
$handler->pushProcessor(new PsrLogMessageProcessor("Y-m-d H:i:s.v", true));
$logger = new Logger('main');
$logger->pushHandler($handler);

$exit = false;
foreach (["DB_HOST", "DB_USER", "DB_PASSWORD", "DB_NAME"] as $var) {
	if (getenv($var) === false) {
		$logger->critical("Environment variable {var} missing", ["var" => $var]);
		$exit = true;
	}
}
if ($exit) {
	exit(1);
}
try {
	$config = MysqlConfig::fromString(
		"host=" . getenv("DB_HOST") . " ".
		"user=" . getenv("DB_USER") . " " .
		"password=" . getenv("DB_PASSWORD") ." ".
		"db=" . getenv("DB_NAME")
	);

	$pool = new MysqlConnectionPool($config);

	$statement = $pool->query("SELECT MAX(last_changed) AS max FROM player_history");
	$row = $statement->fetchRow();
	/** @var int */
	$lastChanged = $row['max'];
	$logger->notice("Newest entry is {latest}", ["latest" => $lastChanged]);
	$lastChanged++;
} catch (Throwable $e) {
	$logger->critical("Cannot get the date of the last change: {error}", [
		"error" => $e->getMessage(),
		"exception" => $e,
	]);
	exit(1);
}

$url = "https://pork.jkbff.com/pork/changes.php?last_changed=" . ($lastChanged + 1);
$client = HttpClientBuilder::buildDefault();

$response = $client->request(new Request($url));
if ($response->getStatus() !== 200) {
	$logger->critical("HTTP code {code} received, aborting", ["code" => $response->getStatus()]);
	exit(1);
}
try {
	$body = $response->getBody()->buffer();
} catch (Exception $e) {
	$logger->critical("Error downloading diff: {error}", [
		"error" => $e->getMessage(),
		"header" => $response->getHeaders(),
		"exception" => $e,
	]);
	exit(1);
}
try {
	$data = json_decode($body, true);
	if (!isset($data['results'])) {
		throw new Exception("Invalid data format, no \"results\"");
	}
} catch (Exception $e) {
	$logger->critical("JSON-error: {error}", [
		"error" => $e->getMessage(),
		"exception" => $e,
		"json" => $body,
	]);
	exit(1);
}
try {
	$prep = $pool->prepare("INSERT INTO `player_history` ".
		"(" .
			"`nickname`, " .
			"`char_id`, " .
			"`first_name`, " .
			"`last_name`, " .
			"`guild_rank`, " .
			"`guild_rank_name`, " .
			"`level`, " .
			"`faction`, " .
			"`profession`, " .
			"`profession_title`, " .
			"`gender`, " .
			"`breed`, " .
			"`defender_rank`, " .
			"`defender_rank_name`, " .
			"`guild_id`, " .
			"`guild_name`, " .
			"`server`, " .
			"`last_checked`, " .
			"`last_changed`, " .
			"`deleted`" .
		") VALUES (" .
			":nickname, " .
			":char_id, " .
			":first_name, " .
			":last_name, " .
			":guild_rank, " .
			":guild_rank_name, " .
			":level, " .
			":faction, " .
			":profession, " .
			":profession_title, " .
			":gender, " .
			":breed, " .
			":defender_rank, " .
			":defender_rank_name, " .
			":guild_id, " .
			":guild_name, " .
			":server, " .
			":last_checked, " .
			":last_changed, " .
			":deleted" .
		")");
} catch (Throwable $e) {
	$logger->critical("Error in SQL prepare-statement: {error}", [
		"error" => $e->getMessage(),
		"exception" => $e
	]);
	exit(1);
}

$inserted = 0;
foreach ($data['results'] as $entry) {
	try {
		$prep->execute($entry);
		$inserted++;
	} catch (Throwable $e) {
		$logger->error("Error inserting record: {error}", [
			'error' => $e->getmessage(),
			'record' => $entry,
			'exception' => $e,
		]);
	}
}

$logger->notice("{num_inserted} records inserted", ["num_inserted" => $inserted]);
