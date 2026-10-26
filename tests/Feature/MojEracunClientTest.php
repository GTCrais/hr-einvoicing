<?php

use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;
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

it('sends the document with the account in the shape the demo service takes, and keeps its identifier and standing', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/send' => Http::response(['ElectronicId' => 3687370, 'DocumentNr' => '455/P1/1', 'DocumentTypeId' => 1, 'StatusId' => 20, 'StatusName' => 'Obrađen']),
	]);

	$answer = app(MojEracunClient::class)->send('<Invoice/>');

	expect($answer)->toBe(['electronicId' => '3687370', 'status' => DocumentStatus::SENT]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/send' && $request->data() === [
		'username' => '10001',
		'password' => 'tajna',
		'companyId' => '12345678903',
		'companyBU' => null,
		'softwareId' => 'Test-002',
		'file' => '<Invoice/>',
	]);
});

it('refuses the production service outside a production installation, and sends to it when that is allowed', function () {
	config(['hrEInvoicing.mojEracun.environment' => 'production']);
	Http::fake([
		'https://www.moj-eracun.hr/apis/v2/send' => Http::response(['ElectronicId' => 81, 'StatusId' => 30]),
	]);

	expect(fn () => app(MojEracunClient::class)->send('<Invoice/>'))
		->toThrow(RuntimeException::class, 'The intermediary\'s production service is refused outside a production installation.');

	Http::assertNothingSent();

	config(['hrEInvoicing.allowProductionAnywhere' => true]);

	expect(app(MojEracunClient::class)->send('<Invoice/>'))->toBe(['electronicId' => '81', 'status' => DocumentStatus::SENT]);
});

it('calls the service of the account\'s own environment, and sends the environment nowhere', function () {
	config(['hrEInvoicing.mojEracun.environment' => 'production', 'hrEInvoicing.allowProductionAnywhere' => true]);
	app()->instance(AccountProvider::class, new class implements AccountProvider
	{
		public function account(): ?array
		{
			return ['username' => '10001', 'password' => 'tajna', 'companyId' => '12345678903', 'companyBU' => null, 'softwareId' => 'Test-002', 'environment' => 'demo'];
		}
	});
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/send' => Http::response(['ElectronicId' => 82, 'StatusId' => 20]),
	]);

	expect(app(MojEracunClient::class)->send('<Invoice/>'))->toBe(['electronicId' => '82', 'status' => DocumentStatus::SENT]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/send' && !array_key_exists('environment', $request->data()));
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

it('takes a refusal of the account for one that passes once the account is put right, never for the document refused', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/send' => Http::response(['SoftwareId' => ['Value' => 'Test-002', 'Messages' => ['Neispravan SoftwareId.']]], 400),
	]);

	$exception = rescue(fn () => app(MojEracunClient::class)->send('<Invoice/>'), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceRefusedException::class);
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

	expect($client->sentDocument('455/P1/1', Carbon::parse('2026-10-22 14:48:09', 'UTC')))->toBe(['electronicId' => '3687375', 'status' => DocumentStatus::SENT])
		->and($client->sentDocument('457/P1/1', Carbon::parse('2026-10-22 14:48:09', 'UTC')))->toBeNull();

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/queryOutbox' && $request['from'] === '2026-10-21' && $request['companyId'] === '12345678903'
		&& $request['InvoiceNumber'] === '455/P1/1' && !array_key_exists('InvoiceYear', $request->data()));
});

it('asks the outbox for the number of the year of its day of issue', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3690000, 'DocumentNr' => '7/WEB1/1', 'StatusId' => 40, 'Issued' => '2026-12-30T00:00:00']]),
	]);

	expect(app(MojEracunClient::class)->sentDocument('7/WEB1/1', Carbon::parse('2026-12-30 09:15:00', 'UTC'), '2026-12-30')['electronicId'])->toBe('3690000');

	Http::assertSent(fn (Request $request) => $request['InvoiceNumber'] === '7/WEB1/1' && $request['InvoiceYear'] === 2026);
});

