<?php

namespace GTCrais\HrEInvoicing\Concerns;

use GTCrais\HrEInvoicing\Contracts\AccountsByEnvironment;
use RuntimeException;

/**
 * Which of the intermediary's services a client calls, and with which account: a client built for one service
 * (onEnvironment(), which GatewayManager::driver($name, $environment) uses to follow a document up where it went)
 * calls that one with the provider's account for it; any other calls the service the account names ("environment"
 * in the account array, for an app whose accounts are on different services), else the one the client was built
 * with. The production service is refused outside a production installation unless
 * config('hrEInvoicing.allowProductionAnywhere') says otherwise, so a development machine with a production key in
 * its .env can never reach it.
 */
trait ChoosesEnvironment
{
	/** The service this client is held to, whatever service the provider's account is on now */
	protected ?string $environmentOverride = null;

	/**
	 * A copy of the client held to one service ("demo" or "production"), or free of any with null.
	 */
	public function onEnvironment(?string $environment): static
	{
		$client = clone $this;
		$client->environmentOverride = $environment === null ? null : ($environment === 'production' ? 'production' : 'demo');

		return $client;
	}

	/**
	 * The account to call with: the provider's account for the service this client is held to (from a provider that
	 * keeps one per service, else its one account when that is on the service), or the provider's account.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function accountOf(object $accounts): ?array
	{
		if ($this->environmentOverride === null) {
			return $accounts->account();
		}

		if ($accounts instanceof AccountsByEnvironment) {
			$account = $accounts->accountFor($this->environmentOverride);

			return $account === null ? null : [...$account, 'environment' => $this->environmentOverride];
		}

		$account = $accounts->account();

		return $account !== null && (($account['environment'] ?? $this->environment) === 'production' ? 'production' : 'demo') === $this->environmentOverride ? $account : null;
	}

	/**
	 * @param  array<string, mixed>|null  $account
	 */
	protected function environmentOf(?array $account): string
	{
		$environment = $this->environmentOverride ?? $account['environment'] ?? $this->environment;

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
