<?php

use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\Exceptions\PaymentReportRefusedException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\GatewayManager;
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

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => DocumentStatus::SENT, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'paymentReports' => 0, 'answersError' => null, 'rejectionReason' => null, 'fiscalizationError' => null, 'deliveryRetriesUntil' => null, 'accepted' => false]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/queryOutbox' && $request['electronicId'] === 3687370);
});

it('keeps the standing when the tax authority\'s answers cannot be read and says why, so a payment reported is never taken for one not reported', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 40]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Service Unavailable'], 503),
	]);

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'paymentReports' => null, 'answersError' => 'HTTP 503: Service Unavailable', 'rejectionReason' => null, 'fiscalizationError' => null, 'deliveryRetriesUntil' => null, 'accepted' => false]);
});

it('names a refused, a reported and a delivered document, and says the status name of a refused one as the reason', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([['ElectronicId' => 1, 'StatusId' => 50, 'StatusName' => 'Odbijen']])
			->push([['ElectronicId' => 2, 'StatusId' => 70, 'StatusName' => 'Prijavljen']])
			->push([['ElectronicId' => 3, 'StatusId' => 40, 'StatusName' => 'Dostavljen']]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Nije moguće pronaći status poruke o fiskalizaciji.', 'traceId' => 'a2fc9bec'], 404),
	]);
	$client = app(MojEracunClient::class);

	expect($client->status('1'))->toBe(['status' => DocumentStatus::REFUSED, 'fiscalization' => null, 'error' => 'Odbijen', 'payment' => null, 'paymentError' => null, 'paymentReports' => 0, 'answersError' => null, 'rejectionReason' => null, 'fiscalizationError' => null, 'deliveryRetriesUntil' => null, 'accepted' => false])
		->and($client->status('2')['status'])->toBe(DocumentStatus::REPORTED)
		->and($client->status('3')['status'])->toBe(DocumentStatus::DELIVERED);

	// The tax authority is never asked about the one it could not process
	Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'statusOutbox') && $request['electronicId'] === 1);
});

it('reads a document it could not process or deliver as refused and final, whatever the tax authority\'s messages say, so the corrected one goes again under its number', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([['ElectronicId' => 5, 'StatusId' => 50, 'StatusName' => 'Neuspjelo']])
			->push([['ElectronicId' => 6, 'StatusId' => 50, 'StatusName' => 'Neuspjelo']])
			->push([['ElectronicId' => 8, 'StatusId' => 50, 'StatusName' => 'Neuspjelo']])
			->push([['ElectronicId' => 5, 'DocumentNr' => '9/P1/1', 'StatusId' => 50]])
			->push([['ElectronicId' => 8, 'DocumentNr' => '11/P1/1', 'StatusId' => 50]])
			->push([['ElectronicId' => 7, 'DocumentNr' => '10/P1/1', 'StatusId' => 50]]),
		// The demo's mock messages, which would say the tax authority took every document
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => fn (Request $request) => Http::response([['electronicId' => $request['electronicId'], 'messages' => [['status' => 0, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-22T16:48:12+02:00']]]]),
	]);
	$client = app(MojEracunClient::class);
	$since = Carbon::parse('2026-10-22 14:48:09', 'UTC');
	$refused = ['status' => DocumentStatus::REFUSED, 'fiscalization' => null, 'error' => 'Neuspjelo', 'payment' => null, 'paymentError' => null, 'paymentReports' => 0, 'answersError' => null, 'rejectionReason' => null, 'fiscalizationError' => null, 'deliveryRetriesUntil' => null, 'accepted' => false];

	// Never exchanged, so the tax authority never saw it and no report of it exists
	expect($client->status('5'))->toBe($refused)
		->and($client->status('6'))->toBe($refused)
		->and($client->status('8'))->toBe($refused)
		// Looked up before sending again: refused, so the corrected document goes under the same number
		->and($client->sentDocument('9/P1/1', $since))->toBe(['electronicId' => '5', 'status' => DocumentStatus::REFUSED])
		->and($client->sentDocument('11/P1/1', $since))->toBe(['electronicId' => '8', 'status' => DocumentStatus::REFUSED])
		->and($client->sentDocument('10/P1/1', $since))->toBe(['electronicId' => '7', 'status' => DocumentStatus::REFUSED])
		->and(DocumentStatus::REFUSED->isHeld())->toBeFalse();

	Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'statusOutbox'));
});

