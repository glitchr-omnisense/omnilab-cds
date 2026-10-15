# omnilab/cds

The Copernicus Climate Data Store for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab):
its datasets - ERA5, ERA5-Land, the seasonal forecasts, the climate projections, satellite and
in-situ observations - as products over a place and a period, its catalogue's search, a dataset's
variables and request form, what a request would cost: all read without an account. With an
account's key, a request is submitted and followed until its file (NetCDF, GRIB, zip) is the
product's asset to download.

```php
$cds = (new CdsSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);

// The datasets that cover Strasbourg in 1950.
$page = $cds->products(new ProductQuery(place: new Point(48.5734, 7.7521), from: new \DateTimeImmutable('1950-01-01'), to: new \DateTimeImmutable('1950-12-31')));
$page->total;                           // 43 (of 144)
$cds->datasets(new Query('era5 land'))->first()->id;   // reanalysis-era5-land-timeseries

$era5 = $cds->dataset('reanalysis-era5-single-levels');
$era5->properties['variables'];         // 262 names: 2m_temperature, total_precipitation...
$era5->properties['sci:doi'];           // 10.24381/cds.adbb2d47

$request = ['product_type' => ['reanalysis'], 'variable' => ['2m_temperature'], 'year' => ['2025'], 'month' => ['07'],
    'day' => ['01'], 'time' => ['12:00'], 'data_format' => 'netcdf', 'area' => [49, 7, 48, 8]];
$cds->cost('reanalysis-era5-single-levels', $request);   // ['cost' => 6.0, 'limit' => 121000.0, 'valid' => true, ...]

// With a key: submit, then ask again until the file is there.
$cds = (new CdsSourceFactory($http))->create(['key' => getenv('CDS_KEY')]);
$job = $cds->submit('reanalysis-era5-single-levels', $request);   // status: accepted
$job = $cds->job($job->id);                                       // ... running, then successful: $job->isDone()
$cds->open($job->file());                                         // the NetCDF, streamed (the core's Job and open())
```

```yaml
omnilab:
    sources:
        cds:
            factory: cds
            options:
                key: '%env(CDS_KEY)%'          # optional: an account's personal access token, for requests
                mailto: 'support@glitchr.io'   # in the User-Agent
```

## Files are the application's to read

A request's file is a NetCDF, a GRIB or a zip of them: this package gives its address, type, size
and checksum, and decodes nothing (no NetCDF or GRIB reader in PHP is light enough to be a
dependency here). Download it with the application's HTTP client, read it with its own tools.

## The key and the licences

Searching the catalogue, reading a dataset and costing a request need no account. Submitting and
following requests need a CDS account's personal access token (on its profile page), given as the
`key` option and sent as `PRIVATE-TOKEN`; it is read from the configuration only, never written.
Each dataset has a licence (ERA5: CC-BY-4.0) that the account accepts once on the dataset's page
before its first request: the store answers 403 until then.

## One client, two stores

The Atmosphere Data Store (CAMS) runs the same software as the Climate Data Store: the same
catalogue, the same requests. [omnilab/cams](https://github.com/glitchr-studio/omnilab-cams) uses
this package's source on the ADS's address. `CdsSource` takes a name and a base URI: another
ECMWF data store is a `base_uri` away.

## Terms of use

The Climate Data Store is free; its datasets carry their own licences (most CC-BY-4.0, credit
"Copernicus Climate Change Service"); requests are queued, and a request whose cost passes the
dataset's limit is refused (`cost()` says before). The catalogue answers whole in one call.

See [docs/](docs/index.md) for what is read, how it maps, and what is left out.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by ECMWF and the Copernicus Climate Change Service. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
