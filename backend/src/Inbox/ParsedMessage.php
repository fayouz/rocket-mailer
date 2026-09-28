<?php

namespace App\Inbox;

final class ParsedMessage
{
    public ?string $messageId = null;
    public ?string $inReplyTo = null;
    /** @var list<string> */
    public array $references = [];
    public string $fromAddress = '';
    public ?string $fromName = null;
    public ?string $replyTo = null;
    /** @var list<string> */
    public array $to = [];
    /** @var list<string> */
    public array $cc = [];
    public string $subject = '';
    public ?\DateTimeImmutable $date = null;
    public string $text = '';
    public ?string $html = null;
    /** @var list<array{filename: string, mimeType: string, content: string}> */
    public array $attachments = [];
    /** Auto-Submitted, bulk, list mail: never answered automatically (informational). */
    public bool $automated = false;
}
