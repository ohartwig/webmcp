<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

/**
 * The application's port for emitting domain events.
 *
 * Structurally identical to PSR-14 — deliberately so, because the adapter is
 * then a one-line delegation. It exists as its own type because the Application
 * layer may not reach into the framework, and "PSR-14 happens to be what the
 * framework provides" is exactly the kind of coupling the layering is there to
 * keep out. Swapping the dispatcher becomes a change to one adapter rather than
 * to every service that emits an event.
 */
interface EventDispatcherInterface
{
    /**
     * @template T of object
     *
     * @param T $event
     *
     * @return T the event, after listeners have had a chance to mutate it
     */
    public function dispatch(object $event): object;
}
