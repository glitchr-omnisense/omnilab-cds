# omnilab/cds

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
| `submit($id, $request)` | the request's product: the job's id, its status (`accepted`) | POST `retrieve/v1/processes/{id}/execution` `{"inputs": ...}`, with the key |
| `job($jobId)` | the request as the store has it now: its status, its request, its times; once `successful`, its file as the `data` asset; null when unknown | `retrieve/v1/jobs/{id}?request=true`, then its `results` link when successful |
| `jobs(ProductQuery)` | the account's requests, newest first (`OLDEST`), `limit` a page, the next by the store's `next` link (the cursor); a query naming datasets, a place or a period is refused | `retrieve/v1/jobs?limit=&sortby=-created` |

Why beside the contract: the core's `search()` answers entities, and a dataset is a product; the
contract has no request to submit nor job to follow (a `ProductQuery` holds no variables nor
inputs). With a core that grew them, `datasets()` and `submit()`/`job()` would move in.

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
