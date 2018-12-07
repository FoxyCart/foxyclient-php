<?php

namespace Foxy\FoxyClient;

/*

The FoxyClient wraps Unirest with some useful helpers for working with link relationships.
config:
    use_sandbox (boolean): set to true to connect to the API sandbox for testing.
    access_token (string): pass in your properly scoped OAuth access_token to be used as a Authentication Bearer HTTP header.

Examples:

//Get Homepage
$fc = new FoxyClient();
$result = $fc->get();
die("<pre>" . print_r($result, 1) . "</pre>");

//Get Authenticated Store
$fc = new FoxyClient(array(
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'access_token' => $access_token, // optional
        'access_token_expires' => $access_token_expires // optional
    )
);
$fc->get();
$result = $fc->get($fc->getLink("fx:store"));
die("<pre>" . print_r($result, 1) . "</pre>");

Credits: David Hollander of https://foxytools.com/ got this project going and is awesome.
*/
class FoxyClient
{
    const PRODUCTION_API_HOME = 'https://api.foxycart.com';
    const SANDBOX_API_HOME = 'https://api-sandbox.foxycart.com';
    const LINK_RELATIONSHIPS_BASE_URI = 'https://api.foxycart.com/rels/';
    const PRODUCTION_AUTHORIZATION_ENDPOINT = 'https://my.foxycart.com/authorize';
    const SANDBOX_AUTHORIZATION_ENDPOINT = 'https://my-sandbox.foxycart.com/authorize';
    const DEFAULT_ACCEPT_CONTENT_TYPE = 'application/hal+json';

    /**
    * OAuth Access Token (can have various scopes such as client_full_access, user_full_access, store_full_access)
    */
    private $access_token = '';
    /**
    * The timestamp for when the access token expires
    */
    private $access_token_expires = 0;
    /**
    * OAuth Refresh token used to obtain a new Access Token
    */
    private $refresh_token = '';
    /**
    * OAuth endpoint for getting a new Access Token
    */
    private $oauth_token_endpoint = '';
    /**
    * Used by OAuth for getting a new Access Token
    */
    private $client_id = '';
    /**
    * Used by OAuth for getting a new Access Token
    */
    private $client_secret = '';

    private $last_response = '';
    private $registered_link_relations = array('self', 'first', 'prev', 'next', 'last');
    private $links = array();
    private $use_sandbox = false;
    private $obtaining_updated_access_token = false;
    private $include_auth_header = true;
    private $include_basic_auth_header = false;
    private $handle_exceptions = true;
    private $accept_content_type = '';
    private $api_home = '';
    private $authorization_endpoint = '';

    public function __construct(array $config = array())
    {
        $this->api_home = static::PRODUCTION_API_HOME;
        $this->authorization_endpoint = static::PRODUCTION_AUTHORIZATION_ENDPOINT;
        $this->updateFromConfig($config);
    }

    public function updateFromConfig($config)
    {
        $valid_config_options = array(
            'api_home',
            'authorization_endpoint',
            'use_sandbox',
            'access_token',
            'access_token_expires',
            'refresh_token',
            'client_id',
            'client_secret',
            'handle_exceptions'
            );
        foreach($valid_config_options as $valid_config_option) {
            if (array_key_exists($valid_config_option, $config)) {
                $this->{$valid_config_option} = $config[$valid_config_option];
            }
        }
        if ($this->use_sandbox && (!array_key_exists('api_home', $config) || !array_key_exists('authorization_endpoint', $config))) {
            $this->api_home = static::SANDBOX_API_HOME;
            $this->authorization_endpoint = static::SANDBOX_AUTHORIZATION_ENDPOINT;
        }
    }

    public function clearCredentials()
    {
        $config = array(
            'access_token' => '',
            'access_token_expires' => '',
            'refresh_token' => '',
            'client_id' => '',
            'client_secret' => ''
        );
        $this->updateFromConfig($config);
    }

