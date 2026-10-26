<?php

use GTCrais\HrEInvoicing\Contracts\AccountsByEnvironment;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\EPoslovanje\ConfigAccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\EPoslovanjeClient;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\GatewayManager;
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

/**
 * The list of outgoing documents as the server pages it: newest first, from the offset asked for, never more than its
 * own page size whatever the limit asked for.
 *
 * @param  list<array<string, mixed>>  $documents
 */
function outgoingListing(array $documents, int $serverPageSize = 1000): Closure
{
	return function (Request $request) use ($documents, $serverPageSize) {
		$query = queryOf($request);

		return Http::response(array_slice($documents, (int) ($query['offset'] ?? 0), min((int) ($query['limit'] ?? 1000), $serverPageSize)));
	};
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

it('says why a document was refused, a refusal no new try changes, unlike an answer that never came or an account to put right', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/send' => Http::sequence()
			// The test server's own words of 2026-10-24
			->push(['error' => 'Greška pri validaciji dokumenta', 'details' => '[HR-BR-25] - Stavka 1: Svaki artikl MORA imati identifikator klasifikacije artikla'], 400)
			// Words never seen before are the document's: its refusal waits for a person, never sent again until it passes
			->push(['error' => 'Dokument nije zaprimljen', 'details' => 'Primatelj nije registriran u AMS-u'], 400)
			->push(['error' => 'Service Unavailable'], 503)
			// Refusals of the account, not of the document, in the API description's words: they pass once it is put right
			->push(['error' => 'Dokument nije zaprimljen', 'details' => 'Nema dovoljno sredstava na računu'], 400)
			->push(['error' => 'Greška pri autentifikaciji korisnika', 'details' => 'Korisnički račun nije aktiviran'], 400)
			->push('', 200),
	]);
	$client = app(EPoslovanjeClient::class);

	expect(fn () => $client->send('<Invoice/>'))
		->toThrow(EInvoiceRefusedException::class, 'HTTP 400: Greška pri validaciji dokumenta ([HR-BR-25] - Stavka 1: Svaki artikl MORA imati identifikator klasifikacije artikla)')
		->and(fn () => $client->send('<Invoice/>'))
		->toThrow(EInvoiceRefusedException::class, 'HTTP 400: Dokument nije zaprimljen (Primatelj nije registriran u AMS-u)');

	foreach (['HTTP 503: Service Unavailable', 'HTTP 400: Dokument nije zaprimljen (Nema dovoljno sredstava na računu)', 'HTTP 400: Greška pri autentifikaciji korisnika (Korisnički račun nije aktiviran)'] as $message) {
		$exception = rescue(fn () => $client->send('<Invoice/>'), fn (Throwable $exception) => $exception, false);

		expect($exception)->toBeInstanceOf(RuntimeException::class)
			->not->toBeInstanceOf(EInvoiceRefusedException::class)
			->and($exception->getMessage())->toBe($message);
	}

	expect(fn () => $client->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary answered without a document identifier.');
});

