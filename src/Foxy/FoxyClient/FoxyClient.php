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
$fc = new FoxyClient($guzzle, array($access_token));
$fc->get();
$result = $fc->get($fc->getLink("fx:store"));
die("<pre>" . print_r($result, 1) . "</pre>");

Credits: David Hollander of https://foxytools.com/ got this project going and is awesome.
*/
class FoxyClient
{
    const PRODUCTION_API_HOME = 'https://api.foxycart.com';
    const SANDBOX_API_HOME = 'https://api-sandbox.foxycart.com';

    /**
    * OAuth Access Token (can have various scopes such as client_full_access, user_full_access, store_full_access)
    */
    private $access_token = '';
    /**
    * The timestamp for when the access token expires
    */
    private $access_token_expires = '';
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
    private $last_status_code = '';
    private $registered_link_relations = array('self', 'first', 'prev', 'next', 'last');
    private $links = array();
    private $use_sandbox = false;
    private $obtaining_updated_access_token = false;
    private $include_auth_header = true;

    public function __construct(\GuzzleHttp\Client $guzzle, array $config = array())
    {
        $this->guzzle = $guzzle;
        $this->updateFromConfig($config);
    }

    public function updateFromConfig($config)
    {
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
        return $this->use_sandbox ? static::SANDBOX_API_HOME : static::PRODUCTION_API_HOME;
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

    private function go($method, $uri, $post)
    {
        if ($this->access_token && !$this->obtaining_updated_access_token) {
            $this->refreshTokenAsNeeded();
        }

        if (!is_array($post)) {
            $post = null;
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
            $api_response = $this->guzzle->send($api_request);
            $this->last_status_code = $api_response->getStatusCode();
            $data = $api_response->json();
            $this->saveLinks($data);
            return $data;

        //Catch Errors - http error
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->last_status_code = 500;
            return array("status" => "error", "message" => $e->getMessage());

        //Catch Errors - not JSON
        } catch (\GuzzleHttp\Exception\ParseException $e) {
            return array("status" => "error", "message" => $e->getMessage());
        }

    }


    //Save Links to the Object For Easy Retrieval Later
    public function saveLinks($data)
    {
        if (!isset($data['_links'])) {
            return;
        }
        foreach ($data['_links'] as $key => $val) {
            $this->links[$key] = $val;
        }
    }


    //Get a link out of the internally stored links
    public function getLink($str)
    {
        $search_string = $str;
        if (!in_array($str, $this->registered_link_relations) && strpos($str, "fx:") === FALSE) {
            $search_string = 'fx:' . $search_string;
        }
        if (isset($this->links[$search_string])) {
            return $this->links[$search_string]['href'];
        } else {
            return "";
        }
    }


    //See if there were any errors
    public function checkForErrors($data)
    {
        if ($this->last_status_code >= 400) {
            if (isset($data['error_description'])) {
                return array($data['error_description']);
            } elseif (isset($data['_embedded']['fx:errors'])) {
                $errors = array();
                foreach ($data['_embedded']['fx:errors'] as $error) {
                    $errors[] = $error['message'];
                }
                return $errors;
            } else {
                return array("No data returned.");
            }
        }
        return false;
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
        return $headers;
    }

    //Get Last Status Code
    public function getLastStatusCode()
    {
        return $this->last_status_code;
    }

    public function refreshTokenAsNeeded()
    {
        if ($this->client_id && $this->client_secret && $this->refresh_token) {
            if (!isset($this->access_token_expires) || ($this->access_token_expires - 30) < time()) {
                $refresh_token_data = array(
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $this->refresh_token,
                        'client_id' => $this->client_id,
                        'client_secret' => $this->client_secret
                );
                $this->obtaining_updated_access_token = true;
                $this->include_auth_header = false;
                $data = $this->post($this->getOAuthTokenEndpoint(),$refresh_token_data);
                $this->obtaining_updated_access_token = false;
                $this->include_auth_header = true;
                if ($this->getLastStatusCode() == '200') {
                    $this->access_token_expires = time() + $data['expires_in']; 
                    $this->access_token = $data['access_token'];
                }
            }
        }
    }

    public function getOAuthTokenEndpoint()
    {
        if ($this->oauth_token_endpoint == '') {
            $this->include_auth_header = false;
            $resp = $this->get();
            $this->include_auth_header = true;
            $this->oauth_token_endpoint = $this->getLink("fx:token");
        }
        return $this->oauth_token_endpoint;
    }


}