it('refuses a document whose number of the year it holds already, the way the demo answered on 2026-10-26, and fails for any other condition of sending', function () {
	$duplicate = ['PrerequisitesValidationFailed' => ['Value' => 'Uvjeti za slanje dokumenta nisu zadovoljeni: {0}', 'Messages' => [
		'Uvjeti za slanje dokumenta nisu zadovoljeni: Document with identical internal number=455/P1/1, sender=25038668428, receiver=91134904012, for issue year 2026 already exists with EID=3687370.. Trace ID: 36608fe2-8f93-4e39-adbb-1d052aa20c2a.',
	]]];
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/send' => Http::sequence()
			->push($duplicate)
			->push(['PrerequisitesValidationFailed' => ['Value' => 'Uvjeti za slanje dokumenta nisu zadovoljeni: {0}', 'Messages' => ['Uvjeti za slanje dokumenta nisu zadovoljeni: paket je istekao.']]]),
	]);

	expect(fn () => app(MojEracunClient::class)->send('<Invoice/>'))->toThrow(EInvoiceRefusedException::class, 'already exists with EID=3687370');

	$exception = rescue(fn () => app(MojEracunClient::class)->send('<Invoice/>'), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceRefusedException::class)
		->and($exception->getMessage())->toBe('HTTP 200: Uvjeti za slanje dokumenta nisu zadovoljeni: paket je istekao.');
});

it('tells a number of one year from the same number of the next by the day of issue', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([
			['ElectronicId' => 3700001, 'DocumentNr' => '7/WEB1/1', 'StatusId' => 30, 'Issued' => '2027-01-05T00:00:00'],
			['ElectronicId' => 3690000, 'DocumentNr' => '7/WEB1/1', 'StatusId' => 40, 'Issued' => '2026-12-30T00:00:00'],
		]),
	]);
	$client = app(MojEracunClient::class);
	$since = Carbon::parse('2026-12-30 09:15:00', 'UTC');

	expect($client->sentDocument('7/WEB1/1', $since, '2026-12-30'))->toBe(['electronicId' => '3690000', 'status' => DocumentStatus::DELIVERED])
		->and($client->sentDocument('7/WEB1/1', $since, '2026-12-29'))->toBeNull()
		->and($client->sentDocument('7/WEB1/1', $since)['electronicId'])->toBe('3700001');
});

it('reads the document standing from the outbox list and leaves the fiscalization unknown while the tax authority has said nothing', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 30, 'StatusName' => 'Poslan']]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Nije moguće pronaći status poruke o fiskalizaciji.', 'traceId' => 'a2fc9bec'], 404),
	]);

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => DocumentStatus::SENT, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'paymentReports' => 0, 'answersError' => null]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/queryOutbox' && $request['electronicId'] === 3687370);
});

it('keeps the standing when the tax authority\'s answers cannot be read and says why, so a payment reported is never taken for one not reported', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 40]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Service Unavailable'], 503),
	]);

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'paymentReports' => null, 'answersError' => 'HTTP 503: Service Unavailable']);
});

it('names a refused, a reported and a delivered document, and says the status name of a refused one as the reason', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([['ElectronicId' => 1, 'StatusId' => 50, 'StatusName' => 'Odbijen']])
			->push([['ElectronicId' => 2, 'StatusId' => 70, 'StatusName' => 'Prijavljen']])
			->push([['ElectronicId' => 3, 'StatusId' => 40, 'StatusName' => 'Dostavljen']]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response([], 404),
	]);
	$client = app(MojEracunClient::class);

	expect($client->status('1'))->toBe(['status' => DocumentStatus::REFUSED, 'fiscalization' => null, 'error' => 'Odbijen', 'payment' => null, 'paymentError' => null, 'paymentReports' => 0, 'answersError' => null])
		->and($client->status('2')['status'])->toBe(DocumentStatus::REPORTED)
		->and($client->status('3')['status'])->toBe(DocumentStatus::DELIVERED);
});

