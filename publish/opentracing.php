<?php

declare(strict_types=1);

/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

use Hyperf\Utils\Codec\Json;
use Hyperf\Utils\Context;
use Hyperf\Utils\Coroutine;
use Jaeger\Span;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zipkin\Samplers\BinarySampler;

return [
    'default' => env('TRACER_DRIVER', 'zipkin'),
    'enable'  => [
        'guzzle' => env('TRACER_ENABLE_GUZZLE', false),
        'redis'  => env('TRACER_ENABLE_REDIS', false),
        'db'     => env('TRACER_ENABLE_DB', false),
        'method' => env('TRACER_ENABLE_METHOD', false),
    ],
    'tracer'  => [
        'zipkin' => [
            'driver'  => Hyperf\Tracer\Adapter\ZipkinTracerFactory::class,
            'app'     => [
                'name' => env('APP_NAME', 'skeleton'),
                // Hyperf will detect the system info automatically as the value if ipv4, ipv6, port is null
                'ipv4' => '127.0.0.1',
                'ipv6' => null,
                'port' => 9501,
            ],
            'options' => [
                'endpoint_url' => env('ZIPKIN_ENDPOINT_URL', 'http://localhost:9411/api/v2/spans'),
                'timeout'      => env('ZIPKIN_TIMEOUT', 1),
            ],
            'sampler' => BinarySampler::createAsAlwaysSample(),
        ],
        'jaeger' => [
            'driver'  => Hyperf\Tracer\Adapter\JaegerTracerFactory::class,
            'name'    => env('APP_NAME', 'skeleton'),
            'options' => [
                /*
                 * You can uncomment the sampler lines to use custom strategy.
                 *
                 * For more available configurations,
                 * @see https://github.com/jonahgeorge/jaeger-client-php
                 */
                // 'sampler' => [
                //     'type' => \Jaeger\SAMPLER_TYPE_CONST,
                //     'param' => true,
                // ],,
                'local_agent' => [
                    'reporting_host' => env('JAEGER_REPORTING_HOST', 'localhost'),
                    'reporting_port' => env('JAEGER_REPORTING_PORT', 5775),
                ],
            ],
        ],
    ],
    'tags'    => [
        # 请求外部接口记录
        'http_client' => function (Span $span, array $data) {
            $method = $data['keys']['method'] ?? 'null';
            $uri    = $data['keys']['uri'] ?? 'null';
            $span->setTag("http.url", $uri);
            $span->setTag("http.method", $method);
        },
        # redis
        'redis'       => function (Span $span, array $data) {
            $span->setTag("redis.arguments", Json::encode($data['arguments']));
        },
        # 数据库
        'db'          => function (Span $span, array $data) {
            $span->setTag("db.query", Json::encode($data['arguments'], JSON_UNESCAPED_UNICODE));
        },
        # 异常记录
        'exception'   => function (Span $span, \Throwable $throwable) {
            $span->setTag("exception.class", get_class($throwable));
            $span->setTag("exception.code", $throwable->getCode());
            $span->setTag("exception.error", $throwable->getMessage());
            $span->setTag("exception.trace", $throwable->getTraceAsString());
        },
        # 外部请求接口时记录的值
        'request'     => function (Span $span) {
            $request = Context::get(ServerRequestInterface::class);
            $span->setTag("http.get.params", Json::encode($request->getQueryParams(), JSON_UNESCAPED_UNICODE));
            $span->setTag("http.post.params", Json::encode($request->getParsedBody(), JSON_UNESCAPED_UNICODE));
        },
        # 记录协程ID
        'coroutine'   => function (Span $span, array $data) {
            $span->setTag("coroutine.id", Coroutine::id());
        },
        # 外部请求接口时记录返回值
        'response'    => function (Span $span) {
            /**@var $response ResponseInterface */
            $response = Context::get(ResponseInterface::class);
            $span->setTag("response.status", (string)$response->getStatusCode());
        },
    ],
];
