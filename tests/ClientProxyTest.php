<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ClientProxy egységtesztjei — a tiszta, mellékhatás-mentes fejléc-
 * szűrő-logikát reflection-nel, közvetlenül hívja (valódi HTTP-válasz
 * nélkül nincs értelmes módja header()-t egységtesztben megfigyelni). A
 * teljes, valódi kérés/válasz test-átlátszóságot (JSON, bináris, Set-Cookie
 * a gyakorlatban) tests/ClientProxyHttpTest.php bizonyítja, két valódi
 * `php -S` folyamattal.
 */
final class ClientProxyTest extends TestCase
{
    private function invokePrivate(object $obj, string $method, array $args = [])
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function withServerVars(array $vars, callable $fn)
    {
        $backup = $_SERVER;
        foreach ($vars as $k => $v) {
            $_SERVER[$k] = $v;
        }
        try {
            return $fn();
        } finally {
            $_SERVER = $backup;
        }
    }

    // -----------------------------------------------------------------
    // buildOutboundHeaders() — sose Host, sose Cookie, sose hop-by-hop
    // -----------------------------------------------------------------

    public function testBuildOutboundHeadersNeverIncludesHostHeader(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $headers = $this->withServerVars(
            ['HTTP_HOST' => 'client-machine:8000', 'HTTP_ACCEPT' => 'application/json'],
            fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', ''])
        );

        foreach ($headers as $h) {
            $this->assertStringStartsNotWith('Host:', $h, 'A böngésző/Kliens inbound Host fejlécét SOSE szabad vakon továbbítani — a curl a cél URL-ből állítja be helyesen.');
        }
    }

    public function testBuildOutboundHeadersNeverForwardsBrowserCookie(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $headers = $this->withServerVars(
            ['HTTP_COOKIE' => 'PHPSESSID=client-local-session-abc123', 'HTTP_ACCEPT' => 'application/json'],
            fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', ''])
        );

        $cookieHeaders = array_filter($headers, static fn ($h) => str_starts_with($h, 'Cookie:'));
        $this->assertCount(0, $cookieHeaders, 'A böngésző↔Kliens saját session-sütije sose kerülhet ki a Szerver felé.');
    }

    public function testBuildOutboundHeadersStripsHopByHopHeaders(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $headers = $this->withServerVars(
            [
                'HTTP_CONNECTION' => 'keep-alive',
                'HTTP_TRANSFER_ENCODING' => 'chunked',
                'HTTP_TE' => 'trailers',
                'HTTP_UPGRADE' => 'websocket',
                'HTTP_ACCEPT' => 'application/json',
            ],
            fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', ''])
        );

        $joined = implode('; ', $headers);
        foreach (['Connection:', 'Transfer-Encoding:', 'Te:', 'Upgrade:'] as $hopByHop) {
            $this->assertStringNotContainsString($hopByHop, $joined);
        }
        $this->assertStringContainsString('Accept: application/json', $joined, 'A nem hop-by-hop fejléceknek változatlanul át kell menniük.');
    }

