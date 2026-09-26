<?php

namespace App\Embed;

use Rocket\Core\Embed\EmbedEndpointsInterface;

/** The embedded compose UI (/embed/compose, see public/embed.js) can only compose and send emails. */
final class ComposerEmbedEndpoints implements EmbedEndpointsInterface
{
    public function embedEndpoints(): iterable
    {
        yield ['GET', '#^/api/senders$#'];
        yield ['GET', '#^/api/email_templates(/[^/]+)?$#'];
        yield ['POST', '#^/api/emails$#'];
        yield ['GET', '#^/api/emails/[^/]+$#'];
        yield ['POST', '#^/api/attachments$#'];
        yield ['GET', '#^/api/attachments/[^/]+$#'];
        yield ['DELETE', '#^/api/attachments/[^/]+$#'];
    }
}
