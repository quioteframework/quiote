<?php

namespace Quiote\Scheduler;

use Quiote\Queue\Job;

/**
 * App-facing base class for defining scheduled tasks — mirrors how an app
 * subclasses `Quiote\Routing\Routing` for its route table. Subclass and
 * implement {@see define()}; bind the subclass as {@see Schedule} in
 * `Config/factories.xml` so `schedule:run` resolves it.
 */
abstract class Schedule
{
    /** @var list<ScheduledTaskDefinition> */
    private array $definitions = [];

    abstract protected function define(): void;

    /** @return list<ScheduledTaskDefinition> */
    public function build(): array
    {
        $this->definitions = [];
        $this->define();
        return $this->definitions;
    }

    /**
     * @param class-string<Job> $jobClass
     * @param array<string, mixed> $params
     */
    protected function job(string $jobClass, array $params = []): ScheduledTaskDefinition
    {
        return $this->register(new DispatchJobTask($jobClass, $params));
    }

    /** @param \Closure(\Quiote\DI\Container): void $callback */
    protected function call(\Closure $callback): ScheduledTaskDefinition
    {
        return $this->register(new InlineCallbackTask($callback));
    }

    private function register(ScheduledTaskAction $action): ScheduledTaskDefinition
    {
        $definition = new ScheduledTaskDefinition($action);
        $this->definitions[] = $definition;
        return $definition;
    }
}
