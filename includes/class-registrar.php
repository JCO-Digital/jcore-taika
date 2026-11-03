<?php
/**
 * The one way abilities reach the Abilities API, for core and add-ons alike.
 *
 * @package Jcore\Taika
 */

namespace Jcore\Taika;

use WP_Abilities_Registry;
use WP_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers abilities with the same guard rails for every plugin, and records
 * which modules and abilities the site has for the status route.
 *
 * Core registers its own abilities through it, then hands it to add-on
 * plugins on the `jcore_taika_register` action:
 *
 *     add_action( 'jcore_taika_register', function ( Registrar $registrar ) {
 *         $registrar->module( 'jcore-taika-acf', JCORE_TAIKA_ACF_VERSION )
 *             ->ability( 'get-fields', $args, Registrar::READ );
 *     } );
 *
 * Add-ons never call `wp_register_ability()` themselves: going through here is
 * what keeps their abilities away from the MCP Adapter and gives the gateway a
 * truthful list of what the site offers.
 */
final class Registrar {

	/**
	 * Kind of an ability that changes nothing.
	 */
	public const READ = 'read';

	/**
	 * Kind of an ability that writes, but nothing visitors see.
	 */
	public const WRITE = 'write';

	/**
	 * Kind of an ability whose result visitors can see.
	 */
	public const DESTRUCTIVE = 'destructive';

	/**
	 * Ability category shared by core and every add-on.
	 */
	public const CATEGORY = 'jcore-taika';

	/**
	 * Module slugs: jcore-taika itself, or an add-on named jcore-taika-<name>.
	 * The slug is also the namespace of the module's ability IDs.
	 */
	private const MODULE_PATTERN = '/^jcore-taika(?:-[a-z0-9]+)*$/';

	/**
	 * Ability names within a module.
	 */
	private const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * MCP tool names: what the gateway offers clients, e.g. get_fields.
	 */
	private const TOOL_NAME_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

	/**
	 * Longest tool description the gateway accepts.
	 */
	private const TOOL_DESCRIPTION_MAX = 2000;

	/**
	 * Schema keyword marking a field added after the ability's first schema
	 * version: the gateway offers it only to sites at that version or later.
	 */
	public const SINCE_KEYWORD = 'x-taika-since';

	/**
	 * Schema keyword marking an enum that differs per site (the post types a
	 * site opts in, for example). The manifest leaves such enums out and the
	 * site validates the value.
	 */
	public const SITE_ENUM_KEYWORD = 'x-taika-site-enum';

	/**
	 * The single instance.
	 *
	 * @var Registrar|null
	 */
	private static ?Registrar $instance = null;

	/**
	 * Registered modules: slug => version.
	 *
	 * @var array<string, string>
	 */
	private array $modules = array();

	/**
	 * Registered abilities: full ability ID => schema version.
	 *
	 * @var array<string, int>
	 */
	private array $abilities = array();

	/**
	 * Abilities offered to the gateway as tools: full ability ID => tool.
	 *
	 * @var array<string, array{module: string, name: string, description: string, kind: string, schema: int}>
	 */
	private array $tools = array();

