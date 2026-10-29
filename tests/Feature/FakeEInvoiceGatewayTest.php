<?php

use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Contracts\FiscalizesAgain;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\GatewayManager;
use GTCrais\HrEInvoicing\Testing\FakeEInvoiceGateway;
use GTCrais\HrEInvoicing\Testing\FakeIntermediary;
use Illuminate\Support\Carbon;

it('keeps what was sent and reported, and answers with the standing a test set and the tax authority answer to the payment', function () {
	$gateway = new FakeEInvoiceGateway;

	$sent = $gateway->send('<Invoice><cbc:ID>1/P1/1</cbc:ID></Invoice>');
	$gateway->setStatus($sent['electronicId'], DocumentStatus::DELIVERED, FiscalizationStatus::FAILED, 'S005 Poruka nije u skladu s XML shemom');
	$gateway->reportPayment($sent['electronicId'], '2026-10-22', 125.0);
	$gateway->ping();

	expect($sent)->toBe(['electronicId' => '1001', 'status' => DocumentStatus::SENT])
		->and($gateway->sent)->toHaveCount(1)
		->and($gateway->status('1001'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => FiscalizationStatus::FAILED, 'error' => 'S005 Poruka nije u skladu s XML shemom', 'payment' => FiscalizationStatus::FISCALIZED, 'paymentError' => null, 'paymentReports' => 1, 'answersError' => null, 'rejectionReason' => null, 'fiscalizationError' => 'S005 Poruka nije u skladu s XML shemom'])
		// One it never sent, nor was given a standing for, it does not know, as the real ones
		->and(fn () => $gateway->status('1002'))->toThrow(RuntimeException::class, 'The intermediary does not know the document 1002.')
		->and(fn () => $gateway->reportPayment('1002', '2026-10-22', 10.0))->toThrow(RuntimeException::class, 'The intermediary does not know the document 1002.')
		->and($gateway->payments)->toBe([['electronicId' => '1001', 'paidOn' => '2026-10-22', 'amount' => 125.0, 'intermediary' => null, 'environment' => null]])
		->and($gateway->pings)->toBe(1);

	// Asked to fiscalize again, it waits for the tax authority's answer once more
	$gateway->fiscalizeAgain('1001');

	expect($gateway->refiscalized)->toBe(['1001'])
		->and($gateway->status('1001')['fiscalization'])->toBe(FiscalizationStatus::WAITING);

	// A fiscalization taken stays taken whatever a later request says, as ePoslovanje's standing reads
	$gateway->setStatus('1001', DocumentStatus::DELIVERED, FiscalizationStatus::FISCALIZED);
	$gateway->fiscalizeAgain('1001');

	expect($gateway->refiscalized)->toBe(['1001', '1001'])
		->and($gateway->status('1001')['fiscalization'])->toBe(FiscalizationStatus::FISCALIZED);
});

it('fails, refuses and loses answers when told to, and finds a document whose answer was lost by its own number only', function () {
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

	// A storno sent later names the invoice in its billing reference, which is not its own number
	$gateway->send('<Invoice><cbc:ID>9/P1/1</cbc:ID><cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>7/P1/1</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference></Invoice>');

	expect($gateway->sentDocument('7/P1/1', Carbon::now())['electronicId'])->toBe('1001')
		// Nor is one sent before the day before the first try looked for
		->and($gateway->sentDocument('7/P1/1', Carbon::now()->addDays(3)))->toBeNull();

	// The same number of the next year's document is told apart by the day of issue
	$gateway->send('<Invoice><cbc:ID>11/P1/1</cbc:ID><cbc:IssueDate>2026-12-30</cbc:IssueDate></Invoice>');
	$gateway->send('<Invoice><cbc:ID>11/P1/1</cbc:ID><cbc:IssueDate>2027-01-05</cbc:IssueDate></Invoice>');

	expect($gateway->sentDocument('11/P1/1', Carbon::now(), '2026-12-30')['electronicId'])->toBe('1003')
		->and($gateway->sentDocument('11/P1/1', Carbon::now())['electronicId'])->toBe('1004')
		->and($gateway->sentDocument('11/P1/1', Carbon::now(), '2026-12-29'))->toBeNull();

	$gateway->setStatus('1001', DocumentStatus::REFUSED, error: 'HTTP 400: [HR-BR-37]');

	expect($gateway->sentDocument('7/P1/1', Carbon::now()))->toBe(['electronicId' => '1001', 'status' => DocumentStatus::REFUSED]);

	$gateway->reset();

	expect($gateway->sent)->toBe([])
		->and($gateway->sendStatus)->toBe(DocumentStatus::SENT);
});

