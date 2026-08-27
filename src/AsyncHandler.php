<?php

use function Opis\Closure\unserialize;

$GLOBALS['luper_stdin'] = STDIN;
$GLOBALS['luper_stdin_buffer'] = '';
$GLOBALS['luper_stdout'] = STDOUT;

stream_set_blocking(STDIN, false);

function luper_read_line(bool $blocking = true): ?string
{
	$stdin = &$GLOBALS['luper_stdin'];
	$buffer = &$GLOBALS['luper_stdin_buffer'];

	while (true) {
		$nl = strpos($buffer, "\n");
		if ($nl !== false) {
			$line = substr($buffer, 0, $nl);
			$buffer = substr($buffer, $nl + 1);
			return $line;
		}

		$chunk = fread($stdin, 65536);
		if ($chunk === false || $chunk === '') {
			if (feof($stdin)) {
				return null;
			}
			if (!$blocking) {
				return null;
			}
			usleep(1000);
			continue;
		}

		$buffer .= $chunk;
	}
}

function luper_write_result(array $payload): void
{
	fwrite($GLOBALS['luper_stdout'], json_encode($payload) . "\n");
	fflush($GLOBALS['luper_stdout']);
}

function luper_send_data($data): void
{
	luper_write_result([
		'type' => 'data',
		'data' => base64_encode(serialize($data))
	]);
}

function luper_get_data(bool $nonBlocking = false)
{
	while (true) {
		$line = luper_read_line(!$nonBlocking);
		if ($line === null) {
			return null;
		}

		$msg = json_decode($line, true);
		if (is_array($msg) && ($msg['type'] ?? null) === 'data') {
			return unserialize(base64_decode($msg['data']));
		}
	}
}

$initLine = luper_read_line();
if ($initLine === null) {
	luper_write_result([
		'type' => 'error',
		'success' => false,
		'error' => base64_encode(serialize([
			'message' => 'Empty init message',
			'code' => 1,
			'file' => __FILE__,
			'line' => __LINE__,
			'trace' => '',
		]))
	]);
	exit(1);
}

$data = json_decode($initLine, true);
if (!is_array($data)) {
	luper_write_result([
		'type' => 'error',
		'success' => false,
		'error' => base64_encode(serialize([
			'message' => 'Invalid init message',
			'code' => 2,
			'file' => __FILE__,
			'line' => __LINE__,
			'trace' => '',
		]))
	]);
	exit(1);
}

$autoloadPath = $data['autoload_path'] ?? (__DIR__ . '/../../../autoload.php');
if (!is_file($autoloadPath)) {
	$autoloadPath = __DIR__ . '/../../../autoload.php';
}
require $autoloadPath;

$userFunction = null;
if (!empty($data['typeIsClosure'])) {
	$closure = $data['closure'] ?? null;
	if ($closure !== null) {
		$userFunction = unserialize($closure);
	}
} else {
	$filePath = $data['file_path'] ?? null;
	if ($filePath === null || !file_exists($filePath)) {
		luper_write_result([
			'type' => 'error',
			'success' => false,
			'error' => base64_encode(serialize([
				'message' => 'Invalid file path',
				'code' => 3,
				'file' => __FILE__,
				'line' => __LINE__,
				'trace' => '',
			]))
		]);
		exit(1);
	}
	$userFunction = require $filePath;
}

if (!is_callable($userFunction)) {
	luper_write_result([
		'type' => 'error',
		'success' => false,
		'error' => base64_encode(serialize([
			'message' => 'File does not return a callable function',
			'code' => 4,
			'file' => __FILE__,
			'line' => __LINE__,
			'trace' => '',
		]))
	]);
	exit(1);
}

// Выполняем пользовательскую функцию
try {
	ob_start();
	$result = call_user_func_array($userFunction, $data['args'] ?? []);
	$output = ob_get_clean();

	luper_write_result([
		'type' => 'result',
		'success' => true,
		'result' => base64_encode(serialize($result)),
		'output' => base64_encode(serialize($output))
	]);
} catch (\Throwable $e) {
	if (ob_get_level() > 0) {
		ob_end_clean();
	}

	luper_write_result([
		'type' => 'error',
		'success' => false,
		'error' => base64_encode(serialize([
			'message' => $e->getMessage(),
			'code' => $e->getCode(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'trace' => $e->getTraceAsString()
		]))
	]);
}