    public function testBuildOutboundHeadersForwardsContentTypeFromNonHttpPrefixedServerKey(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $headers = $this->withServerVars(
            ['CONTENT_TYPE' => 'application/json'],
            fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', ''])
        );
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function testBuildOutboundHeadersNeverDuplicatesContentType(): void
    {
        // Valódi, élesben megfigyelt hiba: a PHP beépített szervere a
        // $_SERVER-ben MIND 'HTTP_CONTENT_TYPE'-ot, MIND 'CONTENT_TYPE'-ot
        // beteszi — enélkül a szűrés két külön Content-Type fejléc-sor
        // került volna kiküldésre, amit a fogadó oldal
        // "application/json, application/json" alakban látott.
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $headers = $this->withServerVars(
            ['HTTP_CONTENT_TYPE' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', ''])
        );
        $contentTypeHeaders = array_filter($headers, static fn ($h) => str_starts_with($h, 'Content-Type:'));
        $this->assertCount(1, $contentTypeHeaders, 'Pontosan EGY Content-Type fejléc-sor mehet ki, sose kettő.');
        $this->assertSame(['Content-Type: application/json'], array_values($contentTypeHeaders));
    }

    public function testBuildOutboundHeadersNeverForwardsContentLength(): void
    {
        // A Content-Length-et sose másoljuk kézzel — a curl maga számolja a
        // ténylegesen elküldött CURLOPT_POSTFIELDS hosszából.
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $headers = $this->withServerVars(
            ['CONTENT_LENGTH' => '1234', 'CONTENT_TYPE' => 'application/json'],
            fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', ''])
        );
        $lengthHeaders = array_filter($headers, static fn ($h) => str_starts_with($h, 'Content-Length:'));
        $this->assertCount(0, $lengthHeaders);
    }

    // -----------------------------------------------------------------
    // HMAC-fejlécek — a kimenő kérésen mindig jelen vannak, helyes aláírással
    // -----------------------------------------------------------------

    private function withSession(array $vars, callable $fn)
    {
        $backup = $_SESSION ?? [];
        $_SESSION = array_merge($_SESSION ?? [], $vars);
        try {
            return $fn();
        } finally {
            $_SESSION = $backup;
        }
    }

    public function testBuildOutboundHeadersIncludesValidHmacSignature(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000', 'client_id' => 'cl_test123', 'client_secret' => 'raw-secret-value']);
        $headers = $this->withServerVars(
            ['SCRIPT_NAME' => '/api/sale.php', 'QUERY_STRING' => 'foo=bar'],
            fn () => $this->withSession([], fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['POST', '{"a":1}']))
        );

        $byName = [];
        foreach ($headers as $h) {
            [$name, $value] = explode(': ', $h, 2);
            $byName[$name] = $value;
        }

        $this->assertSame('cl_test123', $byName['X-Client-Id'] ?? null);
        $this->assertArrayHasKey('X-Client-Timestamp', $byName);
        $this->assertArrayHasKey('X-Client-Nonce', $byName);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $byName['X-Client-Nonce']);

        // A Szerver-oldali ellenőrzés PONTOSAN ugyanígy számolná újra —
        // ha ez itt egyezik, a Kliens/Szerver kanonikus-sztring építése
        // konzisztens.
        $signingKey = ClientHmac::deriveSigningKey('raw-secret-value');
        $canonical = ClientHmac::canonicalString('POST', '/api/sale.php?foo=bar', $byName['X-Client-Timestamp'], $byName['X-Client-Nonce'], '{"a":1}');
        $expectedSignature = ClientHmac::sign($canonical, $signingKey);
        $this->assertSame($expectedSignature, $byName['X-Client-Signature']);
    }

    public function testBuildOutboundHeadersOmitsSessionBridgeHeadersWhenNoLocalSession(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000', 'client_id' => 'cl_x', 'client_secret' => 'secret']);
        $backup = $_SESSION ?? [];
        unset($_SESSION['ft_client_session_id'], $_SESSION['ft_client_csrf_token']);
        try {
            $headers = $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', '']);
        } finally {
            $_SESSION = $backup;
        }

        $joined = implode('; ', $headers);
        $this->assertStringNotContainsString('X-Client-Session-Id:', $joined);
        $this->assertStringNotContainsString('X-Client-Csrf-Token:', $joined);
    }

    public function testBuildOutboundHeadersIncludesSessionBridgeHeadersWhenPresentLocally(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000', 'client_id' => 'cl_x', 'client_secret' => 'secret']);
        $headers = $this->withSession(
            ['ft_client_session_id' => 'sess-abc', 'ft_client_csrf_token' => 'csrf-xyz'],
            fn () => $this->invokePrivate($proxy, 'buildOutboundHeaders', ['GET', ''])
        );

        $this->assertContains('X-Client-Session-Id: sess-abc', $headers);
        $this->assertContains('X-Client-Csrf-Token: csrf-xyz', $headers);
    }

    // -----------------------------------------------------------------
    // captureSessionBridgeHeaders() — a Szerver válaszából a helyi session-be
    // -----------------------------------------------------------------

    public function testCaptureSessionBridgeHeadersStoresSessionAndCsrfToken(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $raw = "HTTP/1.1 200 OK\r\nX-Client-Session-Id: newsess123\r\nX-Client-Csrf-Token: newcsrf456\r\nContent-Type: application/json\r\n\r\n";

        $this->withSession([], function () use ($proxy, $raw) {
            $this->invokePrivate($proxy, 'captureSessionBridgeHeaders', [$raw]);
            $this->assertSame('newsess123', $_SESSION['ft_client_session_id'] ?? null);
            $this->assertSame('newcsrf456', $_SESSION['ft_client_csrf_token'] ?? null);
        });
    }

