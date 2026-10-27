# omnilab/itis

The Integrated Taxonomic Information System's JSON web service
(`https://www.itis.gov/ITISWebService/jsonservice/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a taxon by its TSN or its
name - usage, accepted name, hierarchy, common names, geographic divisions, jurisdictional
origins -, as an entity; a search of scientific and common names. No key.

## Installation

```sh
composer require omnilab/itis
```

```php
use Omnilab\Itis\ItisSourceFactory;

$itis = (new ItisSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, in the User-Agent
    'hierarchy' => true,               // optional
]);
```

With the Symfony bundle, the factory is registered as `itis` (see the core's docs).

## Credentials

None: ITIS's web service takes no key and no account. For the whole database ITIS offers
downloads rather than calls.

## Options

The factory's options, with the defaults it applies (`ItisSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `hierarchy` | `bool` | `true` | no | `entity()` asks for the hierarchy too (a second call): the lineage, the parent, the children; `false`: the record alone |
| `language` | `?string` | `English` | no | the language whose common names come first (and whose first is `common_name`), as ITIS names it (`English`, `Spanish`); null or empty: ITIS's order |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: ITIS publishes no rate |
| `base_uri` | `string` | `https://www.itis.gov/ITISWebService/jsonservice/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a TSN (`180438`, `itis:`, `tsn:`, its LSID, its report page) or an exact scientific name; another scheme refused before any call |
| `search` | yes | scientific and common names, alphabetical, 100 a page at most, paged by number, the total on the first page; a domain other than taxonomy answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no | ITIS holds names, not records |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: ITIS gives no other base's id (WoRMS, the Catalogue of Life and Wikidata carry the TSN) |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

ITIS's JSON web service, `https://www.itis.gov/ITISWebService/jsonservice/` (the one the brief
of the family names; ITIS's Solr service is not used). Two points of its own:

- its JSON is written in **ISO-8859-1** (`Content-Type: text/json;charset=ISO-8859-1`): "Delfín
  común" comes as the byte `0xED`; the source reads a body that is not UTF-8 as ISO-8859-1;
- an unknown TSN is an **empty 200**, read as null.

## Calls

| Method | Reads | ITIS |
|---|---|---|
| `entity()` | a TSN: `180438`, `itis:180438`, `tsn:180438`, `urn:lsid:itis.gov:itis_tsn:180438`, its report page | `getFullRecordFromTSN?tsn=`, `getFullHierarchyFromTSN?tsn=` |
| | an exact scientific name (`Delphinus delphis`) | `searchByScientificName?srchKey=`, then the two above |
| `search()` | `Query::$text`: scientific and common names, alphabetical, page by number (100 at most); its total on the first page; a domain other than taxonomy answers nothing | `searchForAnyMatchPaged?srchKey=&pageSize=&pageNum=&ascend=true`, `getAnyMatchCount?srchKey=` |

An invalid name is itself (its usage, why, its accepted name related). An identifier of another
scheme is refused before any call. A search's result holds its name, authority and common names
only.

## What an entity holds

`id` (`itis:180438`), `label` (its scientific name), `domain` taxonomy, `kind` (its rank, lower
case), `synonyms` (its common names, then its scientific synonyms), `url` (its ITIS report), and:

| Key | ITIS | Value |
|---|---|---|
| `taxon_name`, `taxon_authority`, `scientific_name` | combinedName, taxonAuthor, both | text |
| `taxon_rank` | taxRank.rankName (lower case) | text |
| `taxonomic_status` | usage.taxonUsageRating: `valid`, `invalid` (animals), `accepted`, `not accepted` (plants) | text |
| `unaccept_reason` | unacceptReason: `junior synonym`, `original name/combination`... | text |
| `accepted_name` | acceptedNameList | text |
| `kingdom`, `phylum`, `class`, `order`, `family`, `genus` | the record's kingdom, the hierarchy's ranks | text |
| `lineage` | the hierarchy above it | text |
| `common_name` | the first common name in `language` | text |
| `credibility`, `completeness` | ITIS's credibility and completeness ratings | text |
| `geographic_divisions`, `jurisdictional_origin` | geoDivisions; jurisdiction: origin (`Hawaii: Native`) | text |
| `publication_count` | the publications ITIS cites for it | number |
| `modified` | dateData.updateDate | text |

