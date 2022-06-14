<?php

namespace Foxy\FoxyClient;

/*

config:
    use_sandbox (boolean): set to true to connect to the API sandbox for testing.
    access_token (string): pass in your properly scoped OAuth access_token to be used as a Authentication Bearer HTTP header.

Examples:

$psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
$psr18Client = new \Buzz\Client\Curl($psr17Factory);

//Get Homepage
$fc = new FoxyClient($psr17Factory, $psr18Client);
$result = $fc->get();
die("<pre>" . print_r($result, 1) . "</pre>");

//Get Authenticated Store
$fc = new FoxyClient($psr17Factory, $psr18Client, [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'access_token' => $access_token, // optional
        'access_token_expires' => $access_token_expires // optional
    ]
);
$fc->get();
$result = $fc->get($fc->getLink("fx:store"));
die("<pre>" . print_r($result, 1) . "</pre>");

Credits: David Hollander of https://foxytools.com/ got this project going and is awesome.
*/

use Exception;
use Foxy\FoxyClient\Exceptions\JsonException;
use Foxy\FoxyClient\Exceptions\ResponseException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class FoxyClient
{
    const PRODUCTION_API_HOME = 'https://api.foxycart.com';
    const SANDBOX_API_HOME = 'https://api-sandbox.foxycart.com';
    const LINK_RELATIONSHIPS_BASE_URI = 'https://api.foxycart.com/rels/';
    const PRODUCTION_AUTHORIZATION_ENDPOINT = 'https://my.foxycart.com/authorize';
    const SANDBOX_AUTHORIZATION_ENDPOINT = 'https://my-sandbox.foxycart.com/authorize';
    const DEFAULT_ACCEPT_CONTENT_TYPE = 'application/hal+json';
    private static array $valid_config_options = [
        'api_home',
        'authorization_endpoint',
        'use_sandbox',
        'access_token',
        'access_token_expires',
        'refresh_token',
        'client_id',
        'client_secret',
        'handle_exceptions'
    ];
    /**
     * OAuth Access Token (can have various scopes such as client_full_access, user_full_access, store_full_access)
     */
    private string $access_token = '';
    /**
     * The timestamp for when the access token expires
     */
    private int $access_token_expires = 0;
    /**
     * OAuth Refresh token used to obtain new Access Token
     */
    private string $refresh_token = '';
    /**
     * OAuth endpoint for getting new Access Token
     */
    private ?string $oauth_token_endpoint = null;
    /**
     * Used by OAuth for getting new Access Token
     */
    private string $client_id = '';
    /**
     * Used by OAuth for getting new Access Token
     */
    private string $client_secret = '';
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private ?ResponseInterface $last_response = null;
    private array $registered_link_relations = ['self', 'first', 'prev', 'next', 'last'];
    private array $links = [];
    private bool $use_sandbox = false;
    private bool $obtaining_updated_access_token = false;
    private bool $include_auth_header = true;
    private bool $handle_exceptions = true;
    private string $accept_content_type = '';
    private string $api_home = '';
    private string $authorization_endpoint = '';

    public function __construct(
        RequestFactoryInterface $requestFactory,
        ClientInterface $client,
        array $config = []
    ) {
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->api_home = static::PRODUCTION_API_HOME;
        $this->authorization_endpoint = static::PRODUCTION_AUTHORIZATION_ENDPOINT;
        $this->updateFromConfig($config);
    }

    public function updateFromConfig(array $config): void
    {
        foreach (self::$valid_config_options as $valid_config_option) {
            if (array_key_exists($valid_config_option, $config)) {
                $this->{$valid_config_option} = $config[$valid_config_option];
            }
        }
        if ($this->use_sandbox && (!array_key_exists('api_home', $config) || !array_key_exists(
                    'authorization_endpoint',
                    $config
                ))) {
            $this->api_home = static::SANDBOX_API_HOME;
            $this->authorization_endpoint = static::SANDBOX_AUTHORIZATION_ENDPOINT;
        }
    }

    public function clearCredentials(): void
    {
        $this->updateFromConfig([
            'access_token' => '',
            'access_token_expires' => '',
            'refresh_token' => '',
            'client_id' => '',
            'client_secret' => ''
        ]);
    }

    public function getAccessToken(): string
    {
        return $this->access_token;
    }

    public function setAccessToken(string $access_token): void
    {
        $this->access_token = $access_token;
    }

    public function getAccessTokenExpires(): int
    {
        return $this->access_token_expires;
    }

    public function setAccessTokenExpires(int $access_token_expires): void
    {
        $this->access_token_expires = $access_token_expires;
    }

    public function getRefreshToken(): string
    {
        return $this->refresh_token;
    }

    public function setRefreshToken(string $refresh_token): void
    {
        $this->refresh_token = $refresh_token;
    }

    public function getClientId(): string
    {
        return $this->client_id;
    }

    public function setClientId(string $client_id): void
    {
        $this->client_id = $client_id;
    }

    public function getClientSecret(): string
    {
        return $this->client_secret;
    }

    public function setClientSecret(string $client_secret): void
    {
        $this->client_secret = $client_secret;
    }

    public function getUseSandbox(): bool
    {
        return $this->use_sandbox;
    }

    public function setUseSandbox(bool $use_sandbox): void
    {
        $this->use_sandbox = $use_sandbox;
    }

    public function getAuthorizationEndpoint(): string
    {
        return $this->authorization_endpoint;
    }

    public function get(string $uri = "", array $post = null): array
    {
        return $this->go('GET', $uri, $post);
    }

    private function go(string $method, string $uri, ?array $post, bool $is_retry = false): array
    {
        if (!$this->obtaining_updated_access_token) {
            $this->refreshTokenAsNeeded();
        }

        //Nothing passed in, set uri to homepage
        if (!$uri) {
            $uri = $this->getApiHome();
        }

        //Setup Guzzle Details
        $guzzle_args = [
            'headers' => $this->getHeaders(),
        ];

        //Set Query or Body
        if ($method === "GET" && $post !== null) {
            $guzzle_args['query'] = $post;
        } elseif ($post !== null) {
            $guzzle_args['form_params'] = $post;
        }

        if (!$this->handle_exceptions) {
            return $this->processRequest($method, $uri, $post, $guzzle_args, $is_retry);
        }

        try {
            return $this->processRequest($method, $uri, $post, $guzzle_args, $is_retry);
        } catch (JsonException $e) {
            return [
                "error_description" => $e->getMessage(),
                "error_code" => 400,
                "error_contents" => $e->getResponse()->getBody()->getContents(),
            ];
        } catch (ResponseException $e) {
            $response = $e->getResponse();
            $responseContent = $response->getBody()->getContents();
            $parsedResponseContent = json_decode($responseContent, true);

            if (!empty($parsedResponseContent)) {
                if ($this->hasExpiredAccessTokenError($parsedResponseContent) && !$this->shouldRefreshToken()) {
                    if (!$is_retry) {
                        // we should have gotten a refresh token... looks like our access_token_expires was incorrect
                        // so we'll clear it out to force a refresh
                        $this->access_token_expires = 0;
                        return $this->go($method, $uri, $post, true); // try one more time
                    }

                    return ["error_description" => 'An error occurred attempting to update your access token. Please verify your refresh token and OAuth client credentials.'];
                }
            }

            return [
                "error_description" => $e->getMessage(),
                "error_code" => $response->getStatusCode(),
                "error_contents" => $responseContent,
                "error_contents_parsed" => $parsedResponseContent,
            ];
        } catch (ClientExceptionInterface $e) {
            return ["error_description" => $e->getMessage()];
        } catch (Exception  $e) {
            return ["error_description" => $e->getMessage()];
        }
    }

    public function refreshTokenAsNeeded(): void
    {
        if (!$this->shouldRefreshToken()) {
            return;
        }

        $refresh_token_data = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ];

        $this->obtainingToken();
        $data = $this->post($this->getOAuthTokenEndpoint(), $refresh_token_data);
        $this->obtainingTokenDone();

        if ($this->getLastStatusCode() === 200) {
            $this->access_token_expires = time() + $data['expires_in'];
            $this->access_token = $data['access_token'];
        }
    }

    public function shouldRefreshToken(): bool
    {
        return ($this->hasOAuthCredentialsForTokenRefresh() && $this->accessTokenNeedsRefreshing());
    }

    public function hasOAuthCredentialsForTokenRefresh(): bool
    {
        return ($this->client_id && $this->client_secret && $this->refresh_token);
    }

    public function accessTokenNeedsRefreshing(): bool
    {
        return (!$this->access_token_expires || (($this->access_token_expires - 30) < time()));
    }

    public function obtainingToken(): void
    {
        $this->obtaining_updated_access_token = true;
        $this->include_auth_header = false;
    }

    public function post(string $uri, array $post = null): array
    {
        return $this->go('POST', $uri, $post);
    }

    public function getOAuthTokenEndpoint(): string
    {
        if ($this->oauth_token_endpoint !== null) {
            return $this->oauth_token_endpoint;
        }

        $this->obtainingToken();
        $this->get();

        if ($this->getLastStatusCode() === 200) {
            $this->oauth_token_endpoint = $this->getLink("token");
            return $this->oauth_token_endpoint;
        }

        trigger_error('ERROR IN getOAuthTokenEndpoint: ' . $this->getLastStatusCode());
        $this->oauth_token_endpoint = $this->api_home . '/token';
        return $this->oauth_token_endpoint;
    }

    public function getLastStatusCode(): ?int
    {
        if ($this->last_response === null) {
            return null;
        }

        return $this->last_response->getStatusCode();
    }

    //Clear any saved links

    public function getLink(string $link_rel_string): string
    {
        $search_string = $link_rel_string;

        if (!in_array($link_rel_string, $this->registered_link_relations)
            && strpos($link_rel_string, "fx:") === false
            && strpos($link_rel_string, static::LINK_RELATIONSHIPS_BASE_URI) === false) {
            if ($this->getAcceptContentType() == static::DEFAULT_ACCEPT_CONTENT_TYPE) {
                $search_string = 'fx:' . $search_string;
            } else {
                $search_string = static::LINK_RELATIONSHIPS_BASE_URI . $search_string;
            }
        }

        return $this->links[$search_string] ?? "";
    }

    //Save Links to the Object For Easy Retrieval Later

    public function getAcceptContentType(): string
    {
        if ($this->accept_content_type == '') {
            return static::DEFAULT_ACCEPT_CONTENT_TYPE;
        }

        return $this->accept_content_type;
    }

    // Get a link out of the internally stored links
    // The link relationship base uri can be excluded ("fx:" for HAL, full URI for Siren)

    public function setAcceptContentType(string $accept_content_type): void
    {
        $this->accept_content_type = $accept_content_type;
    }

    // Get stored links (excluding link rel base uri)

    public function obtainingTokenDone(): void
    {
        $this->obtaining_updated_access_token = false;
        $this->include_auth_header = true;
    }

    //Return any errors that exist in the response data.

    public function getApiHome(): string
    {
        return $this->api_home;
    }

    public function getHeaders(): array
    {
        $headers = [
            'FOXY-API-VERSION' => 1
        ];

        if ($this->access_token && $this->include_auth_header) {
            $headers['Authorization'] = "Bearer " . $this->access_token;
        }

        $headers['Accept'] = $this->getAcceptContentType();

        return $headers;
    }

    // Set a custom supported content type (application/hal+json, application/vnd.siren+json, etc)

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    private function processRequest(
        string $method,
        string $uri,
        ?array $post,
        array $options
    ): array {
        // special case for PATCHing a Downloadable File
        if (is_array($post) && array_key_exists('file', $post) && $method == 'PATCH') {
            $method = 'POST';
            $options['headers']['X-HTTP-Method-Override'] = 'PATCH';
        }

        $request = $this->requestFactory->createRequest($method, $uri);

        if (!empty($options['query'])) {
            $uri = $request->getUri();
            $uri = $uri->withQuery(http_build_query($options['query']));
            $request = $request->withUri($uri);
        }

        if (isset($options['form_params'])) {
            $body = $request->getBody();
            $body->write(http_build_query($options['form_params'], '', '&'));
            $request = $request->withBody($body);
        }

        if (is_array($options['headers']) && !empty($options['headers'])) {
            foreach ($options['headers'] as $headerName => $headerValue) {
                $request = $request->withAddedHeader($headerName, $headerValue);
            }
        }

        $response = $this->client->sendRequest($request);
        $this->last_response = $response;

        if ($response->getStatusCode() >= 400) {
            throw new ResponseException('Error completing request', $request, $response);
        }

        $data = json_decode($response->getBody()->getContents(), true);

        if ($data === null) {
            throw new JsonException('Error decoding response body', $request, $response);
        }

        $this->saveLinks($data);

        return $data;
    }

    public function saveLinks(array $data): void
    {
        if (isset($data['_links'])) {
            foreach ($data['_links'] as $rel => $link) {
                if (!in_array($rel, $this->registered_link_relations)
                    && $rel != 'curies'
                    && $rel . '/' != static::LINK_RELATIONSHIPS_BASE_URI) {
                    $this->links[$rel] = $link['href'];
                }
            }

            return;
        }

        if (isset($data['links'])) {
            foreach ($data['links'] as $link) {
                foreach ($link['rel'] as $rel) {
                    if (!in_array($rel, $this->registered_link_relations)
                        && $rel . '/' != static::LINK_RELATIONSHIPS_BASE_URI) {
                        $this->links[$rel] = $link['href'];
                    }
                }
            }
        }
    }

    //Get headers for this call

    public function hasExpiredAccessTokenError(array $data): bool
    {
        $errors = $this->getErrors($data);

        if (in_array('The access token provided has expired', $errors)) {
            return true;
        }

        return false;
    }

    public function getErrors(array $data): array
    {
        $errors = [];

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

    public function put(string $uri, array $post = null): array
    {
        return $this->go('PUT', $uri, $post);
    }

    public function patch(string $uri, array $post = null): array
    {
        return $this->go('PATCH', $uri, $post);
    }

    public function delete(string $uri, array $post = null): array
    {
        return $this->go('DELETE', $uri, $post);
    }

    public function options(string $uri, array $post = null): array
    {
        return $this->go('OPTIONS', $uri, $post);
    }

    public function head(string $uri, array $post = null): array
    {
        return $this->go('HEAD', $uri, $post);
    }

    public function clearLinks(): void
    {
        $this->links = [];
    }

    public function getLinks(): array
    {
        $links = [];
        foreach ($this->links as $rel => $href) {
            $simple_rel = $rel;
            $base_uris = ["fx:", static::LINK_RELATIONSHIPS_BASE_URI];

            foreach ($base_uris as $base_uri) {
                $pos = strpos($simple_rel, $base_uri);
                if ($pos !== false && ($simple_rel . '/' != $base_uri)) {
                    $simple_rel = substr($simple_rel, strlen($base_uri));
                }
            }

            $links[$simple_rel] = $href;
        }

        return $links;
    }

    public function getLastResponseHeader(string $header): ?array
    {
        if ($this->last_response === null) {
            return null;
        }

        return $this->last_response->getHeader($header);
    }

    public function getAccessTokenFromClientCredentials(): array
    {
        $client_credentials_request_data = [
            'grant_type' => 'client_credentials',
            'scope' => 'client_full_access',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ];

        $this->obtainingToken();
        $data = $this->post($this->getOAuthTokenEndpoint(), $client_credentials_request_data);
        $this->obtainingTokenDone();

        if ($this->getLastStatusCode() === 200) {
            $this->access_token_expires = time() + $data['expires_in'];
            $this->access_token = $data['access_token'];
        }

        return $data;
    }

    public function getAccessTokenFromAuthorizationCode(string $code): array
    {
        $authorize_code_request_data = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ];

        $this->obtainingToken();
        $data = $this->post($this->getOAuthTokenEndpoint(), $authorize_code_request_data);
        $this->obtainingTokenDone();

        if ($this->getLastStatusCode() === 200) {
            $this->access_token_expires = time() + $data['expires_in'];
            $this->access_token = $data['access_token'];
            $this->refresh_token = $data['refresh_token'];
        }

        return $data;
    }

}