it('reads a 400 as the account\'s only when its words name the account, and its fields whatever their shape', function () {
	// About the recipient or the buyer, whatever state of an account it names, or the document's means of payment
	$documentWords = ['Primatelj nije aktivan u AMS-u', 'Korisnik 12345678901 nije aktivan u AMS-u', 'Primatelj nije autoriziran za primanje eRačuna', 'Korisnički račun kupca je suspendiran', 'Recipient account is blocked', 'Nedostaje ovlaštena osoba', 'Nema sredstva plaćanja'];
	// The caller's own words first, whatever else is named, and the funds always the clinic's
	$accountWords = ['Korisnički račun je deaktiviran', 'Korisnički račun je blokiran', 'Sredstva na računu su potrošena', 'Nedostatna sredstva na računu', 'Nema dovoljno sredstava za slanje primatelju', 'Pretplata je istekla', 'Greška pri autentifikaciji prema AMS servisu', 'Not authorized', 'Insufficient funds', 'Account is inactive'];
	$sequence = Http::sequence();

	foreach ([...$documentWords, ...$accountWords] as $words) {
		$sequence->push(['error' => 'Dokument nije zaprimljen', 'details' => $words], 400);
	}

	$sequence->push(['error' => 'Dokument nije zaprimljen', 'details' => ['primatelj' => 'Nije registriran u AMS-u']], 400);
	$sequence->push(['error' => 'Dokument nije zaprimljen', 'details' => ['apiKey' => 'Neispravan format datuma']], 400);
	Http::fake(['https://test.eposlovanje.hr/api/v2/document/send' => $sequence]);
	$client = app(EPoslovanjeClient::class);

	foreach ($documentWords as $words) {
		expect(fn () => $client->send('<Invoice/>'))->toThrow(EInvoiceRefusedException::class, $words);
	}

	foreach ($accountWords as $words) {
		$exception = rescue(fn () => $client->send('<Invoice/>'), fn (Throwable $exception) => $exception, false);

		expect($exception)->toBeInstanceOf(RuntimeException::class)
			->not->toBeInstanceOf(EInvoiceRefusedException::class)
			->and($exception->getMessage())->toContain($words);
	}

	// Details given as an object are read as their text, never cast to "Array"
	expect(fn () => $client->send('<Invoice/>'))->toThrow(EInvoiceRefusedException::class, 'HTTP 400: Dokument nije zaprimljen ({"primatelj":"Nije registriran u AMS-u"})')
		// The keys of the details are never weighed: an "apiKey" field names no refusal of the key
		->and(fn () => $client->send('<Invoice/>'))->toThrow(EInvoiceRefusedException::class, 'Neispravan format datuma');
});

it('finds a document sent before among the outgoing ones by its number, the newest of that number, and nothing for one it does not hold', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/outgoing*' => outgoingListing([
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
		&& queryOf($request) === ['insertedFrom' => '2026-10-21T14:48:09+00:00', 'limit' => '1000', 'offset' => '0']);
});

it('tells a number of one year from the same number of the next by the day of issue', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/outgoing*' => outgoingListing([
			['id' => 22001, 'documentId' => '7/WEB1/1', 'status' => 3, 'issuedOn' => '2027-01-05T08:00:00.000+01:00'],
			['id' => 21900, 'documentId' => '7/WEB1/1', 'status' => 2, 'issuedOn' => '2026-12-30T10:15:00.000+01:00'],
		]),
	]);
	$client = app(EPoslovanjeClient::class);
	$since = Carbon::parse('2026-12-30 09:15:00', 'UTC');

	expect($client->sentDocument('7/WEB1/1', $since, '2026-12-30'))->toBe(['electronicId' => '21900', 'status' => DocumentStatus::SENT])
		->and($client->sentDocument('7/WEB1/1', $since, '2026-12-29'))->toBeNull()
		->and($client->sentDocument('7/WEB1/1', $since)['electronicId'])->toBe('22001');
});

it('pages through the outgoing documents until it finds the number or the list ends', function () {
	$documents = [...array_map(fn (int $id) => ['id' => $id, 'documentId' => $id . '/WEB1/1', 'status' => 4], range(5999, 5000)), ['id' => 3999, 'documentId' => '7/WEB1/1', 'status' => 3]];
	Http::fake(['https://test.eposlovanje.hr/api/v2/document/outgoing*' => outgoingListing($documents)]);

	expect(app(EPoslovanjeClient::class)->sentDocument('7/WEB1/1', Carbon::parse('2026-10-22 14:48:09', 'UTC')))->toBe(['electronicId' => '3999', 'status' => DocumentStatus::SENT]);

	Http::assertSent(fn (Request $request) => (queryOf($request)['offset'] ?? null) === '1000');
});