it('counts the payment reports it holds of each document, one whose answer it lost included, and leaves them unknown while the answers cannot be read', function () {
	$gateway = new FakeEInvoiceGateway;
	$first = $gateway->send('<Invoice><cbc:ID>1/P1/1</cbc:ID></Invoice>')['electronicId'];
	$second = $gateway->send('<Invoice><cbc:ID>2/P1/1</cbc:ID></Invoice>')['electronicId'];

	$gateway->reportPayment($first, '2026-10-22', 125.0);
	$gateway->loseNextPaymentAnswer();

	expect(fn () => $gateway->reportPayment($first, '2026-10-22', -125.0))->toThrow(RuntimeException::class, 'Operation timed out')
		->and($gateway->status($first)['paymentReports'])->toBe(2)
		->and($gateway->status($second)['paymentReports'])->toBe(0)
		->and(array_column($gateway->payments, 'amount'))->toBe([125.0, -125.0]);

	// A standing set later keeps the count, unless it names one
	$gateway->setStatus($first, DocumentStatus::DELIVERED, FiscalizationStatus::FISCALIZED);
	$gateway->setStatus($second, DocumentStatus::DELIVERED, paymentReports: 3);

	expect($gateway->status($first)['paymentReports'])->toBe(2)
		->and($gateway->status($second)['paymentReports'])->toBe(3);

	$gateway->setStatus($first, DocumentStatus::DELIVERED, answersError: 'HTTP 503: Service Unavailable');

	expect($gateway->status($first)['paymentReports'])->toBeNull();
});

/**
 * The fake as the gateway, with an account at each intermediary on the configured services.
 */
function fakeWithAccounts(string $mojEracunEnvironment = 'demo', string $ePoslovanjeEnvironment = 'demo'): GatewayManager
{
	config([
		'hrEInvoicing.gateway' => 'fake',
		'hrEInvoicing.mojEracun' => ['environment' => $mojEracunEnvironment, 'username' => '10001', 'password' => 'tajna', 'oib' => '12345678903', 'businessUnit' => null, 'softwareId' => 'Test-002'],
		'hrEInvoicing.ePoslovanje' => ['environment' => $ePoslovanjeEnvironment, 'apiKey' => '1234567890abcdef1234567890abcdef', 'oib' => '12345678903', 'softwareId' => 'EMTOO'],
	]);

	return app(GatewayManager::class);
}

it('is the gateway itself, and stands in for each intermediary with only what its real client can do', function () {
	$manager = fakeWithAccounts();

	expect(app(EInvoiceGateway::class))->toBe($manager->fake())
		->and($manager->driver('eposlovanje'))->toBeInstanceOf(FiscalizesAgain::class)
		->and($manager->driver('mojeracun'))->toBeInstanceOf(FakeIntermediary::class)
		->not->toBeInstanceOf(FiscalizesAgain::class)
		->and($manager->driver('fake'))->toBeInstanceOf(FiscalizesAgain::class)
		->and(fn () => $manager->driver('fina'))->toThrow(InvalidArgumentException::class, 'Unknown e-invoice gateway [fina].');
});

it('stands in, switched on, for the intermediary the gateway names, as that intermediary', function () {
	$manager = fakeWithAccounts();
	config(['hrEInvoicing.gateway' => 'mojeracun', 'hrEInvoicing.fake' => true]);

	$mojEracun = $manager->driver('mojeracun');
	$sent = $mojEracun->send('<Invoice><cbc:ID>5/P1/1</cbc:ID></Invoice>');

	expect($mojEracun)->toBeInstanceOf(FakeIntermediary::class)
		->not->toBeInstanceOf(FiscalizesAgain::class)
		->and($manager->fake()->calls[0])->toBe(['method' => 'send', 'electronicId' => null, 'intermediary' => 'mojeracun', 'environment' => 'demo'])
		->and($mojEracun->sentDocument('5/P1/1', Carbon::now()->subMinute())['electronicId'])->toBe($sent['electronicId']);
});

it('records which intermediary and service each call was made through, whichever was handed out last', function () {
	$manager = fakeWithAccounts(mojEracunEnvironment: 'production');
	config(['hrEInvoicing.allowProductionAnywhere' => true]);
	$fake = $manager->fake();

	$ePoslovanje = $manager->driver('eposlovanje', 'demo');
	$sent = $ePoslovanje->send('<Invoice><cbc:ID>1/P1/1</cbc:ID></Invoice>');
	$mojEracun = $manager->driver('mojeracun', 'production');

	$ePoslovanje->reportPayment($sent['electronicId'], '2026-10-24', 10.0);
	$mojEracun->ping();

	expect($fake->payments[0])->toMatchArray(['intermediary' => 'eposlovanje', 'environment' => 'demo'])
		->and($fake->calls[1])->toBe(['method' => 'reportPayment', 'electronicId' => '1001', 'intermediary' => 'eposlovanje', 'environment' => 'demo'])
		->and($fake->calls[2])->toBe(['method' => 'ping', 'electronicId' => null, 'intermediary' => 'mojeracun', 'environment' => 'production']);
});

