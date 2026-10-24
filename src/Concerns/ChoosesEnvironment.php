<?php

namespace GTCrais\HrEInvoicing\Concerns;

use RuntimeException;

/**
 * Which of the intermediary's services a client calls: the one the account names ("environment" in the account
 * array, for an app whose accounts are on different services, a tenant being set up on the demo one while another
 * is live), else the one the client was built with. The production service is refused outside a production
 * installation unless config('hrEInvoicing.allowProductionAnywhere') says otherwise, so a development machine with a
 * production key in its .env can never reach it.
 */
trait ChoosesEnvironment
{
	/**
	 * @param  array<string, mixed>|null  $account
	 */
	protected function environmentOf(?array $account): string
	{
		$environment = $account['environment'] ?? $this->environment;

		return $environment === 'production' ? 'production' : 'demo';
	}

	/**
	 * The service's address for the account's environment, with the production lock.
	 *
	 * @param  array<string, string>  $services
	 * @param  array<string, mixed>|null  $account
	 */
	protected function serviceFor(array $services, ?array $account): string
	{
		$environment = $this->environmentOf($account);

		if ($environment === 'production' && !app()->isProduction() && !config('hrEInvoicing.allowProductionAnywhere')) {
			throw new RuntimeException('The intermediary\'s production service is refused outside a production installation.');
		}

		return rtrim($services[$environment], '/');
	}
}