it('throws rather than guess when the outbox cannot be read, and needs no tax authority answer for a document it could not process', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push(['message' => 'Service Unavailable'], 503)
			->push([['ElectronicId' => 5, 'DocumentNr' => '9/P1/1', 'StatusId' => 50]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Service Unavailable'], 503),
	]);
	$client = app(MojEracunClient::class);
	$since = Carbon::parse('2026-10-22 14:48:09', 'UTC');

	expect(fn () => $client->sentDocument('9/P1/1', $since))->toThrow(RuntimeException::class, 'HTTP 503: Service Unavailable')
		// The tax authority's answers about it cannot be read, and none is needed: a document in 50 is refused
		->and($client->sentDocument('9/P1/1', $since))->toBe(['electronicId' => '5', 'status' => DocumentStatus::REFUSED]);
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

	expect(app(MojEracunClient::class)->status('3687370'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => FiscalizationStatus::FAILED, 'error' => 'S005 Poruka nije u skladu s XML shemom', 'payment' => FiscalizationStatus::FAILED, 'paymentError' => 'S007 OIB iz zahtjeva nije formalno ispravan', 'paymentReports' => 2, 'answersError' => null, 'rejectionReason' => null, 'fiscalizationError' => 'S005 Poruka nije u skladu s XML shemom', 'deliveryRetriesUntil' => null, 'accepted' => false]);
});

it('takes the tax authority\'s latest answer by the moment it was given, not by the text of its date, whose offset changes the night summer time ends', function () {
	$answers = fn (array $messages) => [['electronicId' => 3687370, 'messages' => $messages]];
	$refused = ['status' => 1, 'errorCode' => 'S006', 'errorCodeDescription' => 'Pristupna točka nije ovlaštena za dostavu podataka'];
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 40]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::sequence()
			// Refused at 02:40 summer time, then made again and taken 25 minutes later, at 02:05 winter time
			->push($answers([
				[...$refused, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-25T02:40:00+02:00'],
				['status' => 0, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-25T02:05:00+01:00'],
				[...$refused, 'messageType' => 3, 'dateOfFiscalization' => '2026-10-25T02:40:00+02:00'],
				['status' => 0, 'messageType' => 3, 'dateOfFiscalization' => '2026-10-25T02:05:00+01:00'],
			]))
			// The same moment in either offset goes by the list, and so does an answer whose moment cannot be read
			->push($answers([
				[...$refused, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-25T03:00:00+02:00'],
				['status' => 0, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-25T02:00:00+01:00'],
				[...$refused, 'messageType' => 3, 'dateOfFiscalization' => '2026-10-25T02:40:00+02:00'],
				['status' => 2, 'messageType' => 3, 'dateOfFiscalization' => null],
			])),
	]);
	$client = app(MojEracunClient::class);

	expect($client->status('3687370'))->toMatchArray(['fiscalization' => FiscalizationStatus::FISCALIZED, 'error' => null, 'payment' => FiscalizationStatus::FISCALIZED, 'paymentError' => null, 'paymentReports' => 2])
		->and($client->status('3687370'))->toMatchArray(['fiscalization' => FiscalizationStatus::FISCALIZED, 'error' => null, 'payment' => FiscalizationStatus::WAITING, 'paymentError' => null, 'paymentReports' => 2]);
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
	// Refused on the spot: said as such, for the app to word it as a refusal rather than a line that failed
	expect(fn () => $client->reportPayment('3687370', '2026-10-22', 167.0))
		->toThrow(PaymentReportRefusedException::class, 'HTTP 400: S007 OIB iz zahtjeva nije formalno ispravan (Pogreška prilikom obrade zahtjeva.)');
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

it('finds the service down, never the account refused, when the check of the account gets no answer, an answer cut off on its way or a server error', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/Ping/' => Http::response('', 200),
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->pushFailedConnection('cURL error 28: Operation timed out after 15001 milliseconds')
			->pushResponse(answerCutOff(502, '<title>502 Bad Gate'))
			->push(['message' => 'Service Unavailable'], 503),
	]);
	$client = app(MojEracunClient::class);

	expect(fn () => $client->ping())->toThrow(EInvoiceUnavailableException::class, 'cURL error 28: Operation timed out after 15001 milliseconds')
		->and(fn () => $client->ping())->toThrow(EInvoiceUnavailableException::class, 'HTTP 502: <title>502 Bad Gate')
		->and(fn () => $client->ping())->toThrow(EInvoiceUnavailableException::class, 'HTTP 503');
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

it('reads a 404 as nothing to tell only when it is Moj-eRačun\'s own answer, and fails for any other, never reading the answers or the outbox as empty', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([['ElectronicId' => 3687370, 'StatusId' => 40]])
			->push([['ElectronicId' => 3687370, 'StatusId' => 40]])
			->push('<html><body><h1>404 Not Found</h1></body></html>', 404),
		// A proxy's page, then a route that answers nothing at all
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::sequence()
			->push('<html><body><h1>404 Not Found</h1></body></html>', 404)
			->push('', 404),
	]);
	$client = app(MojEracunClient::class);

	expect($client->status('3687370'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'payment' => null, 'paymentReports' => null, 'answersError' => 'HTTP 404: <html><body><h1>404 Not Found</h1></body></html>'])
		->and($client->status('3687370'))->toMatchArray(['paymentReports' => null, 'answersError' => 'HTTP 404'])
		->and(fn () => $client->sentDocument('455/P1/1', Carbon::now()))->toThrow(RuntimeException::class, 'HTTP 404: <html>');
});