it('reads on past pages shorter than asked for, and throws for a list that answers the same page again', function () {
	$documents = [...array_map(fn (int $id) => ['id' => $id, 'documentId' => $id . '/WEB1/1', 'status' => 4], range(5249, 5000)), ['id' => 3999, 'documentId' => '7/WEB1/1', 'status' => 3]];
	$since = Carbon::parse('2026-10-22 14:48:09', 'UTC');

	// A server that answers 100 at a time: the document on its third page is found, one it does not hold is not; then
	// one that ignores the offset and gives the same page every time, which can never say the document is not there
	$ignoresOffset = false;
	$listing = outgoingListing($documents, 100);
	Http::fake(['https://test.eposlovanje.hr/api/v2/document/outgoing*' => function (Request $request) use (&$ignoresOffset, $listing, $documents) {
		return $ignoresOffset ? Http::response(array_slice($documents, 0, 100)) : $listing($request);
	}]);
	$client = app(EPoslovanjeClient::class);

	expect($client->sentDocument('7/WEB1/1', $since)['electronicId'])->toBe('3999')
		->and($client->sentDocument('8/WEB1/1', $since))->toBeNull();

	Http::assertSent(fn (Request $request) => (queryOf($request)['offset'] ?? null) === '200');

	$ignoresOffset = true;

	expect(fn () => $client->sentDocument('8/WEB1/1', $since))->toThrow(RuntimeException::class, 'answers the same page again');
});

it('gives up on a list of outgoing documents that never ends rather than read on for ever', function () {
	// A new page at every offset, one document each, never the one looked for
	Http::fake(['https://test.eposlovanje.hr/api/v2/document/outgoing*' => fn (Request $request) => Http::response([
		['id' => 9000000 - (int) (queryOf($request)['offset'] ?? 0), 'documentId' => 'other/WEB1/1', 'status' => 4],
	])]);

	expect(fn () => app(EPoslovanjeClient::class)->sentDocument('8/WEB1/1', Carbon::parse('2026-10-22 14:48:09', 'UTC')))
		->toThrow(RuntimeException::class, 'runs past 1000 pages');
});

