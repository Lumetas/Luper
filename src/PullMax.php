<?php
namespace Luper;

use RuntimeException;

class PullMax
{
    private string|\Closure $asyncFunctionPath;
    private array $tasks = [];
    private array $results = [];
    private array $errors = [];

    public function __construct(\Closure|string $asyncFunctionPath)
    {
		if (is_string($asyncFunctionPath)) {
			if (!file_exists($asyncFunctionPath)) {
				throw new RuntimeException("Файл асинхронной функции не найден: " . $asyncFunctionPath);
			}
		}

        $this->asyncFunctionPath = $asyncFunctionPath;
    }

    public function add(...$args): self
    {
        $this->tasks[] = $args;
        return $this;
    }

    /**
     * Запуск ВСЕХ процессов одновременно - максимальная производительность!
     */
    public function run(): array
    {
        if (empty($this->tasks)) {
            return [];
        }

        $loop = Loop::make();

		$errors = [];
		$results = [];

		$file = $this->asyncFunctionPath;
		$func = Async::create($file);
        foreach ($this->tasks as $taskArgs) {
			$loop->addTask(function () use ($loop, $taskArgs, $file, &$errors, &$results, &$func) {
				$promise = $func(...$taskArgs);

				$results[] = $promise->await();
				$errors[] = $promise->getError();
			});
        }

        $loop->run();


		$this->errors = $errors;
		$this->results = $results;

        return $this->results;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function count(): int
    {
        return count($this->tasks);
    }
}