    public function setAccessToken($access_token)
    {
        $this->access_token = $access_token;
    }
    public function getAccessToken()
    {
        return $this->access_token;
    }

    public function setAccessTokenExpires($access_token_expires)
    {
        $this->access_token_expires = $access_token_expires;
    }
    public function getAccessTokenExpires()
    {
        return $this->access_token_expires;
    }

    public function setRefreshToken($refresh_token)
    {
        $this->refresh_token = $refresh_token;
    }
    public function getRefreshToken()
    {
        return $this->refresh_token;
    }

    public function setClientId($client_id)
    {
        $this->client_id = $client_id;
    }
    public function getClientId()
    {
        return $this->client_id;
    }

    public function setClientSecret($client_secret)
    {
        $this->client_secret = $client_secret;
    }
    public function getClientSecret()
    {
        return $this->client_secret;
    }

    public function setUseSandbox($use_sandbox)
    {
        $this->use_sandbox = $use_sandbox;
    }
    public function getUseSandbox()
    {
        return $this->use_sandbox;
    }

    public function getApiHome()
    {
        return $this->api_home;
    }

    public function getAuthorizationEndpoint()
    {
        return $this->authorization_endpoint;
    }

    public function get($uri = "", $post = null)
    {
        return $this->go(\Unirest\Method::GET, $uri, $post);
    }

    public function put($uri, $post = null)
    {
        return $this->go(\Unirest\Method::PUT, $uri, $post);
    }

    public function post($uri, $post = null)
    {
        return $this->go(\Unirest\Method::POST, $uri, $post);
    }

    public function patch($uri, $post = null)
    {
        return $this->go(\Unirest\Method::PATCH, $uri, $post);
    }

    public function delete($uri, $post = null)
    {
        return $this->go(\Unirest\Method::DELETE, $uri, $post);
    }

    public function options($uri, $post = null)
    {
        return $this->go(\Unirest\Method::OPTIONS, $uri, $post);
    }

    public function head($uri, $post = null)
    {
        return $this->go(\Unirest\Method::HEAD, $uri, $post);
    }

    private function go($method, $uri, $post, $is_retry = false)
    {
        if (!$this->obtaining_updated_access_token) {
            $this->refreshTokenAsNeeded();
        }

        //Nothing passed in, set uri to homepage
        if (!$uri) {
            $uri = $this->getApiHome();
        }

        if (!$this->handle_exceptions) {
            return $this->processRequest($method, $uri, $post, $is_retry);
        } else {
            try {
                return $this->processRequest($method, $uri, $post, $is_retry);
            } catch (\Unirest\Exception $e) {
                return array("error_description" => $e->getMessage());
            }
        }
    }

    private function processRequest($method, $uri, $post, $is_retry = false)
    {
        $headers = $this->getHeaders();
        \Unirest\Request::timeout(30);

        if ($method !== \Unirest\Method::GET && $post !== null && is_array($post)) {
            if (array_key_exists('file', $post)) {
                $file = \Unirest\Request\Body::File($post['file']['tmp_name'], $post['file']['type'], $post['file']['name']);
                $post['file'] = $file;
                $headers['Content-Type'] = 'multipart/form-data';

                // special case for PATCHing a Downloadable File
                if ($method === \Unirest\Method::PATCH) {
                    $method = \Unirest\Method::POST;
                    $headers['X-HTTP-Method-Override'] = 'PATCH';
                }
            } else if ($this->obtaining_updated_access_token) {
                $post = \Unirest\Request\Body::form($post);
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            } else {
                $post = \Unirest\Request\Body::json($post);
                $headers['Content-Type'] = 'application/json';
            }
        }

        //$post = \Unirest\Request\Body::json($post);
        $this->last_response = \Unirest\Request::send($method, $uri, $post, $headers);
        $data = json_decode($this->last_response->raw_body, true);
        $this->saveLinks($data);
        if ($this->hasExpiredAccessTokenError($data) && !$this->shouldRefreshToken()) {
            if (!$is_retry) {
                // we should have gotten a refresh token... looks like our access_token_expires was incorrect
                // so we'll clear it out to force a refresh
                $this->access_token_expires = 0;
                return $this->go($method, $uri, $post, true); // try one more time
            } else {
               return array("error_description" => 'An error occurred attempting to update your access token. Please verify your refresh token and OAuth client credentials.');
            }
        }
        return $data;
    }

