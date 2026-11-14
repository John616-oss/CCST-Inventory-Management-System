<?php
// Demonstration structures used by the application logic / defense.
class AttendanceQueue
{
    private SplQueue $q;
    function __construct()
    {
        $this->q = new SplQueue();
    }
    function enqueue(array $item): void
    {
        $this->q->enqueue($item);
    }
    function processNext(): ?array
    {
        return $this->q->isEmpty() ? null : $this->q->dequeue();
    }
}
class RecentActionStack
{
    private array $items = [];
    function push($v): void
    {
        $this->items[] = $v;
    }
    function pop()
    {
        return array_pop($this->items);
    }
}
class PriorityRequestQueue
{
    private SplPriorityQueue $q;
    function __construct()
    {
        $this->q = new SplPriorityQueue();
    }
    function add($request, int $priority): void
    {
        $this->q->insert($request, $priority);
    }
    function next()
    {
        return $this->q->isEmpty() ? null : $this->q->extract();
    }
}
class CourseTree
{
    public array $children = [];
    function add(int $parent, string $child): void
    {
        $this->children[$parent][] = $child;
    }
}
class StudentHashLookup
{
    private array $map = [];
    function put(string $studentCode, int $id): void
    {
        $this->map[$studentCode] = $id;
    }
    function find(string $studentCode): ?int
    {
        return $this->map[$studentCode] ?? null;
    }
}
