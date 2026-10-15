<?php

namespace Omnilab\Cds\Tests;

use Omnilab\Cds\CdsSource;
use Omnilab\Cds\CdsSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\BBox;
use Omnilab\Model\Point;
use Omnilab\Source\Capability;
use Omnilab\Source\ProductQuery;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from cds.climate.copernicus.eu/api on 2026-10-15,
 * without an account: the catalogue's collections (cut to seven of its
 * 144, each description to 300 characters), its search for "era5 land"
 * (three a page), ERA5 on single levels and its request form (the process
 * description), an unknown dataset (404), the cost of a request (one hour
 * of 2 m temperature over Strasbourg) and of one in the future (invalid),
 * and the 401 of a key the store does not know, for a submission.
 *
 * What needs an account was not called: job-accepted.json,
 * job-successful.json, job-failed.json, job-results.json and jobs.json are
 * built from the examples of ECMWF's own client
 * (github.com/ecmwf/ecmwf-datastores-client, tests/test_20_processing.py:
 * its job, its relative result address, its log), on the store's host;
 * the listing's shape is the client's ("jobs", "links"), its next cursor
 * a placeholder.
 */
final class CdsSourceTest extends TestCase
{
    private const KEY = '00000000-0000-0000-0000-000000000000';
    private const JOB = '9bfc1362-2832-48e1-a235-359267420bb2';

    /** @var list<array{string, string, string, list<string>}> */
    private array $calls = [];

