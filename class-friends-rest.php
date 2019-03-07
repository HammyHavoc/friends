<?php
/**
 * Friends REST
 *
 * This contains the functions for REST.
 *
 * @package Friends
 */

/**
 * This is the class for the REST part of the Friends Plugin.
 *
 * @since 0.6
 *
 * @package Friends
 * @author Alex Kirk
 */
class Friends_REST {
	const PREFIX = 'friends/v1';
	/**
	 * Contains a reference to the Friends class.
	 *
	 * @var Friends
	 */
	private $friends;

	/**
	 * Constructor
	 *
	 * @param Friends $friends A reference to the Friends object.
	 */
	public function __construct( Friends $friends ) {
		$this->friends = $friends;
		$this->register_hooks();
	}

	/**
	 * Register the WordPress hooks
	 */
	private function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'add_rest_routes' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_request_before_callbacks' ), 100, 3 );
		add_action( 'wp_trash_post', array( $this, 'notify_remote_friend_post_deleted' ) );
		add_action( 'before_delete_post', array( $this, 'notify_remote_friend_post_deleted' ) );
		add_action( 'friends_user_post_reaction', array( $this, 'notify_remote_friend_post_reaction' ), 10, 2 );
		add_action( 'friends_user_post_reaction', array( $this, 'notify_friend_of_my_reaction' ) );
		add_action( 'set_user_role', array( $this, 'notify_remote_friend_request_accepted' ), 20, 3 );
	}

	/**
	 * Add the REST API to send and receive friend requests
	 */
	public function add_rest_routes() {
		register_rest_route(
			self::PREFIX,
			'friend-request',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_friend_request' ),
			)
		);
		register_rest_route(
			self::PREFIX,
			'accept-friend-request',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_accept_friend_request' ),
			)
		);
		register_rest_route(
			self::PREFIX,
			'hello',
			array(
				'methods'  => 'GET,POST',
				'callback' => array( $this, 'rest_hello' ),
			)
		);
		register_rest_route(
			self::PREFIX,
			'post-deleted',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_friend_post_deleted' ),
			)
		);
		register_rest_route(
			self::PREFIX,
			'update-post-reactions',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_update_friend_post_reactions' ),
			)
		);
		register_rest_route(
			self::PREFIX,
			'my-reactions',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_update_reactions_on_my_post' ),
			)
		);
		register_rest_route(
			self::PREFIX,
			'recommendation',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_receive_recommendation' ),
			)
		);
	}

	/**
	 * Limit the REST API.
	 *
	 * @param WP_HTTP_Response|WP_Error $response Result to send to the client.
	 * @param array                     $handler  Route handler used for the request.
	 * @param WP_REST_Request           $request  Request used to generate the response.
	 * @return WP_HTTP_Response|WP_Error Result to send to the client.
	 */
	public function rest_request_before_callbacks( $response, $handler, $request ) {
		// Nota bene: when directly accessing an endpoint in a browser, a user will be
		// appear authenticated if a nonce is present, see rest_cookie_check_errors().
		if ( is_wp_error( $response ) || current_user_can( Friends::REQUIRED_ROLE ) ) {
			return $response;
		}

		$route = $request->get_route();

		// Don't allow spying on the users list since it gives away the user's subscriptions,
		// friend requests and friends.
		if ( 0 === stripos( $route, '/wp/v2/users' ) ) {
			return new WP_Error(
				'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				array( 'status' => 401 )
			);
		}

		// The wp/v2/posts and wp/v2/pages endpoints are safe since they respect the post status.
		// The friend_post_cache CPT is also fine since its public attribute is set to false.
		return $response;
	}

	/**
	 * Acknowledge via REST that the friends plugin had called.
	 *
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array The array to be returned via the REST API.
	 */
	public function rest_hello( WP_REST_Request $request ) {
		if ( 'GET' === $request->get_method() ) {
			return array(
				'version'  => Friends::VERSION,
				'site_url' => site_url(),
				'rest_url' => get_rest_url() . Friends_REST::PREFIX,
			);
		}
		$signature = get_option( 'friends_request_token_' . sha1( $request->get_param( 'rest_url' ) ) );

		if ( ! $signature ) {
			return new WP_Error(
				'friends_unknown_request',
				'The other party is unknown.',
				array(
					'status' => 403,
				)
			);
		}

		return array(
			'version'  => Friends::VERSION,
			'response' => sha1( $signature . $request->get_param( 'challenge' ) ),
		);
	}

	/**
	 * Receive a notification via REST that a friend request was accepted
	 *
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array The array to be returned via the REST API.
	 */
	public function rest_accept_friend_request( WP_REST_Request $request ) {
		$accept_token   = $request->get_param( 'token' );
		$out_token      = $request->get_param( 'friend' );
		$proof          = $request->get_param( 'proof' );
		$friend_user_id = get_option( 'friends_accept_token_' . $accept_token );
		$friend_user    = false;
		if ( $friend_user_id ) {
			$friend_user = new WP_User( $friend_user_id );
		}

		if ( ! $accept_token || ! $out_token || ! $proof || ! $friend_user || is_wp_error( $friend_user ) || ! $friend_user->user_url ) {
			return new WP_Error(
				'friends_invalid_parameters',
				'Not all necessary parameters were provided.',
				array(
					'status' => 403,
				)
			);
		}

		$signature = get_user_option( 'friends_accept_signature', $friend_user_id );
		if ( sha1( $accept_token . $signature ) !== $proof ) {
			return new WP_Error(
				'friends_invalid_proof',
				'An invalid proof was provided.',
				array(
					'status' => 403,
				)
			);
		}

		$friend_user_login = $this->friends->access_control->get_user_login_for_site_url( $friend_user->user_url );
		if ( $friend_user_login !== $friend_user->user_login ) {
			return new WP_Error(
				'friends_offer_no_longer_valid',
				'The friendship offer is no longer valid.',
				array(
					'status' => 403,
				)
			);
		}

		delete_user_option( $friend_user->ID, 'friends_accept_signature' );
		delete_option( 'friends_accept_token_' . $accept_token );
		$this->friends->access_control->make_friend( $friend_user, $out_token );
		$in_token = $this->friends->access_control->update_in_token( $friend_user->ID );

		$this->friends->access_control->update_user_icon_url( $friend_user->ID, $request->get_param( 'icon_url' ) );
		if ( $request->get_param( 'name' ) ) {
			wp_update_user(
				array(
					'ID'           => $friend_user->ID,
					'nickname'     => $request->get_param( 'name' ),
					'first_name'   => $request->get_param( 'name' ),
					'display_name' => $request->get_param( 'name' ),
				)
			);
		}

		do_action( 'notify_accepted_friend_request', $friend_user );
		return array(
			'friend' => $in_token,
		);
	}

	/**
	 * Limits the requests from an ip address
	 *
	 * @param  string $name             A unique identifier of the page loader.
	 * @param  int    $allowed_requests The number of allowed requests in time-frame.
	 * @param  int    $minutes          The time-frame in minutes.
	 * @return bool Whether the user should be limited or not.
	 */
	public function limit_requests_in_minutes( $name, $allowed_requests, $minutes ) {
		$requests = 0;
		$now      = time();

		for ( $time = $now - $minutes * 60; $time <= $now; $time += 60 ) {
			$key = $name . date( 'dHi', $time );

			$requests_in_current_minute = wp_cache_get( $key, 'friends' );

			if ( false === $requests_in_current_minute ) {
				wp_cache_set( $key, 1, 'friends', $minutes * 60 + 1 );
			} else {
				wp_cache_incr( $key, 1, 'friends' );
			}
		}

		if ( $requests > $allowed_requests ) {
			return false;
		}
		return true;
	}

	/**
	 * Receive a friend request via REST
	 *
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array The array to be returned via the REST API.
	 */
	public function rest_friend_request( WP_REST_Request $request ) {
		$pre_shared_secret = $request->get_param( 'pre_shared_secret' );
		if ( get_option( 'friends_pre_shared_secret', 'friends' ) !== $pre_shared_secret ) {
			return new WP_Error(
				'friends_invalid_pre_shared_secret',
				'An invalid pre shared secret was provided.',
				array(
					'status' => 403,
				)
			);
		}

		if ( ! $this->limit_requests_in_minutes( 'friend-request' . $_SERVER['REMOTE_ADDR'], 5, 5 ) ) {
			return new WP_Error(
				'too_many_request',
				'Too many requests were sent.',
				array(
					'status' => 529,
				)
			);

		}

		$site_url = trim( $request->get_param( 'site_url' ) );
		if ( ! is_string( $site_url ) || ! wp_http_validate_url( $site_url ) || 0 === strcasecmp( site_url(), $site_url ) ) {
			return new WP_Error(
				'friends_invalid_site',
				'An invalid site was provided.',
				array(
					'status' => 403,
				)
			);
		}

		$key     = $request->get_param( 'key' );
		$message = $request->get_param( 'message' );

		$challenge = sha1( wp_generate_password( 256 ) );
		update_option(
			'friends_request_challenge_' . $challenge,
			array(
				'key'      => $key,
				'icon_url' => $icon_url,
				'url'      => $site_url,
				'message'  => $message,
			)
		);

		if ( ! get_option( 'friends_ignore_incoming_friend_requests' ) ) {
			$friend_user = $this->friends->access_control->create_user_for_challenge( $challenge );
		}

		return array(
			'challenge' => $challenge,
		);
	}

	/**
	 * Notify friends of a deleted post
	 *
	 * @param  int $post_id The post id of the post that is deleted.
	 */
	public function notify_remote_friend_post_deleted( $post_id ) {
		$post = WP_Post::get_instance( $post_id );
		if ( 'post' !== $post->post_type ) {
			return;
		}

		$friends = Friends::all_friends();
		$friends = $friends->get_results();

		foreach ( $friends as $friend_user ) {
			$friend_rest_url = $this->friends->access_control->get_rest_url( $friend_user );

			$response = wp_safe_remote_post(
				$friend_rest_url . '/post-deleted',
				array(
					'body'        => array(
						'post_id' => $post_id,
						'friend'  => get_user_option( 'friends_out_token', $friend_user->ID ),
					),
					'timeout'     => 20,
					'redirection' => 5,
				)
			);
		}
	}

	/**
	 * Receive a REST message that a post was deleted.
	 *
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array The array to be returned via the REST API.
	 */
	public function rest_friend_post_deleted( $request ) {
		$token   = $request->get_param( 'friend' );
		$user_id = $this->friends->access_control->verify_token( $token );
		if ( ! $user_id ) {
			return new WP_Error(
				'friends_request_failed',
				'Could not respond to the request.',
				array(
					'status' => 403,
				)
			);
		}
		$friend_user     = new WP_User( $user_id );
		$remote_post_id  = $request->get_param( 'post_id' );
		$remote_post_ids = $this->friends->feed->get_remote_post_ids( $friend_user );

		if ( ! isset( $remote_post_ids[ $remote_post_id ] ) ) {
			return array(
				'deleted' => false,
			);
		}

		$post_id = $remote_post_ids[ $remote_post_id ];
		$post    = WP_Post::get_instance( $post_id );
		if ( Friends::CPT === $post->post_type ) {
			wp_delete_post( $post_id );
		}

		return array(
			'deleted' => true,
		);
	}


	/**
	 * Notify friends of a friend reaction on my local post
	 *
	 * @param  int $post_id The post id of the post that somebody reacted.
	 * @param  int $exclude_friend_user_id Don't notify this user_id.
	 */
	public function notify_remote_friend_post_reaction( $post_id, $exclude_friend_user_id = null ) {
		$post = WP_Post::get_instance( $post_id );
		if ( 'post' !== $post->post_type ) {
			return;
		}

		$friends = new WP_User_Query(
			array(
				'role'    => 'friend',
				'exclude' => array( $exclude_friend_user_id ),
			)
		);
		foreach ( $friends->get_results() as $friend_user ) {
			$reactions       = $this->friends->reactions->get_reactions( $post->ID, $friend_user->ID );
			$friend_rest_url = $this->friends->access_control->get_rest_url( $friend_user );

			$response = wp_safe_remote_post(
				$friend_rest_url . '/update-post-reactions',
				array(
					'body'        => array(
						'post_id'   => $post_id,
						'reactions' => $reactions,
						'friend'    => get_user_option( 'friends_out_token', $friend_user->ID ),
					),
					'timeout'     => 20,
					'redirection' => 5,
				)
			);
		}
	}

	/**
	 * Update the remote friend reactions for this post.
	 *
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array The array to be returned via the REST API.
	 */
	public function rest_update_friend_post_reactions( $request ) {
		$token   = $request->get_param( 'friend' );
		$user_id = $this->friends->access_control->verify_token( $token );
		if ( ! $user_id ) {
			return new WP_Error(
				'friends_request_failed',
				'Could not respond to the request.',
				array(
					'status' => 403,
				)
			);
		}
		$friend_user     = new WP_User( $user_id );
		$remote_post_id  = $request->get_param( 'post_id' );
		$remote_post_ids = $this->friends->feed->get_remote_post_ids( $friend_user );

		if ( ! isset( $remote_post_ids[ $remote_post_id ] ) ) {
			return array(
				'updated' => false,
			);
		}

		$post_id = $remote_post_ids[ $remote_post_id ];
		$post    = WP_Post::get_instance( $post_id );
		$this->friends->reactions->update_remote_reactions( $post_id, $request->get_param( 'reactions' ) );

		return array(
			'updated' => true,
		);
	}

	/**
	 * Notify the friend of our reaction on their post
	 *
	 * @param  int $post_id The post id of the post that was reacted to.
	 */
	public function notify_friend_of_my_reaction( $post_id ) {
		$post = WP_Post::get_instance( $post_id );
		if ( Friends::CPT !== $post->post_type ) {
			return;
		}

		$friend_user = new WP_User( $post->post_author );

		$reactions      = $this->friends->reactions->get_my_reactions( $post->ID );
		$remote_post_id = get_post_meta( $post->ID, 'remote_post_id', true );

		$friend_rest_url = $this->friends->access_control->get_rest_url( $friend_user );

		$response = wp_safe_remote_post(
			$friend_rest_url . '/my-reactions',
			array(
				'body'        => array(
					'post_id'   => $remote_post_id,
					'reactions' => $reactions,
					'friend'    => get_user_option( 'friends_out_token', $friend_user->ID ),
				),
				'timeout'     => 20,
				'redirection' => 5,
			)
		);
	}

	/**
	 * Update the reactions of a friend on my post.
	 *
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array The array to be returned via the REST API.
	 */
	public function rest_update_reactions_on_my_post( $request ) {
		$token   = $request->get_param( 'friend' );
		$user_id = $this->friends->access_control->verify_token( $token );
		if ( ! $user_id ) {
			return new WP_Error(
				'friends_request_failed',
				'Could not respond to the request.',
				array(
					'status' => 403,
				)
			);
		}
		$friend_user = new WP_User( $user_id );
		$post_id     = $request->get_param( 'post_id' );
		$post        = WP_Post::get_instance( $post_id );

		if ( ! $post || is_wp_error( $post ) ) {
			return array(
				'updated' => false,
			);
		}
		$reactions = $request->get_param( 'reactions' );
		if ( ! $reactions ) {
			$reactions = array();
		}
		$this->friends->reactions->update_friend_reactions( $post_id, $friend_user->ID, $reactions );

		do_action( 'friends_user_post_reaction', $post_id, $friend_user->ID );

		return array(
			'updated' => true,
		);
	}

	/**
	 * Receive a recommendation for a post
	 *
	 * @param  WP_REST_Request $request The incoming request.
	 * @return array The array to be returned via the REST API.
	 */
	public function rest_receive_recommendation( $request ) {
		$token   = $request->get_param( 'friend' );
		$user_id = $this->friends->access_control->verify_token( $token );
		if ( ! $user_id ) {
			return new WP_Error(
				'friends_request_failed',
				'Could not respond to the request.',
				array(
					'status' => 403,
				)
			);
		}

		$standard_response = array(
			'thank' => 'you',
		);
		$standard_response = false;

		$friend_user = new WP_User( $user_id );

		$permalink = $request->get_param( 'link' );
		$sha1_link = $request->get_param( 'sha1_link' );

		$is_public_recommendation = boolval( $permalink );

		if ( ! apply_filters( 'friends_accept_recommendation', true, $is_public_recommendation ? $permalink : $sha1_link, $friend_user ) ) {
			if ( $standard_response ) {
				return $standard_response;
			}

			return array(
				'no' => 'thanks',
			);
		}

		if ( ! $permalink ) {
			// TODO: check if we also have this friend post and highlight it.
			if ( $standard_response ) {
				return $standard_response;
			}

			return array(
				'ignored' => 'for now',
			);
		}

		$post_id = $this->friends->feed->url_to_postid( $permalink );
		if ( $post_id ) {
			if ( $standard_response ) {
				return $standard_response;
			}

			return array(
				'already' => 'knew',
			);
		}

		$post_data = array(
			'post_title'   => $request->get_param( 'title' ),
			'post_content' => $request->get_param( 'description' ),
			'post_status'  => 'publish',
			'post_author'  => $friend_user->ID,
			'guid'         => $permalink,
			'post_type'    => Friends::CPT,
			'tags_input'   => array( 'recommendation' ),
		);

		$post_id = wp_insert_post( $post_data );
		update_post_meta( $post_id, 'author', $request->get_param( 'author' ) );
		update_post_meta( $post_id, 'icon_url', $request->get_param( 'icon_url' ) );

		$message = $request->get_param( 'message' );
		if ( ! $message ) {
			$message = true;
		}
		update_post_meta( $post_id, 'recommendation', $message );

		if ( $standard_response ) {
			return $standard_response;
		}

		return array(
			'thank' => 'you',
		);
	}

	/**
	 * Notify the friend's site via REST about the accepted friend request.
	 *
	 * Accepting a friend request is simply setting the role to "friend".
	 *
	 * @param  int    $user_id   The user id.
	 * @param  string $new_role  The new role.
	 * @param  string $old_roles The old roles.
	 */
	public function notify_remote_friend_request_accepted( $user_id, $new_role, $old_roles ) {
		if ( 'friend' !== $new_role && 'restricted_friend' !== $new_role ) {
			return;
		}

		$request_token = get_user_option( 'friends_request_token', $user_id );
		if ( ! $request_token ) {
			// We were accepted, so no need to notify the other.
			return;
		}

		$friend_user = new WP_User( $user_id );

		$friend_rest_url      = $this->friends->access_control->get_rest_url( $friend_user );
		$friend_request_token = get_option( 'friends_request_token_' . sha1( $friend_rest_url ) );
		$in_token             = $this->friends->access_control->update_in_token( $friend_user->ID );

		$current_user = wp_get_current_user();
		$response     = wp_safe_remote_post(
			$friend_rest_url . '/friend-request-accepted',
			array(
				'body'        => array(
					'token'    => $request_token,
					'friend'   => $in_token,
					'proof'    => sha1( $request_token . $friend_request_token ),
					'name'     => $current_user->display_name,
					'icon_url' => get_avatar_url( $current_user->ID ),
				),
				'timeout'     => 20,
				'redirection' => 5,
			)
		);

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		delete_user_option( $friend_user->ID, 'friends_request_token' );
		$json = json_decode( wp_remote_retrieve_body( $response ) );
		if ( isset( $json->friend ) ) {
			$this->friends->access_control->make_friend( $friend_user, $json->friend );

			if ( isset( $json->user_icon_url ) ) {
				$this->friends->access_control->update_user_icon_url( $friend_user->ID, $json->user_icon_url );
			}

			if ( isset( $json->name ) ) {
				wp_update_user(
					array(
						'ID'           => $friend_user->ID,
						'nickname'     => $json->name,
						'first_name'   => $json->name,
						'display_name' => $json->name,
					)
				);
			}
		} else {
			$friend_user->set_role( 'pending_friend_request' );
			if ( isset( $json->friend_request_pending ) ) {
				update_option( 'friends_accept_token_' . $json->friend_request_pending, $user_id );
			}
		}
	}
}