it('gives up on a list of outgoing documents it cannot read through in its time, so the lock it is looked up under outlasts it', function () {
	// Every page takes the server twenty seconds
	Http::fake(['https://test.eposlovanje.hr/api/v2/document/outgoing*' => function (Request $request) {
		Carbon::setTestNow(Carbon::now()->addSeconds(20));

		return Http::response([['id' => 9000000 - (int) (queryOf($request)['offset'] ?? 0), 'documentId' => 'other/WEB1/1', 'status' => 4]]);
	}]);

	expect(fn () => app(EPoslovanjeClient::class)->sentDocument('8/WEB1/1', Carbon::parse('2026-10-22 14:48:09', 'UTC')))
		->toThrow(RuntimeException::class, 'could not be read through in 60 seconds');

	// Three pages read in the minute, the fourth not asked for
	Http::assertSentCount(3);
	Carbon::setTestNow();
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
		// The test server's answer of 2026-10-24: a refused request is stamped completed too
		'https://test.eposlovanje.hr/api/v2/document/status/229872' => Http::response([
			'id' => 229872, 'insertedOn' => '2026-10-24T20:05:59.5', 'modifiedOn' => '2026-10-24T20:12:02.497', 'documentId' => '5/WEB1/1',
			'documentType' => 'Invoice', 'issuedOn' => '2026-10-24T20:05:59', 'amount' => 129.00, 'currency' => 'EUR',
			'supplierPartyName' => 'BlueM2 Consulting j.d.o.o.', 'supplierPartyVATId' => '25038668428', 'customerPartyName' => 'Test d.o.o.', 'customerPartyVATId' => '50930104221',
			'status' => 4,
			'statusHistory' => [
				['createdOn' => '2026-10-24T20:05:59.5', 'status' => 1, 'statusText' => 'Zaprimljeno u sustav, čeka validaciju'],
				['createdOn' => '2026-10-24T20:05:59.525', 'status' => 2, 'statusText' => 'Validacija završena, čeka slanje'],
				['createdOn' => '2026-10-24T20:12:01.62', 'status' => 3, 'statusText' => 'Poslano inf. posredniku primatelja', 'note' => ''],
				['createdOn' => '2026-10-24T20:12:02.277', 'status' => 4, 'statusText' => 'Primatelj preuzeo', 'note' => ''],
			],
			'eReportingRequests' => [
				['type' => 1, 'description' => 'Evidencija izlaznog računa', 'createdOn' => '2026-10-24T20:12:02.277', 'success' => false, 'errorCode' => 'S006',
					'errorDescription' => 'Pristupna točka nije ovlaštena za dostavu podataka', 'completedOn' => '2026-10-24T20:12:02.497'],
			],
		]),
		'https://test.eposlovanje.hr/api/v2/document/status/10' => Http::response(['id' => 10, 'status' => 4, 'eReportingRequests' => [
			$request(['createdOn' => '2026-10-24T12:00:00+02:00', 'success' => false, 'completedOn' => '2026-10-24T12:00:05+02:00']),
		]]),
		// The test server's payment report of 2026-10-24, refused by the tax authority after the call was taken
		'https://test.eposlovanje.hr/api/v2/document/status/11' => Http::response(['id' => 11, 'status' => 4, 'eReportingRequests' => [
			$request(['createdOn' => '2026-10-24T20:12:02.277', 'success' => false, 'errorCode' => 'S006', 'errorDescription' => 'Pristupna točka nije ovlaštena za dostavu podataka', 'completedOn' => '2026-10-24T20:12:02.497']),
			['type' => 4, 'description' => 'Evidencija naplate računa', 'createdOn' => '2026-10-24T20:18:19.1', 'success' => false, 'errorCode' => 'S006', 'errorDescription' => 'Pristupna točka nije ovlaštena za dostavu podataka', 'completedOn' => '2026-10-24T20:18:19.197'],
		]]),
	]);
	$client = app(EPoslovanjeClient::class);

	$answer = fn (DocumentStatus $status, ?FiscalizationStatus $fiscalization, ?string $error, ?FiscalizationStatus $payment = null, ?string $paymentError = null) => [...compact('status', 'fiscalization', 'error', 'payment', 'paymentError'), 'answersError' => null];

	expect($client->status('1'))->toBe($answer(DocumentStatus::DELIVERED, FiscalizationStatus::FISCALIZED, null))
		// A fiscalization taken stays taken, a refused duplicate after it changes nothing; the payment report is apart
		->and($client->status('2'))->toBe($answer(DocumentStatus::SENT, FiscalizationStatus::FISCALIZED, null, FiscalizationStatus::FISCALIZED))
		->and($client->status('3'))->toBe($answer(DocumentStatus::SENT, FiscalizationStatus::WAITING, null))
		->and($client->status('4'))->toBe($answer(DocumentStatus::SENT, null, null))
		->and($client->status('5'))->toBe($answer(DocumentStatus::REJECTED, FiscalizationStatus::FISCALIZED, 'Odbijen jer iznos nije ispravan'))
		->and($client->status('6'))->toBe($answer(DocumentStatus::REJECTED, null, 'Neuspješna isporuka'))
		->and($client->status('7')['status'])->toBe(DocumentStatus::DELIVERED)
		->and(fn () => $client->status('8'))->toThrow(RuntimeException::class, 'HTTP 400: Status dokumenta nije moguće dohvatiti (Dokument sa ID-em ne pripada korisniku)')
		->and($client->status('229872'))->toBe($answer(DocumentStatus::DELIVERED, FiscalizationStatus::FAILED, 'S006 Pristupna točka nije ovlaštena za dostavu podataka'))
		// No reason given: none made up, the app words it
		->and($client->status('10'))->toBe($answer(DocumentStatus::DELIVERED, FiscalizationStatus::FAILED, null))
		->and($client->status('11'))->toBe($answer(DocumentStatus::DELIVERED, FiscalizationStatus::FAILED, 'S006 Pristupna točka nije ovlaštena za dostavu podataka', FiscalizationStatus::FAILED, 'S006 Pristupna točka nije ovlaštena za dostavu podataka'));
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

it('tells a service that is down from a key it refuses when the connection is tested, and an account that fiscalizes nothing', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/ping' => Http::sequence()
			->push('<title>502 Bad Gateway</title>', 502)
			->push(['error' => 'Greška pri autentifikaciji korisnika', 'details' => 'Korisnički račun nije aktiviran'], 400)
			->push(['status' => 'OK', 'message' => 'Svi servisi su pokrenuti i ispravno rade...'])
			->push(['status' => 'OK', 'message' => 'Svi servisi su pokrenuti i ispravno rade...']),
		// The keys as the test server wrote them on 2026-10-24
		'https://test.eposlovanje.hr/api/v2/account/f2settings' => Http::sequence()
			->push(['AutomaticFiscalizeOutgoingInvoices' => true, 'AutomaticFiscalizeIncomingInvoices' => true])
			->push(['AutomaticFiscalizeOutgoingInvoices' => false, 'AutomaticFiscalizeIncomingInvoices' => true]),
	]);
	$client = app(EPoslovanjeClient::class);

	expect(fn () => $client->ping())->toThrow(EInvoiceUnavailableException::class, 'HTTP 502');

	$exception = rescue(fn () => $client->ping(), fn (Throwable $exception) => $exception, false);

	expect($exception)->toBeInstanceOf(RuntimeException::class)
		->not->toBeInstanceOf(EInvoiceUnavailableException::class)
		->and($exception->getMessage())->toBe('HTTP 400: Greška pri autentifikaciji korisnika (Korisnički račun nije aktiviran)');

	$client->ping();

	expect(fn () => $client->ping())->toThrow(RuntimeException::class, 'switch on automatic fiscalization of outgoing documents');

	Http::assertSentCount(6);
});

