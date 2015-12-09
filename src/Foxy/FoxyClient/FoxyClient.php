<?php

namespace Foxy\FoxyClient;

/*

The FoxyClient wraps Guzzle with some useful helpers for working with link relationships.
config:
    use_sandbox (boolean): set to true to connect to the API sandbox for testing.
    access_token (string): pass in your properly scoped OAuth access_token to be used as a Authentication Bearer HTTP header.

Examples:

//Get Homepage
$fc = new FoxyClient($guzzle);
$result = $fc->get();
die("<pre>" . print_r($result, 1) . "</pre>");

//Get Authenticated Store
$fc = new FoxyClient($guzzle, array(
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
    // Internal Foxy Development Use Only
    const LOCAL_API_HOME = 'https://api.foxy.dev';
    const LINK_RELATIONSHIPS_BASE_URI = 'https://api.foxycart.com/rels/';
    const PRODUCTION_AUTHORIZATION_ENDPOINT = 'https://my.foxycart.com/authorize';
    const SANDBOX_AUTHORIZATION_ENDPOINT = 'https://my-sandbox.foxycart.com/authorize';
    // Internal Foxy Development Use Only
    const LOCAL_AUTHORIZATION_ENDPOINT = 'https://my.foxy.dev:44300/authorize';
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
    private $guzzle;
    private $last_response = '';
    private $registered_link_relations = array('self', 'first', 'prev', 'next', 'last');
    private $links = array();
    private $use_sandbox = false;
    // Internal Foxy Development Use Only
    private $use_local = false;
    private $obtaining_updated_access_token = false;
    private $include_auth_header = true;
    private $accept_content_type = '';

    public function __construct(\GuzzleHttp\Client $guzzle, array $config = array())
    {
        $this->guzzle = $guzzle;
        $this->updateFromConfig($config);
    }

    public function updateFromConfig($config)
    {
        if (array_key_exists('use_local', $config)) {
            $this->use_local = $config['use_local'];
        }
        if (array_key_exists('use_sandbox', $config)) {
            $this->use_sandbox = $config['use_sandbox'];
        }
        if (array_key_exists('access_token', $config)) {
            $this->access_token = $config['access_token'];
        }
        if (array_key_exists('access_token_expires', $config)) {
            $this->access_token_expires = $config['access_token_expires'];
        }
        if (array_key_exists('refresh_token', $config)) {
            $this->refresh_token = $config['refresh_token'];
        }
        if (array_key_exists('client_id', $config)) {
            $this->client_id = $config['client_id'];
        }
        if (array_key_exists('client_secret', $config)) {
            $this->client_secret = $config['client_secret'];
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
        if ($this->use_local) {
            return static::LOCAL_API_HOME;
        } elseif ($this->use_sandbox) {
            return static::SANDBOX_API_HOME;
        } else {
            return static::PRODUCTION_API_HOME;
        }
    }

    public function getAuthorizationEndpoint()
    {
        if ($this->use_local) {
            return static::LOCAL_AUTHORIZATION_ENDPOINT;
        } elseif ($this->use_sandbox) {
            return static::SANDBOX_AUTHORIZATION_ENDPOINT;
        } else {
            return static::PRODUCTION_AUTHORIZATION_ENDPOINT;
        }
    }

    public function get($uri = "", $post = null)
    {
        return $this->go('GET', $uri, $post);
    }

    public function post($uri, $post = null)
    {
        return $this->go('POST', $uri, $post);
    }

    public function patch($uri, $post = null)
    {
        return $this->go('PATCH', $uri, $post);
    }

    public function delete($uri, $post = null)
    {
        return $this->go('DELETE', $uri, $post);
    }

    public function options($uri, $post = null)
    {
        return $this->go('OPTIONS', $uri, $post);
    }

    public function head($uri, $post = null)
    {
        return $this->go('HEAD', $uri, $post);
    }

    private function go($method, $uri, $post)
    {
        if (!$this->obtaining_updated_access_token) {
            $this->refreshTokenAsNeeded();
        }

        //Nothing passed in, set uri to homepage
        if (!$uri) {
            $uri = $this->getApiHome();
        }

        //Setup Guzzle Details
        $guzzle_args = array(
            'headers' => $this->getHeaders(),
            'connect_timeout' => 30,
        );

        //Set Query or Body
        if ($method === "GET" && $post !== null) {
            $guzzle_args['query'] = $post;
        } elseif ($post !== null) {
            $guzzle_args['body'] = $post;
        }

        try {

            $api_request = $this->guzzle->createRequest($method, $uri, $guzzle_args);
            $this->last_response = $this->guzzle->send($api_request);
            $data = $this->last_response->json();
            $this->saveLinks($data);
            if ($this->hasExpiredAccessTokenError($data) && !$this->shouldRefreshToken()) {
                // we should have gotten a refresh token... looks like our access_token_expires was incorrect.
                $this->access_token_expires = 0;
                return $this->go($method, $uri, $post); // try again
            }
            return $data;

        //Catch Errors - http error
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return array("error_description" => $e->getMessage());

        //Catch Errors - not JSON
        } catch (\GuzzleHttp\Exception\ParseException $e) {
            return array("error_description" => $e->getMessage());
        }

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
        if ($this->access_token && $this->include_auth_header) {
            $headers['Authorization'] = "Bearer " . $this->access_token;
        }
        $headers['Accept'] = $this->getAcceptContentType();

        return $headers;
    }

    //Get Last Status Code
    public function getLastStatusCode()
    {
        return $this->last_response->getStatusCode();
    }
    //Get Last Response Header
    public function getLastResponseHeader($header)
    {
        return $this->last_response->getHeader($header);
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

    public function obtainingToken()
    {
        $this->obtaining_updated_access_token = true;
        $this->include_auth_header = false;
    }

    public function obtainingTokenDone()
    {
        $this->obtaining_updated_access_token = false;
        $this->include_auth_header = true;
    }

    public function refreshTokenAsNeeded()
    {
        if ($this->shouldRefreshToken()) {
            $refresh_token_data = array(
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refresh_token,
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret
            );
            $this->obtainingToken();
            $data = $this->post($this->getOAuthTokenEndpoint(),$refresh_token_data);
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
                'scope' => 'client_full_access',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret
        );
        $this->obtainingToken();
        $data = $this->post($this->getOAuthTokenEndpoint(),$client_credentials_request_data);
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
                'code' => $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret
        );
        $this->obtainingToken();
        $data = $this->post($this->getOAuthTokenEndpoint(),$authorize_code_request_data);
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
            $this->include_auth_header = false;
            $data = $this->get();
            if ($this->getLastStatusCode() == '200') {
                $this->include_auth_header = true;
                $this->oauth_token_endpoint = $this->getLink("token");
            }

        }
        return $this->oauth_token_endpoint;
    }

}