	/**
	 * Returns the single instance.
	 *
	 * @return Registrar
	 */
	public static function instance(): Registrar {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Starts registering a module's abilities.
	 *
	 * Call it once per plugin, inside the `jcore_taika_register` action. An
	 * invalid or repeated slug is reported with `_doing_it_wrong()` and gives a
	 * module that registers nothing, so a broken add-on cannot take the site
	 * down with it.
	 *
	 * @param string $slug    Plugin slug: jcore-taika-<name>.
	 * @param string $version Plugin version, reported on the status route.
	 *
	 * @return Module
	 */
	public function module( string $slug, string $version ): Module {
		if ( 1 !== preg_match( self::MODULE_PATTERN, $slug ) ) {
			$this->doing_it_wrong(
				/* translators: %s: module slug. */
				sprintf( __( 'Taika module slugs look like jcore-taika-example; %s does not.', 'jcore-taika' ), $slug )
			);

			return new Module( $this, $slug, false );
		}

		if ( isset( $this->modules[ $slug ] ) ) {
			$this->doing_it_wrong(
				/* translators: %s: module slug. */
				sprintf( __( 'The Taika module %s is already registered.', 'jcore-taika' ), $slug )
			);

			return new Module( $this, $slug, false );
		}

		$this->modules[ $slug ] = $version;

		return new Module( $this, $slug, true );
	}

	/**
	 * Registers one ability for a module. Called by `Module::ability()`.
	 *
	 * @internal
	 *
	 * @param string                    $module Module slug, already validated.
	 * @param string                    $name   Ability name without the module prefix.
	 * @param array<string, mixed>      $args   `wp_register_ability()` arguments.
	 * @param string                    $kind   One of the READ, WRITE, DESTRUCTIVE constants.
	 * @param int                       $schema Schema version of the ability's input.
	 * @param array<string, mixed>|null $tool   Gateway tool: `name` and `description`, or null for none.
	 *
	 * @return WP_Ability|null
	 */
	public function register( string $module, string $name, array $args, string $kind, int $schema, ?array $tool = null ): ?WP_Ability {
		$id = $module . '/' . $name;

		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			/* translators: %s: ability ID. */
			$this->doing_it_wrong( sprintf( __( 'Taika ability names use lowercase letters, digits and dashes; %s does not.', 'jcore-taika' ), $id ) );

			return null;
		}

		$annotations = self::annotations( $kind );
		if ( null === $annotations ) {
			/* translators: %s: ability ID. */
			$this->doing_it_wrong( sprintf( __( 'The Taika ability %s needs a kind: Registrar::READ, WRITE or DESTRUCTIVE.', 'jcore-taika' ), $id ) );

			return null;
		}

		if ( $schema < 1 ) {
			/* translators: %s: ability ID. */
			$this->doing_it_wrong( sprintf( __( 'The schema version of the Taika ability %s must be 1 or more.', 'jcore-taika' ), $id ) );

			return null;
		}

		// A broken tool definition only costs the tool: the ability still
		// registers, so the site keeps working for other clients.
		$tool = null === $tool ? null : $this->valid_tool( $id, $tool, $args, $schema );

		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();

		$args['category'] = self::CATEGORY;
		// Set after the caller's meta, so no module can opt out of them.
		$args['meta'] = array_merge(
			$meta,
			array(
				'show_in_rest' => true,
				'annotations'  => $annotations,
				// Not offered to the MCP Adapter: Taika's tools are reached only
				// through the gateway at mcp.bojaco.com, which checks invitations and
				// plans, rate limits and audits every call. A site that also runs the
				// adapter would otherwise expose them to anyone with a login,
				// bypassing all of that.
				'mcp'          => array(
					'public' => false,
				),
				// Read by the gateway's contract test from the Abilities REST API.
				'jcore_taika'  => array(
					'module' => $module,
					'schema' => $schema,
					'tool'   => $tool['name'] ?? null,
				),
			)
		);

		$ability = wp_register_ability( $id, $args );

		if ( null !== $ability ) {
			$this->abilities[ $id ] = $schema;
			if ( null !== $tool ) {
				$this->tools[ $id ] = array(
					'module'      => $module,
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'kind'        => $kind,
					'schema'      => $schema,
				);
			}
		}

		return $ability;
	}

