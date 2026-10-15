<?php

namespace Omnilab\Cds;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The Copernicus Climate Data Store: its datasets (ERA5, ERA5-Land, the
 * seasonal forecasts, the climate projections...) as products, read
 * without an account; with an account's key, the requests for their files.
 *
 *   options:
 *     key: ~                           # the personal access token of a CDS account (its profile page), for submit(), job() and jobs()
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0                      # seconds between two calls
 *     base_uri: 'https://cds.climate.copernicus.eu/api/'   # another ECMWF data store: its /api/ address
 */
final class CdsSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'cds',
            'omnilab.required_options' => [],
            'key' => null,
            'throttle' => 0.0,
            'base_uri' => CdsSource::CDS_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new CdsSource(
            $this->http ?? HttpClient::create(),
            'cds',
            (string) $c->get('base_uri', CdsSource::CDS_URI),
            $c->get('key'),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
