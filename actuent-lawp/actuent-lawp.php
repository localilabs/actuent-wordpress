<?php
/**
 * Plugin Name:       Actuent LAWP
 * Plugin URI:        https://docs.actuent.ai/#actions
 * Description:       Makes your site readable and actionable by AI agents. Publishes your site as LAWP at /.well-known/lawp.json, with executable "search" and "contact" actions.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            localilabs
 * Author URI:        https://localilabs.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       actuent-lawp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACTUENT_LAWP_VERSION', '1.0.0' );
define( 'ACTUENT_LAWP_JWKS', 'https://agents.actuent.ai/.well-known/actuent-signing-keys.json' );
define( 'ACTUENT_LAWP_CACHE', 'actuent_lawp_json' );

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function actuent_lawp_defaults() {
	return array(
		'enable_search'  => 1,
		'enable_contact' => 1,
		'description'    => '',
		'custom_actions' => '',
		'page_limit'     => 20,
	);
}

function actuent_lawp_options() {
	return wp_parse_args( (array) get_option( 'actuent_lawp', array() ), actuent_lawp_defaults() );
}

/* -------------------------------------------------------------------------
 * The LAWP document
 * ---------------------------------------------------------------------- */

function actuent_lawp_summary( $text, $words = 60 ) {
	$text = strip_shortcodes( (string) $text );
	$text = wp_strip_all_tags( $text );
	return html_entity_decode( wp_trim_words( $text, $words, '…' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

// WordPress returns site names and titles HTML-encoded (e.g. &#039;); LAWP is plain text.
function actuent_lawp_text( $value ) {
	return html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

function actuent_lawp_path( $url ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return $path ? untrailingslashit( $path ) : '/';
}

function actuent_lawp_build() {
	$options = actuent_lawp_options();
	$name    = actuent_lawp_text( get_bloginfo( 'name' ) );
	$tagline = actuent_lawp_text( get_bloginfo( 'description' ) );
	$host    = wp_parse_url( home_url(), PHP_URL_HOST );

	$home_content = $options['description'] ? $options['description'] : $tagline;
	$front_id     = (int) get_option( 'page_on_front' );
	if ( $front_id ) {
		$home_content = trim( $home_content . ' ' . actuent_lawp_summary( get_post_field( 'post_content', $front_id ), 80 ) );
	}

	$pages = array(
		'/' => array(
			'title'   => $tagline ? $name . ' — ' . $tagline : $name,
			'content' => $home_content ? $home_content : 'Website of ' . $name . '.',
		),
	);

	$published = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => max( 1, (int) $options['page_limit'] ),
			'orderby'     => 'menu_order',
			'order'       => 'ASC',
			'exclude'     => $front_id ? array( $front_id ) : array(),
			'has_password' => false,
		)
	);
	foreach ( $published as $page ) {
		$path = actuent_lawp_path( get_permalink( $page ) );
		if ( '/' === $path || isset( $pages[ $path ] ) ) {
			continue;
		}
		$content = actuent_lawp_summary( $page->post_content );
		$pages[ $path ] = array(
			'title'   => actuent_lawp_text( get_the_title( $page ) ),
			'content' => $content ? $content : actuent_lawp_text( get_the_title( $page ) ),
		);
	}

	$actions = array();
	if ( $options['enable_search'] ) {
		$actions[] = array(
			'id'          => 'search',
			'name'        => 'Search ' . $name,
			'description' => 'Search this site\'s posts and pages. Returns matching titles, links and excerpts.',
			'intent'      => array( 'search', 'find', 'look up', 'articles', 'posts' ),
			'input'       => array( 'type' => 'text', 'required' => true ),
			'endpoint'    => array( 'url' => rest_url( 'actuent/v1/search' ), 'method' => 'GET' ),
		);
	}
	if ( $options['enable_contact'] ) {
		$actions[] = array(
			'id'          => 'contact',
			'name'        => 'Contact ' . $name,
			'description' => 'Send a message to the site owner. They reply to the email address given.',
			'intent'      => array( 'contact', 'message', 'email', 'get in touch', 'enquiry', 'ask a question' ),
			// LAWP 0.3 structured input: agents send these fields instead of free text.
			'input'       => array(
				'type'     => 'object',
				'required' => true,
				'fields'   => array(
					array( 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Name of the person sending the message' ),
					array( 'name' => 'email', 'type' => 'email', 'required' => true, 'description' => 'Email address for the reply' ),
					array( 'name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'The message' ),
					array( 'name' => 'phone', 'type' => 'phone', 'required' => false, 'description' => 'Phone number, if they want a call back' ),
				),
			),
			'endpoint'    => array( 'url' => rest_url( 'actuent/v1/contact' ), 'method' => 'POST' ),
		);
	}
	$custom = json_decode( (string) $options['custom_actions'], true );
	if ( is_array( $custom ) ) {
		foreach ( $custom as $action ) {
			if ( is_array( $action ) && ! empty( $action['id'] ) ) {
				$actions[] = $action;
			}
		}
	}

	return array(
		'protocol'  => 'LAWP',
		'version'   => '0.2.0',
		'domain'    => $host,
		'name'      => $name,
		'pages'     => $pages,
		'actions'   => $actions,
		'generator' => 'Actuent LAWP for WordPress ' . ACTUENT_LAWP_VERSION,
	);
}

function actuent_lawp_document() {
	$cached = get_transient( ACTUENT_LAWP_CACHE );
	if ( false !== $cached ) {
		return $cached;
	}
	$doc = actuent_lawp_build();
	set_transient( ACTUENT_LAWP_CACHE, $doc, HOUR_IN_SECONDS );
	return $doc;
}

function actuent_lawp_clear_cache() {
	delete_transient( ACTUENT_LAWP_CACHE );
}
add_action( 'save_post', 'actuent_lawp_clear_cache' );
add_action( 'deleted_post', 'actuent_lawp_clear_cache' );
add_action( 'update_option_blogname', 'actuent_lawp_clear_cache' );
add_action( 'update_option_blogdescription', 'actuent_lawp_clear_cache' );
add_action( 'update_option_actuent_lawp', 'actuent_lawp_clear_cache' );

// Serve /.well-known/lawp.json before WordPress routes the request.
function actuent_lawp_serve() {
	$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$home = wp_parse_url( home_url(), PHP_URL_PATH );
	$want = ( $home ? untrailingslashit( $home ) : '' ) . '/.well-known/lawp.json';
	if ( $path !== $want ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: public, max-age=300' );
	echo wp_json_encode( actuent_lawp_document(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}
add_action( 'init', 'actuent_lawp_serve', 0 );

/* -------------------------------------------------------------------------
 * Verifying that action requests really come from Actuent (Ed25519)
 * Signed string: "<timestamp>\n<METHOD>\n<full url>\n<sha256 hex of raw body>"
 * ---------------------------------------------------------------------- */

function actuent_lawp_b64url_decode( $value ) {
	$value = strtr( (string) $value, '-_', '+/' );
	$pad   = strlen( $value ) % 4;
	if ( $pad ) {
		$value .= str_repeat( '=', 4 - $pad );
	}
	return base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
}

function actuent_lawp_public_key( $kid ) {
	$keys = get_transient( 'actuent_lawp_jwks' );
	if ( false === $keys || ! isset( $keys[ $kid ] ) ) {
		$response = wp_remote_get( ACTUENT_LAWP_JWKS, array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$keys = array();
		foreach ( ( isset( $body['keys'] ) ? $body['keys'] : array() ) as $jwk ) {
			if ( isset( $jwk['kid'], $jwk['x'] ) && 'Ed25519' === ( isset( $jwk['crv'] ) ? $jwk['crv'] : '' ) ) {
				$keys[ $jwk['kid'] ] = $jwk['x'];
			}
		}
		set_transient( 'actuent_lawp_jwks', $keys, HOUR_IN_SECONDS );
	}
	return isset( $keys[ $kid ] ) ? actuent_lawp_b64url_decode( $keys[ $kid ] ) : null;
}

/**
 * @param string $signed_url The URL Actuent called (the endpoint published in lawp.json, plus any query string).
 */
function actuent_lawp_verify_signature( $headers, $method, $signed_url, $raw_body, $public_key = null ) {
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return false;
	}
	$timestamp = isset( $headers['x_actuent_timestamp'] ) ? (string) $headers['x_actuent_timestamp'] : '';
	$kid       = isset( $headers['x_actuent_key_id'] ) ? (string) $headers['x_actuent_key_id'] : '';
	$signature = isset( $headers['x_actuent_signature'] ) ? preg_replace( '/^v1=/', '', (string) $headers['x_actuent_signature'] ) : '';
	if ( '' === $timestamp || '' === $kid || '' === $signature ) {
		return false;
	}
	if ( abs( time() - (int) $timestamp ) > 300 ) {
		return false; // Reject replays older than 5 minutes.
	}
	$key = null !== $public_key ? $public_key : actuent_lawp_public_key( $kid );
	$sig = actuent_lawp_b64url_decode( $signature );
	if ( ! $key || 32 !== strlen( $key ) || ! $sig || 64 !== strlen( $sig ) ) {
		return false;
	}
	$signed = $timestamp . "\n" . strtoupper( $method ) . "\n" . $signed_url . "\n" . hash( 'sha256', (string) $raw_body );
	try {
		return sodium_crypto_sign_verify_detached( $sig, $signed, $key );
	} catch ( Exception $e ) {
		return false;
	}
}

function actuent_lawp_request_is_signed( WP_REST_Request $request, $endpoint_url ) {
	$query  = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$url    = 'GET' === $request->get_method() && '' !== $query ? $endpoint_url . ( false === strpos( $endpoint_url, '?' ) ? '?' : '&' ) . $query : $endpoint_url;
	$lower  = array();
	foreach ( $request->get_headers() as $name => $values ) {
		$lower[ strtolower( str_replace( '-', '_', $name ) ) ] = is_array( $values ) ? reset( $values ) : $values;
	}
	return actuent_lawp_verify_signature( $lower, $request->get_method(), $url, $request->get_body() );
}

/* -------------------------------------------------------------------------
 * Executable actions (REST endpoints published in lawp.json)
 * ---------------------------------------------------------------------- */

function actuent_lawp_is_test( WP_REST_Request $request ) {
	$json = $request->get_json_params();
	return ( is_array( $json ) && ! empty( $json['test'] ) )
		|| 'true' === $request->get_param( 'test' )
		|| 'true' === $request->get_header( 'x_lawp_test' );
}

function actuent_lawp_input( WP_REST_Request $request ) {
	$json  = $request->get_json_params();
	$input = is_array( $json ) && array_key_exists( 'input', $json ) ? $json['input'] : $request->get_param( 'input' );
	return is_string( $input ) ? $input : ( null === $input ? '' : wp_json_encode( $input ) );
}

function actuent_lawp_search( WP_REST_Request $request ) {
	$query = sanitize_text_field( actuent_lawp_input( $request ) );
	if ( '' === $query ) {
		return new WP_REST_Response( array( 'error' => 'Provide a search query as input' ), 400 );
	}
	$posts   = get_posts(
		array(
			's'           => $query,
			'post_type'   => array( 'post', 'page' ),
			'post_status' => 'publish',
			'numberposts' => 10,
			'has_password' => false,
		)
	);
	$results = array();
	foreach ( $posts as $post ) {
		$results[] = array(
			'title'   => actuent_lawp_text( get_the_title( $post ) ),
			'url'     => get_permalink( $post ),
			'excerpt' => actuent_lawp_summary( $post->post_content, 40 ),
		);
	}
	return new WP_REST_Response( array( 'query' => $query, 'count' => count( $results ), 'results' => $results ), 200 );
}

function actuent_lawp_contact( WP_REST_Request $request ) {
	// Contact messages are accepted only from Actuent, so bots can't use this to spam you.
	if ( ! actuent_lawp_request_is_signed( $request, rest_url( 'actuent/v1/contact' ) ) ) {
		return new WP_REST_Response( array( 'error' => 'Invalid Actuent signature' ), 401 );
	}
	// Structured input (LAWP 0.3): { name, email, message, phone }. Plain text from older agents still works.
	$json  = $request->get_json_params();
	$input = is_array( $json ) && array_key_exists( 'input', $json ) ? $json['input'] : null;
	if ( null === $input && 'GET' === $request->get_method() ) {
		$input = array( 'name' => $request->get_param( 'name' ), 'email' => $request->get_param( 'email' ), 'message' => $request->get_param( 'message' ), 'phone' => $request->get_param( 'phone' ) );
	}
	$from_name = '';
	$reply_to  = '';
	$phone     = '';
	if ( is_array( $input ) ) {
		$from_name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		$reply_to  = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		$phone     = isset( $input['phone'] ) ? sanitize_text_field( (string) $input['phone'] ) : '';
		$message   = isset( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : '';
		$missing   = array();
		if ( '' === $from_name ) {
			$missing[] = 'name';
		}
		if ( ! is_email( $reply_to ) ) {
			$missing[] = 'email';
		}
		if ( strlen( $message ) < 5 ) {
			$missing[] = 'message';
		}
		if ( $missing ) {
			return new WP_REST_Response( array( 'error' => 'Missing or invalid: ' . implode( ', ', $missing ) ), 400 );
		}
	} else {
		$message = sanitize_textarea_field( actuent_lawp_input( $request ) );
		if ( strlen( $message ) < 5 ) {
			return new WP_REST_Response( array( 'error' => 'Provide the message as input (at least a few words)' ), 400 );
		}
	}
	if ( actuent_lawp_is_test( $request ) ) {
		return new WP_REST_Response( array( 'status' => 'ok', 'test' => true, 'message' => 'Test received and verified — no email was sent' ), 200 );
	}
	$details = $from_name ? sprintf( "From: %s <%s>%s\n\n", $from_name, $reply_to, $phone ? "\nPhone: " . $phone : '' ) : '';
	$headers = $reply_to ? array( sprintf( 'Reply-To: %s <%s>', str_replace( array( '<', '>', '"', "\r", "\n" ), '', $from_name ), $reply_to ) ) : array();
	$sent = wp_mail(
		get_option( 'admin_email' ),
		sprintf( '[%s] Message from an AI assistant via Actuent', get_bloginfo( 'name' ) ),
		$details . $message . "\n\n—\nSent by an AI assistant on behalf of its user, via Actuent (https://actuent.ai).\nRequest ID: " . sanitize_text_field( (string) $request->get_header( 'x_actuent_request_id' ) ),
		$headers
	);
	if ( ! $sent ) {
		return new WP_REST_Response( array( 'error' => 'The site could not send the message right now' ), 502 );
	}
	return new WP_REST_Response( array( 'status' => 'sent', 'message' => 'Your message was sent to the site owner.' ), 200 );
}

function actuent_lawp_routes() {
	$options = actuent_lawp_options();
	if ( $options['enable_search'] ) {
		register_rest_route(
			'actuent/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => 'actuent_lawp_search',
				'permission_callback' => '__return_true',
			)
		);
	}
	if ( $options['enable_contact'] ) {
		register_rest_route(
			'actuent/v1',
			'/contact',
			array(
				'methods'             => 'POST',
				'callback'            => 'actuent_lawp_contact',
				'permission_callback' => '__return_true', // Checked in the callback via the Actuent signature.
			)
		);
	}
}
add_action( 'rest_api_init', 'actuent_lawp_routes' );

/* -------------------------------------------------------------------------
 * Settings page: Settings → Actuent
 * ---------------------------------------------------------------------- */

function actuent_lawp_sanitize( $input ) {
	$clean                   = actuent_lawp_defaults();
	$clean['enable_search']  = empty( $input['enable_search'] ) ? 0 : 1;
	$clean['enable_contact'] = empty( $input['enable_contact'] ) ? 0 : 1;
	$clean['description']    = isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '';
	$clean['page_limit']     = isset( $input['page_limit'] ) ? max( 1, min( 100, (int) $input['page_limit'] ) ) : 20;
	$custom                  = isset( $input['custom_actions'] ) ? trim( wp_unslash( $input['custom_actions'] ) ) : '';
	if ( '' !== $custom && ! is_array( json_decode( $custom, true ) ) ) {
		add_settings_error( 'actuent_lawp', 'invalid_json', 'Custom actions must be a JSON array. Your other settings were saved.' );
		$custom = '';
	}
	$clean['custom_actions'] = $custom;
	return $clean;
}

function actuent_lawp_admin_menu() {
	add_options_page( 'Actuent LAWP', 'Actuent', 'manage_options', 'actuent-lawp', 'actuent_lawp_settings_page' );
}
add_action( 'admin_menu', 'actuent_lawp_admin_menu' );

function actuent_lawp_register_settings() {
	register_setting( 'actuent_lawp', 'actuent_lawp', array( 'sanitize_callback' => 'actuent_lawp_sanitize' ) );
}
add_action( 'admin_init', 'actuent_lawp_register_settings' );

function actuent_lawp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$options = actuent_lawp_options();
	$url     = home_url( '/.well-known/lawp.json' );
	$check   = 'https://docs.actuent.ai/#checker';
	?>
	<div class="wrap">
		<h1>Actuent LAWP</h1>
		<p>Your site is published for AI agents at <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $url ); ?></a>.
			Test it with the <a href="<?php echo esc_url( $check ); ?>" target="_blank">LAWP Checker</a>.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'actuent_lawp' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Actions</th>
					<td>
						<label><input type="checkbox" name="actuent_lawp[enable_search]" value="1" <?php checked( $options['enable_search'] ); ?>> Let AI agents search this site</label><br>
						<label><input type="checkbox" name="actuent_lawp[enable_contact]" value="1" <?php checked( $options['enable_contact'] ); ?>> Let AI agents send you messages (emailed to <?php echo esc_html( get_option( 'admin_email' ) ); ?>, only from Actuent)</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="actuent-description">Description</label></th>
					<td><textarea id="actuent-description" name="actuent_lawp[description]" rows="3" class="large-text" placeholder="What your site or business does, in plain English. Defaults to your tagline."><?php echo esc_textarea( $options['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="actuent-limit">Pages included</label></th>
					<td><input id="actuent-limit" type="number" min="1" max="100" name="actuent_lawp[page_limit]" value="<?php echo esc_attr( $options['page_limit'] ); ?>"> published pages</td>
				</tr>
				<tr>
					<th scope="row"><label for="actuent-custom">Custom actions</label></th>
					<td>
						<textarea id="actuent-custom" name="actuent_lawp[custom_actions]" rows="8" class="large-text code" placeholder='[{"id":"book","name":"Book a table","description":"Reserve a table","intent":["book","reserve","table"],"input":{"type":"text","required":true},"endpoint":{"url":"https://yoursite.com/api/book","method":"POST"}}]'><?php echo esc_textarea( $options['custom_actions'] ); ?></textarea>
						<p class="description">Optional JSON array of extra LAWP actions. See <a href="https://docs.actuent.ai/#actions" target="_blank">LAWP Actions</a>.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

function actuent_lawp_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=actuent-lawp' ) ) . '">Settings</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'actuent_lawp_action_links' );
