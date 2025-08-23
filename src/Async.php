<?php
namespace Luper;

class Async {
    private string $filepath;
    private $process;
	private $error;
    private $pipes;
    private bool $completed = false;
    private $result = null;

    public static function create(string $filepath): self {
        return new self($filepath);
    }

    public function __construct(string $filepath) {
        $this->filepath = $filepath;
    }

    public function __invoke() {
        $args = func_get_args();
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
        $this->process = proc_open("php " . escapeshellarg($handlerPath), $descriptors, $this->pipes);

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start async handler");
        }

        // Передаем данные и сразу закрываем stdin
        fwrite($this->pipes[0], json_encode($stdin));
        fclose($this->pipes[0]);

        // Переводим пайпы в неблокирующий режим
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        return $this;
    }

    public function isCompleted(): bool {
        if ($this->completed) {
            return true;
        }

        $status = proc_get_status($this->process);
        
        if (!$status['running']) {
            $this->completeProcess();
            return true;
        }

        return false;
    }

    public function getResult() {
        if (!$this->completed) {
            $this->isCompleted(); // Проверяем завершение
        }
        
        return $this->result;
    }

	public function getError() {
        if (!$this->completed) {
            $this->isCompleted(); // Проверяем завершение
        }
        
        return $this->error;
	}

    private function completeProcess(): void {
        if ($this->completed) {
            return;
        }

        // Читаем вывод
        $output = stream_get_contents($this->pipes[1]);
        $error = stream_get_contents($this->pipes[2]);

        // Закрываем ресурсы
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);

        $this->result = json_decode($output, true);
        $this->completed = true;

		$this->error = $error;
    }

    public function __destruct() {
        if (!$this->completed && is_resource($this->process)) {
            $this->completeProcess();
        }
    }
}
