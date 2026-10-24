<?php

use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\Testing\FakeEInvoiceGateway;
use Illuminate\Support\Carbon;

it('keeps what was sent and reported, and answers with the standing a test set', function () {
	$gateway = new FakeEInvoiceGateway;

	$sent = $gateway->send('<Invoice><cbc:ID>1/P1/1</cbc:ID></Invoice>');
	$gateway->setStatus($sent['electronicId'], DocumentStatus::DELIVERED, FiscalizationStatus::FAILED, 'S005 Poruka nije u skladu s XML shemom');
	$gateway->reportPayment($sent['electronicId'], '2026-10-22', 125.0);
	$gateway->ping();

	expect($sent)->toBe(['electronicId' => '1001', 'status' => DocumentStatus::SENT])
		->and($gateway->sent)->toHaveCount(1)
		->and($gateway->status('1001'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => FiscalizationStatus::FAILED, 'error' => 'S005 Poruka nije u skladu s XML shemom'])
		->and($gateway->status('1002'))->toBe(['status' => DocumentStatus::SENT, 'fiscalization' => null, 'error' => null])
		->and($gateway->payments)->toBe([['electronicId' => '1001', 'paidOn' => '2026-10-22', 'amount' => 125.0]])
		->and($gateway->pings)->toBe(1);
});

it('fails, refuses and loses answers when told to, and finds a document whose answer was lost by its number', function () {
	$gateway = new FakeEInvoiceGateway;

	$gateway->failNext();
	$gateway->refuseNext();
	$gateway->unavailableNext();

	expect(fn () => $gateway->send('<Invoice/>'))->toThrow(RuntimeException::class, 'cURL error 28: Connection timed out')
		->and(fn () => $gateway->send('<Invoice/>'))->toThrow(EInvoiceRefusedException::class)
		->and(fn () => $gateway->ping())->toThrow(EInvoiceUnavailableException::class);

	$gateway->loseNextAnswer();

	expect(fn () => $gateway->send('<Invoice><cbc:ID>7/P1/1</cbc:ID></Invoice>'))->toThrow(RuntimeException::class, 'Operation timed out')
		->and($gateway->sentDocument('7/P1/1', Carbon::now()))->toBe(['electronicId' => '1001', 'status' => DocumentStatus::SENT])
		->and($gateway->sentDocument('8/P1/1', Carbon::now()))->toBeNull();

	$gateway->setStatus('1001', DocumentStatus::REFUSED, error: 'HTTP 400: [HR-BR-37]');

	expect($gateway->sentDocument('7/P1/1', Carbon::now()))->toBe(['electronicId' => '1001', 'status' => DocumentStatus::REFUSED]);

	$gateway->reset();

	expect($gateway->sent)->toBe([])
		->and($gateway->sendStatus)->toBe(DocumentStatus::SENT);
});
