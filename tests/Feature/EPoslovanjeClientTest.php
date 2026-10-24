<?php

use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\EPoslovanjeClient;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The query of a GET request, which the HTTP client keeps in the URL.
 *
 * @return array<string, string>
 */
function queryOf(Request $request): array
{
	parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

	return $query;
}

beforeEach(function () {
	config(['hrEInvoicing.ePoslovanje' => [
		'environment' => 'demo',
		'apiKey' => '1234567890abcdef1234567890abcdef',
		'oib' => '12345678903',
		'softwareId' => 'EMTOO',
	]]);

	Http::preventStrayRequests();
});

it('sends the document with the key in the header and the software identifier, and keeps its identifier', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/send' => Http::response(['id' => 12345, 'insertedOn' => '2026-10-24T12:00:00.123+02:00', 'message' => 'Dokument uspješno zaprimljen i digitalno potpisan. Čeka fiskalizaciju izlaznog računa i slanje primatelju.']),
	]);

	expect(app(EPoslovanjeClient::class)->send('<Invoice/>'))->toBe(['electronicId' => '12345', 'status' => DocumentStatus::SENT]);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://test.eposlovanje.hr/api/v2/document/send'
		&& $request->hasHeader('Authorization', '1234567890abcdef1234567890abcdef')
		&& $request->data() === ['document' => '<Invoice/>', 'softwareId' => 'EMTOO']);
});

it('says why a document was refused, a refusal no new try changes, unlike an answer that never came', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/send' => Http::sequence()
			->push(['error' => 'Dokument nije zaprimljen', 'details' => 'XML ne prolazi validaciju: [HR-BR-37] Račun mora sadržavati oznaku operatera'], 400)
			->push(['error' => 'Service Unavailable'], 503)
			->push('', 200),
	]);
	$client = app(EPoslovanjeClient::class);

	expect(fn () => $client->send('<Invoice/>'))
		->toThrow(EInvoiceRefusedException::class, 'HTTP 400: Dokument nije zaprimljen (XML ne prolazi validaciju: [HR-BR-37] Račun mora sadržavati oznaku operatera)');

	$exception = rescue(fn () => $client->send('<Invoice/>'), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceRefusedException::class)
		->and($exception->getMessage())->toBe('HTTP 503: Service Unavailable')
		->and(fn () => $client->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary answered without a document identifier.');
});

it('finds a document sent before among the outgoing ones by its number, the newest of that number, and nothing for one it does not hold', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/outgoing*' => Http::response([
			['id' => 12346, 'documentId' => '456/P1/1', 'status' => 3],
			['id' => 12345, 'documentId' => '455/P1/1', 'status' => 9],
			['id' => 12350, 'documentId' => '455/P1/1', 'status' => 2],
		]),
	]);
	$client = app(EPoslovanjeClient::class);
	$since = Carbon::parse('2026-10-22 14:48:09', 'UTC');

	expect($client->sentDocument('455/P1/1', $since))->toBe(['electronicId' => '12350', 'status' => DocumentStatus::SENT])
		->and($client->sentDocument('457/P1/1', $since))->toBeNull();

	Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://test.eposlovanje.hr/api/v2/document/outgoing?')
		&& queryOf($request) === ['insertedFrom' => '2026-10-21T14:48:09+00:00', 'limit' => '1000']);
});

