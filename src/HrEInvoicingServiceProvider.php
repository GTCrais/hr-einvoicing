<?php

namespace GTCrais\HrEInvoicing;

use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\EPoslovanje\ConfigAccountProvider as EPoslovanjeConfigAccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider as EPoslovanjeAccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\EPoslovanjeClient;
use GTCrais\HrEInvoicing\MojEracun\ConfigAccountProvider as MojEracunConfigAccountProvider;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider as MojEracunAccountProvider;
use GTCrais\HrEInvoicing\MojEracun\MojEracunClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the clients with the accounts their AccountProviders give (the configured ones unless the app bound its own),
 * the GatewayManager that hands them out by name, and the gateway config('hrEInvoicing.gateway') names (the fake when
 * it says so); and offers the configuration for publishing.
 */
class HrEInvoicingServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->mergeConfigFrom(__DIR__ . '/config/hrEInvoicing.php', 'hrEInvoicing');

		$this->app->bindIf(MojEracunAccountProvider::class, MojEracunConfigAccountProvider::class);
		$this->app->bindIf(EPoslovanjeAccountProvider::class, EPoslovanjeConfigAccountProvider::class);

		$this->app->bind(MojEracunClient::class, fn (Application $app) => new MojEracunClient(
			$app->make(MojEracunAccountProvider::class),
			(string) config('hrEInvoicing.mojEracun.environment'),
			(int) config('hrEInvoicing.attemptSeconds')
		));

		$this->app->bind(EPoslovanjeClient::class, fn (Application $app) => new EPoslovanjeClient(
			$app->make(EPoslovanjeAccountProvider::class),
			(string) config('hrEInvoicing.ePoslovanje.environment'),
			(int) config('hrEInvoicing.attemptSeconds')
		));

		// One manager, so each intermediary's gateway, and the fake, exist once for the whole run
		$this->app->singleton(GatewayManager::class);

		$this->app->bind(EInvoiceGateway::class, fn (Application $app) => $app->make(GatewayManager::class)->driver());
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