it('fails for a 404 in ASP.NET Core\'s ProblemDetails or in a gateway\'s words, which say nothing of what Moj-eRačun holds', function () {
	$problem = ['type' => 'https://tools.ietf.org/html/rfc9110#section-15.5.5', 'title' => 'Not Found', 'status' => 404, 'traceId' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00'];
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([['ElectronicId' => 3687370, 'StatusId' => 40]])
			->push([['ElectronicId' => 3687370, 'StatusId' => 40]])
			->push($problem, 404)
			->push(['message' => 'Not Found'], 404)
			// A framework's or a gateway's words for a route it lacks, in Moj-eRačun's own shape
			->push(['Message' => "No HTTP resource was found that matches the request URI 'https://demo.moj-eracun.hr/apis/v2/queryOutbox'."], 404)
			->push(['message' => 'no Route matched with those values'], 404),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::sequence()
			->push($problem, 404)
			->push(['message' => 'Not Found'], 404),
	]);
	$client = app(MojEracunClient::class);

	// The tax authority's answers unknown rather than none, the outbox unread rather than empty
	expect($client->status('3687370'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'payment' => null, 'paymentReports' => null, 'answersError' => 'HTTP 404: Not Found'])
		->and($client->status('3687370'))->toMatchArray(['payment' => null, 'paymentReports' => null, 'answersError' => 'HTTP 404: Not Found'])
		->and(fn () => $client->sentDocument('455/P1/1', Carbon::now()))->toThrow(RuntimeException::class, 'HTTP 404: Not Found')
		->and(fn () => $client->sentDocument('455/P1/1', Carbon::now()))->toThrow(RuntimeException::class, 'HTTP 404: Not Found')
		->and(fn () => $client->sentDocument('455/P1/1', Carbon::now()))->toThrow(RuntimeException::class, 'HTTP 404: No HTTP resource was found')
		->and(fn () => $client->sentDocument('455/P1/1', Carbon::now()))->toThrow(RuntimeException::class, 'HTTP 404: no Route matched');
});