it('reads the document standing and the tax authority answer off the status answer', function () {
	$request = fn (array $fields) => ['type' => 1, 'description' => 'Evidencija izlaznog računa', ...$fields];
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/status/1' => Http::response(['id' => 1, 'documentId' => '455/P1/1', 'status' => 4, 'eReportingRequests' => [
			$request(['createdOn' => '2026-10-24T12:00:00+02:00', 'success' => true, 'completedOn' => '2026-10-24T12:00:05+02:00', 'referenceId' => 'af2cd-1234']),
		]]),
		'https://test.eposlovanje.hr/api/v2/document/status/2' => Http::response(['id' => 2, 'status' => 3, 'eReportingRequests' => [
			// The latest fiscalization request counts; a payment report's does not
			$request(['createdOn' => '2026-10-24T12:00:00+02:00', 'success' => false, 'errorCode' => 'S005', 'errorDescription' => 'Poruka nije u skladu s XML shemom']),
			$request(['createdOn' => '2026-10-24T11:00:00+02:00', 'success' => true, 'completedOn' => '2026-10-24T11:00:05+02:00']),
			['type' => 4, 'description' => 'Evidencija naplate računa', 'createdOn' => '2026-10-24T13:00:00+02:00', 'success' => true],
		]]),
		'https://test.eposlovanje.hr/api/v2/document/status/3' => Http::response(['id' => 3, 'status' => 2, 'eReportingRequests' => [$request(['createdOn' => '2026-10-24T12:00:00+02:00'])]]),
		'https://test.eposlovanje.hr/api/v2/document/status/4' => Http::response(['id' => 4, 'status' => 1, 'eReportingRequests' => []]),
		'https://test.eposlovanje.hr/api/v2/document/status/5' => Http::response(['id' => 5, 'status' => 6, 'statusHistory' => [
			['createdOn' => '2026-10-24T12:00:00+02:00', 'status' => 4, 'statusText' => 'Zaprimljen od strane primatelja'],
			['createdOn' => '2026-10-24T12:30:00+02:00', 'status' => 6, 'statusText' => 'Odbijen', 'note' => 'Odbijen jer iznos nije ispravan'],
		], 'eReportingRequests' => [$request(['createdOn' => '2026-10-24T12:00:00+02:00', 'success' => true, 'completedOn' => '2026-10-24T12:00:05+02:00'])]]),
		'https://test.eposlovanje.hr/api/v2/document/status/6' => Http::response(['id' => 6, 'status' => 9, 'statusHistory' => [
			['createdOn' => '2026-10-24T12:30:00+02:00', 'status' => 9, 'statusText' => 'Neuspješna isporuka'],
		]]),
		'https://test.eposlovanje.hr/api/v2/document/status/7' => Http::response(['id' => 7, 'status' => 7, 'eReportingRequests' => []]),
		'https://test.eposlovanje.hr/api/v2/document/status/8' => Http::response(['error' => 'Status dokumenta nije moguće dohvatiti', 'details' => 'Dokument sa ID-em ne pripada korisniku'], 400),
	]);
	$client = app(EPoslovanjeClient::class);

	expect($client->status('1'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => FiscalizationStatus::FISCALIZED, 'error' => null])
		->and($client->status('2'))->toBe(['status' => DocumentStatus::SENT, 'fiscalization' => FiscalizationStatus::FAILED, 'error' => 'S005 Poruka nije u skladu s XML shemom'])
		->and($client->status('3'))->toBe(['status' => DocumentStatus::SENT, 'fiscalization' => FiscalizationStatus::WAITING, 'error' => null])
		->and($client->status('4'))->toBe(['status' => DocumentStatus::SENT, 'fiscalization' => null, 'error' => null])
		->and($client->status('5'))->toBe(['status' => DocumentStatus::REJECTED, 'fiscalization' => FiscalizationStatus::FISCALIZED, 'error' => 'Odbijen jer iznos nije ispravan'])
		->and($client->status('6'))->toBe(['status' => DocumentStatus::REJECTED, 'fiscalization' => null, 'error' => 'Neuspješna isporuka'])
		->and($client->status('7')['status'])->toBe(DocumentStatus::DELIVERED)
		->and(fn () => $client->status('8'))->toThrow(RuntimeException::class, 'HTTP 400: Status dokumenta nije moguće dohvatiti (Dokument sa ID-em ne pripada korisniku)');
});

