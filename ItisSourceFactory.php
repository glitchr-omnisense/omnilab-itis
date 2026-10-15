<?php

namespace Omnilab\Itis;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * ITIS: taxa by TSN or name, their hierarchy, usage, synonyms and common
 * names, and a search of names - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     hierarchy: true                  # entity() asks for the hierarchy too (a second call)
 *     language: English                # the common names put first; null: ITIS's order
 *     throttle: 0                      # seconds between two calls: ITIS publishes no rate
 *     base_uri: 'https://www.itis.gov/ITISWebService/jsonservice/'
 */
final class ItisSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'itis',
            'omnilab.required_options' => [],
            'hierarchy' => true,
            'language' => 'English',
            'throttle' => 0.0,
            'base_uri' => ItisSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $language = $c->get('language');

        return new ItisSource(
            $this->http ?? HttpClient::create(),
            (bool) $c->get('hierarchy'),
            \in_array($language, [null, '', false], true) ? null : (string) $language,
            (string) $c->get('base_uri', ItisSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