it('says which fields it refused without the values it repeats, and never says the account\'s password, however the answer carries it', function () {
	config(['hrEInvoicing.mojEracun.password' => 'ta+jna&č']);
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/send' => Http::sequence()
			// Its field errors repeat the value sent with each
			->push(['Password' => ['Value' => 'ta+jna&č', 'Messages' => ['Neispravna lozinka.']], 'SoftwareId' => ['Value' => 'Test-002', 'Messages' => ['Neispravan SoftwareId.']]], 400)
			->push(['message' => 'Lozinka ta+jna&č nije ispravna.'], 401)
			// Cut off, and written as .NET escapes it: "+", "&" and every letter outside ASCII as \uXXXX
			->push('{"message":"Lozinka ta\u002Bjna\u0026\u010D nije isprav', 500)
			// A page, written as ASP.NET Core's encoder writes "+" and every letter outside ASCII: as character references
			->push('<html><body>Unauthorized: ta&#x2B;jna&amp;&#x10D;</body></html>', 401),
	]);
	$client = app(MojEracunClient::class);

	$messages = array_map(fn () => rescue(fn () => $client->send('<Invoice/>'), fn (Throwable $exception) => $exception->getMessage(), false), range(1, 4));

	expect($messages)->toBe([
		'HTTP 400: Password: Neispravna lozinka.; SoftwareId: Neispravan SoftwareId.',
		'HTTP 401: Lozinka [redacted] nije ispravna.',
		'HTTP 500: {"message":"Lozinka [redacted] nije isprav',
		'HTTP 401: <html><body>Unauthorized: [redacted]</body></html>',
	]);
});

it('takes an answer cut off on its way for one that never came: the tax authority\'s answers unknown, no document refused, and the service down when the connection is tested', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3687370, 'StatusId' => 40]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => answerCutOff(503, '{"message":"Service Unav'),
		'https://demo.moj-eracun.hr/apis/v2/send' => answerCutOff(400, '{"File":{"Messages":["[HR-BR-37] - Račun mora'),
		'https://demo.moj-eracun.hr/apis/v2/Ping/' => answerCutOff(502, '<title>502 Bad Gate'),
	]);
	$client = app(MojEracunClient::class);

	$sent = rescue(fn () => $client->send('<Invoice/>'), fn (Throwable $exception) => $exception, false);

	expect($sent)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceRefusedException::class)
		->and($client->status('3687370'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'paymentReports' => null, 'answersError' => 'HTTP 503: {"message":"Service Unav'])
		->and(fn () => $client->ping())->toThrow(EInvoiceUnavailableException::class, 'HTTP 502: <title>502 Bad Gate');
});

it('follows a document up with a tenant\'s one account only on the service that account is on, and calls nowhere without one', function () {
	config(['hrEInvoicing.allowProductionAnywhere' => true]);
	// The README's TenantMojEracunAccount: one account, on the service it names, with none kept per service
	app()->instance(AccountProvider::class, new class implements AccountProvider
	{
		public function account(): ?array
		{
			return ['username' => 'klinika', 'password' => 'tajna', 'companyId' => '12345678903', 'companyBU' => null, 'softwareId' => 'Test-002', 'environment' => 'production'];
		}
	});
	Http::fake([
		'https://www.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 81, 'StatusId' => 40]]),
		'https://www.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Nema dokumenata.'], 404),
	]);
	$manager = app(GatewayManager::class);
	$production = $manager->driver('mojeracun', 'production');
	$demo = $manager->driver('mojeracun', 'demo');

	expect($production->ready())->toBeTrue()
		->and($production->status('81')['status'])->toBe(DocumentStatus::DELIVERED)
		->and($demo->ready())->toBeFalse()
		->and(fn () => $demo->status('81'))->toThrow(RuntimeException::class, 'The intermediary account is not set up.');

	Http::assertSent(fn (Request $request) => $request->url() === 'https://www.moj-eracun.hr/apis/v2/queryOutbox' && $request['username'] === 'klinika');
	Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://demo.moj-eracun.hr/'));

	// An account that names no service is on the one the client is configured for, the demo service here
	app()->instance(AccountProvider::class, new class implements AccountProvider
	{
		public function account(): ?array
		{
			return ['username' => 'klinika', 'password' => 'tajna', 'companyId' => '12345678903', 'companyBU' => null, 'softwareId' => 'Test-002'];
		}
	});

	expect(app(MojEracunClient::class)->onEnvironment('demo')->ready())->toBeTrue()
		->and(app(MojEracunClient::class)->onEnvironment('production')->ready())->toBeFalse();
});

