<?php
// Обработчик асинхронных задач
$input = file_get_contents('php://stdin');
$data = json_decode($input, true);

if (!isset($data['file_path']) || !file_exists($data['file_path'])) {
    echo json_encode(['error' => 'Invalid file path']);
    exit(1);
}

// Загружаем пользовательский скрипт
$userFunction = require $data['file_path'];

if (!is_callable($userFunction)) {
    echo json_encode(['error' => 'File does not return a callable function']);
    exit(1);
}

// Выполняем пользовательскую функцию
try {
	ob_start();
    $result = call_user_func_array($userFunction, $data['args'] ?? []);
	ob_clean();
    echo json_encode(['success' => true, 'result' => $result]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
