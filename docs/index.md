# omnilab/cds

The Copernicus Climate Data Store (`https://cds.climate.copernicus.eu/api/`: its catalogue
`catalogue/v1`, STAC; its requests `retrieve/v1`, OGC API - Processes) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): ERA5, ERA5-Land, the seasonal
forecasts, the climate projections and the store's other datasets as products and datasets,
what a request would cost; with an account's key, the requests that make their files, followed as
`Job`s. One client for ECMWF's data stores: omnilab/cams builds it on the Atmosphere Data Store.

## Installation

```sh
composer require omnilab/cds
```

```php
use Omnilab\Cds\CdsSourceFactory;

$cds = (new CdsSourceFactory($httpClient))->create([
    'key' => getenv('CDS_KEY'),        // optional: an account's personal access token, for requests
    'mailto' => 'support@glitchr.io',  // optional: in the User-Agent
]);
```

With the Symfony bundle, the factory is registered as `cds` when the bundle knows it; otherwise
register `CdsSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Credentials

None to read the catalogue (`products()`, `datasets()`, `dataset()`, `cost()`). A request for a
file (`submit()`, `job()`, `jobs()`) needs the personal access token of a Climate Data Store
account, from its profile page on `cds.climate.copernicus.eu`, given as `key` and sent as
`PRIVATE-TOKEN`; the account accepts each dataset's licence once on the store's site before its
first request (a 403 before). The key is read from the configuration, never written.

## Options

The factory's options, with the defaults it applies (`CdsSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `key` | `?string` | null | no | a CDS account's personal access token: `submit()`, `job()`, `jobs()` need it (refused without it, before any call); `open()` sends it when set |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the store queues requests and limits their cost, it publishes no rate |
| `base_uri` | `string` | `https://cds.climate.copernicus.eu/api/` | no | the store's API, ending in `/api/`: another ECMWF data store by its `/api/` address (the Atmosphere Data Store is omnilab/cams's) |

## Capabilities

`capabilities()` answers `products`, `area`, `datasets`, `jobs`, `open`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | no | a dataset is a `Product`, not an entity; ERA5 as series at a point is omnilab/open-meteo's |
| `search` | no |  |
| `series` | no |  |
| `products` | yes | the datasets that cover the place and overlap the period, the most recently updated first (oldest with `ProductQuery::OLDEST`); `collections` keeps those named; one call for the whole catalogue, paged by offset, `total` counted |
| `occurrences` | no |  |
| `datasets` | yes | the catalogue's own search, the best match first, 100 a page at most, `total` the store's count |
| `jobs` | yes | implements `JobSourceInterface`; with the `key` only |
| `open` | yes | a job's file (its `data` asset) or any `https://` address, the key sent when set; the response streamed, its status unchecked |
| `structure` | no |  |
| `cross_references` | no |  |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | yes | a `BBox` or a `Point`, against each dataset's extent |
| `station` | no |  |
| `cloud_cover` | no | a dataset has no cloud cover: `maxCloudCover` is refused |

## Which API

The Climate Data Store's API (`https://cds.climate.copernicus.eu/api`), the one ECMWF's own client
(`ecmwf-datastores-client`, formerly `cads-api-client`; `cdsapi` on top of it) calls since the
store moved to its new infrastructure in 2024:

- the **catalogue**, a STAC API (`/catalogue/v1`): every dataset as a STAC collection
  (`/collections`, the 144 in one answer), one by its id (`/collections/{id}`), and the store's
  search (`/datasets?q=&sortby=relevance&page=`) - open;
- the **retrieve** API, OGC API - Processes (`/retrieve/v1`): a dataset's request form
  (`/processes/{id}`: its inputs and their values) and what a request would cost
  (`/processes/{id}/costing`) - open; a request submitted (`/processes/{id}/execution`), followed
  (`/jobs/{id}`), its file (`/jobs/{id}/results`), the account's requests (`/jobs`) - with the key,
  sent as `PRIVATE-TOKEN`.

## The contract, and beside it

A dataset is catalogued, has a footprint and a period, and its data come as files: the core's
**product**. `products()` is the contract's part; the rest is the store's own, as public methods
of `CdsSource`:

