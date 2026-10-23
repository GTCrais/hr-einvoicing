<?php

use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\MojEracun\MojEracunClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
	config(['hrEInvoicing.mojEracun' => [
		'environment' => 'demo',
		'username' => '10001',
		'password' => 'tajna',
		'oib' => '12345678903',
		'businessUnit' => null,
		'softwareId' => 'Test-002',
	]]);

	Http::preventStrayRequests();
});

it('sends the document with the account in the shape the demo service takes, and keeps its identifier and status', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/send' => Http::response(['ElectronicId' => 3687370, 'DocumentNr' => '455/P1/1', 'DocumentTypeId' => 1, 'StatusId' => 20, 'StatusName' => 'Obrađen']),
	]);

	$answer = app(MojEracunClient::class)->send('<Invoice/>');

	expect($answer)->toBe(['electronicId' => '3687370', 'status' => 20]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/send' && $request->data() === [
		'username' => '10001',
		'password' => 'tajna',
		'companyId' => '12345678903',
		'companyBU' => null,
		'softwareId' => 'Test-002',
		'file' => '<Invoice/>',
	]);
});

it('sends to the production service when the environment says so', function () {
	config(['hrEInvoicing.mojEracun.environment' => 'production']);
	Http::fake([
		'https://www.moj-eracun.hr/apis/v2/send' => Http::response(['ElectronicId' => 81, 'StatusId' => 30]),
	]);

	expect(app(MojEracunClient::class)->send('<Invoice/>'))->toBe(['electronicId' => '81', 'status' => 30]);
});

it('says which rules a refused document broke, a refusal no new try changes, unlike an answer that never came', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/send' => Http::sequence()
			->push(['File' => ['Value' => '<Invoice/>', 'Messages' => ['[HR-BR-37] - Račun mora sadržavati oznaku operatera (HR-BT-4)']]], 400)
			->push(['message' => 'Service Unavailable'], 503),
	]);

	expect(fn () => app(MojEracunClient::class)->send('<Invoice/>'))
		->toThrow(EInvoiceRefusedException::class, 'HTTP 400: [HR-BR-37] - Račun mora sadržavati oznaku operatera (HR-BT-4)');

	$exception = rescue(fn () => app(MojEracunClient::class)->send('<Invoice/>'), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceRefusedException::class)
		->and($exception->getMessage())->toBe('HTTP 503: Service Unavailable');
});

it('finds a document sent before by its number in the outbox, the latest of that number, and nothing for a number it does not hold', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([
				['ElectronicId' => 3687371, 'DocumentNr' => '456/P1/1', 'StatusId' => 30],
				['ElectronicId' => 3687370, 'DocumentNr' => '455/P1/1', 'StatusId' => 50],
				['ElectronicId' => 3687375, 'DocumentNr' => '455/P1/1', 'StatusId' => 30],
			])
			->push(['message' => 'Nema dokumenata.'], 404),
	]);

	$client = app(MojEracunClient::class);

	expect($client->sentDocument('455/P1/1', Carbon::parse('2026-10-22 14:48:09', 'UTC')))->toBe(['electronicId' => '3687375', 'status' => 30])
		->and($client->sentDocument('457/P1/1', Carbon::parse('2026-10-22 14:48:09', 'UTC')))->toBeNull();

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/queryOutbox' && $request['from'] === '2026-10-21' && $request['companyId'] === '12345678903');
});

it('reads the document status from the outbox list and leaves the fiscalization unknown while the tax authority has said nothing', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 30, 'StatusName' => 'Poslan']]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Nije moguće pronaći status poruke o fiskalizaciji.', 'traceId' => 'a2fc9bec'], 404),
	]);

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => 30, 'fiscalization' => null, 'error' => null]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/queryOutbox' && $request['electronicId'] === 3687370);
});

it('takes the tax authority answer about the document itself, the latest one, and passes over the answers about other documents', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 40]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response([
			// The demo service answers with made-up documents too, which must not count
			['electronicId' => 1454891830, 'messages' => [['status' => 0, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-22T17:20:03+02:00']]],
			['electronicId' => 3687370, 'channelType' => 0, 'messages' => [
				['status' => 2, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-22T16:48:12+02:00'],
				['status' => 1, 'messageType' => 0, 'errorCode' => 'S005', 'errorCodeDescription' => 'Poruka nije u skladu s XML shemom', 'dateOfFiscalization' => '2026-10-22T16:49:00+02:00'],
				// A payment report's answer is no answer about the document
				['status' => 0, 'messageType' => 3, 'dateOfFiscalization' => '2026-10-22T16:50:00+02:00'],
			]],
		]),
	]);

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => 40, 'fiscalization' => 1, 'error' => 'S005 Poruka nije u skladu s XML shemom']);
});

it('reports a payment with the transfer method, and says the tax authority code when it refuses one', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/api/fiscalization/markPaid' => Http::sequence()
			->push(['fiscalizationTimestamp' => '2026-10-22T16:48:35+02:00', 'electronicId' => 3687370, 'fiscalizationRequestId' => '19b1e1fa'])
			->push(['traceId' => 'b5feb4c7', 'message' => 'Pogreška prilikom obrade zahtjeva.', 'taxAdministrationSpecifics' => ['code' => 'S007', 'description' => 'OIB iz zahtjeva nije formalno ispravan']], 400),
	]);
	$client = app(MojEracunClient::class);

	$client->reportPayment('3687370', '2026-10-22', 167.0);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/api/fiscalization/markPaid'
		&& $request['electronicId'] === 3687370 && $request['paymentDate'] === '2026-10-22' && $request['paymentAmount'] === 167.0 && $request['paymentMethod'] === 'T');
	expect(fn () => $client->reportPayment('3687370', '2026-10-22', 167.0))
		->toThrow(RuntimeException::class, 'HTTP 400: S007 OIB iz zahtjeva nije formalno ispravan (Pogreška prilikom obrade zahtjeva.)');
});

it('tells a service that is down from an account it refuses when the connection is tested', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/Ping/' => Http::sequence()
			->push('<title>HTTP Error 500.30 - ASP.NET Core app failed to start</title>', 500)
			->push('', 200),
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response(['message' => 'Unauthorized'], 401),
	]);

	// Down: the account is never tried
	expect(fn () => app(MojEracunClient::class)->ping())->toThrow(EInvoiceUnavailableException::class, 'HTTP 500');

	Http::assertSentCount(1);

	// Up, but the account is refused
	$exception = rescue(fn () => app(MojEracunClient::class)->ping(), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceUnavailableException::class)
		->and($exception->getMessage())->toBe('HTTP 401: Unauthorized');
});

it('is not ready without the software identifier the intermediary issued, and sends nothing', function () {
	config(['hrEInvoicing.mojEracun.softwareId' => null]);
	$client = app(MojEracunClient::class);

	expect($client->ready())->toBeFalse()
		->and(fn () => $client->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary account is not set up.');
});
