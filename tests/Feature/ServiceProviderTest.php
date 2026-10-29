<?php

use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\EPoslovanje\ConfigAccountProvider as EPoslovanjeConfigAccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider as EPoslovanjeAccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\EPoslovanjeClient;
use GTCrais\HrEInvoicing\GatewayManager;
use GTCrais\HrEInvoicing\HrEInvoicingServiceProvider;
use GTCrais\HrEInvoicing\MojEracun\ConfigAccountProvider;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\MojEracun\MojEracunClient;
use GTCrais\HrEInvoicing\Testing\FakeEInvoiceGateway;
use GTCrais\HrEInvoicing\Testing\FakeIntermediary;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

it('binds the configured intermediary\'s client as the gateway, and the fake, one for the whole run, when the configuration says so', function () {
	expect(app(EInvoiceGateway::class))->toBeInstanceOf(MojEracunClient::class);

	config(['hrEInvoicing.gateway' => 'eposlovanje']);

	expect(app(EInvoiceGateway::class))->toBeInstanceOf(EPoslovanjeClient::class);

	config(['hrEInvoicing.gateway' => 'fake']);

	expect(app(EInvoiceGateway::class))->toBeInstanceOf(FakeEInvoiceGateway::class)
		->and(app(EInvoiceGateway::class))->toBe(app(EInvoiceGateway::class))
		->and(app(EInvoiceGateway::class))->toBe(app(GatewayManager::class)->fake())
		// Each intermediary is that one fake as the intermediary, once for the run
		->and(app(GatewayManager::class)->driver('mojeracun'))->toBeInstanceOf(FakeIntermediary::class)
		->and(app(GatewayManager::class)->driver('mojeracun'))->toBe(app(GatewayManager::class)->driver('mojeracun'))
		->and(app(GatewayManager::class)->driver('eposlovanje', 'demo'))->toBeInstanceOf(FakeIntermediary::class);
});

it('hands out each intermediary\'s gateway by name, once, and knows which names are intermediaries', function () {
	$manager = app(GatewayManager::class);

	expect($manager->driver('mojeracun'))->toBeInstanceOf(MojEracunClient::class)
		->and($manager->driver('mojeracun'))->toBe($manager->driver('mojeracun'))
		->and($manager->driver('eposlovanje'))->toBeInstanceOf(EPoslovanjeClient::class)
		->and($manager->driver())->toBe($manager->driver('mojeracun'))
		->and(fn () => $manager->driver('fina'))->toThrow(InvalidArgumentException::class, 'Unknown e-invoice gateway [fina].')
		->and(GatewayManager::knows('eposlovanje'))->toBeTrue()
		->and(GatewayManager::knows('fina'))->toBeFalse()
		->and(GatewayManager::knows(null))->toBeFalse()
		->and(GatewayManager::INTERMEDIARIES)->toBe(['mojeracun', 'eposlovanje']);
});

it('takes a blank service for none, handing out the free client that follows the account\'s own service, never one held to the demo service', function () {
	Http::preventStrayRequests();
	config(['hrEInvoicing.allowProductionAnywhere' => true]);
	app()->instance(AccountProvider::class, new class implements AccountProvider
	{
		public function account(): ?array
		{
			return ['username' => '10001', 'password' => 'tajna', 'companyId' => '12345678903', 'companyBU' => null, 'softwareId' => 'Test-002', 'environment' => 'production'];
		}
	});
	Http::fake(['https://www.moj-eracun.hr/apis/v2/send' => Http::response(['ElectronicId' => 81, 'StatusId' => 30])]);
	$manager = app(GatewayManager::class);

	$blank = $manager->driver('mojeracun', '');

	expect($blank)->toBe($manager->driver('mojeracun'))
		->and($blank->send('<Invoice/>')['electronicId'])->toBe('81');

	// With the fake as the gateway, the fake itself, and each intermediary as free as asked for no service
	config(['hrEInvoicing.gateway' => 'fake']);
	$manager->driver('mojeracun', '')->ping();

	expect($manager->driver(null, ''))->toBe($manager->fake())
		->and($manager->driver('mojeracun', ''))->toBe($manager->driver('mojeracun'))
		->and($manager->fake()->calls)->toBe([['method' => 'ping', 'electronicId' => null, 'intermediary' => 'mojeracun', 'environment' => 'production']]);
});

it('takes the configured Moj-eRačun account, complete or not at all', function () {
	config(['hrEInvoicing.mojEracun' => [
		'environment' => 'demo',
		'username' => '10001',
		'password' => 'tajna',
		'oib' => '12345678903',
		'businessUnit' => 'PJ1',
		'softwareId' => 'Test-002',
	]]);

	expect(app(AccountProvider::class))->toBeInstanceOf(ConfigAccountProvider::class)
		->and(app(AccountProvider::class)->account())->toBe(['username' => '10001', 'password' => 'tajna', 'companyId' => '12345678903', 'companyBU' => 'PJ1', 'softwareId' => 'Test-002'])
		->and(app(MojEracunClient::class)->ready())->toBeTrue();

	config(['hrEInvoicing.mojEracun.password' => null]);

	expect(app(AccountProvider::class)->account())->toBeNull()
		->and(app(MojEracunClient::class)->ready())->toBeFalse();
});

it('takes the configured ePoslovanje account, complete or not at all', function () {
	config(['hrEInvoicing.ePoslovanje' => [
		'environment' => 'demo',
		'apiKey' => '1234567890abcdef',
		'oib' => '12345678903',
		'softwareId' => 'EMTOO',
	]]);

	expect(app(EPoslovanjeAccountProvider::class))->toBeInstanceOf(EPoslovanjeConfigAccountProvider::class)
		->and(app(EPoslovanjeAccountProvider::class)->account())->toBe(['apiKey' => '1234567890abcdef', 'oib' => '12345678903', 'softwareId' => 'EMTOO'])
		->and(app(EPoslovanjeClient::class)->ready())->toBeTrue();

	config(['hrEInvoicing.ePoslovanje.oib' => null]);

	expect(app(EPoslovanjeAccountProvider::class)->account())->toBeNull()
		->and(app(EPoslovanjeClient::class)->ready())->toBeFalse();
});

it('calls with the account the app binds, one per tenant say, and asks for it before every call', function () {
	$accounts = new class implements AccountProvider
	{
		public ?string $tenant = null;

		public function account(): ?array
		{
			return $this->tenant === null ? null : ['username' => $this->tenant, 'password' => 'tajna', 'companyId' => '98765432106', 'companyBU' => null, 'softwareId' => 'Test-002'];
		}
	};
	app()->instance(AccountProvider::class, $accounts);

	$client = app(MojEracunClient::class);
	Http::fake(['https://demo.moj-eracun.hr/apis/v2/send' => Http::response(['ElectronicId' => 1, 'StatusId' => 30])]);

	expect($client->ready())->toBeFalse();

	// The same client sends with whichever account the provider gives at the moment of the call
	foreach (['sunce', 'mjesec'] as $tenant) {
		$accounts->tenant = $tenant;

		expect($client->ready())->toBeTrue();

		$client->send('<Invoice/>');

		Http::assertSent(fn (Request $request) => $request['username'] === $tenant);
	}

	Http::assertSentCount(2);
});

it('offers its configuration for publishing', function () {
	$paths = ServiceProvider::pathsToPublish(HrEInvoicingServiceProvider::class, 'hr-einvoicing-config');

	expect(array_values($paths))->toBe([config_path('hrEInvoicing.php')])
		->and(array_key_first($paths))->toEndWith('hrEInvoicing.php');
});
