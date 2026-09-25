<?php

namespace App\Tests\Functional;

use App\Template\Layouts;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmailLayoutTest extends WebTestCase
{
    use ApiTestTrait;

    private const LAYOUT = '<!doctype html><html><head><style>.x{color:red}</style></head><body style="background:#eee"><div class="header">ACME</div>{{ content }}<p class="footer">Se désinscrire : {{ unsubscribe_url }}</p></body></html>';

    public function testAdminsManageLayoutsAndEveryoneCanUseThem(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));

        $this->api('POST', '/api/email_layouts', ['name' => 'Sans emplacement', 'html' => '<p>no slot</p>'], $admin);
        $this->assertStatus(422);

        $layout = $this->api('POST', '/api/email_layouts', ['name' => 'Charte ACME', 'html' => self::LAYOUT], $admin);
        $this->assertStatus(201);
        self::assertSame('admin@example.org', $layout['createdBy']);

        self::assertSame(['Charte ACME'], array_column($this->api('GET', '/api/email_layouts', authorization: $alice), 'name'));
        $this->api('POST', '/api/email_layouts', ['name' => 'x', 'html' => '{{ content }}'], $alice);
        $this->assertStatus(403);
        $this->api('PATCH', '/api/email_layouts/'.$layout['id'], ['name' => 'x'], $alice);
        $this->assertStatus(403);
    }

    public function testTemplatesAreRenderedInTheirLayout(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $layout = $this->api('POST', '/api/email_layouts', ['name' => 'Charte ACME', 'html' => self::LAYOUT], $admin);

        $template = $this->api('POST', '/api/email_templates', [
            'name' => 'Relance',
            'defaultSubject' => 'Relance',
            // As exported by GrapesJS: a body.
            'html' => '<body style="margin:0"><p>Bonjour {{ client.prenom }}</p></body>',
            'layout' => '/api/email_layouts/'.$layout['id'],
            'shared' => true,
        ], $alice);
        $this->assertStatus(201);
        self::assertSame(['id' => $layout['id'], 'name' => 'Charte ACME'], array_intersect_key($template['layout'], ['id' => 0, 'name' => 0]));
        self::assertStringContainsString('<div class="header">ACME</div><p>Bonjour {{ client.prenom }}</p><p class="footer">', $template['renderedHtml']);
        self::assertStringContainsString('<style>.x{color:red}</style>', $template['renderedHtml']);
        self::assertStringNotContainsString('{{ content }}', $template['renderedHtml']);
        self::assertStringContainsString('<body style="margin:0">', $template['html'], 'the content itself is unchanged');
        // Variables of the layout are variables of the template; the slot is not.
        self::assertSame(['client.prenom', 'unsubscribe_url'], array_column($template['variables'], 'name'));

        // Sent through the API: the layout is applied, then the variables.
        $this->api('POST', '/api/emails', [
            'to' => ['client@example.com'],
            'template' => '/api/email_templates/'.$template['id'],
            'variables' => ['client' => ['prenom' => 'Claire'], 'unsubscribe_url' => 'https://acme.example/u/42'],
        ], $alice);
        $this->assertStatus(202);
        $message = self::getMailerMessage();
        self::assertEmailHtmlBodyContains($message, '<div class="header">ACME</div><p>Bonjour Claire</p>');
        self::assertEmailHtmlBodyContains($message, 'Se désinscrire : https://acme.example/u/42');

        // The layout is part of the template's versions.
        $this->api('PATCH', '/api/email_templates/'.$template['id'], ['layout' => null], $alice);
        $this->assertStatus(200);
        $versions = $this->api('GET', '/api/email_templates/'.$template['id'].'/versions', authorization: $alice);
        self::assertContains('layout', $versions[0]['changedFields']);
        $restored = $this->api('POST', '/api/email_templates/'.$template['id'].'/versions/1/restore', authorization: $alice);
        $this->assertStatus(200);
        self::assertSame('Charte ACME', $restored['layout']['name']);

        // Deleting a layout in use leaves its templates without layout.
        $this->api('DELETE', '/api/email_layouts/'.$layout['id'], authorization: $admin);
        $this->assertStatus(204);
        $orphan = $this->api('GET', '/api/email_templates/'.$template['id'], authorization: $alice);
        self::assertNull($orphan['layout']);
        self::assertSame('<body style="margin:0"><p>Bonjour {{ client.prenom }}</p></body>', $orphan['renderedHtml']);
    }

    public function testWrappingHelpers(): void
    {
        self::assertSame('<main>$1 \\1 ok</main>', Layouts::wrap('<main>{{content}}</main>', '<html><head><title>t</title></head><body class="a">$1 \\1 ok</body></html>'));
        self::assertSame('<p>plain</p>', Layouts::body('<p>plain</p>'));
        self::assertFalse(Layouts::hasSlot('<p>{{ contents }}</p>'));
    }
}
