<?php declare(strict_types=1);

namespace SearchSolrTest\Unit\Querier;

use AdvancedSearch\Query;
use AdvancedSearch\Response;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use SearchSolr\Querier\SolariumQuerier;
use Solarium\Client as SolariumClient;
use Solarium\QueryType\Suggester\Query as SuggesterQuery;
use Solarium\QueryType\Suggester\Result\Dictionary;
use Solarium\QueryType\Suggester\Result\Result as SuggesterResult;
use Solarium\QueryType\Suggester\Result\Term;

/**
 * Unit tests for SolariumQuerier suggester functionality with mocks.
 *
 * These tests don't require a real Solr server.
 *
 * @group unit
 * @group suggester
 * @group solr
 */
class SolariumQuerierSuggesterTest extends TestCase
{
    /**
     * Test getSuggesterNames with single field.
     */
    public function testGetSuggesterNamesWithSingleField(): void
    {
        $querier = $this->createQuerierForTesting();

        $options = [
            'solr_suggester_name' => 'omeka_suggester',
            'solr_fields' => ['dcterms_title_txt'],
        ];

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [$options]);

        $this->assertEquals('omeka_suggester', $result);
    }

    /**
     * Test getSuggesterNames with multiple fields.
     */
    public function testGetSuggesterNamesWithMultipleFields(): void
    {
        $querier = $this->createQuerierForTesting();

        $options = [
            'solr_suggester_name' => 'omeka_suggester',
            'solr_fields' => ['dcterms_title_txt', 'dcterms_creator_txt'],
        ];

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [$options]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('omeka_suggester_dcterms_title_txt', $result[0]);
        $this->assertEquals('omeka_suggester_dcterms_creator_txt', $result[1]);
    }

    /**
     * Test getSuggesterNames with _text_ selected.
     */
    public function testGetSuggesterNamesWithTextCatchall(): void
    {
        $querier = $this->createQuerierForTesting();

        $options = [
            'solr_suggester_name' => 'omeka_suggester',
            'solr_fields' => ['_text_'],
        ];

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [$options]);

        $this->assertEquals('omeka_suggester', $result);
    }

    /**
     * Test getSuggesterNames with _text_ mixed with other fields.
     */
    public function testGetSuggesterNamesWithTextMixedIgnoresOthers(): void
    {
        $querier = $this->createQuerierForTesting();

        $options = [
            'solr_suggester_name' => 'omeka_suggester',
            'solr_fields' => ['_text_', 'dcterms_title_txt', 'dcterms_creator_txt'],
        ];

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [$options]);

        // When _text_ is present, only _text_ should be used.
        $this->assertEquals('omeka_suggester', $result);
    }

    /**
     * Test getSuggesterNames with legacy solr_field (backward compatibility).
     */
    public function testGetSuggesterNamesWithLegacySolrField(): void
    {
        $querier = $this->createQuerierForTesting();

        $options = [
            'solr_suggester_name' => 'omeka_suggester',
            'solr_field' => 'dcterms_title_txt',
        ];

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [$options]);

        $this->assertEquals('omeka_suggester', $result);
    }

    /**
     * Test getSuggesterNames with empty fields falls back to suggester name.
     */
    public function testGetSuggesterNamesWithEmptyFieldsFallsBack(): void
    {
        $querier = $this->createQuerierForTesting();

        $options = [
            'solr_suggester_name' => 'my_suggester',
            'solr_fields' => [],
        ];

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [$options]);

        $this->assertEquals('my_suggester', $result);
    }

    /**
     * Test getSuggesterNames with no config returns null.
     */
    public function testGetSuggesterNamesWithNoConfigReturnsNull(): void
    {
        $querier = $this->createQuerierForTesting();

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [[]]);

        $this->assertNull($result);
    }

    /**
     * Test getSuggesterNames uses default base name.
     */
    public function testGetSuggesterNamesUsesDefaultBaseName(): void
    {
        $querier = $this->createQuerierForTesting();

        $options = [
            'solr_fields' => ['dcterms_title_txt', 'dcterms_creator_txt'],
        ];

        $result = $this->invokeMethod($querier, 'getSuggesterNames', [$options]);

        $this->assertIsArray($result);
        $this->assertEquals('omeka_suggester_dcterms_title_txt', $result[0]);
        $this->assertEquals('omeka_suggester_dcterms_creator_txt', $result[1]);
    }

    /**
     * Test querySuggestions returns empty for empty query.
     */
    public function testQuerySuggestionsReturnsEmptyForEmptyQuery(): void
    {
        $querier = $this->createQuerierWithMockedClient();

        $query = $this->createMock(Query::class);
        $query->method('getQuery')->willReturn('');
        $query->method('getSuggestOptions')->willReturn([
            'solr_suggester_name' => 'omeka_suggester',
            'solr_fields' => ['_text_'],
        ]);

        $querier->setQuery($query);
        $response = $querier->querySuggestions();

        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->getSuggestions());
    }

    /**
     * Test querySuggestions returns error when not configured.
     */
    public function testQuerySuggestionsReturnsErrorWhenNotConfigured(): void
    {
        $querier = $this->createQuerierWithMockedClient();

        $query = $this->createMock(Query::class);
        $query->method('getQuery')->willReturn('paris');
        $query->method('getSuggestOptions')->willReturn([]);

        $querier->setQuery($query);
        $response = $querier->querySuggestions();

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('not configured', $response->getMessage());
    }

    /**
     * Test querySuggestions with mocked Solr response.
     */
    public function testQuerySuggestionsWithMockedResponse(): void
    {
        // Create mock suggestions.
        $mockSuggestions = [
            ['term' => 'Paris', 'weight' => 100],
            ['term' => 'Paris France', 'weight' => 50],
        ];

        $querier = $this->createQuerierWithMockedSuggesterResponse($mockSuggestions);

        $query = $this->createMock(Query::class);
        $query->method('getQuery')->willReturn('par');
        $query->method('getLimit')->willReturn(10);
        $query->method('getSuggestOptions')->willReturn([
            'solr_suggester_name' => 'omeka_suggester',
            'solr_fields' => ['_text_'],
        ]);

        $querier->setQuery($query);
        $response = $querier->querySuggestions();

        $this->assertTrue($response->isSuccess());
        $suggestions = $response->getSuggestions();
        $this->assertCount(2, $suggestions);
        $this->assertEquals('Paris', $suggestions[0]['value']);
        $this->assertEquals(100, $suggestions[0]['data']);
        $this->assertEquals('Paris France', $suggestions[1]['value']);
        $this->assertEquals(50, $suggestions[1]['data']);
    }

    /**
     * Test querySuggestions handles Solr exception.
     */
    public function testQuerySuggestionsHandlesSolrException(): void
    {
        $querier = $this->createQuerierWithExceptionThrowingClient();

        $query = $this->createMock(Query::class);
        $query->method('getQuery')->willReturn('par');
        $query->method('getLimit')->willReturn(10);
        $query->method('getSuggestOptions')->willReturn([
            'solr_suggester_name' => 'omeka_suggester',
            'solr_fields' => ['_text_'],
        ]);

        $querier->setQuery($query);
        $response = $querier->querySuggestions();

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('error', $response->getMessage());
    }

    /**
     * Create a basic querier for testing protected methods.
     */
    protected function createQuerierForTesting(): SolariumQuerier
    {
        $services = $this->createMock(ServiceManager::class);
        $services->method('get')->willReturnCallback(function ($name) {
            if ($name === 'Omeka\ApiManager') {
                return $this->createMock(\Omeka\Api\Manager::class);
            }
            if ($name === 'Omeka\Logger') {
                return $this->createMock(\Laminas\Log\Logger::class);
            }
            return null;
        });

        $querier = new SolariumQuerier();
        $this->setProperty($querier, 'services', $services);

        return $querier;
    }

    /**
     * Create a querier with a mocked Solarium client.
     */
    protected function createQuerierWithMockedClient(): SolariumQuerier
    {
        $querier = $this->createQuerierForTesting();

        $mockClient = $this->createMock(SolariumClient::class);
        $this->setProperty($querier, 'solariumClient', $mockClient);

        // Bypass appendCoreAliasesToQuery() which needs a real Solr core.
        $this->setProperty($querier, 'aliasesAppended', true);

        return $querier;
    }

    /**
     * Create a querier with mocked suggester response.
     */
    protected function createQuerierWithMockedSuggesterResponse(array $suggestions): SolariumQuerier
    {
        $querier = $this->createQuerierForTesting();

        // Create mock Term with suggestions.
        $mockTerm = $this->createMock(Term::class);
        $mockTerm->method('getSuggestions')->willReturn($suggestions);

        // Create mock Dictionary that yields the term.
        $mockDictionary = $this->createMock(Dictionary::class);
        $mockDictionary->method('getIterator')->willReturn(new \ArrayIterator([$mockTerm]));

        // Create mock Result that yields the dictionary.
        $mockResult = $this->createMock(SuggesterResult::class);
        $mockResult->method('getIterator')->willReturn(new \ArrayIterator([$mockDictionary]));

        // Create mock suggester query.
        $mockSuggesterQuery = $this->createMock(SuggesterQuery::class);
        $mockSuggesterQuery->method('setQuery')->willReturnSelf();
        $mockSuggesterQuery->method('setDictionary')->willReturnSelf();
        $mockSuggesterQuery->method('setCount')->willReturnSelf();

        // Create mock client.
        $mockClient = $this->createMock(SolariumClient::class);
        $mockClient->method('createSuggester')->willReturn($mockSuggesterQuery);
        $mockClient->method('suggester')->willReturn($mockResult);

        $this->setProperty($querier, 'solariumClient', $mockClient);

        // Bypass appendCoreAliasesToQuery() which needs a real Solr core.
        $this->setProperty($querier, 'aliasesAppended', true);

        return $querier;
    }

    /**
     * Create a querier with an exception-throwing client.
     */
    protected function createQuerierWithExceptionThrowingClient(): SolariumQuerier
    {
        $querier = $this->createQuerierForTesting();

        $mockSuggesterQuery = $this->createMock(SuggesterQuery::class);
        $mockSuggesterQuery->method('setQuery')->willReturnSelf();
        $mockSuggesterQuery->method('setDictionary')->willReturnSelf();
        $mockSuggesterQuery->method('setCount')->willReturnSelf();

        $mockClient = $this->createMock(SolariumClient::class);
        $mockClient->method('createSuggester')->willReturn($mockSuggesterQuery);
        $mockClient->method('suggester')->willThrowException(
            new \Solarium\Exception\HttpException('Solr connection error')
        );

        $this->setProperty($querier, 'solariumClient', $mockClient);

        // Bypass appendCoreAliasesToQuery() which needs a real Solr core.
        $this->setProperty($querier, 'aliasesAppended', true);

        // Set mock logger for error handling.
        $mockLogger = $this->createMock(\Laminas\Log\Logger::class);
        $querier->setLogger($mockLogger);

        return $querier;
    }

    /**
     * Invoke a protected/private method.
     *
     * @param object $object
     * @param string $methodName
     * @param array $parameters
     * @return mixed
     */
    protected function invokeMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Set a protected/private property.
     *
     * @param object $object
     * @param string $propertyName
     * @param mixed $value
     */
    protected function setProperty(object $object, string $propertyName, $value): void
    {
        $reflection = new \ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
