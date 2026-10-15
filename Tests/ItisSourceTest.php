<?php

namespace Omnilab\Itis\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Itis\ItisSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.itis.gov/ITISWebService/jsonservice on
 * 2026-10-15, as ITIS sent them (ISO-8859-1; Tests/Fixtures/index.json
 * names each one's address): the common dolphin (TSN 180438) and its
 * hierarchy, a junior synonym (555654), the genus Delphinus (180437), the
 * dolphin by name, an unknown TSN (an empty 200) and name, and a search
 * for "Delphinus" in two pages of five with its count.
 */
final class ItisSourceTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    private function source(array $options = []): SourceInterface
    {
        $fixtures = __DIR__.'/Fixtures';
        $index = json_decode((string) file_get_contents($fixtures.'/index.json'), true);
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($fixtures, $index): MockResponse {
            $this->urls[] = rawurldecode($url);
            $name = self::fixture($method, $url, $o['body'] ?? null);
            if (!isset($index[$name])) {
                self::fail('No recorded answer for '.rawurldecode($url));
            }

            return new MockResponse((string) file_get_contents($fixtures.'/'.$name), ['http_code' => $index[$name]['status']]);
        });

        return (new ItisSourceFactory($http))->create($options + ['mailto' => 'support@glitchr.io']);
    }

    private static function fixture(string $method, string $url, ?string $body): string
    {
        $parts = parse_url($url);
        $readable = strtolower(trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', rawurldecode(($parts['path'] ?? '').'-'.($parts['query'] ?? ''))), '-'));

        return substr($readable, 0, 70).'-'.substr(md5($method.' '.$url.' '.$body), 0, 8).'.json';
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH], $this->source()->capabilities());
    }

    public function testTheCommonDolphinByItsTsn(): void
    {
        $dolphin = $this->source()->entity('180438');

        self::assertSame(['https://www.itis.gov/ITISWebService/jsonservice/getFullRecordFromTSN?tsn=180438', 'https://www.itis.gov/ITISWebService/jsonservice/getFullHierarchyFromTSN?tsn=180438'], $this->urls);
        self::assertSame(['itis:180438', 'Delphinus delphis', Domain::TAXONOMY, 'species'], [(string) $dolphin->id, $dolphin->label, $dolphin->domain, $dolphin->kind]);
        self::assertSame(['Linnaeus, 1758', 'valid', 'TWG standards met', '2023-12-07'], [$dolphin->value('taxon_authority'), $dolphin->value('taxonomic_status'), $dolphin->value('credibility'), $dolphin->value('modified')]);
        self::assertSame(['Animalia', 'Chordata', 'Mammalia', 'Cetacea', 'Delphinidae', 'Delphinus'], [$dolphin->value('kingdom'), $dolphin->value('phylum'), $dolphin->value('class'), $dolphin->value('order'), $dolphin->value('family'), $dolphin->value('genus')]);
        self::assertSame('Animalia > Bilateria > Deuterostomia > Chordata > Vertebrata > Gnathostomata > Tetrapoda > Mammalia > Theria > Eutheria > Cetacea > Odontoceti > Delphinidae > Delphinus', $dolphin->value('lineage'));
        self::assertSame('East Pacific, Eastern Atlantic Ocean, Indo-West Pacific, Western Atlantic Ocean', $dolphin->value('geographic_divisions'));
        self::assertSame('Continental US: Native, Canada: Native, Hawaii: Native', $dolphin->value('jurisdictional_origin'));
        self::assertSame(9, $dolphin->value('publication_count'));
        self::assertSame('saddle-backed dolphin', $dolphin->value('common_name'), 'the first English name, in ITIS\'s order');
        self::assertContains('Delfín común', $dolphin->synonyms, 'read from ISO-8859-1');
        self::assertSame(['itis:555654', 'itis:770905', 'itis:771148'], $dolphin->related('synonym')->keys());
        self::assertSame(['itis:180437'], $dolphin->related('parent')->keys());
        self::assertSame(['itis:180438'], $dolphin->identifiers()->keys(), 'ITIS gives no other base\'s id');
    }

    public function testAJuniorSynonymAndAGenusWithItsChildren(): void
    {
        $synonym = $this->source()->entity(Identifier::of(Scheme::ITIS, '555654'));
        self::assertSame(['Delphinus capensis', 'invalid', 'junior synonym', 'Delphinus delphis'], [$synonym->label, $synonym->value('taxonomic_status'), $synonym->value('unaccept_reason'), $synonym->value('accepted_name')]);
        self::assertSame(['itis:180438'], $synonym->related('accepted')->keys());

        $genus = $this->source()->entity('urn:lsid:itis.gov:itis_tsn:180437');
        self::assertSame(['Delphinus', 'genus'], [$genus->label, $genus->kind]);
        self::assertContains('itis:180438', $genus->related('child')->keys());
    }

    public function testByNameUnknownAndRefused(): void
    {
        self::assertSame('itis:180438', (string) $this->source()->entity('Delphinus delphis')?->id);
        self::assertNull($this->source()->entity('999999999'), 'an empty 200');
        self::assertNull($this->source()->entity('Nothingus nowhereus'));
        $this->urls = [];
        foreach (['worms:137094', Identifier::of(Scheme::NCBI_TAXON, '9728'), 'Q140'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testSearchByPageItsCountOnTheFirst(): void
    {
        $source = $this->source();
        $first = $source->search(new Query('Delphinus', limit: 5));
        $second = $source->search(new Query('Delphinus', limit: 5, cursor: $first->next));

        self::assertSame(['Chaenodelphinus', 'Delphinus', 'Delphinus acutus', 'Delphinus aduncus', 'Delphinus albigena'], array_map(static fn ($e) => $e->label, $first->items));
        self::assertSame([107, '2', null, '3'], [$first->total, $first->next, $second->total, $second->next]);
        self::assertSame('common dolphins', $first->items[1]->value('common_name'));
        self::assertCount(3, $this->urls, 'the count once');
        self::assertSame([], $source->search(new Query('Delphinus', Domain::ASTRONOMY))->items);
    }
}
