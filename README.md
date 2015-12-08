# FoxyClient PHP
FoxyClient - a PHP Guzzle based API Client for working with the Foxy Hypermedia API

Please see <a href="https://api.foxycart.com/docs">the Foxy Hypermedia API documentation</a> for more information.

## Installation

The best way to get started with FoxyClient is to run the <a href="https://github.com/FoxyCart/foxyclient-php-example">foxyclient-php-example</a> code.

Once you're familiar with how it works, you can add it as a composer package to your application, just as the example code does.

## Usage

As mentioned above, begin by getting familiar with <a href="https://github.com/FoxyCart/foxyclient-php-example">the example code</a>. The <a href="https://github.com/FoxyCart/foxyclient-php-example/blob/master/bootstrap.php">bootstrap.php file</a> in particular is useful for configuring FoxyClient within your application. Please note how it takes advantage of HTTP caching and CSRF protection which are important for the performance and security of your application.

FoxyClient supports all HTTP methods for our Hypermedia API. It also automatically handles OAuth token refreshes and handles authorization code grants. It does not specify a persistance layer for storing OAuth tokens so you can implement that however you want. You can also support a static service with no database which would only use the client_id, client_secret, and refresh_token to obtain a new access_token as needed.

## See Also

Along with the <a href="https://github.com/FoxyCart/foxyclient-php-example">example code</a>, we'll also be adding to the <a href="https://github.com/FoxyCart/foxyclient-php-playground">playground</a> for more examples of how to use the client. It currently demonstrates how to work with coupons: https://github.com/FoxyCart/foxyclient-php-playground

### Config Options
 * use_sandbox: Set to true to work with https://api-sandbox.foxycart.com. This is highly recomended when first getting familiar with the system.
 * client_id: Your OAuth Client id.
 * client_secret: Your OAuth Client Secret.
 * access_token: Your OAuth Acccess Token.
 * access_token_expires: A timestamp for when the access_token needs to be refreshed. You can be set via time() + expires_in.
 * refresh_token: OAuth Refresh Token.

### HTTP methods
 * get($uri, $post = null)
 * post($uri, $post = null)
 * patch($uri, $post = null)
 * put($uri, $post = null)
 * delete($uri, $post = null)
 * options($uri, $post = null)
 * head($uri, $post = null)

### API Methods
 * getLink($link_rel_string): Used to get the href of a given link relationship such as "self" or "fx:store". It also supports the short version of just "store".
 * getLinks(): Returns an array of link relationships the client has seen so far in the rel => href format. Each request stores the links in the links array.
 * getErrors($data): Given a response payload, it will normalize errors and return an array of them (or any empty array).
 * getLastStatusCode(): Returns the last HTTP status code.
 * getAuthorizationEndpoint(): The <a href="https://tools.ietf.org/html/rfc6749#section-4.1">Authorization Code Grant</a> server endpoint url. You'll need to forward users to this to let them grant your application access to their store or user.
 * getOAuthTokenEndpoint(): The OAuth token endpoint. Note: you shouldn't have to use this as the library takes care of all OAuth functionality for you.
 * getAccessTokenFromAuthorizationCode($code): Used when returning from our Authorization server in order to obtain an access_token and refresh_token.
 * setAcceptContentType($accept_content_type): Set the Accept header content type used for the all future requests. All supported json content types on the FoxyCart API are supported such as application/hal+json and application/vnd.siren+json.
 * getAcceptContentType(): get the Accept header content type
 * getLastResponseHeader($header): Get a header (such as "Location") from the previous response.

