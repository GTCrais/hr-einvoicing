<?php

use GTCrais\HrEInvoicing\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

uses(TestCase::class)->in('Feature');

/**
 * A stub for Http::fake() whose answer is cut off on its way: the status line and the start of the body came, then the
 * transfer failed (cURL 18 or 56), which Laravel's client throws as a RequestException carrying what arrived.
 */
function answerCutOff(int $status, string $body): Closure
{
	return fn () => Create::rejectionFor(new RequestException(new Response(new Psr7Response($status, [], $body))));
}
