<?php
namespace Luper;

class Async {
    private string $filepath;

    public static function create(string $filepath): self {
        return new self($filepath);
    }

    public function __construct(string $filepath) {
        $this->filepath = $filepath;
    }

    public function __invoke(...$args): AsyncPromise {
        $stdin = [
            "file_path" => $this->filepath,
            "args" => $args
        ];

        $descriptors = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $handlerPath = __DIR__ . '/AsyncHandler.php';
        $process = proc_open("php " . escapeshellarg($handlerPath), $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start async handler");
        }

        // Передаем данные и сразу закрываем stdin
        fwrite($pipes[0], json_encode($stdin));
        fclose($pipes[0]);

        // Переводим пайпы в неблокирующий режим
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Создаем и возвращаем новый промис для каждого вызова
        return new AsyncPromise($process, $pipes);
    }
}