    private function source(array $options = [], ?MockResponse $response = null): CdsSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $request) use ($response): MockResponse {
            $this->calls[] = [$method, urldecode($url), (string) ($request['body'] ?? ''), $request['normalized_headers']['private-token'] ?? []];
            if ($response) {
                return $response;
            }
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $fixture = match (true) {
                str_ends_with($path, '/catalogue/v1/collections') => 'catalogue-collections.json',
                str_ends_with($path, '/catalogue/v1/datasets') => 'catalogue-datasets-era5-land.json',
                str_ends_with($path, '/collections/reanalysis-era5-single-levels') => 'collection-reanalysis-era5-single-levels.json',
                str_ends_with($path, '/processes/reanalysis-era5-single-levels') => 'process-reanalysis-era5-single-levels.json',
                str_ends_with($path, '/costing') => str_contains((string) $request['body'], '2027') ? 'costing-invalid.json' : 'costing.json',
                str_ends_with($path, '/execution') => 'job-accepted.json',
                str_ends_with($path, '/results') => 'job-results.json',
                str_ends_with($path, '/jobs/'.self::JOB) => 'job-successful.json',
                str_contains($path, '/jobs/') => 'job-failed.json',
                str_ends_with($path, '/jobs') => 'jobs.json',
                default => 'collection-unknown.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => match ($fixture) {
                'collection-unknown.json' => 404,
                'job-accepted.json' => 201,
                default => 200,
            }]);
        });
        $source = (new CdsSourceFactory($http))->create($options);
        self::assertInstanceOf(CdsSource::class, $source);

        return $source;
    }

    public function testTheDatasetsOverAPlaceAndAPeriod(): void
    {
        $page = $this->source()->products(new ProductQuery(place: new Point(48.5734, 7.7521), from: new \DateTimeImmutable('1950-01-01'), to: new \DateTimeImmutable('1950-12-31'), limit: 3));

        // Five of the seven: the sea ice starts in 1978, the seasonal hydrology of Europe in 2020.
        self::assertSame(5, $page->total);
        self::assertSame('3', $page->next);
        self::assertCount(1, $this->calls, 'the catalogue in one call');
        $ids = array_map(static fn ($p) => $p->id, $page->items);
        self::assertContains('reanalysis-era5-single-levels', $ids);

        $era5 = $this->source()->products(new ProductQuery(['reanalysis-era5-single-levels']))->first();
        self::assertSame(['reanalysis-era5-single-levels', 'reanalysis-era5-single-levels', 'cds'], [$era5->id, $era5->collection, $era5->source]);
        self::assertSame('ERA5 hourly data on single levels from 1940 to present', $era5->properties['title']);
        self::assertSame(['1940-01-01', '2026-10-09'], [$era5->start->format('Y-m-d'), $era5->end->format('Y-m-d')]);
        self::assertSame([-180.0, -89.0, 180.0, 89.0], $era5->bbox->toArray(), 'the catalogue\'s 0 to 360 made -180 to 180');
        self::assertSame(['CC-BY-4.0', '10.24381/cds.adbb2d47'], [$era5->properties['license'], $era5->properties['sci:doi']]);
        self::assertSame('https://cds.climate.copernicus.eu/datasets/reanalysis-era5-single-levels', $era5->url);
        self::assertStringStartsWith('https://object-store.os-api.cci2.ecmwf.int:443/cci2-prod-catalogue/resources/reanalysis-era5-single-levels/overview_', (string) $era5->preview);
        self::assertSame(['thumbnail'], $era5->asset('thumbnail')->roles);
        self::assertNull($era5->asset('form'), 'the listing has no form link; dataset() has');
        self::assertNull($era5->datetime);
    }

    public function testABoxAndThePagesAfter(): void
    {
        $source = $this->source();
        // The box of Iceland: Europe's gridded observations reach 40° W, the seasonal hydrology 22° W.
        $page = $source->products(new ProductQuery(place: new BBox(-24.5, 63.3, -13.5, 66.6), limit: 10));
        $ids = array_map(static fn ($p) => $p->id, $page->items);
        self::assertContains('insitu-gridded-observations-europe', $ids);
        self::assertContains('sis-hydrology-variables-derived-seasonal-forecast', $ids, 'its box reaches 22° W');
        self::assertNull($page->next);

        $tail = $source->products(new ProductQuery(limit: 5, cursor: '5'));
        self::assertSame([2, 7, null], [\count($tail), $tail->total, $tail->next]);
        $oldest = $source->products(new ProductQuery(limit: 7, sort: ProductQuery::OLDEST));
        self::assertLessThanOrEqual($oldest->items[6]->properties['updated'], $oldest->items[0]->properties['updated']);
    }

    public function testTheCatalogueSearch(): void
    {
        $page = $this->source()->datasets(new Query('era5 land', limit: 3));

        self::assertSame(['reanalysis-era5-land-timeseries', 'reanalysis-era5-land', 'derived-era5-land-daily-statistics'], array_map(static fn ($p) => $p->id, $page->items));
        self::assertSame([36, '1'], [$page->total, $page->next]);
        self::assertStringContainsString('catalogue/v1/datasets?q=era5 land&limit=3&page=0&sortby=relevance', $this->calls[0][1]);
    }

    public function testADatasetWithItsVariables(): void
    {
        $source = $this->source();
        $era5 = $source->dataset('reanalysis-era5-single-levels');

        self::assertCount(2, $this->calls, 'the collection, then its request form');
        self::assertCount(262, $era5->properties['variables']);
        self::assertContains('2m_temperature', $era5->properties['variables']);
        self::assertSame(['product_type', 'variable', 'year', 'month', 'day', 'time', 'area', 'data_format', 'download_format'], array_keys($era5->properties['inputs']));
        self::assertSame('Variable', $era5->properties['inputs']['variable']['title']);
        self::assertSame(['metadata'], $era5->asset('form')->roles);
        self::assertStringEndsWith('.json', $era5->asset('constraints')->href);
        self::assertNull($source->dataset('nope-nope'));
    }

    public function testWhatARequestWouldCost(): void
    {
        $source = $this->source();
        $request = ['product_type' => ['reanalysis'], 'variable' => ['2m_temperature'], 'year' => ['2025'], 'month' => ['07'], 'day' => ['01'], 'time' => ['12:00'], 'data_format' => 'netcdf', 'area' => [49, 7, 48, 8]];

        self::assertSame(['cost' => 6.0, 'limit' => 121000.0, 'valid' => true, 'reason' => null], $source->cost('reanalysis-era5-single-levels', $request));
        [$method, $url, $body, $key] = $this->calls[0];
        self::assertSame(['POST', 'https://cds.climate.copernicus.eu/api/retrieve/v1/processes/reanalysis-era5-single-levels/costing', []], [$method, $url, $key], 'no key sent');
        self::assertSame(['inputs' => $request], json_decode($body, true));

        $future = $source->cost('reanalysis-era5-single-levels', ['variable' => ['2m_temperature'], 'year' => ['2027'], 'month' => ['01'], 'day' => ['01'], 'time' => ['00:00'], 'product_type' => ['reanalysis']]);
        self::assertFalse($future['valid']);
        self::assertStringStartsWith('None of the data you have requested is available yet', (string) $future['reason']);
    }

    public function testSubmitThenFollowARequest(): void
    {
        $source = $this->source(['key' => self::KEY]);
        $job = $source->submit('reanalysis-era5-pressure-levels', ['variable' => 'temperature', 'year' => '2022']);

        self::assertSame([self::JOB, 'reanalysis-era5-pressure-levels', CdsSource::ACCEPTED], [$job->id, $job->collection, $job->properties['status']]);
        self::assertSame([], $job->assets, 'no file yet');
        self::assertNull($job->start);
        self::assertSame('2022-09-02T17:30:48', $job->datetime->format('Y-m-d\TH:i:s'));
        self::assertSame('https://cds.climate.copernicus.eu/api/retrieve/v1/jobs/'.self::JOB, $job->url);
        self::assertSame(['POST', ['PRIVATE-TOKEN: '.self::KEY]], [$this->calls[0][0], $this->calls[0][3]]);
        self::assertSame(['inputs' => ['variable' => 'temperature', 'year' => '2022']], json_decode($this->calls[0][2], true));

        $done = $source->job(self::JOB);
        self::assertSame(CdsSource::SUCCESSFUL, $done->properties['status']);
        self::assertSame(['variable' => 'temperature', 'year' => '2022'], $done->properties['request']);
        self::assertSame('2022-09-02T17:32:54', $done->end->format('Y-m-d\TH:i:s'));
        $file = $done->asset('data');
        self::assertSame('https://cds.climate.copernicus.eu/api/retrieve/v1/jobs/'.self::JOB.'/e7d452a747061ab880887d88814bfb0c27593a73cb7736d2dc340852', $file->href, 'the relative address made absolute');
        self::assertSame(['application/netcdf', 8, ['data']], [$file->type, $file->size, $file->roles]);
        self::assertSame('e7d452a747061ab880887d88814bfb0c27593a73cb7736d2dc340852', $done->properties['file:checksum']);
        self::assertStringEndsWith('/jobs/'.self::JOB.'?request=true', $this->calls[1][1]);
        self::assertStringEndsWith('/jobs/'.self::JOB.'/results', $this->calls[2][1]);
        self::assertSame(['PRIVATE-TOKEN: '.self::KEY], $this->calls[2][3]);

        $failed = $source->job('9bfc1362-2832-48e1-a235-359267420bb3');
        self::assertSame([CdsSource::FAILED, []], [$failed->properties['status'], $failed->assets]);
        self::assertCount(4, $this->calls, 'no results asked of a failed request');
    }

    public function testTheAccountsRequests(): void
    {
        $source = $this->source(['key' => self::KEY]);
        $page = $source->jobs(new ProductQuery(limit: 2));

        self::assertSame([self::JOB, '9bfc1362-2832-48e1-a235-359267420bb3'], array_map(static fn ($p) => $p->id, $page->items));
        self::assertStringContainsString('retrieve/v1/jobs?limit=2&sortby=-created', $this->calls[0][1]);
        self::assertStringStartsWith('https://cds.climate.copernicus.eu/api/retrieve/v1/jobs?limit=2&sortby=-created&cursor=', (string) $page->next);
        $source->jobs(new ProductQuery(cursor: $page->next));
        self::assertSame($page->next, $this->calls[1][1]);
    }

    public function testRefusedBeforeAnyCall(): void
    {
        $open = $this->source();
        $keyed = $this->source(['key' => self::KEY]);
        $refusals = [
            static fn () => $open->submit('reanalysis-era5-single-levels', []),
            static fn () => $open->job(self::JOB),
            static fn () => $open->jobs(),
            static fn () => $keyed->jobs(new ProductQuery(['reanalysis-era5-land'])),
            static fn () => $open->products(new ProductQuery(maxCloudCover: 30)),
            static fn () => $open->entity('reanalysis-era5-land'),
            static fn () => $open->search(new Query('era5')),
            static fn () => $open->series(new SeriesQuery(['2m_temperature'], new Point(48.5734, 7.7521))),
        ];
        foreach ($refusals as $i => $refusal) {
            try {
                $refusal();
                self::fail("refusal $i answered");
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testCapabilitiesAndName(): void
    {
        self::assertSame('cds', (new CdsSourceFactory())->getName());
        self::assertSame('cds', $this->source()->getName());
        self::assertSame([Capability::PRODUCTS, Capability::AREA], $this->source()->capabilities());
    }

    public function testErrors(): void
    {
        try {
            $this->source(['key' => self::KEY], new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/execution-bad-key.json'), ['http_code' => 401]))->submit('reanalysis-era5-single-levels', []);
            self::fail('a 401 answered');
        } catch (ProviderException $e) {
            self::assertStringContainsString('401: Authentication failed: operation not allowed', $e->getMessage());
        }
        try {
            $this->source([], new MockResponse('', ['http_code' => 502]))->cost('reanalysis-era5-single-levels', []);
            self::fail('a 502 answered');
        } catch (UnavailableException) {
        }
        try {
            $this->source([], new MockResponse('', ['http_code' => 503]))->products(new ProductQuery());
            self::fail('a 503 answered');
        } catch (UnavailableException) {
        }
        $this->expectException(RateLimitedException::class);
        $this->source(['key' => self::KEY], new MockResponse('', ['http_code' => 429]))->submit('reanalysis-era5-single-levels', []);
    }
}
