<?php

namespace GTCrais\HrEInvoicing;

use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\MojEracun\ConfigAccountProvider;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\MojEracun\MojEracunClient;
use GTCrais\HrEInvoicing\Testing\FakeEInvoiceGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the gateway: Moj-eRačun's client with the account the AccountProvider gives (the configured one unless the
 * app bound its own), or the fake when the configuration says so; and offers the configuration for publishing.
 */
class HrEInvoicingServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->mergeConfigFrom(__DIR__ . '/config/hrEInvoicing.php', 'hrEInvoicing');

		$this->app->bindIf(AccountProvider::class, ConfigAccountProvider::class);

		$this->app->bind(MojEracunClient::class, fn (Application $app) => new MojEracunClient(
			$app->make(AccountProvider::class),
			(string) config('hrEInvoicing.mojEracun.environment'),
			(int) config('hrEInvoicing.attemptSeconds')
		));

		// One fake for the whole run, so a test can tell it what to answer and read what was sent
		$this->app->singleton(EInvoiceGateway::class, fn (Application $app) => config('hrEInvoicing.gateway') === 'fake'
			? new FakeEInvoiceGateway
			: $app->make(MojEracunClient::class));
	}

	public function boot(): void
	{
		if ($this->app->runningInConsole()) {
			$this->publishes([
				__DIR__ . '/config/hrEInvoicing.php' => config_path('hrEInvoicing.php'),
			], 'hr-einvoicing-config');
		}
	}
}