it('reports a payment by bank transfer, and says the reason when the intermediary refuses one', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/ereporting/paid/12345' => Http::sequence()
			->push('', 200)
			->push(['error' => 'Greška u parametrima zahtjeva', 'details' => 'Dokument nije fiskaliziran kao izlazni dokument'], 400),
	]);
	$client = app(EPoslovanjeClient::class);

	$client->reportPayment('12345', '2026-10-22', 167.0);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://test.eposlovanje.hr/api/v2/ereporting/paid/12345'
		&& $request->data() === ['paymentDate' => '2026-10-22', 'paidAmount' => 167.0, 'paymentType' => 1]);
	expect(fn () => $client->reportPayment('12345', '2026-10-22', 167.0))
		->toThrow(RuntimeException::class, 'HTTP 400: Greška u parametrima zahtjeva (Dokument nije fiskaliziran kao izlazni dokument)');
});

it('tells a service that is down from a key it refuses when the connection is tested', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/ping' => Http::sequence()
			->push('<title>502 Bad Gateway</title>', 502)
			->push(['error' => 'Greška pri autentifikaciji korisnika', 'details' => 'Korisnički račun nije aktiviran'], 400)
			->push(['status' => 'OK', 'message' => 'Svi servisi su pokrenuti i ispravno rade...']),
	]);
	$client = app(EPoslovanjeClient::class);

	expect(fn () => $client->ping())->toThrow(EInvoiceUnavailableException::class, 'HTTP 502');

	$exception = rescue(fn () => $client->ping(), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceUnavailableException::class)
		->and($exception->getMessage())->toBe('HTTP 400: Greška pri autentifikaciji korisnika (Korisnički račun nije aktiviran)');

	$client->ping();

	Http::assertSentCount(3);
});

it('is not ready without the key, the OIB or the software identifier, and sends nothing', function () {
	config(['hrEInvoicing.ePoslovanje.apiKey' => null]);
	$client = app(EPoslovanjeClient::class);

	expect($client->ready())->toBeFalse()
		->and(fn () => $client->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary account is not set up.')
		->and(fn () => $client->ping())->toThrow(RuntimeException::class, 'The intermediary account is not set up.');

	config(['hrEInvoicing.ePoslovanje.apiKey' => 'k', 'hrEInvoicing.ePoslovanje.softwareId' => null]);

	expect(app(EPoslovanjeClient::class)->ready())->toBeFalse();
});

it('refuses the production server outside a production installation, sends to it when allowed, and lets the account pick its own environment', function () {
	config(['hrEInvoicing.ePoslovanje.environment' => 'production']);
	Http::fake([
		'https://eracun.eposlovanje.hr/api/v2/document/send' => Http::response(['id' => 1, 'insertedOn' => '2026-10-24T12:00:00+02:00', 'message' => 'OK']),
		'https://test.eposlovanje.hr/api/v2/document/send' => Http::response(['id' => 2, 'insertedOn' => '2026-10-24T12:00:00+02:00', 'message' => 'OK']),
	]);

	expect(fn () => app(EPoslovanjeClient::class)->send('<Invoice/>'))
		->toThrow(RuntimeException::class, 'The intermediary\'s production service is refused outside a production installation.');

	Http::assertNothingSent();

	config(['hrEInvoicing.allowProductionAnywhere' => true]);

	expect(app(EPoslovanjeClient::class)->send('<Invoice/>')['electronicId'])->toBe('1');

	// A tenant still on the test server while the installation's default is production
	app()->instance(AccountProvider::class, new class implements AccountProvider
	{
		public function account(): ?array
		{
			return ['apiKey' => 'test-key', 'oib' => '12345678903', 'softwareId' => 'EMTOO', 'environment' => 'demo'];
		}
	});

	expect(app(EPoslovanjeClient::class)->send('<Invoice/>')['electronicId'])->toBe('2');

	Http::assertSent(fn (Request $request) => $request->url() === 'https://test.eposlovanje.hr/api/v2/document/send' && $request->hasHeader('Authorization', 'test-key'));
});
