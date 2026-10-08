<?php
/**
 * The assistant for agents: WordPress Abilities, a public MCP server and discovery files, so
 * visitors browsing through an AI agent get the same help (search, product facts, configuring a
 * product, a basket link, leaving details) as visitors using the chat widget.
 *
 * @package ACE\AdaptiveCustomerEngagement
 */

namespace ACE\AdaptiveCustomerEngagement\AI;

use ACE\AdaptiveCustomerEngagement\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Agent-facing surface of the assistant.
 */
final class AgentSurface {
	const PREFIX        = 'adaptive-customer-engagement';
	const CATEGORY      = 'adaptive-customer-engagement';
	const MCP_ID        = 'adaptive-customer-engagement';
	const MCP_NS        = 'adaptive-customer-engagement';
	const MCP_ROUTE     = 'mcp';
	const SERVICE_LOGIN = 'ace-public-agent';
	const REWRITE_FLAG  = 'ace_agent_surface_rewrites';
	const REWRITE_VER   = '1';

	/** @var SiteContextService */
	private $site_context;
	/** @var FrontendChatService */
	private $chat;

	public function __construct( SiteContextService $site_context, FrontendChatService $chat ) {
		$this->site_context = $site_context;
		$this->chat         = $chat;
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );
		add_action( 'init', array( $this, 'rewrites' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_discovery' ), 0 );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical_redirect' ), 10, 2 );
		add_filter( 'robots_txt', array( $this, 'robots' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'head_link' ), 5 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'no_app_passwords' ), 10, 2 );
	}

	/** The surface follows the public chat: on when the assistant and its widget are on for this site. */
	public function enabled(): bool {
		$ai_agent = is_array( Settings::get()['ai_agent'] ?? null ) ? Settings::get()['ai_agent'] : array();
		$chat_on  = ! empty( $ai_agent['enabled'] ) && ! empty( $ai_agent['frontend_chat_enabled'] ) && empty( $ai_agent['frontend_chat_admin_only'] );
		// Agents can be served without the visitor-facing widget: set ai_agent.agent_surface_enabled.
		$enabled = $chat_on || ! empty( $ai_agent['agent_surface_enabled'] );
		/**
		 * Filter whether the agent surface (abilities, MCP server, discovery files) is available.
		 *
		 * @param bool $enabled Availability.
		 */
		return (bool) apply_filters( 'ace_ai_agent_surface_enabled', $enabled );
	}

	/* ---------------------------------------------------------------- Abilities */

	public function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( self::CATEGORY, array( 'label' => __( 'Site assistant', 'adaptive-customer-engagement' ), 'description' => __( 'Ask the site assistant, search and configure products, get a basket link, leave contact details.', 'adaptive-customer-engagement' ) ) );
		}
	}

	/** @return array<string, array<string, mixed>> */
	public function definitions(): array {
		$woo  = function_exists( 'wc_get_product' );
		$str  = static fn( string $d ): array => array( 'type' => 'string', 'description' => $d );
		$int  = static fn( string $d ): array => array( 'type' => 'integer', 'description' => $d );
		$any  = array( 'type' => 'object', 'additionalProperties' => true );
		$list = array( 'type' => 'array', 'items' => $any );
		$bot  = $this->bot_name();
		$defs = array(
			'ask'               => array(
				'label'       => sprintf( __( 'Ask %s', 'adaptive-customer-engagement' ), $bot ),
				'description' => sprintf( __( 'Ask %s a question in plain language about the company, products, sizes, prices, delivery or how to order. Returns the answer, supporting sources and, when a product was chosen, ready-made basket links.', 'adaptive-customer-engagement' ), $bot ),
				'input'       => array( 'type' => 'object', 'properties' => array( 'question' => $str( 'The question, as the visitor would type it.' ), 'page_url' => $str( 'Optional: the page the visitor is looking at, so "this one" is understood.' ) ), 'required' => array( 'question' ) ),
				'output'      => array( 'type' => 'object', 'properties' => array( 'answer' => $str( 'Plain-text answer.' ), 'sources' => $list, 'basket_links' => $list ) ),
				'run'         => array( $this, 'run_ask' ),
				'readonly'    => true,
			),
			'search'            => array(
				'label'       => __( 'Search the site', 'adaptive-customer-engagement' ),
				'description' => $woo ? __( 'Search the live site, products first. Returns results with link and summary, and for products the price, capacity, stock and whether they are configurable.', 'adaptive-customer-engagement' ) : __( 'Search the live site. Returns pages and posts with link and summary.', 'adaptive-customer-engagement' ),
				'input'       => array( 'type' => 'object', 'properties' => array( 'query' => $str( 'Words to search for.' ), 'limit' => $int( 'How many results, 1 to 8.' ), 'products_only' => array( 'type' => 'boolean', 'description' => 'Only return products (when the site sells online).' ) ), 'required' => array( 'query' ) ),
				'output'      => array( 'type' => 'object', 'properties' => array( 'results' => $list ) ),
				'run'         => array( $this, 'run_search' ),
				'readonly'    => true,
			),
			'lookup'            => array(
				'label'       => __( 'Page or product facts', 'adaptive-customer-engagement' ),
				'description' => __( 'Everything known about one page, post or product by ID or URL: summary and, for products, price, stock, dimensions, options and every component with its choices and prices.', 'adaptive-customer-engagement' ),
				'input'       => array( 'type' => 'object', 'properties' => array( 'id' => $int( 'Post or product ID.' ), 'url' => $str( 'Or the page URL.' ) ) ),
				'output'      => $any,
				'run'         => array( $this, 'run_lookup' ),
				'readonly'    => true,
			),
			'catalogue'         => array(
				'label'       => __( 'Whole catalogue', 'adaptive-customer-engagement' ),
				'description' => __( 'Every published product in one list with capacity, price, stock and link, grouped with bins first and sorted by capacity.', 'adaptive-customer-engagement' ),
				'input'       => array( 'type' => 'object', 'properties' => array() ),
				'output'      => array( 'type' => 'object', 'properties' => array( 'lines' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ) ),
				'run'         => array( $this, 'run_catalogue' ),
				'readonly'    => true,
			),
			'configure-product' => array(
				'label'       => __( 'Configure a product and get a basket link', 'adaptive-customer-engagement' ),
				'description' => __( 'Resolve a product with its options or components into an exact purchasable configuration. Returns the price and a one-click basket link (and the checkout link), or lists the choices still needed.', 'adaptive-customer-engagement' ),
				'input'       => array( 'type' => 'object', 'properties' => array( 'product_id' => $int( 'WooCommerce product ID.' ), 'quantity' => $int( 'Quantity, default 1.' ), 'attributes' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ), 'description' => 'For products with options: label => value, e.g. {"Colour":"Blue"}.' ), 'components' => array( 'type' => 'object', 'additionalProperties' => true, 'description' => 'For configurable products: component title => {"option": "...", "attributes": {"Colour": "Galvanised"}} or just the value, e.g. {"Body":{"attributes":{"Colour":"Galvanised"}},"Artwork":"No Artwork"}.' ) ), 'required' => array( 'product_id' ) ),
				'output'      => $any,
				'run'         => array( $this, 'run_configure' ),
				'readonly'    => true,
			),
			'leave-details'     => array(
				'label'       => __( 'Leave contact details', 'adaptive-customer-engagement' ),
				'description' => __( 'Leave a name with an email address or phone number (and optionally company and a message) so the team can follow up.', 'adaptive-customer-engagement' ),
				'input'       => array( 'type' => 'object', 'properties' => array( 'name' => $str( 'Full name.' ), 'email' => $str( 'Email address.' ), 'phone' => $str( 'Phone number.' ), 'company' => $str( 'Company or organisation.' ), 'message' => $str( 'What the follow-up is about.' ) ), 'required' => array( 'name' ) ),
				'output'      => array( 'type' => 'object', 'properties' => array( 'saved' => array( 'type' => 'boolean' ), 'message' => $str( 'Confirmation.' ) ) ),
				'run'         => array( $this, 'run_leave_details' ),
				'readonly'    => false,
			),
		);
		if ( ! $woo ) {
			unset( $defs['catalogue'], $defs['configure-product'] );
		}
		/**
		 * Filter the agent tool definitions (add, remove or reword tools for this site).
		 *
		 * @param array<string, array<string, mixed>> $defs Definitions keyed by tool slug.
		 */
		return (array) apply_filters( 'ace_ai_agent_tools', $defs );
	}

	/** @return array<int, string> */
	public function ability_names(): array {
		return array_map( static fn( string $k ): string => self::PREFIX . '/' . $k, array_keys( $this->definitions() ) );
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( $this->definitions() as $key => $def ) {
			wp_register_ability(
				self::PREFIX . '/' . $key,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $def['input'],
					'output_schema'       => $def['output'],
					'execute_callback'    => function ( $input ) use ( $def ) {
						if ( ! $this->enabled() ) {
							return new \WP_Error( 'ace_agent_disabled', __( 'The site assistant is not available.', 'adaptive-customer-engagement' ) );
						}
						return call_user_func( $def['run'], is_array( $input ) ? $input : array() );
					},
					'permission_callback' => '__return_true',
					'meta'                => array( 'show_in_rest' => false, 'public' => false, 'annotations' => array( 'readonly' => ! empty( $def['readonly'] ), 'destructive' => false, 'idempotent' => ! empty( $def['readonly'] ) ) ),
				)
			);
		}
	}

	/* ---------------------------------------------------------------- Runners */

	/** @param array<string, mixed> $input */
	public function run_ask( array $input ) {
		$question = sanitize_textarea_field( (string) ( $input['question'] ?? '' ) );
		if ( '' === $question ) {
			return new \WP_Error( 'ace_agent_question_required', __( 'Ask a question.', 'adaptive-customer-engagement' ) );
		}
		$page_url = esc_url_raw( (string) ( $input['page_url'] ?? '' ) );
		$response = $this->chat->respond( array( 'message' => $question, 'page_url' => $page_url, 'page_title' => '', 'conversation_uuid' => 'agent-' . wp_generate_uuid4(), 'history' => array() ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$links = array();
		foreach ( (array) ( $response['cart_actions'] ?? array() ) as $action ) {
			$links[] = $this->describe_action( (array) $action );
		}
		return array(
			'answer'       => (string) ( $response['message'] ?? '' ),
			'sources'      => array_map( array( $this, 'card' ), array_slice( (array) ( $response['sources'] ?? array() ), 0, 5 ) ),
			'basket_links' => $links,
		);
	}

	/** @param array<string, mixed> $input */
	public function run_search( array $input ) {
		$query = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		if ( '' === $query ) {
			return new \WP_Error( 'ace_agent_query_required', __( 'Give me something to search for.', 'adaptive-customer-engagement' ) );
		}
		$limit = max( 1, min( 8, absint( $input['limit'] ?? 5 ) ) );
		$woo   = function_exists( 'wc_get_product' );
		$docs  = $woo ? $this->site_context->search( $query, $limit, array( 'product' ), false ) : array();
		if ( empty( $input['products_only'] ) && count( $docs ) < $limit ) {
			$seen = array_map( static fn( $d ) => (int) ( $d['id'] ?? 0 ), $docs );
			foreach ( $this->site_context->search( $query, $limit, array(), false ) as $doc ) {
				if ( ! in_array( (int) ( $doc['id'] ?? 0 ), $seen, true ) ) {
					$docs[] = $doc;
				}
			}
		}
		$results = array();
		foreach ( array_slice( $docs, 0, $limit ) as $doc ) {
			$full = $this->site_context->get_source_document( (int) ( $doc['id'] ?? 0 ) );
			if ( is_array( $full ) ) {
				$results[] = $this->card( $full );
			}
		}
		return array( 'results' => $results );
	}

	/** @param array<string, mixed> $input */
	public function run_lookup( array $input ) {
		$id = absint( $input['id'] ?? $input['product_id'] ?? 0 );
		if ( ! $id && ! empty( $input['url'] ) ) {
			$id = (int) url_to_postid( esc_url_raw( (string) $input['url'] ) );
		}
		$doc = $id ? $this->site_context->get_source_document( $id ) : null;
		if ( ! is_array( $doc ) ) {
			return new \WP_Error( 'ace_agent_not_found', __( 'Nothing published at that ID or URL.', 'adaptive-customer-engagement' ) );
		}
		$card = $this->card( $doc );
		$card['summary']    = (string) ( $doc['summary'] ?? '' );
		$card['components'] = (array) ( $doc['commerce']['components'] ?? array() );
		$card['variations'] = array_map( static fn( $v ) => array( 'id' => (int) ( $v['id'] ?? 0 ), 'label' => (string) ( $v['label'] ?? '' ), 'price' => (string) ( $v['price_html'] ?? '' ), 'attributes' => (array) ( $v['attributes'] ?? array() ) ), (array) ( $doc['commerce']['variations'] ?? array() ) );
		return $card;
	}

	/** @param array<string, mixed> $input */
	public function run_catalogue( array $input ) {
		return array( 'lines' => $this->site_context->get_catalogue_digest_lines() );
	}

	/** @param array<string, mixed> $input */
	public function run_configure( array $input ) {
		$id = absint( $input['product_id'] ?? 0 );
		if ( ! $id ) {
			return new \WP_Error( 'ace_agent_product_required', __( 'A product ID is required.', 'adaptive-customer-engagement' ) );
		}
		$attributes = array();
		foreach ( (array) ( $input['attributes'] ?? array() ) as $k => $v ) {
			$attributes[ sanitize_text_field( (string) $k ) ] = sanitize_text_field( (string) ( is_scalar( $v ) ? $v : '' ) );
		}
		$components = array();
		foreach ( (array) ( $input['components'] ?? array() ) as $k => $v ) {
			$key = sanitize_text_field( (string) $k );
			if ( is_array( $v ) ) {
				$attrs = array();
				foreach ( (array) ( $v['attributes'] ?? array() ) as $ak => $av ) {
					$attrs[ sanitize_text_field( (string) $ak ) ] = sanitize_text_field( (string) ( is_scalar( $av ) ? $av : '' ) );
				}
				$components[ $key ] = array( 'option' => sanitize_text_field( (string) ( is_scalar( $v['option'] ?? null ) ? $v['option'] : '' ) ), 'attributes' => $attrs, 'qty' => max( 1, absint( $v['qty'] ?? 1 ) ) );
			} elseif ( is_scalar( $v ) ) {
				$components[ $key ] = array( 'option' => sanitize_text_field( (string) $v ), 'attributes' => array(), 'qty' => 1 );
			}
		}
		$resolved = $this->site_context->resolve_cart_selection( $id, $attributes, max( 1, absint( $input['quantity'] ?? 1 ) ), $components );
		if ( ! is_array( $resolved ) ) {
			return new \WP_Error( 'ace_agent_not_purchasable', __( 'That product cannot be bought online. Ask for a quote instead.', 'adaptive-customer-engagement' ) );
		}
		if ( ! empty( $resolved['error'] ) ) {
			return new \WP_Error( 'ace_agent_' . sanitize_key( (string) $resolved['error'] ), sprintf( __( '%s is not available right now.', 'adaptive-customer-engagement' ), (string) ( $resolved['name'] ?? 'That option' ) ) );
		}
		if ( ! empty( $resolved['needs_more'] ) ) {
			return array( 'complete' => false, 'name' => (string) ( $resolved['name'] ?? '' ), 'missing' => (array) ( $resolved['missing'] ?? array() ), 'hint' => __( 'Call configure-product again with the missing choices filled in.', 'adaptive-customer-engagement' ) );
		}
		return array( 'complete' => true ) + $this->describe_action( $resolved );
	}

	/** @param array<string, mixed> $input */
	public function run_leave_details( array $input ) {
		$payload = array(
			'conversation_uuid' => 'agent-' . wp_generate_uuid4(),
			'contact_name'      => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'contact_email'     => sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'contact_phone'     => sanitize_text_field( (string) ( $input['phone'] ?? '' ) ),
			'contact_company'   => sanitize_text_field( (string) ( $input['company'] ?? '' ) ),
			'message'           => sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ),
			'page_url'          => home_url( '/' ),
			'page_title'        => 'Agent',
		);
		$result = $this->chat->submit_follow_up_request( $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'saved' => true, 'message' => __( 'Thanks, the team will be in touch.', 'adaptive-customer-engagement' ) );
	}

	/* ---------------------------------------------------------------- Helpers */

	/** A product card an agent can act on. @param array<string, mixed> $doc */
	public function card( array $doc ): array {
		$c    = (array) ( $doc['commerce'] ?? array() );
		$card = array(
			'id'      => (int) ( $doc['id'] ?? 0 ),
			'type'    => (string) ( $doc['source_type'] ?? '' ),
			'title'   => (string) ( $doc['title'] ?? '' ),
			'url'     => (string) ( $doc['url'] ?? '' ),
			'summary' => (string) ( $doc['summary'] ?? '' ),
			'image'   => (string) ( $doc['image_url'] ?? '' ),
		);
		if ( 'product' === $card['type'] || ! empty( $c['product_id'] ) ) {
			$card += array(
				'product_id'      => (int) ( $c['product_id'] ?? $doc['id'] ?? 0 ),
				'price'           => (string) ( $c['price'] ?? '' ),
				'in_stock'        => 'outofstock' !== (string) ( $c['stock_status'] ?? '' ),
				'capacity_litres' => isset( $c['capacity_litres'] ) ? $c['capacity_litres'] : null,
				'purchasable'     => ! empty( $c['purchasable'] ),
				'configurable'    => ! empty( $c['is_composite'] ),
				'has_options'     => ! empty( $c['is_variable'] ),
			);
		}
		return $card;
	}

	/** Basket and checkout links for a resolved cart action. @param array<string, mixed> $action */
	private function describe_action( array $action ): array {
		return array(
			'product_id'    => (int) ( $action['product_id'] ?? 0 ),
			'name'          => (string) ( $action['name'] ?? '' ),
			'quantity'      => (int) ( $action['quantity'] ?? 1 ),
			'price'         => (string) ( $action['price'] ?? '' ),
			'configuration' => (array) ( $action['composite'] ?? array() ),
			'basket_url'    => $this->basket_url( $action ),
			'checkout_url'  => function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '',
			'note'          => __( 'Opening basket_url adds this exact configuration to the basket; checkout_url then completes the order.', 'adaptive-customer-engagement' ),
		);
	}

	/**
	 * One-click add-to-basket URL for a resolved action (simple, variation or composite configuration).
	 *
	 * @param array<string, mixed> $action Resolved cart action.
	 * @return string
	 */
	public function basket_url( array $action ): string {
		$product_id = (int) ( $action['product_id'] ?? 0 );
		$permalink  = $product_id ? (string) get_permalink( $product_id ) : '';
		if ( '' === $permalink ) {
			return '';
		}
		$args = array( 'add-to-cart' => $product_id, 'quantity' => max( 1, (int) ( $action['quantity'] ?? 1 ) ) );
		if ( ! empty( $action['variation_id'] ) ) {
			$args['variation_id'] = (int) $action['variation_id'];
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $action['variation_id'] ) : null;
			if ( $variation && method_exists( $variation, 'get_variation_attributes' ) ) {
				foreach ( (array) $variation->get_variation_attributes() as $k => $v ) {
					$args[ (string) $k ] = (string) $v;
				}
			}
		}
		foreach ( (array) ( $action['composite'] ?? array() ) as $component_id => $entry ) {
			$args['wccp_component_selection'][ (string) $component_id ] = (int) ( $entry['product_id'] ?? 0 );
			$args['wccp_component_quantity'][ (string) $component_id ]  = max( 1, (int) ( $entry['quantity'] ?? 1 ) );
			if ( ! empty( $entry['variation_id'] ) ) {
				$args['wccp_variation_id'][ (string) $component_id ] = (int) $entry['variation_id'];
			}
			foreach ( (array) ( $entry['attributes'] ?? array() ) as $k => $v ) {
				$args[ 'wccp_' . (string) $k ][ (string) $component_id ] = (string) $v;
			}
		}
		return esc_url_raw( add_query_arg( $args, $permalink ) );
	}

	private function bot_name(): string {
		$ai_agent = is_array( Settings::get()['ai_agent'] ?? null ) ? Settings::get()['ai_agent'] : array();
		return sanitize_text_field( (string) ( $ai_agent['frontend_chat_bot_name'] ?: ( $ai_agent['frontend_chat_title'] ?? '' ) ) ) ?: __( 'the site assistant', 'adaptive-customer-engagement' );
	}

	/* ---------------------------------------------------------------- MCP server */

	public function register_mcp_server( $adapter ): void {
		if ( ! $this->enabled() || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) || ! class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ) {
			return;
		}
		$adapter->create_server(
			self::MCP_ID,
			self::MCP_NS,
			self::MCP_ROUTE,
			sprintf( '%s (%s)', $this->bot_name(), get_bloginfo( 'name' ) ),
			sprintf( __( 'Public assistant for %1$s: ask questions, search the site, look up pages or products%3$s, leave contact details. No account needed. Declaration at %2$s', 'adaptive-customer-engagement' ), get_bloginfo( 'name' ), home_url( '/.well-known/mcp.json' ), function_exists( 'wc_get_product' ) ? __( ', configure products and get a basket link', 'adaptive-customer-engagement' ) : '' ),
			'v' . ( defined( 'ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION' ) ? ACE_ADAPTIVE_CUSTOMER_ENGAGEMENT_PLUGIN_VERSION : '1' ),
			array( '\\WP\\MCP\\Transport\\HttpTransport' ),
			class_exists( '\\WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler' ) ? '\\WP\\MCP\\Infrastructure\\ErrorHandling\\NullMcpErrorHandler' : null,
			class_exists( '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' ) ? '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' : null,
			$this->ability_names(),
			array(),
			array(),
			array( $this, 'transport_permission' )
		);
		add_filter( 'mcp_adapter_session_max_per_user', array( $this, 'session_cap' ) );
	}

	/** The adapter binds sessions to a user; anonymous agents run as one locked-down subscriber. */
	public function transport_permission() {
		if ( is_user_logged_in() ) {
			return true;
		}
		$id = $this->service_user_id();
		if ( ! $id ) {
			return false;
		}
		wp_set_current_user( $id );
		return true;
	}

	public function service_user_id(): int {
		$user = get_user_by( 'login', self::SERVICE_LOGIN );
		if ( $user ) {
			return (int) $user->ID;
		}
		$id = wp_insert_user( array( 'user_login' => self::SERVICE_LOGIN, 'user_pass' => wp_generate_password( 48, true, true ), 'role' => 'subscriber', 'display_name' => 'Public agent', 'show_admin_bar_front' => 'false' ) );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_user_meta( (int) $id, 'ace_service_account', 'public-mcp' );
		return (int) $id;
	}

	public function session_cap( $max ) {
		return get_current_user_id() && 'public-mcp' === get_user_meta( get_current_user_id(), 'ace_service_account', true ) ? 500 : $max;
	}

	public function no_app_passwords( $available, $user ) {
		return ( $user instanceof \WP_User && self::SERVICE_LOGIN === $user->user_login ) ? false : $available;
	}

	/* ---------------------------------------------------------------- Discovery */

	public function rewrites(): void {
		add_rewrite_rule( '^\\.well-known/mcp\\.json/?$', 'index.php?ace_agent=mcp', 'top' );
		/**
		 * Filter whether this plugin serves /llms.txt (switch off when something else does).
		 *
		 * @param bool $serve Serve llms.txt.
		 */
		if ( apply_filters( 'ace_ai_agent_serve_llms_txt', true ) ) {
			add_rewrite_rule( '^llms\\.txt/?$', 'index.php?ace_agent=llms', 'top' );
		}
	}

	public function maybe_flush_rewrites(): void {
		if ( get_option( self::REWRITE_FLAG ) !== self::REWRITE_VER ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_FLAG, self::REWRITE_VER, false );
		}
	}

	public function query_vars( $vars ) {
		$vars[] = 'ace_agent';
		return $vars;
	}

	public function no_canonical_redirect( $redirect, $requested ) {
		return get_query_var( 'ace_agent' ) ? false : $redirect;
	}

	/** @return array<string, mixed> */
	public function declaration(): array {
		$tools = array();
		foreach ( $this->definitions() as $key => $def ) {
			$tools[] = array( 'name' => self::PREFIX . '/' . $key, 'description' => $def['description'], 'readonly' => ! empty( $def['readonly'] ) );
		}
		return array(
			'name'        => sprintf( '%s (%s)', $this->bot_name(), get_bloginfo( 'name' ) ),
			'description' => sprintf( __( 'Public assistant for %s. No account needed.', 'adaptive-customer-engagement' ), get_bloginfo( 'name' ) ),
			'site'        => home_url( '/' ),
			'mcp'         => array( 'transport' => 'http', 'endpoint' => rest_url( self::MCP_NS . '/' . self::MCP_ROUTE ), 'available' => class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ),
			'tools'       => $tools,
			'chat'        => array( 'endpoint' => rest_url( 'adaptive-customer-engagement/v1/ai/chat/respond' ), 'method' => 'POST', 'body' => array( 'message' => 'string', 'page_url' => 'string (optional)' ) ),
			'llms_txt'    => home_url( '/llms.txt' ),
			'privacy'     => get_privacy_policy_url() ?: '',
		);
	}

	public function serve_discovery(): void {
		$what = get_query_var( 'ace_agent' );
		if ( ! $what || ! $this->enabled() ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		header( 'Cache-Control: public, max-age=3600' );
		if ( 'mcp' === $what ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $this->declaration(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}
		if ( 'llms' === $what ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo $this->llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text built from escaped parts.
			exit;
		}
	}

	public function llms_txt(): string {
		$d     = $this->declaration();
		$lines = array( '# ' . wp_strip_all_tags( get_bloginfo( 'name' ) ), '', '> ' . wp_strip_all_tags( get_bloginfo( 'description' ) ?: $d['description'] ), '', '## Assistant for agents', '', sprintf( '%s answers questions and looks things up on this site%s. MCP (HTTP transport): %s — declaration: %s', $this->bot_name(), function_exists( 'wc_get_product' ) ? ', configures products and gives basket links' : '', $d['mcp']['endpoint'], home_url( '/.well-known/mcp.json' ) ), '', 'Tools:' );
		foreach ( $d['tools'] as $tool ) {
			$lines[] = sprintf( '- %s: %s', $tool['name'], wp_strip_all_tags( $tool['description'] ) );
		}
		$lines[] = '';
		$lines[] = sprintf( 'Plain chat without MCP: POST %s with {"message": "..."}', $d['chat']['endpoint'] );
		$lines[] = '';
		$lines[] = '## Key pages';
		$lines[] = '';
		$pages = array( home_url( '/' ) => 'Home' );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$pages[ (string) wc_get_page_permalink( 'shop' ) ] = 'Shop';
			$pages[ (string) wc_get_page_permalink( 'cart' ) ] = 'Basket';
			$pages[ (string) wc_get_page_permalink( 'checkout' ) ] = 'Checkout';
		}
		foreach ( $pages as $url => $label ) {
			if ( $url ) {
				$lines[] = sprintf( '- [%s](%s)', $label, $url );
			}
		}
		/**
		 * Filter the llms.txt lines.
		 *
		 * @param array<int, string> $lines Lines.
		 */
		return implode( "\n", (array) apply_filters( 'ace_ai_agent_llms_txt_lines', $lines ) ) . "\n";
	}

	public function robots( $output, $public ) {
		if ( $public && $this->enabled() ) {
			$output .= "\n# Agents: MCP declaration at " . home_url( '/.well-known/mcp.json' ) . " and site index at " . home_url( '/llms.txt' ) . "\n";
		}
		return $output;
	}

	public function head_link(): void {
		if ( $this->enabled() ) {
			printf( '<link rel="alternate" type="application/json" title="MCP server" href="%s">' . "\n", esc_url( home_url( '/.well-known/mcp.json' ) ) );
		}
	}
}
