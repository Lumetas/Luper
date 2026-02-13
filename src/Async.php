<?php
namespace Luper;
use function Opis\Closure\serialize;
class Async {
    private string|null $filepath = null;
	private string|null $closure = null;
	private bool $typeIsClosure = false;

    public static function create(string|\Closure $filepath): self {
        return new self($filepath);
    }

    public function __construct(string|\Closure $filepath) {
		if (is_string($filepath)) {
			$this->filepath = $filepath;
		} else {
			$this->closure = serialize($filepath);	
			$this->typeIsClosure = true;
		}
    }

    public function __invoke(...$args): AsyncPromise {
        $stdin = [
            "file_path" => $this->filepath,
			"args" => $args,
			"closure" => $this->closure,
			"typeIsClosure" => $this->typeIsClosure
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
