<?php

namespace Ernestdefoe\Detour\Tests\integration;

use PHPUnit\Framework\Attributes\Test;

class PageTest extends DetourTestCase
{
    #[Test]
    public function the_friendly_page_offers_a_search_and_guest_visible_suggestions(): void
    {
        $response = $this->get('/threads/the-garden-club.42/');
        $body = (string) $response->getBody();

        $this->assertSame(404, $response->getStatusCode());
        // Extension locales are not loaded by the test harness; the key stands in.
        $this->assertMatchesRegularExpression('~Page not found|views\.not_found_title~', $body);
        $this->assertStringContainsString('value="garden club"', $body);
        $this->assertStringContainsString('Welcome to the garden club', $body);
        $this->assertStringContainsString(self::BASE.'/d/1', $body);
        $this->assertStringNotContainsString('Secret garden', $body, 'a private discussion is never suggested');

        // Case doesn't matter, on PostgreSQL too.
        $this->assertStringContainsString('Welcome to the garden club', (string) $this->get('/WELCOME-aboard')->getBody());
    }

    #[Test]
    public function words_from_the_address_are_escaped(): void
    {
        $body = (string) $this->get('/%3Cscript%3Ealert(1)%3C%2Fscript%3E%22onmouseover%3D')->getBody();

        $this->assertStringNotContainsString('<script>alert', $body);
        $this->assertStringNotContainsString('"onmouseover', $body);
    }

    #[Test]
    public function the_admins_own_heading_and_message_are_plain_text(): void
    {
        $this->setting('ernestdefoe-detour.page_title', 'Lost? <b>Us too</b>');
        $this->setting('ernestdefoe-detour.page_message', "Line one\n<img src=x onerror=alert(1)>");

        $body = (string) $this->get('/nowhere')->getBody();

        $this->assertStringContainsString('Lost? &lt;b&gt;Us too&lt;/b&gt;', $body);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $body);
    }

    #[Test]
    public function json_clients_get_flarums_own_answer(): void
    {
        $response = $this->get('/nowhere', ['Accept' => 'application/vnd.api+json']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('detour-search', (string) $response->getBody());
    }

    #[Test]
    public function a_turned_off_page_leaves_flarums_own(): void
    {
        $this->setting('ernestdefoe-detour.friendly_page', '0');
        $response = $this->get('/nowhere');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('detour-search', (string) $response->getBody());
    }

    #[Test]
    public function suggestions_can_be_turned_off(): void
    {
        $this->setting('ernestdefoe-detour.suggestions', '0');

        $this->assertStringNotContainsString('Welcome to the garden club', (string) $this->get('/garden')->getBody());
    }

    #[Test]
    public function suggestion_searches_have_a_budget_per_minute(): void
    {
        $this->get('/warm-up');
        $db = $this->database();

        for ($i = 0; $i < \Ernestdefoe\Detour\Http\NotFoundPage::SEARCHES_PER_MINUTE + 10; $i++) {
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->get('/garden-'.str_repeat('x', 3).chr(97 + $i % 26).chr(97 + intdiv($i, 26)));
            $searched[] = (bool) array_filter(array_column($db->getQueryLog(), 'query'), fn ($q) => stripos($q, 'like') !== false);
            $db->disableQueryLog();
        }

        // The warm-up spent one; the minute may roll over mid-test, which only allows more.
        $this->assertGreaterThanOrEqual(\Ernestdefoe\Detour\Http\NotFoundPage::SEARCHES_PER_MINUTE - 1, count(array_filter($searched)));
        $this->assertLessThan(count($searched), count(array_filter($searched)));
    }
}
