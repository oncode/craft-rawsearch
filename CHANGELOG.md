# Release Notes for RawSearch

## 2.0.0 - Unreleased

- Added support for Craft CMS 6.
- Events are Laravel event classes now (e.g. `oncode\rawsearch\events\SearchQueryResolving` instead of `Search::EVENT_MODIFY_SEARCH_QUERY`), listen to them with `Event::listen()`. See the README for the full list.
- Db queries passed to events are Laravel query builders (`Illuminate\Database\Query\Builder`).
- Console commands are Artisan commands (`php craft rawsearch/index/all` still works, also available as `php artisan rawsearch:index:all`).
- The config file moved from `config/rawsearch.php` to `config/craft/rawsearch.php`.
- The services are container singletons, `RawSearch::getInstance()->search` etc. still work.
- A migration renames the stored Craft 5 element and field type classes to the Craft 6 ones.

## 1.0.0 - 2026-09-26

- Initial release.
