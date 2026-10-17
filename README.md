# omnilab/itis

ITIS, the Integrated Taxonomic Information System, for
[omniscient/omnilab](https://github.com/glitchr-studio/omnilab): a taxon by its TSN or its exact
scientific name - its authority, rank, usage (valid, invalid, accepted, not accepted) and why, its
accepted name, its hierarchy, its common names, its geographic divisions and jurisdictional
origins, ITIS's credibility rating; its parent, accepted name, synonyms and children as related
things. A search of scientific and common names. No key.

```php
$itis = (new ItisSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$dolphin = $itis->entity('180438');               // or 'itis:180438', 'tsn:180438', its LSID, its page, 'Delphinus delphis'
$dolphin->value('taxonomic_status');              // valid
$dolphin->value('lineage');                       // Animalia > Bilateria > ... > Delphinidae > Delphinus
$dolphin->value('jurisdictional_origin');         // Continental US: Native, Canada: Native, Hawaii: Native
$itis->entity('555654')->related('accepted')->keys();   // itis:180438: Delphinus capensis is a junior synonym

$itis->search(new Query('common dolphin', limit: 20));
```

```yaml
omnilab:
    sources:
        itis:
            factory: itis
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                hierarchy: true                # the lineage and the children (a second call)
                language: English              # the common names put first; null: ITIS's order
```

ITIS gives no other base's id. The way to it is WoRMS (`omnilab/worms` carries the TSN of a
marine taxon), the Catalogue of Life (`omnilab/col` carries the TSN of a taxon it took from ITIS)
and Wikidata (P815): the `Collector` follows the `itis` identifier they give.

## Terms of use

ITIS's data are in the public domain ([CC0](https://creativecommons.org/publicdomain/zero/1.0/));
ITIS asks to be cited: "Retrieved [date] from the Integrated Taxonomic Information System (ITIS)
on-line database, www.itis.gov, CC0, https://doi.org/10.5066/F7KH0KBK". The web service needs no
key and publishes no rate; for the whole database ITIS offers downloads rather than calls
([itis.gov/ws_description.html](https://www.itis.gov/ws_description.html),
[itis.gov/privacy.html](https://www.itis.gov/privacy.html)).

See [docs/](docs/index.md) for what is read, how it maps, and what ITIS does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Integrated Taxonomic Information System (ITIS) and the U.S. Geological Survey. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
