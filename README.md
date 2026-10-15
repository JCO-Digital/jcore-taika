# JCORE Taika

Lets invited users read, draft and publish posts from AI assistants through the bojaco MCP gateway.

This file covers the repository and the development workflow. What the plugin does, and how to use it, is in [readme.txt](readme.txt).

## Requirements

- PHP 8.2+
- WordPress 6.9+ (the Abilities API is in core from 6.9)
- Node 22+ and [pnpm](https://pnpm.io/)
- [Composer](https://getcomposer.org/) and [WP-CLI](https://wp-cli.org/) (WP-CLI is only needed for the translation targets)

## Getting started

```sh
pnpm install
composer install
pnpm build
```

Then run a throwaway WordPress with the plugin mounted:

```sh
pnpm playground
```

That serves [WordPress Playground](https://wordpress.org/playground/) on <http://localhost:8829> from `.wp/blueprint.json`, logged in as `admin` / `password`. Plugin Check is installed alongside it.

## Scripts

| Command                                  | What it does                                                 |
| ---------------------------------------- | ------------------------------------------------------------ |
| `pnpm build`                             | Build `src/` into `build/`.                                  |
| `pnpm start`                             | Same, in watch mode.                                         |
| `pnpm check`                             | Everything CI lints: ESLint, Stylelint and PHPCS.            |
| `pnpm lint:js` / `lint:css` / `lint:php` | One linter at a time.                                        |
| `pnpm format`                            | Format `src/` with `wp-scripts format`.                      |
| `composer lint:fix`                      | Fix what PHPCBF can fix.                                     |
| `pnpm i18n`                              | Regenerate the POT, the MO files and the JS translation JSON. |
| `pnpm playground`                        | Serve the plugin in WordPress Playground.                    |

A `Makefile` wraps the same scripts (`make ci` is the entry point the shared publish workflow calls); it is a shim, not a second build system.

## Layout

```
jcore-taika.php    Plugin header, constants, autoloader, bootstrap
uninstall.php   Removes stored data on delete
includes/       Classes; Jcore\Taika\Foo_Bar lives in includes/class-foo-bar.php
src/            Sources built by @wordpress/scripts
languages/      .po sources; .pot, .mo and .json are generated
```

## Abilities

Registered with the WordPress Abilities API under the `jcore-taika` category, shown at `/wp-json/wp-abilities/v1/`. They are deliberately not offered to the MCP Adapter (`meta.mcp.public` is false): clients reach them only through the gateway. The gateway at `mcp.bojaco.com` calls them with the linked user's Application Password and an `X-Taika-Request-Id` header, which is stored on written posts as `_jcore_taika_request_id`.

| Ability | Kind | Capability |
| --- | --- | --- |
| `jcore-taika/list-posts` | read | `edit_posts` for the type |
| `jcore-taika/get-post` | read | `edit_post` |
| `jcore-taika/list-terms` | read | `assign_terms` for the taxonomy |
| `jcore-taika/create-post` | write, draft or pending only | `create_posts` for the type |
| `jcore-taika/update-post` | write, refuses live posts | `edit_post` |
| `jcore-taika/publish-post` | destructive | `edit_post` + `publish_posts` |
| `jcore-taika/upload-media` | write | `upload_files` |

Writes to an existing post need `expected_version` (the `version` from `get-post`, a hash of everything the abilities can edit) and are refused when the post changed since, is locked in the editor, or the block markup would break (`Write_Guard`). Names and schemas are a public contract: add fields, never rename or remove them.

## REST API

| Route | Permission | Purpose |
| --- | --- | --- |
| `GET /jcore-taika/v1/status` | public | Plugin version, and whether the Abilities API and Application Passwords are available. The gateway checks it before linking a site. |

## Releasing

Pushing to `main` runs `.github/workflows/release.yml`:

1. **check** – lint, build, then run Plugin Check against the tree minus `.distignore`. Also runs on pull requests.
2. **release** – [foonver](https://github.com/foonly/foonver) reads the conventional commits since the last tag, bumps the version, syncs it into `jcore-taika.php`, `readme.txt` and `package.json`, writes the `== Changelog ==` section of `readme.txt` and pushes the tag.
3. **publish** – the reusable workflow in [jcore-update](https://github.com/JCO-Digital/jcore-update) builds the zip, attaches it to a GitHub release, registers the version with `update.jcore.fi`, pushes to the dist repository and posts to Slack.

Every commit that reaches `main` produces a release, so work on branches and merge through pull requests. Commit messages must follow [Conventional Commits](https://www.conventionalcommits.org/).

`.distignore` is the single list of what does not ship.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
