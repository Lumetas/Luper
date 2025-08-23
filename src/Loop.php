<?php
namespace Luper;
use \Fiber;
class Loop {
    private $tasks = [];
    private $timers = [];
    private $running = false;
    private $activeFibers = [];
	private static self $instance;

	public static function make(bool $new = false) {
		if ($new) {
			return new self();
		} else {
			self::$instance = new self();
			return self::$instance;
		}
	}
    
    public function addTask(callable $task) {
        $this->tasks[] = new Fiber($task);
    }
    
    private function generateRandomString($length = 16) {
        $bytes = random_bytes(ceil($length / 2)); 
        $hexString = bin2hex($bytes);
        return substr($hexString, 0, $length);
    }
    
    public function addTimer($interval, callable $callback, $repeat = false) {
        $timerKey = $this->generateRandomString();
        $this->timers[$timerKey] = [
            'next_run' => microtime(true) + $interval,
            'interval' => $interval,
            'callback' => $callback,
            'repeat' => $repeat
        ];

        return $timerKey;
    }

    public function clearTimer(string $id): bool {
        try {
            unset($this->timers[$id]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
    
    public function run() {
        $this->running = true;
        
        while ($this->running) {
            $this->processTasks();
            $this->processTimers();
            $this->processActiveFibers();
			
            // Не грузим CPU
            if (empty($this->tasks) && empty($this->activeFibers)) {
                /* usleep(10000); // 10ms */
				return;
            }
        }
    }
    
    private function processTasks() {
        while (!empty($this->tasks)) {
            $fiber = array_shift($this->tasks);
            
            try {
                if (!$fiber->isStarted()) {
                    $fiber->start();
                } else {
                    $fiber->resume();
                }
                
                // Если файбер приостановлен, добавляем в активные
                if ($fiber->isSuspended()) {
                    $this->activeFibers[] = $fiber;
                }
                
            } catch (Throwable $e) {
                // Обработка ошибок в файберах
                error_log("Fiber error: " . $e->getMessage());
            }
        }
    }
    
    private function processActiveFibers() {
        $stillActive = [];
        
        foreach ($this->activeFibers as $fiber) {
            try {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                    
                    // Если всё ещё приостановлен, оставляем активным
                    if ($fiber->isSuspended()) {
                        $stillActive[] = $fiber;
                    } elseif (!$fiber->isTerminated()) {
                        // Если завершился, но не терминатед - странное состояние
                        $stillActive[] = $fiber;
                    }
                }
            } catch (Throwable $e) {
                error_log("Active fiber error: " . $e->getMessage());
            }
        }
        
        $this->activeFibers = $stillActive;
    }
    
    private function processTimers() {
        $currentTime = microtime(true);
        
        foreach ($this->timers as $key => $timer) {
            if ($currentTime >= $timer['next_run']) {
                // Заворачиваем callback в файбер для поддержки suspend
                $timerFiber = new Fiber($timer['callback']);
                
                try {
                    $timerFiber->start();
                    
                    // Если таймер приостановил выполнение, добавляем в активные
                    if ($timerFiber->isSuspended()) {
                        $this->activeFibers[] = $timerFiber;
                    }
                    
                } catch (Throwable $e) {
                    error_log("Timer fiber error: " . $e->getMessage());
                }
                
                if ($timer['repeat']) {
                    $this->timers[$key]['next_run'] = $currentTime + $timer['interval'];
                } else {
                    unset($this->timers[$key]);
                }
            }
        }
    }
    
    public function stop() {
        $this->running = false;
    }
    
    // Вспомогательный метод для приостановки выполнения из любого файбера
    public static function suspend(): void {
        Fiber::suspend();
    }
    
    // Метод для "засыпания" на указанное время
    public function sleep(float $seconds): void {
        $start = microtime(true);
        $end = $start + $seconds;
        
        while (microtime(true) < $end) {
            Fiber::suspend();
        }
    }

}
