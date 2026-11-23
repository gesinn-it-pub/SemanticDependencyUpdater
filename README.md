# Semantic Dependency Updater
[![CI](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/actions/workflows/ci.yml/badge.svg)](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/actions/workflows/ci.yml)
[![codecov](https://codecov.io/github/gesinn-it-pub/SemanticDependencyUpdater/graph/badge.svg?token=35TWH21ZPJ)](https://codecov.io/github/gesinn-it-pub/SemanticDependencyUpdater)

Semantic Dependency Updater (SDU) is a MediaWiki extension that monitors pages for changes in their semantic data.
If a Semantic Dependency property is found, SDU will update all pages that have been selected through that property.

See https://www.mediawiki.org/wiki/Extension:SemanticDependencyUpdater for more details.

For measured performance characteristics and how to benchmark a change, see [docs/performance.md](docs/performance.md).

## Page names with special characters

A dependency may name a page whose title contains `&`, `'`, `"`, `=`, `+`, `%`, `,` or any other character MediaWiki allows in titles, for example through `{{FULLPAGENAME}}`. These are found, also when `{{FULLPAGENAME}}` writes them as character references (`&#38;`).

The dependency property is a plain text value that may hold a query (`Property::Value`, `A OR B`) or several values separated by the list separator of PageForms (`;`). SDU cannot tell from the text alone whether `A;B` or `A OR B` is one page name or a list. It decides by looking at the page:

- If the whole value is the name of an existing page, or the name of the page being saved, it is that one page.
- Otherwise it is read as a list or a query.

Known limitation: a dependency on a page that does not exist yet, whose name contains ` OR ` or ` AND ` as a separate word, does not update that page. With ` OR ` it updates the pages named by the parts of that name; with ` AND ` the parts are read as a conjunction and Semantic MediaWiki decides what it returns, which can be a wrong page. The same applies to a raw `;` in a value that is typed or read from the store; `{{FULLPAGENAME}}` writes `;` as a character reference, which is never read as a separator. Names that only contain the letters, such as `ORDER` or `BRAND`, are not affected.

Recommendation: avoid ` OR `, ` AND ` and `;` in page names. Several dependencies belong in one value separated by the list separator, or in a query, not in a page name.