    //Clear any saved links
    public function clearLinks()
    {
        $this->links = array();
    }

    //Save Links to the Object For Easy Retrieval Later
    public function saveLinks($data)
    {
        if (isset($data['_links'])) {
            foreach ($data['_links'] as $rel => $link) {
                if (!in_array($rel, $this->registered_link_relations)
                    && $rel != 'curies'
                    && $rel.'/' != static::LINK_RELATIONSHIPS_BASE_URI) {
                    $this->links[$rel] = $link['href'];
                }
            }
        } else if (isset($data['links'])) {
            foreach ($data['links'] as $link) {
                foreach ($link['rel'] as $rel) {
                    if (!in_array($rel, $this->registered_link_relations)
                        && $rel.'/' != static::LINK_RELATIONSHIPS_BASE_URI) {
                        $this->links[$rel] = $link['href'];
                    }
                }
            }
        }
    }

    // Get a link out of the internally stored links
    // The link relationship base uri can be excluded ("fx:" for HAL, full URI for Siren)
    public function getLink($link_rel_string)
    {
        $search_string = $link_rel_string;
        if (!in_array($link_rel_string, $this->registered_link_relations)
            && strpos($link_rel_string, "fx:") === FALSE
            && strpos($link_rel_string, static::LINK_RELATIONSHIPS_BASE_URI) === FALSE) {
                if ($this->getAcceptContentType() == static::DEFAULT_ACCEPT_CONTENT_TYPE) {
                    $search_string = 'fx:' . $search_string;
                } else {
                    $search_string = static::LINK_RELATIONSHIPS_BASE_URI . $search_string;
                }
        }
        if (isset($this->links[$search_string])) {
            return $this->links[$search_string];
        } else {
            return "";
        }
    }

    // Get stored links (excluding link rel base uri)
    public function getLinks()
    {
        $links = array();
        foreach($this->links as $rel => $href) {
            $simple_rel = $rel;
            $base_uris = array("fx:", static::LINK_RELATIONSHIPS_BASE_URI);
            foreach($base_uris as $base_uri) {
                $pos = strpos($simple_rel, $base_uri);
                if ($pos !== FALSE && ($simple_rel.'/' != $base_uri)) {
                    $simple_rel = substr($simple_rel, strlen($base_uri));
                }
            }
            $links[$simple_rel] = $href;
        }
        return $links;
    }

    //Return any errors that exist in the response data.
    public function getErrors($data)
    {
        $errors = array();
        if ($this->getLastStatusCode() >= 400) {
            if (isset($data['error_description'])) {
                $errors[] = $data['error_description'];
            } elseif (isset($data['_embedded']['fx:errors'])) {
                foreach ($data['_embedded']['fx:errors'] as $error) {
                    $errors[] = $error['message'];
                }
            } else {
                $errors[] = 'No data returned.';
            }
        }
        return $errors;
    }

    public function hasExpiredAccessTokenError($data)
    {
        $errors = $this->getErrors($data);
        if (in_array('The access token provided has expired', $errors)) {
            return true;
        }
        return false;
    }

    // Set a custom supported content type (application/hal+json, application/vnd.siren+json, etc)
    public function setAcceptContentType($accept_content_type)
    {
        $this->accept_content_type = $accept_content_type;
    }

    public function getAcceptContentType()
    {
        if ($this->accept_content_type == '') {
            return static::DEFAULT_ACCEPT_CONTENT_TYPE;
        }

        return $this->accept_content_type;
    }