it('asks for a document to be fiscalized again', function () {
	Http::fake(['https://test.eposlovanje.hr/api/v2/ereporting/fiscalize/229872' => Http::response('', 200)]);

	app(EPoslovanjeClient::class)->fiscalizeAgain('229872');

	Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://test.eposlovanje.hr/api/v2/ereporting/fiscalize/229872');
});

it('is not ready without the key, the OIB or the software identifier, and sends nothing', function () {
	config(['hrEInvoicing.ePoslovanje.apiKey' => null]);
	$client = app(EPoslovanjeClient::class);

	expect($client->ready())->toBeFalse()
		->and(fn () => $client->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary account is not set up.')
		->and(fn () => $client->ping())->toThrow(RuntimeException::class, 'The intermediary account is not set up.');

	config(['hrEInvoicing.ePoslovanje.apiKey' => 'k', 'hrEInvoicing.ePoslovanje.softwareId' => null]);

	expect(app(EPoslovanjeClient::class)->ready())->toBeFalse();

	config(['hrEInvoicing.ePoslovanje.softwareId' => 'EMTOO', 'hrEInvoicing.ePoslovanje.oib' => null]);

	expect(app(EPoslovanjeClient::class)->ready())->toBeFalse();

	Http::assertNothingSent();
});

it('follows a document up on the service that carried it, with that service\'s account, or not at all without one', function () {
	Http::fake([
		'https://test.eposlovanje.hr/api/v2/document/status/1' => Http::response(['id' => 1, 'status' => 4]),
		'https://eracun.eposlovanje.hr/api/v2/document/status/2' => Http::response(['id' => 2, 'status' => 4]),
	]);
	config(['hrEInvoicing.allowProductionAnywhere' => true]);

	// A tenant on production now, its demo document followed up on the test server with its demo key
	app()->instance(AccountProvider::class, new class implements AccountProvider, AccountsByEnvironment
	{
		public function account(): ?array
		{
			return ['apiKey' => 'production-key', 'oib' => '12345678903', 'softwareId' => 'EMTOO', 'environment' => 'production'];
		}

		public function accountFor(string $environment): ?array
		{
			return ['apiKey' => $environment . '-key', 'oib' => '12345678903', 'softwareId' => 'EMTOO'];
		}
	});

	$manager = app(GatewayManager::class);

	expect($manager->driver('eposlovanje', 'demo')->status('1')['status'])->toBe(DocumentStatus::DELIVERED)
		->and($manager->driver('eposlovanje')->status('2')['status'])->toBe(DocumentStatus::DELIVERED);

	Http::assertSent(fn (Request $request) => $request->url() === 'https://test.eposlovanje.hr/api/v2/document/status/1' && $request->hasHeader('Authorization', 'demo-key'));
	Http::assertSent(fn (Request $request) => $request->url() === 'https://eracun.eposlovanje.hr/api/v2/document/status/2' && $request->hasHeader('Authorization', 'production-key'));

	// The installation's own account is on one service only: a document of the other has no account to go with
	app()->forgetInstance(AccountProvider::class);
	app()->bind(AccountProvider::class, ConfigAccountProvider::class);

	expect(app(EPoslovanjeClient::class)->onEnvironment('production')->ready())->toBeFalse()
		->and(app(EPoslovanjeClient::class)->onEnvironment('demo')->ready())->toBeTrue();
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
