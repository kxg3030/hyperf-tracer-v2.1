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
namespace Hyperf\Tracer\Aspect;

use GuzzleHttp\Client;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AroundInterface;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Tracer\SpanStarter;
use Hyperf\Tracer\SpanTagManager;
use Hyperf\Tracer\SwitchManager;
use Hyperf\Utils\Context;
use OpenTracing\Tracer;
use Psr\Http\Message\ResponseInterface;
use const OpenTracing\Formats\TEXT_MAP;

/**
 * @Aspect
 */
class HttpClientAspect implements AroundInterface
{
    use SpanStarter;

    public $classes = [
        Client::class . '::requestAsync',
    ];

    public $annotations = [];

    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * @var SwitchManager
     */
    private $switchManager;

    /**
     * @var SpanTagManager
     */
    private $spanTagManager;

    public function __construct(Tracer $tracer, SwitchManager $switchManager, SpanTagManager $spanTagManager)
    {
        $this->tracer = $tracer;
        $this->switchManager = $switchManager;
        $this->spanTagManager = $spanTagManager;
    }

    /**
     * @return mixed return the value from process method of ProceedingJoinPoint, or the value that you handled
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        if ($this->switchManager->isEnable('guzzle') === false) {
            return $proceedingJoinPoint->process();
        }
        $options = $proceedingJoinPoint->arguments['keys']['options'];
        if (isset($options['no_aspect']) && $options['no_aspect'] === true) {
            return $proceedingJoinPoint->process();
        }
        $arguments = $proceedingJoinPoint->arguments;
        $uri       = $arguments['keys']['uri'] ?? 'null';
        $span      = $this->startSpan($uri);
        $span->setTag('source', $proceedingJoinPoint->className . '::' . $proceedingJoinPoint->methodName);
        $this->record("http_client", $arguments,$span);
        $appendHeaders = [];
        $this->tracer->inject($span->getContext(), TEXT_MAP, $appendHeaders);
        $options['headers']                                = array_replace($options['headers'] ?? [], $appendHeaders);
        $proceedingJoinPoint->arguments['keys']['options'] = $options;
        try {
            $result = $proceedingJoinPoint->process();
            if ($result instanceof ResponseInterface) {
                $span->setTag("http.status", $result->getStatusCode());
            }
        } catch (\Throwable $e) {
            $span->setTag('exception', true);
            $span->log(['message', $e->getMessage(), 'code' => $e->getCode(), 'stacktrace' => $e->getTraceAsString()]);
            throw $e;
        } finally {
            $span->finish();
        }
        return $result;
    }
}