it('reads the buyer\'s rejection of a delivered document from its process status, with the buyer\'s reason, and an approval or a payment as accepted', function () {
	// The process status as the demo answered it on 2026-10-30, once the buyer downloaded the document and rejected it
	$process = fn (?int $status, ?string $reason = null) => [[
		'ElectronicId' => 3707285, 'DocumentNr' => '799451/P1/1', 'StatusId' => 40, 'StatusName' => 'Preuzet',
		'DocumentProcessStatusId' => $status, 'DocumentProcessStatusName' => $status === 1 ? 'Odbijen' : null, 'RejectReason' => $reason, 'OutboundFiscalizationStatus' => null,
	]];
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3707285, 'StatusId' => 40, 'StatusName' => 'Preuzet']]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response([['electronicId' => 3707285, 'messages' => [['status' => 0, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-30T18:26:00+01:00']]]]),
		'https://demo.moj-eracun.hr/apis/v2/queryDocumentProcessStatusOutbox' => Http::sequence()
			->push($process(1, 'Pogrešna poslovna jedinica'))
			->push($process(0))
			->push($process(2))
			->push($process(4))
			->push($process(null)),
	]);
	$client = app(MojEracunClient::class);

	// Rejected after the tax authority took it: the buyer's reason kept, the fiscalization as it stands
	expect($client->status('3707285'))->toMatchArray(['status' => DocumentStatus::REJECTED, 'fiscalization' => FiscalizationStatus::FISCALIZED, 'error' => 'Pogrešna poslovna jedinica', 'rejectionReason' => 'Pogrešna poslovna jedinica', 'fiscalizationError' => null, 'accepted' => false])
		// Approved, paid: accepted; downloaded, or nothing yet: neither
		->and($client->status('3707285'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'accepted' => true])
		->and($client->status('3707285'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'accepted' => true])
		->and($client->status('3707285'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'accepted' => false, 'rejectionReason' => null])
		->and($client->status('3707285'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'accepted' => false, 'rejectionReason' => null]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://demo.moj-eracun.hr/apis/v2/queryDocumentProcessStatusOutbox' && $request['electronicId'] === 3707285 && $request['username'] === '10001');
});

it('takes the tax authority\'s record of the buyer\'s rejection for one, as Moj-eRačun advised, with the document\'s business reason', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::response([['ElectronicId' => 3707285, 'StatusId' => 30, 'StatusName' => 'Poslan']]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response([['electronicId' => 3707285, 'businessStatusReason' => 'Iznos nije ugovoren', 'messages' => [
			['status' => 0, 'messageType' => 0, 'dateOfFiscalization' => '2026-10-30T18:26:00+01:00'],
			['status' => 0, 'messageType' => 2, 'dateOfFiscalization' => '2026-10-30T18:29:50+01:00'],
		]]]),
	]);

	expect(app(MojEracunClient::class)->status('3707285'))->toMatchArray(['status' => DocumentStatus::REJECTED, 'fiscalization' => FiscalizationStatus::FISCALIZED, 'error' => 'Iznos nije ugovoren', 'rejectionReason' => 'Iznos nije ugovoren', 'accepted' => false]);
});

it('asks the process status only of a delivered document, and leaves the buyer\'s verdict to the next read when it cannot be read', function () {
	Http::fake([
		'https://demo.moj-eracun.hr/apis/v2/queryOutbox' => Http::sequence()
			->push([['ElectronicId' => 1, 'StatusId' => 30]])
			->push([['ElectronicId' => 2, 'StatusId' => 70]])
			->push([['ElectronicId' => 3, 'StatusId' => 40]]),
		'https://demo.moj-eracun.hr/api/fiscalization/statusOutbox' => Http::response(['message' => 'Nije moguće pronaći status poruke o fiskalizaciji.', 'traceId' => 'a2fc9bec'], 404),
		'https://demo.moj-eracun.hr/apis/v2/queryDocumentProcessStatusOutbox' => Http::response(['message' => 'Service Unavailable'], 503),
	]);
	$client = app(MojEracunClient::class);

	expect($client->status('1')['status'])->toBe(DocumentStatus::SENT)
		->and($client->status('2')['status'])->toBe(DocumentStatus::REPORTED)
		->and($client->status('3'))->toMatchArray(['status' => DocumentStatus::DELIVERED, 'answersError' => null, 'rejectionReason' => null, 'accepted' => false]);

	Http::assertSentCount(7);
	Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'queryDocumentProcessStatusOutbox') && $request['electronicId'] !== 3);
});