    public function testCaptureSessionBridgeHeadersClearsOnSessionCleared(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $raw = "HTTP/1.1 200 OK\r\nX-Client-Session-Cleared: 1\r\nContent-Type: application/json\r\n\r\n";

        $this->withSession(['ft_client_session_id' => 'old', 'ft_client_csrf_token' => 'old'], function () use ($proxy, $raw) {
            $this->invokePrivate($proxy, 'captureSessionBridgeHeaders', [$raw]);
            $this->assertArrayNotHasKey('ft_client_session_id', $_SESSION);
            $this->assertArrayNotHasKey('ft_client_csrf_token', $_SESSION);
        });
    }

    public function testFilterResponseHeaderLinesStripsSessionBridgeHeadersFromBrowserRelay(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $raw = "HTTP/1.1 200 OK\r\nX-Client-Session-Id: s1\r\nX-Client-Csrf-Token: c1\r\nX-Client-Session-Cleared: 1\r\nContent-Type: application/json\r\n\r\n";
        $lines = $this->invokePrivate($proxy, 'filterResponseHeaderLines', [$raw]);

        $joined = implode('; ', $lines);
        $this->assertStringNotContainsString('X-Client-Session-Id', $joined, 'A böngésző sose láthatja a dolgozói munkamenet-híd fejléceit.');
        $this->assertStringNotContainsString('X-Client-Csrf-Token', $joined);
        $this->assertStringNotContainsString('X-Client-Session-Cleared', $joined);
    }

    // -----------------------------------------------------------------
    // filterResponseHeaderLines() — sose Set-Cookie, sose hop-by-hop, sose Content-Length
    // -----------------------------------------------------------------

    public function testFilterResponseHeaderLinesStripsSetCookie(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nSet-Cookie: PHPSESSID=server-side-secret; Path=/; HttpOnly\r\n\r\n";
        $lines = $this->invokePrivate($proxy, 'filterResponseHeaderLines', [$raw]);

        $joined = implode('; ', $lines);
        $this->assertStringNotContainsString('Set-Cookie', $joined, 'A Szerver saját session-sütije SOSE juthat el a böngészőig — más eredet, értelmezhetetlen/veszélyes keveredés lenne.');
        $this->assertStringContainsString('Content-Type: application/json', $joined);
    }

    public function testFilterResponseHeaderLinesStripsHopByHopAndContentLength(): void
    {
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"invoice.pdf\"\r\nContent-Length: 4096\r\nConnection: close\r\nTransfer-Encoding: chunked\r\n\r\n";
        $lines = $this->invokePrivate($proxy, 'filterResponseHeaderLines', [$raw]);

        $joined = implode('; ', $lines);
        $this->assertStringNotContainsString('Content-Length', $joined);
        $this->assertStringNotContainsString('Connection', $joined);
        $this->assertStringNotContainsString('Transfer-Encoding', $joined);
        $this->assertStringContainsString('Content-Type: application/pdf', $joined);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="invoice.pdf"', $joined, 'Content-Disposition-nak, mint üzletileg releváns fejlécnek, meg kell maradnia.');
    }

    public function testFilterResponseHeaderLinesUsesOnlyTheLastHeaderBlock(): void
    {
        // 100 Continue köztes válasz szimulálása — két fejléc-blokk, üres
        // sorral elválasztva; csak az utolsó (a tényleges végső válasz)
        // fejlécei számítanak.
        $proxy = new ClientProxy(['server_url' => 'http://192.168.1.10:8000']);
        $raw = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/csv\r\n\r\n";
        $lines = $this->invokePrivate($proxy, 'filterResponseHeaderLines', [$raw]);
        $this->assertContains('Content-Type: text/csv', $lines);
    }

    // -----------------------------------------------------------------
    // Hibakezelés
    // -----------------------------------------------------------------

    public function testForwardRespondsWithErrorWhenServerUrlMissing(): void
    {
        $proxy = new ClientProxy([]); // nincs 'server_url' kulcs
        ob_start();
        $proxy->forward();
        $output = ob_get_clean();
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }
}
