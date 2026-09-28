<?php

namespace App\MessageHandler;

use App\Inbox\InboxFetcher;
use App\Message\FetchInboxesMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchInboxesHandler
{
    public function __construct(private readonly InboxFetcher $fetcher)
    {
    }

    public function __invoke(FetchInboxesMessage $message): void
    {
        // Failures are recorded on each mailbox (inboxError) and logged.
        $this->fetcher->fetchAll();
    }
}
