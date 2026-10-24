<?php

namespace GTCrais\HrEInvoicing\EPoslovanje;

use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider;

/**
 * The one ePoslovanje account of config('hrEInvoicing.ePoslovanje'), for an app that sends its e-invoices as a single
 * company; it is the account provider unless the app binds its own.
 */
class ConfigAccountProvider implements AccountProvider
{
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
