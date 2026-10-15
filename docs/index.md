# omnilab/itis

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
- From ITIS: none (no cross-reference); with `omnilab/worms` configured, WoRMS reads a TSN
  (`AphiaRecordByExternalID`), so `collect('itis', ...)` merged with WoRMS needs the TSN offered
  to WoRMS by the caller: `$worms->entity(Identifier::of(Scheme::ITIS, '180438'))`.

## Verified, and not

Against the real service on 2026-10-15, without a key: the common dolphin by its TSN, its LSID
and its name (its lineage, geographic divisions, jurisdictional origins, nine publications, its
Spanish name read from ISO-8859-1); a junior synonym (*Delphinus capensis*, 555654) and its
accepted name; the genus *Delphinus* and its children; an unknown TSN (an empty 200) and name; a
search for "Delphinus" (107 matches) in two pages. The fixtures in `Tests/Fixtures/` are its
answers as sent, in ISO-8859-1 (`index.json` names each one's address).

Not verified: a 429 or an outage (none met); a plant's `accepted` / `not accepted`.