    //Get headers for this call
    public function getHeaders()
    {
        $headers = array(
            'FOXY-API-VERSION' => 1
        );
        if ($this->include_basic_auth_header) {
            $headers['Authorization'] = "Basic " . base64_encode($this->client_id . ":" . $this->client_secret);
        } else if ($this->access_token && $this->include_auth_header) {
            $headers['Authorization'] = "Bearer " . $this->access_token;
        }
        $headers['Accept'] = $this->getAcceptContentType();

        return $headers;
    }

    //Get Last Status Code
    public function getLastStatusCode()
    {
        if ($this->last_response == '') {
            return '';
        }
        return $this->last_response->code;
    }
    //Get Last Response Header
    public function getLastResponseHeader($header)
    {
        if ($this->last_response == '' || in_array($header, $this->last_response->headers)) {
            return '';
        }
        return $this->last_response->headers[$header];
    }

    public function hasOAuthCredentialsForTokenRefresh()
    {
        return ($this->client_id && $this->client_secret && $this->refresh_token);
    }

    public function accessTokenNeedsRefreshing()
    {
        return (!$this->access_token_expires || (($this->access_token_expires - 30) < time()));
    }

    public function shouldRefreshToken()
    {
        return ($this->hasOAuthCredentialsForTokenRefresh() && $this->accessTokenNeedsRefreshing());
    }

    public function obtainingToken($endpoint = false)
    {
        $this->obtaining_updated_access_token = true;
        $this->include_basic_auth_header = ($endpoint) ? false : true;
        $this->include_auth_header = false;
    }

    public function obtainingTokenDone()
    {
        $this->obtaining_updated_access_token = false;
        $this->include_basic_auth_header = false;
        $this->include_auth_header = true;
    }

    public function refreshTokenAsNeeded()
    {
        if ($this->shouldRefreshToken()) {
            $refresh_token_data = array(
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refresh_token
            );
            $token_endpoint = $this->getOAuthTokenEndpoint();
            $this->obtainingToken();
            $data = $this->post($token_endpoint,$refresh_token_data);
            $this->obtainingTokenDone();
            if ($this->getLastStatusCode() == '200') {
                $this->access_token_expires = time() + $data['expires_in'];
                $this->access_token = $data['access_token'];
            }
        }
    }

    public function getAccessTokenFromClientCredentials()
    {
        $client_credentials_request_data = array(
                'grant_type' => 'client_credentials',
                'scope' => 'client_full_access'
        );
        $token_endpoint = $this->getOAuthTokenEndpoint();
        $this->obtainingToken();
        $data = $this->post($token_endpoint,$client_credentials_request_data);
        $this->obtainingTokenDone();
        if ($this->getLastStatusCode() == '200') {
            $this->access_token_expires = time() + $data['expires_in'];
            $this->access_token = $data['access_token'];
        }
        return $data;
    }

    public function getAccessTokenFromAuthorizationCode($code)
    {
        $authorize_code_request_data = array(
                'grant_type' => 'authorization_code',
                'code' => $code
        );
        $token_endpoint = $this->getOAuthTokenEndpoint();
        $this->obtainingToken();
        $data = $this->post($token_endpoint,$authorize_code_request_data);
        $this->obtainingTokenDone();
        if ($this->getLastStatusCode() == '200') {
            $this->access_token_expires = time() + $data['expires_in'];
            $this->access_token = $data['access_token'];
            $this->refresh_token = $data['refresh_token'];
        }
        return $data;
    }

    public function getOAuthTokenEndpoint()
    {
        if ($this->oauth_token_endpoint == '') {
            $this->obtainingToken(true);
            $data = $this->get();
            if ($this->getLastStatusCode() == '200') {
                $this->oauth_token_endpoint = $this->getLink("token");
            } else {
                trigger_error('ERROR IN getOAuthTokenEndpoint: ' . $this->getLastStatusCode());
                //trigger_error(serialize($this->last_response->json()));
                //die or hard code the endpoint?
                $this->oauth_token_endpoint = $this->api_home . '/token';
            }
            $this->obtainingTokenDone();
        }
        return $this->oauth_token_endpoint;
    }

}