it('finds a document only through the intermediary and the service it was sent through, as the real ones know nothing of the others', function () {
	$manager = fakeWithAccounts();
	$fake = $manager->fake();

	// Sent through ePoslovanje on the service its account is on, the configured test server
	$sent = $manager->driver('eposlovanje')->send('<Invoice><cbc:ID>4/P1/1</cbc:ID></Invoice>');

	expect($manager->driver('eposlovanje', 'demo')->sentDocument('4/P1/1', Carbon::now()))->toBe(['electronicId' => $sent['electronicId'], 'status' => DocumentStatus::SENT])
		->and($manager->driver('eposlovanje', 'demo')->status($sent['electronicId'])['status'])->toBe(DocumentStatus::SENT)
		->and($manager->driver('mojeracun')->sentDocument('4/P1/1', Carbon::now()))->toBeNull()
		->and(fn () => $manager->driver('mojeracun')->status($sent['electronicId']))->toThrow(RuntimeException::class, 'The intermediary does not know the document 1001.')
		->and(fn () => $manager->driver('mojeracun')->reportPayment($sent['electronicId'], '2026-10-24', 10.0))->toThrow(RuntimeException::class, 'The intermediary does not know the document 1001.')
		// A test asking the fake itself sees every document
		->and($fake->sentDocument('4/P1/1', Carbon::now())['electronicId'])->toBe($sent['electronicId'])
		->and($fake->payments)->toBe([]);

	// Held to the production server, with an account there, it knows nothing of a document on the test server
	config(['hrEInvoicing.ePoslovanje.environment' => 'production', 'hrEInvoicing.allowProductionAnywhere' => true]);

	expect(fn () => $manager->driver('eposlovanje', 'production')->status($sent['electronicId']))->toThrow(RuntimeException::class, 'The intermediary does not know the document 1001.');
});

