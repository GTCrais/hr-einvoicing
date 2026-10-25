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
		->and($gateway->status('1001'))->toBe(['status' => DocumentStatus::DELIVERED, 'fiscalization' => FiscalizationStatus::FAILED, 'error' => 'S005 Poruka nije u skladu s XML shemom', 'payment' => FiscalizationStatus::FISCALIZED, 'paymentError' => null, 'answersError' => null])
		->and($gateway->status('1002'))->toBe(['status' => DocumentStatus::SENT, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'answersError' => null])
		->and($gateway->payments)->toBe([['electronicId' => '1001', 'paidOn' => '2026-10-22', 'amount' => 125.0, 'intermediary' => null, 'environment' => null]])
		->and($gateway->pings)->toBe(1);

	// Asked to fiscalize again, it waits for the tax authority's answer once more
	$gateway->fiscalizeAgain('1001');

	expect($gateway->refiscalized)->toBe(['1001'])
		->and($gateway->status('1001')['fiscalization'])->toBe(FiscalizationStatus::WAITING);
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
	config(['hrEInvoicing.ePoslovanje.environment' => 'production']);

	expect(fn () => $manager->driver('eposlovanje', 'production')->status($sent['electronicId']))->toThrow(RuntimeException::class, 'The intermediary does not know the document 1001.');
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
