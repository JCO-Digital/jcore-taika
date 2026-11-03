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

Registered with the WordPress Abilities API under the `jcore-taika` category, shown at `/wp-json/wp-abilities/v1/`. Every ability, core's and add-ons', goes through `Registrar` (`includes/class-registrar.php`), which sets the category, the annotations for its kind and `meta.jcore_taika` (`module`, `schema`). They are deliberately not offered to the MCP Adapter (`meta.mcp.public` is false): clients reach them only through the gateway. The gateway at `mcp.bojaco.com` calls them with the linked user's Application Password and an `X-Taika-Request-Id` header, which is stored on written posts as `_jcore_taika_request_id`.

| Ability | Kind | Capability |
| --- | --- | --- |
| `jcore-taika/list-posts` | read | `edit_posts` for the type |
| `jcore-taika/get-post` | read | `edit_post` |
| `jcore-taika/list-terms` | read | `assign_terms` for the taxonomy |
| `jcore-taika/create-post` | write, draft or pending only | `create_posts` for the type |
| `jcore-taika/update-post` | write, refuses live posts | `edit_post` |
| `jcore-taika/publish-post` | destructive | `edit_post` + `publish_posts` |
| `jcore-taika/upload-media` | write | `upload_files` |
| `jcore-taika/describe-site` | read | `edit_posts` for at least one supported type |

The post abilities work on posts and pages, plus custom post types a site opts in from its theme or a mu-plugin:

```php
add_filter( 'jcore_taika_post_types', fn( array $types ) => [ ...$types, 'event' ] );
```

WordPress's internal types (attachments, templates, reusable blocks, menus) and types without an admin screen are dropped whatever the filter returns. `describe-site` lists the types the user can edit, with what each supports. Add-ons extend each entry with the `jcore_taika_post_type_description` filter, e.g. with its custom fields.

Writes to an existing post need `expected_version` (the `version` from `get-post`, a hash of everything the abilities can edit) and are refused when the post changed since, is locked in the editor, or the block markup would break (`Write_Guard`). Names and schemas are a public contract: add fields, never rename or remove them. Each ability has a schema version, starting at 1; bump it whenever its input gains a field, so the gateway offers the field only to sites that accept it.

## Add-on plugins

Integrations (ACF, WooCommerce, …) ship as separate plugins named `jcore-taika-<name>` that depend on this one. An add-on:

1. Declares `Requires Plugins: jcore-taika` in its header, so WordPress won't activate it without core.
2. Registers its abilities on the `jcore_taika_register` action, never with `wp_register_ability()` directly:

   ```php
   use Jcore\Taika\Registrar;
   use Jcore\Taika\Schema;

   add_action(
   	'jcore_taika_register',
   	static function ( Registrar $registrar ): void {
   		$module = $registrar->module( 'jcore-taika-acf', JCORE_TAIKA_ACF_VERSION );

   		$module->ability(
   			'get-fields',
   			array(
   				'label'               => __( 'Get fields', 'jcore-taika-acf' ),
   				'description'         => __( 'Returns the custom fields of a post.', 'jcore-taika-acf' ),
   				'input_schema'        => Schema::object( array( 'id' => Schema::id() ), array( 'id' ) ),
   				'execute_callback'    => array( Fields::class, 'get_fields' ),
   				'permission_callback' => array( Fields::class, 'can_edit' ),
   			),
   			Registrar::READ, // Or WRITE, or DESTRUCTIVE when visitors see the result.
   			1,               // Schema version.
   			array(           // The gateway tool: its MCP name and what the model reads.
   				'name'        => 'get_fields',
   				'description' => 'Returns the custom fields of a post or page, with a version token for update_fields.',
   			)
   		);
   	}
   );
   ```

3. Marks written posts with `Write_Guard::mark( $post_id )` and uses `Write_Guard::check_version()` for writes to existing content, like core does.

The ability ID is `<module slug>/<name>`. The registrar refuses slugs that don't start with `jcore-taika`, registering the same module twice, and names that aren't lowercase with dashes (each with a `_doing_it_wrong()` notice). It overrides `category`, `show_in_rest`, `annotations` and `mcp` in the ability's `meta`, so no add-on can opt into the MCP Adapter. The site's `/status` then lists the module and its abilities.

## Gateway tools

An ability registered with tool metadata (the last argument above) is offered through the gateway as an MCP tool. Its title, kind and input schema come from the ability, and the description is written for the model, naming tools (`publish_post`) rather than abilities. The registrar refuses a tool, with a notice but still registering the ability, when its name isn't lowercase with underscores, is already used by another module, or has no description (at most 2,000 characters).

Two schema keywords are for the gateway; WordPress ignores them:

- `x-taika-since: <schema>` on a field added after the ability's first schema version. The gateway offers that field only to sites at that version or later. It must be between 2 and the ability's schema version.
- `x-taika-site-enum: true` on an enum whose values differ per site, like `post_type`. The manifest leaves the enum out and the site validates the value.

`wp jcore-taika manifest <module> [--output=<file>]` prints the module's `taika-tools.json`: format, module, version and its tools with their input schemas. Ability names in field descriptions are rewritten to tool names. Each release attaches this file to the module's GitHub release through the shared workflow (see Releasing), and the gateway reads it from there. A new or changed tool reaches the gateway without a gateway release. Add-on tools still start off on every site until staff enable them.

## REST API

| Route | Permission | Purpose |
| --- | --- | --- |
| `GET /jcore-taika/v1/status` | public | Plugin version, whether the Abilities API and Application Passwords are available, and the registered `modules` (slug → version) and `abilities` (ID → schema version). The gateway checks it before linking a site and refreshes what each site offers from it. |

## Releasing

Pushing to `main` runs `.github/workflows/release.yml`:

1. **check** – lint, build, then run Plugin Check against the tree minus `.distignore`. Also runs on pull requests.
2. **release** – [foonver](https://github.com/foonly/foonver) reads the conventional commits since the last tag, bumps the version, syncs it into `jcore-taika.php`, `readme.txt` and `package.json`, writes the `== Changelog ==` section of `readme.txt` and pushes the tag.
3. **publish** – the reusable workflow in [jcore-update](https://github.com/JCO-Digital/jcore-update) builds the zip, attaches it to a GitHub release, registers the version with `update.jcore.fi`, pushes to the dist repository and posts to Slack.
4. **manifest** – `.github/workflows/taika-manifest.yml` downloads the release zip, boots WordPress Playground, runs `wp jcore-taika manifest jcore-taika`, checks the result and attaches `taika-tools.json` to the release. It then pings the gateway (`POST /catalog/refresh`, with the `TAIKA_CATALOG_TOKEN` secret) so it reads the new tools within minutes. Without the secret, or if the ping fails, the gateway's daily refresh picks the release up.

Add-ons call the same workflow from their own `release.yml`, as the comment at the top of `taika-manifest.yml` shows. `wp_plugins` names WordPress.org plugins their abilities need active to register, such as `advanced-custom-fields`. The workflow downloads the latest jcore-taika release for them.

Every commit that reaches `main` produces a release, so work on branches and merge through pull requests. Commit messages must follow [Conventional Commits](https://www.conventionalcommits.org/).

`.distignore` is the single list of what does not ship.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