	/**
	 * Registered abilities offered as gateway tools, in registration order.
	 *
	 * @return array<string, array{module: string, name: string, description: string, kind: string, schema: int}> Full ability ID => tool.
	 */
	public function tools(): array {
		self::load();

		return array_filter(
			$this->tools,
			static function ( string $id ): bool {
				return wp_has_ability( $id );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Checks a tool definition; null (with a notice) when it can't be offered.
	 *
	 * @param string               $id     Full ability ID.
	 * @param array<string, mixed> $tool   `name` and `description`.
	 * @param array<string, mixed> $args   `wp_register_ability()` arguments.
	 * @param int                  $schema Schema version of the ability's input.
	 *
	 * @return array{name: string, description: string}|null
	 */
	private function valid_tool( string $id, array $tool, array $args, int $schema ): ?array {
		$name        = isset( $tool['name'] ) && is_string( $tool['name'] ) ? $tool['name'] : '';
		$description = isset( $tool['description'] ) && is_string( $tool['description'] ) ? trim( $tool['description'] ) : '';

		if ( 1 !== preg_match( self::TOOL_NAME_PATTERN, $name ) ) {
			/* translators: 1: tool name, 2: ability ID. */
			$this->doing_it_wrong( sprintf( __( 'Tool names use lowercase letters, digits and underscores, like get_fields; %1$s (for %2$s) does not.', 'jcore-taika' ), $name, $id ) );

			return null;
		}

		foreach ( $this->tools as $other => $registered ) {
			if ( $registered['name'] === $name ) {
				/* translators: 1: tool name, 2: ability ID, 3: other ability ID. */
				$this->doing_it_wrong( sprintf( __( 'The tool name %1$s (for %2$s) is already used by %3$s.', 'jcore-taika' ), $name, $id, $other ) );

				return null;
			}
		}

		if ( '' === $description || strlen( $description ) > self::TOOL_DESCRIPTION_MAX ) {
			/* translators: 1: tool name, 2: maximum length. */
			$this->doing_it_wrong( sprintf( __( 'The tool %1$s needs a description, at most %2$d characters long.', 'jcore-taika' ), $name, self::TOOL_DESCRIPTION_MAX ) );

			return null;
		}

		$properties = $args['input_schema']['properties'] ?? array();
		foreach ( is_array( $properties ) ? $properties : array() as $field => $property ) {
			if ( ! is_array( $property ) || ! array_key_exists( self::SINCE_KEYWORD, $property ) ) {
				continue;
			}
			$since = $property[ self::SINCE_KEYWORD ];
			if ( ! is_int( $since ) || $since < 2 || $since > $schema ) {
				/* translators: 1: field name, 2: tool name, 3: keyword, 4: schema version. */
				$this->doing_it_wrong( sprintf( __( 'Field %1$s of the tool %2$s has %3$s outside 2 to the schema version of the ability (%4$d).', 'jcore-taika' ), (string) $field, $name, self::SINCE_KEYWORD, $schema ) );

				return null;
			}
		}

		return array(
			'name'        => $name,
			'description' => $description,
		);
	}

	/**
	 * Registered modules and their versions.
	 *
	 * @return array<string, string> Slug => version.
	 */
	public function modules(): array {
		self::load();

		return $this->modules;
	}

	/**
	 * Registered abilities and their schema versions.
	 *
	 * Leaves out any that another plugin unregistered afterwards, so the
	 * gateway is never told about an ability it cannot call.
	 *
	 * @return array<string, int> Full ability ID => schema version.
	 */
	public function abilities(): array {
		self::load();

		return array_filter(
			$this->abilities,
			static function ( string $id ): bool {
				return wp_has_ability( $id );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Abilities API annotations for a kind of ability.
	 *
	 * @param string $kind One of the READ, WRITE, DESTRUCTIVE constants.
	 *
	 * @return array<string, bool>|null Null for an unknown kind.
	 */
	public static function annotations( string $kind ): ?array {
		return match ( $kind ) {
			self::READ        => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			self::WRITE       => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			self::DESTRUCTIVE => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			default           => null,
		};
	}

	/**
	 * Makes sure the Abilities API has run its registration actions.
	 *
	 * WordPress registers abilities lazily, the first time anything asks for
	 * the registry, so a request that only reads the status would otherwise
	 * see an empty list.
	 *
	 * @return void
	 */
	private static function load(): void {
		if ( class_exists( WP_Abilities_Registry::class ) && did_action( 'init' ) ) {
			WP_Abilities_Registry::get_instance();
		}
	}

	/**
	 * Reports a registration mistake to developers.
	 *
	 * @param string $message Translated message.
	 *
	 * @return void
	 */
	private function doing_it_wrong( string $message ): void {
		_doing_it_wrong( esc_html( self::class . '::module' ), esc_html( $message ), '0.5.0' );
	}
}
