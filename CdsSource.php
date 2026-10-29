<?php

namespace Omnilab\Cds;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Http\Redaction;
use Omnilab\Model\Asset;
use Omnilab\Model\BBox;
use Omnilab\Model\Job;
use Omnilab\Model\Point;
use Omnilab\Model\Product;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\JobSourceInterface;
use Omnilab\Source\Page;
use Omnilab\Source\ProductQuery;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An ECMWF data store (the software behind the Copernicus Climate Data
 * Store, cds.climate.copernicus.eu, and the Atmosphere Data Store,
 * ads.atmosphere.copernicus.eu - one API, two catalogues): its datasets
 * (ERA5, ERA5-Land, CAMS's forecasts and reanalyses...) as products, and
 * the requests that make a file of one of them.
 *
 * Read without an account: the catalogue (products(), datasets(),
 * dataset() with its variables) and what a request would cost (cost()).
 * With a personal access token (the key option, sent as PRIVATE-TOKEN):
 * submit() a request, follow it (job(), jobs()) until its Product holds
 * the file to download as its "data" asset - a NetCDF, GRIB or zip the
 * application reads with its own tools: this source decodes nothing.
 *
 * A request is asynchronous on the service's side: accepted, queued,
 * running, then successful (or failed, rejected, dismissed); the source
 * never waits - the application asks job() again.
 */
final class CdsSource extends HttpSource implements JobSourceInterface
{
    public const CDS_URI = 'https://cds.climate.copernicus.eu/api/';
    public const ADS_URI = 'https://ads.atmosphere.copernicus.eu/api/';

    /** A request's states: queued, running, done, and the ends without a file (the core's Job's). */
    public const ACCEPTED = Job::ACCEPTED;
    public const RUNNING = Job::RUNNING;
    public const SUCCESSFUL = Job::SUCCESSFUL;
    public const FAILED = Job::FAILED;
    public const REJECTED = Job::REJECTED;
    public const DISMISSED = Job::DISMISSED;

    /** The most datasets a page of the catalogue's search answers. */
    public const MAX_PER_PAGE = 100;

    /**
     * @param string      $name    what the source is configured as: "cds", "cams" (the Atmosphere Data Store)...
     * @param string      $baseUri the store's API, ending in /api/
     * @param string|null $key     an account's personal access token, for submit(), job() and jobs()
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $name = 'cds',
        string $baseUri = self::CDS_URI,
        #[\SensitiveParameter] private readonly ?string $key = null,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function capabilities(): array
    {
        return [Capability::PRODUCTS, Capability::AREA, Capability::DATASETS, Capability::JOBS, Capability::OPEN];
    }

    /**
     * The store's datasets that cover the place and overlap the period
     * (every one when the query says neither), each a Product: its
     * footprint, its period, its catalogue's properties, its thumbnail.
     * The most recently updated first (oldest first with ProductQuery::OLDEST).
     */
    public function products(ProductQuery $query): Page
    {
        if (null !== $query->maxCloudCover) {
            throw NotSupportedException::operation($this->name, 'filter datasets by cloud cover');
        }
        $data = $this->getJson('catalogue/v1/collections') ?? [];
        $datasets = [];
        foreach ($data['collections'] ?? [] as $collection) {
            if (!\is_array($collection) || !isset($collection['id'])) {
                continue;
            }
            $product = $this->toDataset($collection);
            if (($query->collections && !\in_array($product->id, $query->collections, true)) || !self::covers($product, $query)) {
                continue;
            }
            $datasets[] = $product;
        }
        usort($datasets, static fn (Product $a, Product $b) => ProductQuery::OLDEST === $query->sort
            ? [$a->properties['updated'] ?? '', $a->id] <=> [$b->properties['updated'] ?? '', $b->id]
            : [$b->properties['updated'] ?? '', $a->id] <=> [$a->properties['updated'] ?? '', $b->id]);

        $offset = max(0, (int) $query->cursor);
        $limit = max(1, $query->limit);
        $page = \array_slice($datasets, $offset, $limit);

        return new Page($page, $offset + $limit < \count($datasets) ? (string) ($offset + $limit) : null, \count($datasets));
    }

    /**
     * The catalogue's datasets that match a text, the best match first
     * (its own search: titles, descriptions, keywords), a page at a time:
     * the contract's datasets().
     *
     * @return Page<Product>
     */
    public function datasets(Query $query): Page
    {
        $page = max(0, (int) $query->cursor);
        $data = $this->getJson('catalogue/v1/datasets', ['q' => $query->text, 'limit' => max(1, min(self::MAX_PER_PAGE, $query->limit)), 'page' => $page, 'sortby' => 'relevance']) ?? [];
        $datasets = array_values(array_map($this->toDataset(...), array_filter($data['collections'] ?? [], static fn (mixed $c) => \is_array($c) && isset($c['id']))));
        $next = null;
        foreach ($data['links'] ?? [] as $link) {
            if ('next' === ($link['rel'] ?? null)) {
                $next = (string) ($page + 1);
            }
        }

        return new Page($datasets, $datasets ? $next : null, isset($data['numberMatched']) ? (int) $data['numberMatched'] : null);
    }

    /**
     * One dataset by its id, with what a request of it takes: its inputs
     * ("inputs", by name: their title and their values, when the form
     * lists them) and its variables ("variables"). Null when unknown.
     */
    public function dataset(string $id): ?Product
    {
        $collection = $this->getJson('catalogue/v1/collections/'.rawurlencode($id));
        if (null === $collection) {
            return null;
        }
        $process = $this->getJson('retrieve/v1/processes/'.rawurlencode($id));
        if (null === $process) {
            return $this->toDataset($collection);
        }
        $inputs = [];
        foreach ($process['inputs'] ?? [] as $name => $input) {
            $schema = $input['schema'] ?? [];
            $values = $schema['items']['enum'] ?? $schema['enum'] ?? null;
            $inputs[(string) $name] = array_filter(['title' => $input['title'] ?? null, 'values' => \is_array($values) ? array_values(array_map('strval', $values)) : null, 'default' => $schema['default'] ?? null], static fn (mixed $v) => null !== $v);
        }

        return $this->toDataset($collection, ['inputs' => $inputs, 'variables' => $inputs['variable']['values'] ?? []]);
    }

    /**
     * What a request would cost against the store's limit, before it is
     * submitted - no account needed: ["cost" => 6.0, "limit" => 121000.0,
     * "valid" => true, "reason" => null]. A request over its limit, or
     * that the store finds invalid, would be refused.
     *
     * @param array<string, mixed> $request the dataset's inputs: ["variable" => ["2m_temperature"], "year" => ["2025"], ...]
     *
     * @return array{cost: float|null, limit: float|null, valid: bool, reason: string|null}
     */
    public function cost(string $dataset, array $request): array
    {
        $data = $this->postForJson('retrieve/v1/processes/'.rawurlencode($dataset).'/costing', ['inputs' => (object) $request]) ?? [];

        return [
            'cost' => isset($data['cost']) ? (float) $data['cost'] : null,
            'limit' => isset($data['limit']) ? (float) $data['limit'] : null,
            'valid' => false !== ($data['request_is_valid'] ?? true),
            'reason' => isset($data['invalid_reason']) ? (string) $data['invalid_reason'] : null,
        ];
    }

    /**
     * Submits a request for a file of a dataset; the store queues it. Its
     * Product: the job's id, the dataset as its collection, its status
     * (accepted, mostly) in properties. Needs the key, and the dataset's
     * licence accepted by the account on the store's site (a 403 before).
     *
     * @param array<string, mixed> $request the dataset's inputs (dataset() lists them)
     */
    public function submit(string $dataset, array $request): Job
    {
        $data = $this->postForJson('retrieve/v1/processes/'.rawurlencode($dataset).'/execution', ['inputs' => (object) $request], [], $this->auth());
        if (!isset($data['jobID'])) {
            throw new ProviderException($this->name, 'the store answered a request without a job');
        }

        return $this->toJob($data, null);
    }

    /**
     * A submitted request, as the store has it now; once successful, its
     * file is the Product's "data" asset (its address, type, size; the
     * checksum in properties). Null when the store does not know it (or no
     * longer: results are kept for a while, then deleted).
     */
    public function job(string $id): ?Job
    {
        $data = $this->getJson('retrieve/v1/jobs/'.rawurlencode($id), ['request' => 'true'], $this->auth());
        if (null === $data) {
            return null;
        }
        $results = null;
        if (self::SUCCESSFUL === ($data['status'] ?? null)) {
            $href = 'retrieve/v1/jobs/'.rawurlencode($id).'/results';
            foreach ($data['links'] ?? [] as $link) {
                if ('results' === ($link['rel'] ?? null) && \is_string($link['href'] ?? null)) {
                    $href = $link['href'];
                }
            }
            $results = [$this->resolve($href), $this->getJson($href, [], $this->auth())];
        }

        return $this->toJob($data, $results);
    }

    /**
     * The account's requests, the newest first (oldest first with
     * ProductQuery::OLDEST), a page at a time; without their files (job()
     * reads one's). The store lists them all together: a query that names
     * datasets, a place or a period is refused.
     *
     * @return Page<Job>
     */
    public function jobs(ProductQuery $query = new ProductQuery()): Page
    {
        if ($query->collections || null !== $query->place || null !== $query->from || null !== $query->to || null !== $query->maxCloudCover) {
            throw NotSupportedException::operation($this->name, 'list requests by dataset, place or period (the store lists them all, newest or oldest first)');
        }
        $auth = $this->auth();
        $data = null !== $query->cursor
            ? $this->getJson($query->cursor, [], $auth)
            : $this->getJson('retrieve/v1/jobs', ['limit' => max(1, min(self::MAX_PER_PAGE, $query->limit)), 'sortby' => ProductQuery::OLDEST === $query->sort ? 'created' : '-created'], $auth);
        $jobs = array_values(array_map(fn (array $job) => $this->toJob($job, null), array_filter($data['jobs'] ?? [], static fn (mixed $j) => \is_array($j) && isset($j['jobID']))));
        $next = null;
        foreach ($data['links'] ?? [] as $link) {
            if ('next' === ($link['rel'] ?? null) && \is_string($link['href'] ?? null)) {
                $next = $this->resolve($link['href']);
            }
        }

        return new Page($jobs, $jobs ? $next : null);
    }

    /**
     * A job's file (its "data" asset, or its address) read with the key
     * when there is one: the response, streamed. Nothing is decoded (a
     * NetCDF, a GRIB, a zip).
     */
    public function open(Asset|string $file): ResponseInterface
    {
        $href = $file instanceof Asset ? ($file->httpsHref() ?? $file->href) : $file;
        if (!str_starts_with($href, 'https://')) {
            throw NotSupportedException::operation($this->name, 'open an address other than https:// ('.$href.')');
        }
        try {
            return $this->http->request('GET', $href, ['headers' => (null === $this->key || '' === $this->key ? [] : ['PRIVATE-TOKEN' => $this->key]) + $this->headers]);
        } catch (TransportExceptionInterface $e) {
            // The token masked in the message and the previous, a copy; no trace keeps the headers.
            $secrets = Redaction::secrets($href, ['headers' => $this->headers]);
            if (null !== $this->key && '' !== $this->key) {
                $secrets[] = $this->key;
            }

            throw Redaction::withoutArguments(new UnavailableException($this->name, 'unreachable: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
    }

    /**
     * @param array<string, mixed> $collection a STAC collection of the catalogue
     * @param array<string, mixed> $more       properties read elsewhere (the request's inputs)
     */
    private function toDataset(array $collection, array $more = []): Product
    {
        $id = (string) $collection['id'];
        $bbox = $collection['extent']['spatial']['bbox'][0] ?? null;
        $interval = $collection['extent']['temporal']['interval'][0] ?? [null, null];
        $assets = [];
        foreach ($collection['assets'] ?? [] as $name => $asset) {
            if (\is_array($asset) && \is_string($asset['href'] ?? null)) {
                $assets[(string) $name] = new Asset((string) $name, $asset['href'], $asset['type'] ?? null, array_values(array_map('strval', $asset['roles'] ?? ['thumbnail' === $name ? 'thumbnail' : 'metadata'])), $asset['title'] ?? null);
            }
        }
        foreach ($collection['links'] ?? [] as $link) {
            // The request form and its constraints: what the store's site builds a request from.
            if (\in_array($link['rel'] ?? null, ['form', 'constraints'], true) && \is_string($link['href'] ?? null)) {
                $assets[$link['rel']] = new Asset($link['rel'], $link['href'], 'application/json', ['metadata'], 'form' === $link['rel'] ? 'Request form' : 'Request constraints');
            }
        }
        $properties = array_filter([
            'title' => $collection['title'] ?? null,
            'description' => $collection['description'] ?? null,
            'keywords' => $collection['keywords'] ?? null,
            'license' => $collection['license'] ?? null,
            'sci:doi' => $collection['sci:doi'] ?? null,
            'providers' => isset($collection['providers']) && \is_array($collection['providers']) ? array_values(array_filter(array_map(static fn (mixed $p) => \is_array($p) ? ($p['name'] ?? null) : null, $collection['providers']))) : null,
            'published' => $collection['published'] ?? null,
            'updated' => $collection['updated'] ?? null,
            'update_frequency' => $collection['cads:update_frequency'] ?? null,
            'disabled_reason' => $collection['cads:disabled_reason'] ?? null,
        ], static fn (mixed $v) => null !== $v && '' !== $v && [] !== $v) + $more;

        return new Product(
            id: $id,
            collection: $id,
            start: self::time($interval[0] ?? null),
            end: self::time($interval[1] ?? null),
            bbox: \is_array($bbox) && \count($bbox) >= 4 ? self::box($bbox) : null,
            properties: $properties,
            assets: $assets,
            preview: isset($assets['thumbnail']) ? $assets['thumbnail']->href : null,
            url: $this->site().'datasets/'.rawurlencode($id),
            source: $this->name,
        );
    }

    /**
     * A job as the core's Job: a Product in the making.
     *
     * @param array<string, mixed>                          $job
     * @param array{string, array<string, mixed>|null}|null $results the results' address and answer, when successful
     */
    private function toJob(array $job, ?array $results): Job
    {
        $properties = array_filter([
            'status' => $job['status'] ?? null,
            'created' => $job['created'] ?? null,
            'updated' => $job['updated'] ?? null,
            'request' => $job['metadata']['request']['ids'] ?? null,
        ], static fn (mixed $v) => null !== $v);
        $file = null;
        $value = $results[1]['asset']['value'] ?? null;
        if (\is_array($value) && \is_string($value['href'] ?? null)) {
            $file = new Asset(
                name: 'data',
                href: $this->resolve($value['href'], $results[0]),
                type: $value['type'] ?? null,
                roles: ['data'],
                size: isset($value['file:size']) ? (int) $value['file:size'] : null,
            );
            if (isset($value['file:checksum'])) {
                $properties['file:checksum'] = (string) $value['file:checksum'];
            }
        }
        $monitor = null;
        foreach ($job['links'] ?? [] as $link) {
            if ('monitor' === ($link['rel'] ?? null) && \is_string($link['href'] ?? null)) {
                $monitor = $this->resolve($link['href']);
            }
        }
        $status = (string) ($job['status'] ?? Job::ACCEPTED);

        return new Job(
            id: (string) $job['jobID'],
            dataset: (string) ($job['processID'] ?? ''),
            status: $status,
            // The store says no percentage: done is 100.
            progress: Job::SUCCESSFUL === $status ? 100.0 : null,
            created: self::time($job['created'] ?? null),
            started: self::time($job['started'] ?? null),
            finished: self::time($job['finished'] ?? null),
            properties: $properties,
            file: $file,
            url: $monitor ?? $this->resolve('retrieve/v1/jobs/'.rawurlencode((string) $job['jobID'])),
            source: $this->name,
        );
    }

    /** Whether a dataset covers the query's place and overlaps its period (what it does not say, it is taken to cover). */
    private static function covers(Product $dataset, ProductQuery $query): bool
    {
        if (null !== $query->place && null !== $dataset->bbox) {
            $box = $dataset->bbox;
            if ($query->place instanceof Point ? !$box->contains($query->place) : !self::intersects($box, $query->place)) {
                return false;
            }
        }
        if (null !== $query->from && null !== $dataset->end && $dataset->end < $query->from) {
            return false;
        }

        return null === $query->to || null === $dataset->start || $dataset->start <= $query->to;
    }

    private static function intersects(BBox $a, BBox $b): bool
    {
        if ($a->south > $b->north || $b->south > $a->north) {
            return false;
        }
        // Longitudes as intervals on a circle, an antimeridian crossing unrolled.
        $span = static fn (BBox $x) => $x->west <= $x->east ? [[$x->west, $x->east]] : [[$x->west, 180.0], [-180.0, $x->east]];
        foreach ($span($a) as [$w1, $e1]) {
            foreach ($span($b) as [$w2, $e2]) {
                if ($w1 <= $e2 && $w2 <= $e1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A catalogue's box, whose longitudes may run 0 to 360, in -180 to 180. */
    private static function box(array $bbox): BBox
    {
        [$west, $south, $east, $north] = array_map('floatval', \array_slice(array_values($bbox), 0, 4));
        if ($east - $west >= 360) {
            return new BBox(-180, max(-90, $south), 180, min(90, $north));
        }
        $wrap = static fn (float $lon) => $lon > 180 ? $lon - 360 : $lon;

        return new BBox($wrap($west), max(-90, $south), $wrap($east), min(90, $north));
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        if (null === $this->key || '' === $this->key) {
            throw NotSupportedException::operation($this->name, 'follow requests without a key (option key: an account\'s personal access token)');
        }

        return ['PRIVATE-TOKEN' => $this->key];
    }

    /** The store's site: its API's address without /api/. */
    private function site(): string
    {
        return (string) preg_replace('~api/?$~', '', rtrim($this->baseUri, '/').'/');
    }

    /** An address of the API's answers (absolute, relative to the API, or "./..." relative to $base) made absolute. */
    private function resolve(string $href, ?string $base = null): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $href)) {
            return $href;
        }
        if (null !== $base && str_starts_with($href, './')) {
            return substr($base, 0, (int) strrpos($base, '/') + 1).substr($href, 2);
        }

        return rtrim($this->baseUri, '/').'/'.ltrim($href, '/');
    }
}
