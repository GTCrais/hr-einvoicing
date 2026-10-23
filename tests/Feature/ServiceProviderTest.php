<?php

use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\HrEInvoicingServiceProvider;
use GTCrais\HrEInvoicing\MojEracun\ConfigAccountProvider;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\MojEracun\MojEracunClient;
use GTCrais\HrEInvoicing\Testing\FakeEInvoiceGateway;
use Illuminate\Support\ServiceProvider;

it('binds Moj-eRačun\'s client as the gateway, and the fake, one for the whole run, when the configuration says so', function () {
	expect(app(EInvoiceGateway::class))->toBeInstanceOf(MojEracunClient::class);

	app()->forgetInstance(EInvoiceGateway::class);
	config(['hrEInvoicing.gateway' => 'fake']);

	expect(app(EInvoiceGateway::class))->toBeInstanceOf(FakeEInvoiceGateway::class)
		->and(app(EInvoiceGateway::class))->toBe(app(EInvoiceGateway::class));
});

it('takes the configured account, complete or not at all', function () {
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

	expect($client->ready())->toBeFalse();

	$accounts->tenant = 'sunce';

	expect($client->ready())->toBeTrue();
});

it('offers its configuration for publishing', function () {
	$paths = ServiceProvider::pathsToPublish(HrEInvoicingServiceProvider::class, 'hr-einvoicing-config');

	expect(array_values($paths))->toBe([config_path('hrEInvoicing.php')])
		->and(array_key_first($paths))->toEndWith('hrEInvoicing.php');
});
