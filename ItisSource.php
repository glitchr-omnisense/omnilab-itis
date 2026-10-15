<?php

namespace Omnilab\Itis;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ITIS, the Integrated Taxonomic Information System (USGS and its
 * partners), through its JSON web service
 * (www.itis.gov/ITISWebService/jsonservice): a taxon by its TSN or its
 * exact scientific name - its name and authority, rank, usage (valid,
 * invalid, accepted, not accepted) and why, its accepted name, its
 * hierarchy, its common names, its geographic divisions and jurisdictional
 * origins, ITIS's credibility rating; its parent, accepted name, synonyms
 * and children as related things. A search of scientific and common names.
 *
 * No key, no published rate. ITIS writes its JSON in ISO-8859-1 (its
 * content type says so): the source reads it as such. An unknown TSN is an
 * empty answer (200), read as null.
 */
final class ItisSource extends HttpSource
{
    public const BASE_URI = 'https://www.itis.gov/ITISWebService/jsonservice/';

    /** The most a page of a search holds (ITIS's pageSize). */
    public const MAX_PER_PAGE = 100;

    private const MAIN_RANKS = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus'];

    /**
     * @param bool        $hierarchy entity() asks for the hierarchy too (a second call): the lineage and the children
     * @param string|null $language  the common names kept first ("English"); null: ITIS's order
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly bool $hierarchy = true,
        private readonly ?string $language = 'English',
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'itis';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH];
    }

    /**
     * A taxon by its TSN (180438, itis:180438, tsn:180438, its LSID, its
     * report page) or its exact scientific name (`Delphinus delphis`): the
     * record as ITIS keeps it - an invalid name is itself, its accepted
     * name related. Two calls (one without the hierarchy), three for a name.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $identifier = Scheme::ITIS === $id->scheme ? $id : throw NotSupportedException::identifier('itis', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::tryOf(Scheme::ITIS, $text) ?? Identifier::parse($text);
            if (null !== $identifier && Scheme::ITIS !== $identifier->scheme) {
                throw NotSupportedException::identifier('itis', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
        }

        return $this->taxon($identifier->value);
    }

    /**
     * ITIS's search of its scientific and common names (searchForAnyMatchPaged),
     * in alphabetical order, page by number; a taxon matched by several of
     * its names comes once a page. The total (getAnyMatchCount, a call) on
     * the first page. A domain other than taxonomy answers nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::TAXONOMY !== $query->domain) || '' === $text) {
            return new Page();
        }
        $size = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $page = max(1, (int) $query->cursor);
        $data = $this->read('searchForAnyMatchPaged', ['srchKey' => $text, 'pageSize' => $size, 'pageNum' => $page, 'ascend' => 'true']) ?? [];
        $records = array_values(array_filter($data['anyMatchList'] ?? [], static fn (mixed $r) => \is_array($r) && isset($r['tsn'])));
        $entities = [];
        foreach ($records as $r) {
            $entities[(string) $r['tsn']] ??= $this->fromSearch($r);
        }
        $total = 1 === $page ? (int) ($this->read('getAnyMatchCount', ['srchKey' => $text])['return'] ?? 0) : null;
        $more = \count($records) === $size && (null === $total || $page * $size < $total);

        return new Page(array_values($entities), $more ? (string) ($page + 1) : null, $total);
    }

    /** ITIS's JSON, read in ISO-8859-1 when it is not UTF-8; null for an empty answer (an unknown TSN). */
    private function read(string $path, array $query): ?array
    {
        $body = $this->get($path, $query);
        if (null === $body || '' === trim($body)) {
            return null;
        }
        if (!preg_match('//u', $body)) {
            // ISO-8859-1 to UTF-8, byte by byte: each byte is its code point.
            $body = (string) preg_replace_callback('~[\x80-\xFF]~', static fn (array $m) => \chr(0xC0 | (\ord($m[0]) >> 6)).\chr(0x80 | (\ord($m[0]) & 0x3F)), $body);
        }
        $data = json_decode($body, true);

        return \is_array($data) ? $data : throw new ProviderException('itis', 'the answer is not JSON');
    }

