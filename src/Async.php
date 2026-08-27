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
			"typeIsClosure" => $this->typeIsClosure,
			"autoload_path" => $this->resolveAutoloadPath()
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
        // Передаем init-сообщение и НЕ закрываем stdin:
        // пайп остаётся открытым для двустороннего обмена данными (sendData в child)
        fwrite($pipes[0], json_encode($stdin) . "\n");
        fflush($pipes[0]);
        // Переводим пайпы в неблокирующий режим
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        stream_set_write_buffer($pipes[0], 0);

        // Создаем и возвращаем новый промис для каждого вызова
        return new AsyncPromise($process, $pipes);
    }

    private function resolveAutoloadPath(): string {
        $candidates = [__DIR__ . '/../../../autoload.php'];

        // Ищем автозагрузчик Composer, чтобы корректно обработать
        // symlink-установку (path repository в dev-режиме)
        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'ComposerAutoloaderInit') && method_exists($class, 'getLoader')) {
                try {
                    $loader = $class::getLoader();
                    if ($loader instanceof \Composer\Autoload\ClassLoader) {
                        $prefixes = $loader->getPrefixesPsr4();
                        if (isset($prefixes['Luper\\'])) {
                            foreach ($prefixes['Luper\\'] as $dir) {
                                $candidates[] = dirname($dir, 3) . DIRECTORY_SEPARATOR . 'autoload.php';
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return __DIR__ . '/../../../autoload.php';
    }
}