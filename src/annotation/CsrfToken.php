<?php

/*
 * This file is part of the Kuiper package.
 *
 * (c) Ye Wenbin <wenbinye@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace kuiper\web\annotation;

use kuiper\web\middleware\AbstractMiddlewareFactory;
use kuiper\web\middleware\CsrfToken as CsrfTokenMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * @Annotation
 * @Target({"CLASS", "METHOD"})
 */
class CsrfToken extends AbstractMiddlewareFactory
{
    /**
     * @var bool
     */
    public $repeatOk = true;

    /**
     * {@inheritdoc}
     */
    public function create(ContainerInterface $container): MiddlewareInterface
    {
        return new CsrfTokenMiddleware($this->repeatOk);
    }
}
