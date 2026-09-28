<?php

namespace App\Inbox;

use App\Message\FetchInboxesMessage;
use Rocket\Core\Scheduler\RecurringTaskProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\RecurringMessage;

/** The worker fetches the shared inboxes every INBOX_FETCH_INTERVAL (default 5 minutes). */
final class InboxTasks implements RecurringTaskProviderInterface
{
    public function __construct(#[Autowire(env: 'INBOX_FETCH_INTERVAL')] private readonly string $interval)
    {
    }

    public function recurringMessages(): iterable
    {
        yield RecurringMessage::every($this->interval, new FetchInboxesMessage());
    }
}