it('refuses the production service outside a production installation, every call without an account, and itself in production', function () {
	$manager = fakeWithAccounts(mojEracunEnvironment: 'production');

	expect(fn () => $manager->driver('mojeracun')->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary\'s production service is refused outside a production installation.')
		->and($manager->fake()->sent)->toBe([]);

	// Told it has no account, it refuses every call, as the real client does
	$fake = $manager->fake();
	$fake->ready = false;

	expect(fn () => $manager->driver('eposlovanje')->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary account is not set up.')
		->and(fn () => $fake->ping())->toThrow(RuntimeException::class, 'The intermediary account is not set up.');

	$fake->ready = true;

	// A stray "fake" in a production installation would mark documents sent that never left
	app()['env'] = 'production';

	expect(fn () => app(GatewayManager::class)->driver())->toThrow(RuntimeException::class, 'The fake e-invoice gateway is refused in production.')
		->and(fn () => app(GatewayManager::class)->driver('mojeracun'))->toThrow(RuntimeException::class, 'The fake e-invoice gateway is refused in production.');

	app()['env'] = 'testing';
});

it('is ready and calls only with an account there, as the real client is', function () {
	$manager = fakeWithAccounts();
	config(['hrEInvoicing.mojEracun.username' => null]);

	expect($manager->driver('mojeracun')->ready())->toBeFalse()
		->and(fn () => $manager->driver('mojeracun')->send('<Invoice/>'))->toThrow(RuntimeException::class, 'The intermediary account is not set up.')
		->and($manager->driver('eposlovanje')->ready())->toBeTrue()
		// Held to the service its one account is not on, it has none
		->and($manager->driver('eposlovanje', 'production')->ready())->toBeFalse()
		->and($manager->fake()->sent)->toBe([]);
});

it('refuses a document of a number it holds for the year on the same intermediary and service, as the real ones do, unless the one it holds was refused', function () {
	$manager = fakeWithAccounts();
	$fake = $manager->fake();
	$invoice = '<Invoice><cbc:ID>12/P1/1</cbc:ID><cbc:IssueDate>2026-10-23</cbc:IssueDate></Invoice>';
	$mojEracun = $manager->driver('mojeracun');

	$first = $mojEracun->send($invoice);

	expect(fn () => $mojEracun->send($invoice))->toThrow(EInvoiceRefusedException::class, 'already exists with EID=1001')
		// The number of the next year's document, and the same document through another intermediary, are no duplicates
		->and($mojEracun->send(str_replace('2026-10-23', '2027-01-04', $invoice))['electronicId'])->toBe('1002')
		->and($manager->driver('eposlovanje')->send($invoice)['electronicId'])->toBe('1003');

	// Nor is it on the other service
	config(['hrEInvoicing.mojEracun.environment' => 'production', 'hrEInvoicing.allowProductionAnywhere' => true]);

	expect($manager->driver('mojeracun', 'production')->send($invoice)['electronicId'])->toBe('1004');

	// One the intermediary could not process is refused, and the corrected document goes again under its number
	$fake->setStatus($first['electronicId'], DocumentStatus::REFUSED, error: 'Neuspjelo');

	expect($mojEracun->send($invoice)['electronicId'])->toBe('1005')
		->and($fake->sent)->toHaveCount(5);
});

it('keeps a document whose answer was lost out of the next lookups of it when told to, refusing it sent again meanwhile, and finds it after', function () {
	$gateway = new FakeEInvoiceGateway;
	$invoice = '<Invoice><cbc:ID>7/P1/1</cbc:ID><cbc:IssueDate>2026-10-23</cbc:IssueDate></Invoice>';
	$gateway->loseNextAnswer();
	$gateway->hideFromLookup(2);

	expect(fn () => $gateway->send($invoice))->toThrow(RuntimeException::class, 'Operation timed out')
		->and($gateway->sentDocument('7/P1/1', Carbon::now(), '2026-10-23'))->toBeNull()
		->and(fn () => $gateway->send($invoice))->toThrow(EInvoiceRefusedException::class, 'already exists with EID=1001')
		->and($gateway->sentDocument('7/P1/1', Carbon::now(), '2026-10-23'))->toBeNull()
		->and($gateway->sentDocument('7/P1/1', Carbon::now(), '2026-10-23'))->toBe(['electronicId' => '1001', 'status' => DocumentStatus::SENT])
		->and($gateway->sent)->toHaveCount(1);
});

it('refuses a document only when one is sent and finds the service down only when the connection is tested, as the real clients throw them, so a lookup or a status asked first passes', function () {
	$gateway = new FakeEInvoiceGateway;
	$sent = $gateway->send('<Invoice><cbc:ID>1/P1/1</cbc:ID></Invoice>');
	$gateway->refuseNext();
	$gateway->unavailableNext();

	expect($gateway->sentDocument('2/P1/1', Carbon::now()))->toBeNull()
		->and($gateway->status($sent['electronicId'])['status'])->toBe(DocumentStatus::SENT)
		->and(fn () => $gateway->send('<Invoice><cbc:ID>2/P1/1</cbc:ID></Invoice>'))->toThrow(EInvoiceRefusedException::class, 'HR-BR-37')
		->and(fn () => $gateway->ping())->toThrow(EInvoiceUnavailableException::class, 'HTTP 500')
		->and($gateway->send('<Invoice><cbc:ID>2/P1/1</cbc:ID></Invoice>')['electronicId'])->toBe('1002')
		->and($gateway->pings)->toBe(0);
});

it('leaves the tax authority\'s answers and their reasons unknown while they cannot be read, as the real clients do, keeping why a rejected document will not reach the buyer', function () {
	$gateway = new FakeEInvoiceGateway;
	$delivered = $gateway->send('<Invoice><cbc:ID>1/P1/1</cbc:ID></Invoice>')['electronicId'];
	$rejected = $gateway->send('<Invoice><cbc:ID>2/P1/1</cbc:ID></Invoice>')['electronicId'];
	$gateway->setStatus($delivered, DocumentStatus::DELIVERED, FiscalizationStatus::FAILED, 'S006 Pristupna točka nije ovlaštena za dostavu podataka', FiscalizationStatus::FAILED, 'S007 OIB iz zahtjeva nije formalno ispravan', answersError: 'HTTP 503: Service Unavailable');
	$gateway->setStatus($rejected, DocumentStatus::REJECTED, FiscalizationStatus::FISCALIZED, 'Neuspješna dostava', answersError: 'HTTP 503: Service Unavailable');

	expect($gateway->status($delivered))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'paymentReports' => null, 'answersError' => 'HTTP 503: Service Unavailable', 'rejectionReason' => null, 'fiscalizationError' => null])
		->and($gateway->status($rejected))->toBe(['status' => DocumentStatus::REJECTED, 'fiscalization' => null, 'error' => 'Neuspješna dostava', 'payment' => null, 'paymentError' => null, 'paymentReports' => null, 'answersError' => 'HTTP 503: Service Unavailable', 'rejectionReason' => 'Neuspješna dostava', 'fiscalizationError' => null]);
});