| Method | Answers | Calls |
|---|---|---|
| `products(ProductQuery)` | the datasets that cover the `Point` or meet the `BBox`, and overlap the period (every one when the query says neither), those named in `collections` when it names some; the most recently updated first (`OLDEST`: the least); a page of `limit` from the `cursor` (an offset); `total` the count | `catalogue/v1/collections` (one call, whatever the page) |
| `datasets(Query)` | the catalogue's search by text, the best match first, 100 a page at most; `total` the store's count | `catalogue/v1/datasets?q=&limit=&page=&sortby=relevance` |
| `dataset($id)` | one dataset, with `inputs` (each input's `title`, `values`, `default`) and `variables` in its properties; null when unknown | `catalogue/v1/collections/{id}`, `retrieve/v1/processes/{id}` |
| `cost($id, $request)` | `cost`, `limit`, `valid`, `reason` (the store's words when it finds the request invalid: a period not yet available, a combination it does not have) | POST `retrieve/v1/processes/{id}/costing` `{"inputs": ...}` |
| `open(Asset\|string)` | a job's file (or any https:// address of the store), with the key when there is one: the response, streamed, not read | GET, `PRIVATE-TOKEN` |
| `submit($id, $request)` | the request's `Job` (a `Product`): the job's id, its status (`accepted`) | POST `retrieve/v1/processes/{id}/execution` `{"inputs": ...}`, with the key |
| `job($jobId)` | the request as the store has it now, a `Job`: its `status`, its `progress` (100 once successful: the store gives no percentage), its request, its times; once `successful`, its file (`file()`, the `data` asset); null when unknown | `retrieve/v1/jobs/{id}?request=true`, then its `results` link when successful |
| `jobs(ProductQuery)` | the account's requests, newest first (`OLDEST`), `limit` a page, the next by the store's `next` link (the cursor); a query naming datasets, a place or a period is refused | `retrieve/v1/jobs?limit=&sortby=-created` |

In the contract since the core named them on 2026-10-15: `datasets()` and `open()`
(`Capability::DATASETS`, `OPEN`), and `submit()`, `job()`, `jobs()` as the core's
`JobSourceInterface` (`Capability::JOBS`), answering the core's `Job` - a `Product`, so every read
of before (`properties['status']`, `asset('data')`) answers the same. `dataset()` and `cost()` stay
the store's own.

The store never waits for the source: `submit()` returns at once, and the application asks `job()`
again (ECMWF's client waits from one second up to two minutes between two asks, growing by half).
A request's states: `accepted` (queued), `running`, `successful`; `failed`, `rejected`, `dismissed`
end it without a file (`CdsSource::ACCEPTED`... `DISMISSED`).

`series()`, `entity()`, `search()`, `occurrences()`: not supported. ERA5 as series at a point is
Open-Meteo's (omnilab/open-meteo serves its archive) - or a CDS request of
`reanalysis-era5-single-levels-timeseries`, whose CSV the application reads.

## What a product holds

A dataset: `id` and `collection` its id (`reanalysis-era5-single-levels`), `start` and `end` its
period (`end` null when the catalogue leaves it open), `bbox` its footprint (the catalogue's 0 to
360 longitudes made -180 to 180), `properties`: `title`, `description`, `keywords`, `license`
(`CC-BY-4.0`), `sci:doi`, `providers`, `published`, `updated`, `update_frequency`,
`disabled_reason` when the store says one, and from `dataset()` `inputs` and `variables`;
`assets`: `thumbnail` (the overview image, open; also `preview`), and from `dataset()` `form` and
`constraints` (the request form and its valid combinations, JSON, role `metadata`); `url` its page
(`https://cds.climate.copernicus.eu/datasets/{id}`).

A request: `id` the job's (`9bfc1362-...`), `collection` the dataset, `datetime` when it was
created, `start` and `end` when it ran, `properties`: `status`, `created`, `updated`, `request`
(the inputs as the store keeps them), `file:checksum`; `assets`: `data` once successful (`href`
absolute - the store may answer it relative to the results' address -, `type` such as
`application/netcdf` or `application/x-grib`, `size` in bytes); `url` the job's address.

## Refused before any call

`submit()`, `job()`, `jobs()` without a key; `jobs()` with datasets, a place or a period; a cloud
cover in `products()` (datasets have none). An answer that is not one: a `ProviderException` with
the store's title and detail (401 "Authentication failed: operation not allowed" for a key it does
not know), `UnavailableException` for a 5xx, `RateLimitedException` for a 429.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Cds\CdsSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$cds = (new CdsSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'key' => getenv('CDS_KEY') ?: null]);

$found = $cds->datasets(new Query('era5 land', limit: 3));
printf("%d datasets match \"era5 land\"; the first %d:\n", $found->total, count($found));
foreach ($found as $dataset) {
    printf("  %s  %s..%s\n", $dataset->id, $dataset->start?->format('Y-m-d'), $dataset->end?->format('Y-m-d') ?? 'open');
}

$request = ['product_type' => ['reanalysis'], 'variable' => ['2m_temperature'], 'year' => ['2025'], 'month' => ['07'], 'day' => ['01'], 'time' => ['12:00'], 'data_format' => 'netcdf', 'area' => [49, 7, 48, 8]];
$cost = $cds->cost('reanalysis-era5-single-levels', $request);
printf("one hour of 2 m temperature over Strasbourg costs %s of %s: %s\n", $cost['cost'], $cost['limit'], $cost['valid'] ? 'valid' : $cost['reason']);
```

```
37 datasets match "era5 land"; the first 3:
  reanalysis-era5-land-timeseries  1950-01-01..2026-12-31
  reanalysis-era5-land  1950-01-01..2026-10-20
  derived-era5-land-daily-statistics  1950-01-01..2026-10-20
one hour of 2 m temperature over Strasbourg costs 6 of 121000: valid
```

(as answered on 2026-10-27, without an account). With a key the same `$request` is submitted and
followed to its file ([the core's datasets guide](https://github.com/glitchr-omnisense/omnilab/blob/1.x/docs/datasets.md#asynchronous-requests-job)):
`$job = $cds->submit('reanalysis-era5-single-levels', $request)`, `$cds->job($job->id)` until
`isDone()`, then `$cds->open($job->file())` - not run here (no account).

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        cds: { factory: cds, options: { mailto: '%env(OMNILAB_MAILTO)%', key: '%env(default::CDS_KEY)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

The refusals before any call are [above](#refused-before-any-call). Besides: `submit()` of an
answer without a job id is a `ProviderException`; the store's problem details (RFC 9457, title
and detail) are read into the message. `open()` maps only an unreachable host
(`UnavailableException`): the response is returned as it is, its status the caller's to check.

## Verified, and not

Against the real store on 2026-10-15, without an account: the catalogue's 144 datasets, 43 of them
over Strasbourg in 1950 (`derived-era5-land-daily-statistics`, `reanalysis-era5-land`,
`reanalysis-era5-pressure-levels`... - the most recently updated first, many on 2026-10-15); the
search for "era5 land" (36 matches: `reanalysis-era5-land-timeseries`, `reanalysis-era5-land`,
`derived-era5-land-daily-statistics` first); ERA5 on single levels (1940-01-01 to 2026-10-09,
CC-BY-4.0, DOI 10.24381/cds.adbb2d47, 262 variables, nine inputs: `product_type`, `variable`,
`year`, `month`, `day`, `time`, `area`, `data_format`, `download_format`); an unknown dataset (404,
null); the cost of one hour of 2 m temperature over Strasbourg (6 of a limit of 121,000), of a
year of two variables hourly over the globe (105,120), of a day in 2027 (invalid: "None of the
data you have requested is available yet... The latest date available for this dataset is:
2026-10-10 07:00"); a submission and a job read with a key the store does not know (401
"Authentication failed").

**Not verified live (needs a key)**: `submit()`, `job()` and `jobs()` against the store - no
account was used. Their answers are taken from ECMWF's own client
(`github.com/ecmwf/ecmwf-datastores-client`: `processing.py` and its tests, whose job, result and
listing fixtures `Tests/Fixtures/job-*.json` and `jobs.json` follow, on the store's host) and from
OGC API - Processes; the store's 403 for a licence not yet accepted is its documented answer, not
seen here. A result file's address is made absolute whether the store gives it absolute or, as in the
client's tests, relative to the results' address.

Again on 2026-10-27, without an account: the search for "era5 land" (37 matches, the same three
first) and the cost of one hour of 2 m temperature over Strasbourg (6 of 121,000, valid: the
example above).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by ECMWF, the Copernicus Climate Change Service or the European Commission. The store is
free; its datasets carry their own licences (most CC-BY-4.0, credit "Copernicus Climate Change
Service"), accepted per dataset by the account, and a request beyond a dataset's cost limit is
refused, as the README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-cds#terms-of-use)
says. Whoever runs the package is responsible for complying with them and for any use made of
what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-cds#responsibility) section.
