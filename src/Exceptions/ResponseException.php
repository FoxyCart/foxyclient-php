<?php

namespace Foxy\FoxyClient\Exceptions;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ResponseException extends RequestException
{

    public function __construct(
        string $message,
        RequestInterface $request,
        ResponseInterface $response,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $request, $response, $previous);
    }

    public function hasResponse(): bool
    {
        return true;
    }

    public function getResponse(): ResponseInterface
    {
        return parent::getResponse();
    }
}