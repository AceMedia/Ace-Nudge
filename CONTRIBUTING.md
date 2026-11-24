# Contributing

Issues and pull requests are welcome at https://github.com/AceMedia/Ace-Nudge.

- Use British English in code, comments and docs.
- Keep PHP compatible with 8.1 and WordPress 6.5. Follow WordPress coding standards, and escape output and prepare every query.
- Anything site-specific belongs behind a filter, not in the plugin. See [docs/hooks.md](docs/hooks.md).
- Never send email or push outside `on` mode to anyone who is not in the test lists.
- Contributions are accepted under the same licence as the project, GPL-2.0-or-later.
- Update `CHANGELOG.md` and the relevant page in `docs/` with each change.

## Releases

Bump the version in `ace-nudge.php` (header and `ACE_NUDGE_VERSION`) and `readme.txt`, add a `## X.Y.Z` section to `CHANGELOG.md`, commit, then tag `vX.Y.Z` and push the tag. The release workflow builds `ace-nudge-X.Y.Z.zip` and publishes the release with that version's changelog section as its notes.
