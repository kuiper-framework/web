<?php

declare(strict_types=1);

namespace kuiper\web\middleware;

use kuiper\web\RequestLogFormatterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class AccessLog implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var RequestLogFormatterInterface
     */
    private $formatter;

    /**
     * @var callable|null
     */
    private $requestFilter;

    /**
     * AccessLog constructor.
     *
     * @param RequestLogFormatterInterface $formatter
     * @param callable|null                $requestFilter
     */
    public function __construct(RequestLogFormatterInterface $formatter, ?callable $requestFilter = null)
    {
        $this->formatter = $formatter;
        $this->requestFilter = $requestFilter;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $response = null;
        try {
            $response = $handler->handle($request);

            return $response;
        } finally {
            if (null === $this->requestFilter || (bool) call_user_func($this->requestFilter, $request, $response)) {
                $responseTime = (microtime(true) - $start) * 1000;
                $this->logger->info(...$this->formatter->format($request, $response, $responseTime));
            }
        }
    }
}
