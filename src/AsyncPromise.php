<?php
namespace Luper;

class AsyncPromise {
    private $process;
    private $pipes;
    private bool $completed = false;
    private $result = null;
    private $error = null;
    private $output = null;

    public function __construct($process, $pipes) {
        $this->process = $process;
        $this->pipes = $pipes;
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

    public function getOutput() {
        if (!$this->completed) {
            $this->isCompleted(); // Проверяем завершение
        }
        
        return $this->output;
    }

    private function completeProcess(): void {
        if ($this->completed) {
            return;
        }

        // Читаем вывод
        $stdout = stream_get_contents($this->pipes[1]);
        $stderr = stream_get_contents($this->pipes[2]);

        // Закрываем ресурсы
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);

        // Декодируем и десериализуем результат
        $response = json_decode($stdout, true);

		if (empty($response)){return;}
        
        if ($response['success']) {
            $this->result = unserialize(base64_decode($response['result']));
            $this->output = unserialize(base64_decode($response['output']));
        } else {
            $errorData = unserialize(base64_decode($response['error']));
            $this->error = new \Exception(
                $errorData['message'],
                $errorData['code']
            );
        }

        $this->completed = true;
    }

    public function __destruct() {
        if (!$this->completed && is_resource($this->process)) {
            $this->completeProcess();
        }
    }

	public function await() {
		if ($this->isCompleted()) {
			return $this->getResult();
		} else {
			while(!$this->isCompleted()) {
				if (\Fiber::getCurrent() !== null) {Loop::suspend();}
			}
			return $this->getResult();
		}
	}
}
