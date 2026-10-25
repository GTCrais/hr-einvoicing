<?php

namespace GTCrais\HrEInvoicing\EPoslovanje;

use GTCrais\HrEInvoicing\Contracts\AccountsByEnvironment;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider;

/**
 * The one ePoslovanje account of config('hrEInvoicing.ePoslovanje'), for an app that sends its e-invoices as a single
 * company; it is the account provider unless the app binds its own. Its key opens the configured server only (a test
 * account's the test server, a production account's production), so a document sent on the other is not followed up
 * with it.
 */
class ConfigAccountProvider implements AccountProvider, AccountsByEnvironment
{
	public function accountFor(string $environment): ?array
	{
		$configured = config('hrEInvoicing.ePoslovanje.environment') === 'production' ? 'production' : 'demo';

		return $environment === $configured ? $this->account() : null;
	}

	public function account(): ?array
	{
		$account = (array) config('hrEInvoicing.ePoslovanje');

		if (blank($account['apiKey'] ?? null) || blank($account['oib'] ?? null) || blank($account['softwareId'] ?? null)) {
			return null;
		}

		return [
			'apiKey' => (string) $account['apiKey'],
			'oib' => (string) $account['oib'],
			'softwareId' => (string) $account['softwareId'],
		];
	}
}
