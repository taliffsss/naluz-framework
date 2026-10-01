<?php

declare(strict_types=1);

namespace Naluz\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/** PSR-14 dispatcher. Listeners are matched on the event class, its parents and interfaces. */
final class Dispatcher implements EventDispatcherInterface, ListenerProviderInterface
{
    /** @var array<string,list<callable>> */
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function getListenersForEvent(object $event): iterable
    {
        foreach ([$event::class, ...class_parents($event), ...class_implements($event)] as $type) {
            yield from $this->listeners[$type] ?? [];
        }
    }

    public function dispatch(object $event): object
    {
        foreach ($this->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }
        return $event;
    }
}
