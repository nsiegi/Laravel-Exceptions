<?php

/*
 * This file is part of Laravel Exceptions.
 *
 * (c) Graham Campbell <graham@alt-three.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GrahamCampbell\Tests\Exceptions;

use Exception;
use GrahamCampbell\Exceptions\ExceptionHandler;
use GrahamCampbell\Exceptions\ExceptionIdentifier;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Mockery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * This is the exception handler test class.
 *
 * @author Graham Campbell <graham@alt-three.com>
 */
class ExceptionHandlerTest extends AbstractTestCase
{
    public function testBasicRender()
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render($this->app->request, $e = new Exception('Foo Bar.'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame($e, $response->exception);
        $this->assertTrue(str_contains($response->getContent(), 'Internal Server Error'));
        $this->assertFalse(str_contains($response->getContent(), 'Foo Bar.'));
        $this->assertSame('text/html', $response->headers->get('Content-Type'));
    }

    public function testNotFoundRender()
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render($this->app->request, $e = new NotFoundHttpException());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($e, $response->exception);
        $this->assertTrue(str_contains($response->getContent(), 'Not Found'));
        $this->assertSame('text/html', $response->headers->get('Content-Type'));
    }

    public function testCsrfExceptionRender()
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render($this->app->request, $e = new TokenMismatchException());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertInstanceOf(BadRequestHttpException::class, $response->exception);
        $this->assertTrue(str_contains($response->getContent(), 'Bad Request'));
        $this->assertTrue(str_contains($response->getContent(), 'CSRF token validation failed.'));
        $this->assertSame('text/html', $response->headers->get('Content-Type'));
    }

    public function testJsonRender()
    {
        $this->app->request->headers->set('accept', 'application/json');

        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render($this->app->request, $e = new GoneHttpException());
        $id = $this->app->make(ExceptionIdentifier::class)->identify($e);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame($e, $response->exception);
        $this->assertSame('{"errors":[{"id":"'.$id.'","status":410,"title":"Gone","detail":"The requested resource is no longer available and will not be available again."}]}', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testBadRender()
    {
        $this->app->request->headers->set('accept', 'not/acceptable');

        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render($this->app->request, $e = new NotFoundHttpException());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($e, $response->exception);
        $this->assertTrue(str_contains($response->getContent(), 'Not Found'));
        $this->assertSame('text/html', $response->headers->get('Content-Type'));
    }

    public function testReportHttp()
    {
        $this->app->instance(LoggerInterface::class, Mockery::mock(LoggerInterface::class));

        $this->assertNull($this->app->make(ExceptionHandler::class)->report(new NotFoundHttpException()));
    }

    public function testReportException()
    {
        $mock = Mockery::mock(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $mock);
        $e = new Exception();
        $id = $this->app->make(ExceptionIdentifier::class)->identify($e);
        $mock->shouldReceive('error')->once()->with($e, ['identification' => ['id' => $id]]);

        $this->assertNull($this->app->make(ExceptionHandler::class)->report($e));
    }

    public function testReportBadRequestException()
    {
        $mock = Mockery::mock(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $mock);
        $e = new BadRequestHttpException();
        $id = $this->app->make(ExceptionIdentifier::class)->identify($e);
        $mock->shouldReceive('warning')->once()->with($e, ['identification' => ['id' => $id]]);

        $this->assertNull($this->app->make(ExceptionHandler::class)->report($e));
    }

    public function testReportCsrfException()
    {
        $mock = Mockery::mock(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $mock);
        $e = new TokenMismatchException();
        $id = $this->app->make(ExceptionIdentifier::class)->identify($e);
        $mock->shouldReceive('notice')->once()->with($e, ['identification' => ['id' => $id]]);

        $this->assertNull($this->app->make(ExceptionHandler::class)->report($e));
    }

    public function testReportFallbackWorks()
    {
        $this->app->config->set('exceptions.levels', [TokenMismatchException::class => 'notice']);

        $mock = Mockery::mock(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $mock);
        $e = new BadRequestHttpException();
        $id = $this->app->make(ExceptionIdentifier::class)->identify($e);
        $mock->shouldReceive('error')->once()->with($e, ['identification' => ['id' => $id]]);

        $this->assertNull($this->app->make(ExceptionHandler::class)->report($e));
    }
}
