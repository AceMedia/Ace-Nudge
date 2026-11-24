# Contributing

Issues and pull requests are welcome at https://github.com/AceMedia/Ace-Nudge.

## Ground rules

- Use British English in code, comments and docs.
- Keep PHP compatible with 8.1 and WordPress 6.5. Follow the WordPress coding standards, escape output and prepare every query.
- No build step and no bundled libraries. The front end is plain JavaScript and CSS.
- Anything site-specific belongs behind a filter, not in the plugin. See [docs/hooks.md](docs/hooks.md). Do not name a particular site, brand or host in code, comments, docs or tests.
- Pages must stay cacheable: nothing personal in the page, and no nonces on the public routes.
- Never send the newsletter or a push outside `on` mode to anyone who is not in the test lists.
- Consent comes first: nothing pre-ticked, a separate consent for each channel, and a log entry with the wording shown for every grant and withdrawal.
- Update `CHANGELOG.md` and the relevant page in `docs/` with each change.

## Trying a change

Set `ACE_NUDGE_MODE` to `test`, visit the site with `?ace_nudge_preview=1`, and see [the developer guide](docs/developer-guide.md) for local development notes. Check PHP with `php -l`, and run `wp ace-nudge status` and `wp ace-nudge preview` to make sure the commands still load.

## Licence of contributions

Ace NUDGE is licensed under GPL-2.0-or-later. By contributing you agree that your contribution is licensed under the same terms. Only submit code you have the right to contribute, and do not add third-party code without raising it in an issue first.

## Releases

1. Bump the version in `ace-nudge.php` (the header and `ACE_NUDGE_VERSION`), the stable tag in `readme.txt` and the badge and version line in `README.md`.
2. Add a section to `CHANGELOG.md` headed exactly `## X.Y.Z`, with nothing else on that line. The release workflow takes the release notes from the section with that heading and fails if it is missing.
3. Commit, tag `vX.Y.Z` and push the tag. The workflow builds `ace-nudge-X.Y.Z.zip` and publishes the release.
