<?php
namespace ACE\AdaptiveCustomerEngagement\AI;
defined( 'ABSPATH' ) || exit;
/** Uses the shared connection's Responses transport; never supplies tokens to Chat Completions. */
final class ChatGPTClient implements ChatCompletionClient {
    public function list_models( string $api_key ) {
        $shared = function_exists( 'ace_ai_connection_service' ) ? ace_ai_connection_service() : null;
        if ( ! $shared ) { return new \WP_Error( 'ace_ai_not_connected', 'The shared AI service is unavailable.' ); }
        $models = $shared->subscription_models();
        return is_wp_error( $models ) ? $models : array( 'active' => true, 'models' => $models, 'preferred_model' => '' );
    }
    public function create_chat_completion( array $messages, array $options = array() ) {
        $shared = function_exists( 'ace_ai_connection_service' ) ? ace_ai_connection_service() : null;
        return $shared ? $shared->subscription_text( $messages ) : new \WP_Error( 'ace_ai_not_connected', 'The shared AI service is unavailable.' );
    }
}
