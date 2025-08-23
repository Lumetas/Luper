<?php
namespace Luper;

use RuntimeException;

class Pull
{
	private int $maxProcesses;
	private string $asyncFunctionPath;
	private array $tasks = [];
	private array $results = [];
	private array $errors = [];

	public function __construct(int $maxProcesses, string $asyncFunctionPath)
	{
		if ($maxProcesses <= 0) {
			throw new RuntimeException("Максимальное количество процессов должно быть больше 0");
		}

		if (!file_exists($asyncFunctionPath)) {
			throw new RuntimeException("Файл асинхронной функции не найден: " . $asyncFunctionPath);
		}

		$this->maxProcesses = $maxProcesses;
		$this->asyncFunctionPath = $asyncFunctionPath;
	}

	public function add(...$args): self
	{
		$this->tasks[] = $args;
		return $this;
	}

	public function run(): array
	{
		if (empty($this->tasks)) {
			return [];
		}

		$loop = Loop::make();
		$chunks = array_chunk($this->tasks, $this->maxProcesses);

		foreach ($chunks as $chunk) {
			foreach ($chunk as $taskArgs) {
				$loop->addTask(function () use ($taskArgs) {
					$asyncFunction = Async::create($this->asyncFunctionPath);
					$promise = $asyncFunction(...$taskArgs);

					while (!$promise->isCompleted()) {
						Loop::suspend();
					}

					$this->results[] = $promise->getResult()['result'];
					$this->errors[] = $promise->getError();
				});
			}
			$loop->run();
		}

		return $this->results;
	}

	public function getErrors(): array
	{
		return array_filter($this->errors);
	}

	public function count(): int
	{
		return count($this->tasks);
	}
}
