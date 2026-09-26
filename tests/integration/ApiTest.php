<?php

namespace oncode\rawsearch\tests\integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use oncode\rawsearch\tests\TestCase;

/**
 * Tests the JSON API over HTTP. Needs RAWSEARCH_TEST_URL, e.g. http://localhost:8080
 */
class ApiTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('RAWSEARCH_TEST_URL');

        if (!$url) {
            $this->markTestSkipped('RAWSEARCH_TEST_URL is not set.');
        }

        $this->client = new Client(['base_uri' => rtrim($url, '/') . '/', 'http_errors' => false, 'timeout' => 20]);

        try {
            $this->client->get('');
        } catch (ConnectException $e) {
            $this->markTestSkipped("$url is not reachable.");
        }
    }

    private function request(string $action, array $params = [], string $method = 'GET'): array
    {
        $options = $method === 'GET'
            ? ['query' => ['p' => "actions/rawsearch/api/$action"] + $params]
            : ['query' => ['p' => "actions/rawsearch/api/$action"], 'form_params' => $params];
        $response = $this->client->request($method, 'index.php', $options + ['headers' => ['Accept' => 'application/json']]);

        return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)];
    }

    public function testSearch(): void
    {
        [$status, $data] = $this->request('search', ['query' => 'quokka', 'resultsPerPage' => 2, 'page' => 2]);

        $this->assertSame(200, $status);
        $this->assertFalse($data['error']);
        $this->assertSame(3, $data['total']);
        $this->assertSame(['first' => 3, 'last' => 3, 'total' => 3, 'currentPage' => 2, 'totalPages' => 2], $data['pagination']);
        $this->assertCount(1, $data['result']);
        // elements are not serialized
        $this->assertArrayNotHasKey('element', $data['result'][0]);
        $this->assertArrayHasKey('url', $data['result'][0]);
    }

    public function testSearchParams(): void
    {
        [, $data] = $this->request('search', ['query' => 'quokka', 'mode' => 1, 'elementTypes' => 'entry', 'extract' => 0]);
        $this->assertSame(2, $data['total']);
        $this->assertArrayNotHasKey('extracts', $data['result'][0]);

        [, $data] = $this->request('search', ['query' => 'cyclists xylophonist', 'or' => 1]);
        $this->assertSame(2, $data['total']);

        [, $data] = $this->request('search', ['query' => 'velofahrer', 'site' => self::$fixture->deSite->handle]);
        $this->assertSame('Fährplan', $data['result'][0]['title']);

        [, $data] = $this->request('search', ['query' => 'quokka', 'extract' => ['radius' => 1, 'wrap' => '<b>{phrase}</b>']]);
        $this->assertStringContainsString('<b>', $data['result'][0]['extracts'][0]['text']);

        [, $data] = $this->request('search', ['query' => 'rottnest', 'extract' => ['type' => 'sentences', 'maxLength' => 300]]);
        $this->assertSame('<mark>Rottnest</mark> Island is home to the quokka.', $data['result'][0]['extracts'][0]['text']);
    }

    public function testSearchHtml(): void
    {
        [$status, $data] = $this->request('search', ['query' => 'rottnest', 'html' => 1]);

        $this->assertSame(200, $status);
        $this->assertIsString($data['result']);
        $this->assertStringContainsString('Quokka habitat protection', $data['result']);
        $this->assertStringContainsString('<mark>Rottnest</mark>', $data['result']);
    }

    public function testSearchErrors(): void
    {
        [$status, $data] = $this->request('search');
        $this->assertSame(400, $status);
        $this->assertTrue($data['error']);

        [$status, $data] = $this->request('search', ['query' => 'x', 'site' => 'doesNotExist']);
        $this->assertSame(400, $status);
        $this->assertSame('Invalid site: doesNotExist', $data['message']);
    }

    public function testAutocomplete(): void
    {
        [$status, $data] = $this->request('autocomplete', ['query' => 'quok', 'limit' => 2]);

        $this->assertSame(200, $status);
        $this->assertSame(['quokka', 'quokkas'], array_column($data['result'], 'word'));
    }

    public function testKeyProtectedActions(): void
    {
        foreach (['queries', 'reindex-elements', 'reindex-element-types'] as $action) {
            [$status] = $this->request($action);
            $this->assertSame(403, $status, $action);

            [$status] = $this->request($action, ['key' => 'wrong']);
            $this->assertSame(403, $status, $action);
        }
    }

    public function testKeyProtectedActionsWithKey(): void
    {
        $key = $this->plugin()->getSettings()->apiKey;
        $this->assertNotSame('', $key);

        [$status, $data] = $this->request('queries', ['key' => $key]);
        $this->assertSame(200, $status);
        $this->assertIsArray($data['result']);

        // POST without CSRF token works with the key
        [$status, $data] = $this->request('reindex-elements', ['key' => $key, 'elementIds' => self::$fixture->ids['ferry']], 'POST');
        $this->assertSame(200, $status);
        $this->assertFalse($data['error']);

        [$status, $data] = $this->request('reindex-element-types', ['key' => $key, 'elementTypes' => 'nope']);
        $this->assertSame(400, $status);

        \Craft::$app->getQueue()->run();
    }
}