`related`: `parent` (hierarchyUp), `accepted`, `synonym` (their TSNs), `child` (the hierarchy's
direct children).

## What ITIS does not give here

- No other base's id (no NCBI, GBIF, WoRMS or Wikidata): the others carry the TSN.
- Not read: the experts, other sources, publications one by one, comments, the vernaculars'
  references.

## The Collector

- Into ITIS: `collect('worms', '137094', 1, ['itis'])` follows WoRMS's TSN 180438; the Catalogue
  of Life gives the TSN of a taxon it took from ITIS (`collect('col', '4CGXP', 1, ['itis'])`:
  the lion, TSN 183803).
- From ITIS: no cross-reference, but the `Collector` offers the other sources the entity's own
  id too: with `omnilab/worms` configured, `collect('itis', '180438')` reaches WoRMS by the TSN
  (`AphiaRecordByExternalID`), and with `omnilab/wikidata` the item that carries it (P815).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Itis\ItisSourceFactory;
use Omnilab\Model\Scheme;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$itis = (new ItisSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$dolphin = $itis->entity('urn:lsid:itis.gov:itis_tsn:180438');
echo $dolphin->label, ' ', $dolphin->value('taxon_authority'), ' (', $dolphin->id, '), ', $dolphin->value('taxonomic_status'), ', ', $dolphin->value('common_name'), "\n";
echo '  ', $dolphin->value('lineage'), "\n";
echo '  parent ', $dolphin->related('parent')->value(Scheme::ITIS), ', ', count($dolphin->related('child')), " children\n";

$page = $itis->search(new Query('Delphinus', limit: 3));
printf("%d names match \"Delphinus\"; the first %d:\n", $page->total, count($page));
foreach ($page as $taxon) {
    echo '  ', $taxon->id, '  ', $taxon->label, "\n";
}
```

```
Delphinus delphis Linnaeus, 1758 (itis:180438), valid, saddle-backed dolphin
  Animalia > Bilateria > Deuterostomia > Chordata > Vertebrata > Gnathostomata > Tetrapoda > Mammalia > Theria > Eutheria > Cetacea > Odontoceti > Delphinidae > Delphinus
  parent 180437, 4 children
107 names match "Delphinus"; the first 3:
  itis:770858  Chaenodelphinus
  itis:180437  Delphinus
  itis:771051  Delphinus acutus
```

(as answered on 2026-10-27)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        itis: { factory: itis, options: { mailto: '%env(OMNILAB_MAILTO)%', language: English } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

ITIS's own: an unknown TSN is an empty 200, read as null; its JSON is ISO-8859-1, read as such - a
body that is neither UTF-8 nor ISO-8859-1 JSON is a `ProviderException` ("the answer is not
JSON"). An identifier of another scheme is refused before any call (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15, without a key: the common dolphin by its TSN, its LSID
and its name (its lineage, geographic divisions, jurisdictional origins, nine publications, its
Spanish name read from ISO-8859-1); a junior synonym (*Delphinus capensis*, 555654) and its
accepted name; the genus *Delphinus* and its children; an unknown TSN (an empty 200) and name; a
search for "Delphinus" (107 matches) in two pages. The fixtures in `Tests/Fixtures/` are its
answers as sent, in ISO-8859-1 (`index.json` names each one's address).

Again on 2026-10-27: the common dolphin by its LSID, its lineage, parent and four children, the
search for "Delphinus" (107 matches; the example above).

Not verified: a 429 or an outage (none met); a plant's `accepted` / `not accepted`.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by ITIS or its partner agencies. ITIS's data are in the public domain (CC0) and ITIS
asks to be cited ("Retrieved [date] from the Integrated Taxonomic Information System (ITIS)
on-line database, www.itis.gov, CC0, https://doi.org/10.5066/F7KH0KBK"), as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-itis#terms-of-use) says. Whoever runs
the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-itis#responsibility) section.