it('stands a document it could not process or deliver by the tax authority\'s answer, so one taken or still being answered is never sent twice', function () {
	$answer = fn (int $id, int $status) => [['electronicId' => $id, 'messages' => [['status' => $status, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-22T16:48:12+02:00']]]];
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([['ElectronicId' => 5, 'StatusId' => 50, 'StatusName' => 'Neuspjelo']])
			->push([['ElectronicId' => 6, 'StatusId' => 50, 'StatusName' => 'Neuspjelo']])
			->push([['ElectronicId' => 8, 'StatusId' => 50, 'StatusName' => 'Neuspjelo']])
			->push([['ElectronicId' => 5, 'DocumentNr' => '9/P1/1', 'StatusId' => 50]])
			->push([['ElectronicId' => 8, 'DocumentNr' => '11/P1/1', 'StatusId' => 50]])
			->push([['ElectronicId' => 7, 'DocumentNr' => '10/P1/1', 'StatusId' => 50]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::sequence()
			->push($answer(5, 0))
			->push(['message' => 'Service Unavailable'], 503)
			->push($answer(8, 2))
			->push($answer(5, 0))
			->push($answer(8, 2))
			->push([], 404),
	]);
	$client = app(MojEracunClient::class);
	$since = Carbon::parse('2026-10-22 14:48:09', 'UTC');

	// The tax authority took it: held, its delivery failed
	expect($client->status('5'))->toMatchArray(['status' => DocumentStatus::REJECTED, 'fiscalization' => FiscalizationStatus::FISCALIZED, 'error' => 'Neuspjelo'])
		// Its answers cannot be read, or the tax authority is still answering: followed as sent until they tell
		->and($client->status('6'))->toMatchArray(['status' => DocumentStatus::SENT, 'answersError' => 'HTTP 503: Service Unavailable'])
		->and($client->status('8'))->toMatchArray(['status' => DocumentStatus::SENT, 'fiscalization' => FiscalizationStatus::WAITING])
		// Looked up before sending again: held when the tax authority took it or is still answering, refused when it was
		// never asked
		->and($client->sentDocument('9/P1/1', $since))->toBe(['electronicId' => '5', 'status' => DocumentStatus::REJECTED])
		->and($client->sentDocument('11/P1/1', $since))->toBe(['electronicId' => '8', 'status' => DocumentStatus::SENT])
		->and($client->sentDocument('10/P1/1', $since))->toBe(['electronicId' => '7', 'status' => DocumentStatus::REFUSED])
		->and(DocumentStatus::REJECTED->isHeld())->toBeTrue();
});

it('throws rather than guess when the outbox, or the tax authority\'s answers about a document it could not process, cannot be read, so nothing is sent twice', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push(['message' => 'Service Unavailable'], 503)
			->push([['ElectronicId' => 5, 'DocumentNr' => '9/P1/1', 'StatusId' => 50]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Service Unavailable'], 503),
	]);
	$client = app(MojEracunClient::class);
	$since = Carbon::parse('2026-10-22 14:48:09', 'UTC');

	expect(fn () => $client->sentDocument('9/P1/1', $since))->toThrow(RuntimeException::class, 'HTTP 503: Service Unavailable')
		->and(fn () => $client->sentDocument('9/P1/1', $since))->toThrow(RuntimeException::class, 'HTTP 503: Service Unavailable');
});

it('takes the tax authority answer about the document itself, the latest one, and the one about its payment apart, counting the payment reports, and passes over the answers about other documents', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 40]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response([
			// The demo service answers with made-up documents too, which must not count
			['electronicId' => 1454891830, 'messages' => [['status' => 0, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-22T17:20:03+02:00']]],
			['electronicId' => 3687370, 'channelType' => 0, 'messages' => [
				['status' => 2, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-22T16:48:12+02:00'],
				['status' => 1, 'messageType' => 0, 'errorCode' => 'S005', 'errorCodeDescription' => 'Poruka nije u skladu s XML shemom', 'dateOfFiscalization' => '2026-10-22T16:49:00+02:00'],
				// A payment report's answer is no answer about the document, but about its payment
				['status' => 0, 'messageType' => 3, 'dateOfFiscalization' => '2026-10-22T16:50:00+02:00'],
				['status' => 1, 'messageType' => 3, 'errorCode' => 'S007', 'errorCodeDescription' => 'OIB iz zahtjeva nije formalno ispravan', 'dateOfFiscalization' => '2026-10-22T16:55:00+02:00'],
			]],
		]),
	]);

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => FiscalizationStatus::FAILED, 'error' => 'S005 Poruka nije u skladu s XML shemom', 'payment' => FiscalizationStatus::FAILED, 'paymentError' => 'S007 OIB iz zahtjeva nije formalno ispravan', 'paymentReports' => 2, 'answersError' => null]);
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

it('takes an account whose outbox is empty as proven, and refuses no document over another call refused', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/Ping/' => Http::response('', 200),
		// The service answers an empty outbox only to an account it took
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push(['message' => 'Nema dokumenata.'], 404)
			->push(['message' => 'Neispravan zahtjev.'], 400),
	]);
	$client = app(MojEracunClient::class);

	$client->ping();

	$exception = rescue(fn () => $client->sentDocument('455/P1/1', Carbon::now()), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceRefusedException::class)
		->and($exception->getMessage())->toBe('HTTP 400: Neispravan zahtjev.');
});

it('is not ready without the software identifier the intermediary issued, and sends nothing', function () {
	config(['hrEInvoicing.mojEracun.softwareId' => null]);
	$client = app(MojEracunClient::class);

	expect($client->ready())->toBeFalse()
		->and(fn () => $client->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary account is not set up.');
});
