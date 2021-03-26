<?php

declare(strict_types=1);

namespace kuiper\web\middleware;

use kuiper\helper\Arrays;
use kuiper\helper\Text;
use kuiper\swoole\monolog\CoroutineIdProcessor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class AccessLog implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const MAIN = '$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" '
    .'"$http_user_agent" "$http_x_forwarded_for" rt=$request_time';

    /**
     * @var string|callable
     */
    private $format;

    /**
     * 放到 extra 中变量，可选值 query, body, jwt, cookies, headers, header.{name}.
     *
     * @var string[]
     */
    private $extra;

    /**
     * @var int
     */
    private $bodyMaxSize;
    /**
     * @var callable|null
     */
    private $requestFilter;

    /**
     * @var callable
     */
    private $dateFormatter;

    /**
     * @var CoroutineIdProcessor
     */
    private $pidProcessor;

    /**
     * AccessLog constructor.
     *
     * @param string[] $extra
     */
    public function __construct(
        $format = self::MAIN,
        array $extra = ['query', 'body'],
        int $bodyMaxSize = 4096,
        $dateFormat = '%d/%b/%Y:%H:%M:%S %z',
        ?callable $requestFilter = null)
    {
        $this->format = $format;
        $this->extra = $extra;
        $this->bodyMaxSize = $bodyMaxSize;
        $this->requestFilter = $requestFilter;
        $this->pidProcessor = new CoroutineIdProcessor();
        if (is_string($dateFormat)) {
            if (substr_count($dateFormat, '%') >= 2) {
                $this->dateFormatter = static function () use ($dateFormat) {
                    return strftime($dateFormat);
                };
            } else {
                $this->dateFormatter = static function () use ($dateFormat) {
                    return date_create()->format($dateFormat);
                };
            }
        } elseif (is_callable($dateFormat)) {
            $this->dateFormatter = $dateFormat;
        }
    }

    public function getJwtPayload(?string $tokenHeader): ?array
    {
        if (Text::isNotEmpty($tokenHeader) && 0 === strpos($tokenHeader, 'Bearer ')) {
            $parts = explode('.', substr($tokenHeader, 7));
            if (isset($parts[1])) {
                return json_decode(base64_decode($parts[1], true), true);
            }
        }

        return null;
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
            if (null === $this->requestFilter || call_user_func($this->requestFilter, $request, $response)) {
                $responseTime = (microtime(true) - $start) * 1000;
                $this->format($this->prepareMessageContext($request, $response, $responseTime));
            }
        }
    }

    protected function format(array $messageContext): void
    {
        if (is_string($this->format)) {
            $this->logger->info(strtr($this->format, Arrays::mapKeys($messageContext, function ($key) {
                return '$'.$key;
            })), $messageContext['extra'] ?? []);
        } elseif (is_callable($this->format)) {
            $this->logger->info(call_user_func($this->format, $messageContext));
        }
    }

    protected function prepareMessageContext(ServerRequestInterface $request, ?ResponseInterface $response, float $responseTime): array
    {
        $time = round($responseTime, 2);

        $ipList = RemoteAddress::getAll($request);
        $statusCode = isset($response) ? $response->getStatusCode() : 500;
        $responseBodySize = isset($response) ? $response->getBody()->getSize() : 0;
        $messageContext = [
            'remote_addr' => $ipList[0] ?? '-',
            'remote_user' => $request->getUri()->getUserInfo() ?? '-',
            'time_local' => call_user_func($this->dateFormatter),
            'request_method' => $request->getMethod(),
            'request_uri' => (string) $request->getUri(),
            'request' => strtoupper($request->getMethod())
                .' '.$request->getUri()->getPath()
                .' '.strtoupper($request->getUri()->getScheme()).'/'.$request->getProtocolVersion(),
            'status' => $statusCode,
            'body_bytes_sent' => $responseBodySize,
            'http_referer' => $request->getHeaderLine('Referer'),
            'http_user_agent' => $request->getHeaderLine('User-Agent'),
            'http_x_forwarded_for' => implode(',', $ipList),
            'request_time' => $time,
        ];
        $extra = [];
        foreach ($this->extra as $name) {
            if ('query' === $name) {
                $extra['query'] = http_build_query($request->getQueryParams());
            } elseif ('body' === $name) {
                $bodySize = $request->getBody()->getSize();
                if (isset($this->bodyMaxSize) && $bodySize > $this->bodyMaxSize) {
                    $extra['body'] = 'body with '.$bodySize.' bytes';
                } else {
                    $body = (string) $request->getBody();
                    if (mb_check_encoding($body, 'utf-8')) {
                        $extra['body'] = $body;
                    } else {
                        $extra['body'] = 'binary data with '.$bodySize.'bytes';
                    }
                }
            } elseif ('headers' === $name) {
                $extra['headers'] = $request->getHeaders();
            } elseif ('cookies' === $name) {
                $extra['cookies'] = $request->getHeaderLine('cookie');
            } elseif ('jwt' === $name) {
                $extra['jwt'] = $this->getJwtPayload($request->getHeaderLine('Authorization'));
            } elseif (0 === strpos($name, 'header.')) {
                $header = substr($name, 7);
                $extra[$header] = $request->getHeaderLine($header);
            } elseif ('pid' === $name) {
                $extra = call_user_func($this->pidProcessor, $extra);
            }
        }
        $extra = array_filter($extra);
        $messageContext['extra'] = $extra;

        return $messageContext;
    }
}
