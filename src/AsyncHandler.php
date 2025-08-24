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
    $output = ob_get_clean();
    
    // Сериализуем результат для безопасной передачи
    $serializedResult = base64_encode(serialize($result));
    $serializedOutput = base64_encode(serialize($output));
    
    echo json_encode([
        'success' => true, 
        'result' => $serializedResult,
        'output' => $serializedOutput
    ]);
} catch (\Throwable $e) {
    // Сериализуем исключение для безопасной передачи
    $serializedError = base64_encode(serialize([
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]));
    
    echo json_encode([
        'success' => false, 
        'error' => $serializedError
    ]);
}
