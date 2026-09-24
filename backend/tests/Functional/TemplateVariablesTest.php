<?php

namespace App\Tests\Functional;

use App\Template\Placeholders;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TemplateVariablesTest extends WebTestCase
{
    use ApiTestTrait;

    private function quoteTemplate(string $jwt): array
    {
        return $this->api('POST', '/api/email_templates', [
            'name' => 'Relance devis',
            'defaultSubject' => 'Votre devis {{ devis.numero }}',
            'html' => '<p>Bonjour {{client.prenom}},</p><p>Montant : {{ devis.montant }}. {{ signature }}</p>',
            'shared' => true,
            'variables' => [
                ['name' => 'client.prenom', 'label' => 'Prénom du client'],
                ['name' => 'signature', 'label' => 'Signature', 'defaultValue' => 'L’équipe commerciale'],
                ['name' => 'client.prenom', 'label' => 'duplicate, ignored'],
            ],
        ], $jwt);
    }

    public function testTemplatesListDeclaredAndDetectedVariables(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $template = $this->quoteTemplate($jwt);
        $this->assertStatus(201);
        self::assertSame(
            [
                ['name' => 'client.prenom', 'label' => 'Prénom du client', 'defaultValue' => null, 'used' => true],
                ['name' => 'signature', 'label' => 'Signature', 'defaultValue' => 'L’équipe commerciale', 'used' => true],
                ['name' => 'devis.numero', 'label' => null, 'defaultValue' => null, 'used' => true],
                ['name' => 'devis.montant', 'label' => null, 'defaultValue' => null, 'used' => true],
            ],
            $template['variables'],
        );
        // Also in the list, for pickers.
        self::assertCount(4, $this->api('GET', '/api/email_templates', authorization: $jwt)[0]['variables']);

        $this->api('PATCH', '/api/email_templates/'.$template['id'], ['variables' => [['name' => 'not valid!']]], $jwt);
        $this->assertStatus(422);
    }

    public function testApiSendsATemplateWithVariables(): void
    {
        $alice = $this->createUser('alice@example.org');
        $jwt = 'Bearer '.$this->jwtFor($alice);
        $template = $this->quoteTemplate($jwt);

        $email = $this->api('POST', '/api/emails', [
            'to' => ['jean@client.example'],
            'template' => '/api/email_templates/'.$template['id'],
            // Nested and flat values; the default signature fills the rest.
            'variables' => ['client' => ['prenom' => 'Jean <b>'], 'devis.numero' => "D-42\r\nBcc: x@evil.example", 'devis.montant' => 1200],
        ], $jwt);
        $this->assertStatus(202);
        self::assertSame('Votre devis D-42 Bcc: x@evil.example', $email['subject']);

        $message = self::getMailerMessage();
        self::assertSame('Votre devis D-42 Bcc: x@evil.example', $message->getSubject());
        self::assertSame(['jean@client.example'], array_map(fn ($a) => $a->getAddress(), $message->getTo()));
        self::assertEmpty($message->getBcc());
        self::assertEmailHtmlBodyContains($message, 'Bonjour Jean &lt;b&gt;,');
        self::assertEmailHtmlBodyContains($message, 'Montant : 1200. L’équipe commerciale');
    }

    public function testMissingVariablesAreRefused(): void
    {
        $jwt = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $template = $this->quoteTemplate($jwt);

        $this->api('POST', '/api/emails', [
            'to' => ['jean@client.example'],
            'template' => '/api/email_templates/'.$template['id'],
            'variables' => ['client.prenom' => 'Jean'],
        ], $jwt);
        $this->assertStatus(422);
        self::assertStringContainsString('devis.numero, devis.montant', $this->client->getResponse()->getContent());

        // Content written in the composer (template imported, variables filled there) is sent as is.
        $this->api('POST', '/api/emails', [
            'to' => ['jean@client.example'],
            'subject' => 'Votre devis D-42',
            'htmlBody' => '<p>Bonjour Jean, montant : 1200.</p>',
            'template' => '/api/email_templates/'.$template['id'],
        ], $jwt);
        $this->assertStatus(202);

        // Without a template, braces are just text.
        $this->api('POST', '/api/emails', ['to' => ['x@example.com'], 'subject' => 'Hi', 'htmlBody' => '<p>{{ not a template }}</p>'], $jwt);
        $this->assertStatus(202);
        $this->api('POST', '/api/emails', ['to' => ['x@example.com'], 'subject' => '', 'htmlBody' => '<p>x</p>'], $jwt);
        $this->assertStatus(422);
    }

    public function testRenderingHelpers(): void
    {
        self::assertSame(['a', 'b.c'], Placeholders::names('{{a}} {{ b.c }} {{a}}', '{{ not valid }}'));
        self::assertSame(['a.b.c' => '1', 'd' => 'oui'], Placeholders::flatten(['a' => ['b' => ['c' => 1]], 'd' => true]));
        self::assertSame('x &quot;y&quot; {{ z }}', Placeholders::render('{{a}} {{ z }}', ['a' => 'x "y"'], html: true));
    }
}