    private function byName(string $name): ?Entity
    {
        $names = $this->read('searchByScientificName', ['srchKey' => $name])['scientificNames'] ?? [];
        foreach ($names as $n) {
            if (\is_array($n) && isset($n['tsn']) && 0 === strcasecmp(trim((string) ($n['combinedName'] ?? '')), $name)) {
                return $this->taxon((string) $n['tsn']);
            }
        }

        return null;
    }

    private function taxon(string $tsn): ?Entity
    {
        $record = $this->read('getFullRecordFromTSN', ['tsn' => $tsn]);
        if (!\is_array($record) || !isset($record['scientificName']['combinedName'])) {
            return null;
        }
        $hierarchy = $this->hierarchy ? $this->read('getFullHierarchyFromTSN', ['tsn' => $tsn])['hierarchyList'] ?? [] : [];

        return $this->toEntity($tsn, $record, array_values(array_filter($hierarchy, 'is_array')));
    }

    /**
     * @param array<string, mixed>       $r a full record
     * @param list<array<string, mixed>> $hierarchy its ancestors, itself, its children
     */
    private function toEntity(string $tsn, array $r, array $hierarchy): Entity
    {
        $name = self::text($r['scientificName']['combinedName'] ?? null) ?? $tsn;
        $authority = self::text($r['taxonAuthor']['authorship'] ?? null) ?? self::text($r['scientificName']['author'] ?? null);
        $rank = null !== ($value = self::text($r['taxRank']['rankName'] ?? null)) ? strtolower($value) : null;
        $properties = [];
        $add = static function (string $key, string $label, string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'itis');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', $authority);
        $add('scientific_name', 'Scientific name', null === $authority ? $name : $name.' '.$authority);
        $add('taxon_rank', 'Taxon rank', $rank);
        $add('taxonomic_status', 'Taxonomic status (ITIS usage)', self::text($r['usage']['taxonUsageRating'] ?? null));
        $add('unaccept_reason', 'Why not accepted', self::text($r['unacceptReason']['unacceptReason'] ?? null) ?? self::text($r['coreMetadata']['unacceptReason'] ?? null));
        $accepted = array_values(array_filter($r['acceptedNameList']['acceptedNames'] ?? [], static fn (mixed $a) => \is_array($a) && isset($a['acceptedTsn'])));
        $add('accepted_name', 'Accepted name', self::text($accepted[0]['acceptedName'] ?? null));
        $add('kingdom', 'Kingdom', self::text($r['kingdom']['kingdomName'] ?? null));
        $lineage = [];
        $children = [];
        $above = true;
        foreach ($hierarchy as $h) {
            $level = strtolower((string) self::text($h['rankName'] ?? null));
            $taxon = self::text($h['taxonName'] ?? null);
            if ((string) ($h['tsn'] ?? '') === $tsn) {
                $above = false;
                continue;
            }
            if ($above && null !== $taxon) {
                $lineage[] = $taxon;
                if (\in_array($level, self::MAIN_RANKS, true) && 'kingdom' !== $level) {
                    $add($level, ucfirst($level), $taxon);
                }
            } elseif ((string) ($h['parentTsn'] ?? '') === $tsn) {
                $children[] = Identifier::tryOf(Scheme::ITIS, $h['tsn'] ?? null);
            }
        }
        $add('lineage', 'Lineage', $lineage ? implode(' > ', $lineage) : null);
        $common = [];
        foreach ($r['commonNameList']['commonNames'] ?? [] as $c) {
            if (\is_array($c) && null !== ($n = self::text($c['commonName'] ?? null))) {
                $common[] = [$n, (string) ($c['language'] ?? '')];
            }
        }
        if (null !== $this->language) {
            usort($common, fn (array $a, array $b) => ($b[1] === $this->language) <=> ($a[1] === $this->language));
        }
        $add('common_name', 'Common name', $common[0][0] ?? null);
        $add('credibility', 'Credibility rating (ITIS)', self::text($r['credibilityRating']['credRating'] ?? null));
        $add('completeness', 'Completeness rating (ITIS)', self::text($r['completenessRating']['completeness'] ?? null));
        $geo = array_values(array_filter(array_map(static fn (mixed $g) => \is_array($g) ? self::text($g['geographicValue'] ?? null) : null, $r['geographicDivisionList']['geoDivisions'] ?? [])));
        $add('geographic_divisions', 'Geographic divisions', $geo ? implode(', ', $geo) : null);
        $origins = array_values(array_filter(array_map(static fn (mixed $j) => \is_array($j) && null !== self::text($j['jurisdictionValue'] ?? null) ? self::text($j['jurisdictionValue']).': '.(self::text($j['origin'] ?? null) ?? '?') : null, $r['jurisdictionalOriginList']['jurisdictionalOrigins'] ?? [])));
        $add('jurisdictional_origin', 'Jurisdictional origin', $origins ? implode(', ', $origins) : null);
        $publications = array_filter($r['publicationList']['publications'] ?? [], 'is_array');
        $add('publication_count', 'Publications (cited by ITIS)', $publications ? \count($publications) : null);
        $add('modified', 'Updated (in ITIS)', self::text($r['dateData']['updateDate'] ?? null));
        $synonymIds = [];
        $synonymNames = [];
        foreach ($r['synonymList']['synonyms'] ?? [] as $s) {
            if (\is_array($s) && isset($s['tsn'])) {
                $synonymIds[] = Identifier::tryOf(Scheme::ITIS, $s['tsn']);
                if (null !== ($n = self::text($s['sciName'] ?? null))) {
                    $synonymNames[] = $n;
                }
            }
        }
        $parent = self::text($r['hierarchyUp']['parentTsn'] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::ITIS, $tsn),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: array_values(array_unique([...array_column($common, 0), ...$synonymNames])),
            properties: $properties,
            url: 'https://www.itis.gov/servlet/SingleRpt/SingleRpt?search_topic=TSN&search_value='.$tsn,
            source: 'itis',
            related: array_filter([
                'parent' => Identifiers::of(Identifier::tryOf(Scheme::ITIS, $parent)),
                'accepted' => new Identifiers(array_map(static fn (array $a) => Identifier::tryOf(Scheme::ITIS, $a['acceptedTsn']), $accepted)),
                'synonym' => new Identifiers($synonymIds),
                'child' => new Identifiers($children),
            ], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /** @param array<string, mixed> $r a result of searchForAnyMatchPaged */
    private function fromSearch(array $r): Entity
    {
        $tsn = (string) $r['tsn'];
        $name = self::text($r['sciName'] ?? null) ?? $tsn;
        $common = array_values(array_filter(array_map(static fn (mixed $c) => \is_array($c) ? self::text($c['commonName'] ?? null) : null, $r['commonNameList']['commonNames'] ?? [])));
        $authority = self::text($r['author'] ?? null);
        $properties = [new Property('taxon_name', 'Taxon name', $name, 'itis')];
        if (null !== $authority) {
            $properties[] = new Property('taxon_authority', 'Authority', $authority, 'itis');
        }
        if ($common) {
            $properties[] = new Property('common_name', 'Common name', $common[0], 'itis');
        }

        return new Entity(
            id: Identifier::of(Scheme::ITIS, $tsn),
            label: $name,
            domain: Domain::TAXONOMY,
            synonyms: $common,
            properties: $properties,
            url: 'https://www.itis.gov/servlet/SingleRpt/SingleRpt?search_topic=TSN&search_value='.$tsn,
            source: 'itis',
        );
    }
}
