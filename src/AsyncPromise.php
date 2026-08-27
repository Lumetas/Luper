<?php
namespace Luper;

class AsyncPromise {
    private $process;
    private $pipes;
    private bool $completed = false;
    private $result = null;
    private $error = null;
    private $output = null;
    private array $dataQueue = [];
    private string $stdoutBuffer = '';
    private string $pendingWrite = '';
    private bool $resourcesClosed = false;

    public function __construct($process, $pipes) {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    public function isCompleted(): bool {
        if ($this->completed) {
            $this->closeResources();
            return true;
        }

        $this->poll();
        return $this->completed;
    }

    /**
     * Неблокирующая отправка данных в дочерний процесс.
     * Дочерний процесс получает их через luper_get_data().
     */
    public function sendData($data): void {
        $this->pendingWrite .= json_encode([
            'type' => 'data',
            'data' => base64_encode(serialize($data))
        ]) . "\n";
        $this->flushPendingWrites();
    }

    /**
     * Получение данных, отправленных дочерним процессом через luper_send_data().
     * По умолчанию ожидает появления данных. Если передан true -
     * возвращает null сразу, в случае отсутствия готовых данных.
     */
    public function getData(bool $nonBlocking = false) {
        $this->poll();
        if (!empty($this->dataQueue)) {
            return array_shift($this->dataQueue);
        }
        if ($nonBlocking) {
            return null;
        }

        while (empty($this->dataQueue)) {
            if ($this->completed) {
                return null;
            }
            $this->poll();
            if (!empty($this->dataQueue)) {
                return array_shift($this->dataQueue);
            }
            if ($this->completed) {
                return null;
            }
            if (\Fiber::getCurrent() !== null) {
                Loop::suspend();
            } else {
                usleep(1000);
            }
        }

        return array_shift($this->dataQueue);
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

    private function poll(): void {
        if ($this->completed) {
            $this->closeResources();
            return;
        }

        $this->flushPendingWrites();
        $this->readStdout();
        $this->drainStderr();

        if (!$this->completed && is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $this->drainStdout();
                $this->completeProcess();
            }
        }
    }

    private function flushPendingWrites(): void {
        if ($this->pendingWrite === ''
            || $this->completed
            || !is_resource($this->pipes[0] ?? null)
        ) {
            return;
        }

        while ($this->pendingWrite !== '') {
            $written = @fwrite($this->pipes[0], $this->pendingWrite);
            if ($written === false || $written === 0) {
                break;
            }
            $this->pendingWrite = substr($this->pendingWrite, $written);
        }
        @fflush($this->pipes[0]);
    }

    private function readStdout(): void {
        if (!is_resource($this->pipes[1] ?? null)) {
            return;
        }

        while (true) {
            $chunk = @fread($this->pipes[1], 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->stdoutBuffer .= $chunk;
        }

        $this->processStdoutBuffer();
    }

    private function drainStderr(): void {
        if (!is_resource($this->pipes[2] ?? null)) {
            return;
        }

        while (true) {
            $chunk = @fread($this->pipes[2], 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
        }
    }

    private function processStdoutBuffer(): void {
        while (($nl = strpos($this->stdoutBuffer, "\n")) !== false) {
            $line = substr($this->stdoutBuffer, 0, $nl);
            $this->stdoutBuffer = substr($this->stdoutBuffer, $nl + 1);

            if ($line === '') {
                continue;
            }

            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                continue;
            }

            switch ($msg['type'] ?? null) {
                case 'data':
                    $this->dataQueue[] = @unserialize(base64_decode($msg['data'] ?? ''));
                    break;
                case 'result':
                    if (!empty($msg['success'])) {
                        $this->result = @unserialize(base64_decode($msg['result'] ?? ''));
                        $this->output = @unserialize(base64_decode($msg['output'] ?? ''));
                    } else {
                        $this->applyError($msg);
                    }
                    $this->completed = true;
                    return;
                case 'error':
                    $this->applyError($msg);
                    $this->completed = true;
                    return;
            }
        }
    }

    private function applyError(array $msg): void {
        $errorData = @unserialize(base64_decode($msg['error'] ?? ''));
        if (is_array($errorData)) {
            $this->error = new \Exception(
                $errorData['message'],
                $errorData['code']
            );
        }
    }

    private function completeProcess(): void {
        if ($this->completed) {
            $this->closeResources();
            return;
        }

        $this->processStdoutBuffer();
        if (!$this->completed) {
            $this->completed = true;
        }
        $this->closeResources();
    }

    private function closeResources(): void {
        if ($this->resourcesClosed) {
            return;
        }
        $this->resourcesClosed = true;

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
    }

    public function __destruct() {
        if (!$this->completed) {
            $this->poll();
        }
        $this->closeResources();
    }

	public function await() {
		while (!$this->isCompleted()) {
			if (\Fiber::getCurrent() !== null) {
				Loop::suspend();
			} else {
				usleep(1000);
			}
		}
		return $this->getResult();
	}
}