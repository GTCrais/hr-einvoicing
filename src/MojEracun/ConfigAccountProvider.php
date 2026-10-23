<?php

namespace GTCrais\HrEInvoicing\MojEracun;

use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;

/**
 * The one Moj-eRačun account of config('hrEInvoicing.mojEracun'), for an app that sends its e-invoices as a single
 * company; it is the account provider unless the app binds its own.
 */
class ConfigAccountProvider implements AccountProvider
{
	public function account(): ?array
	{
		$account = (array) config('hrEInvoicing.mojEracun');

		if (blank($account['username'] ?? null) || blank($account['password'] ?? null) || blank($account['oib'] ?? null) || blank($account['softwareId'] ?? null)) {
			return null;
		}

		return [
			'username' => (string) $account['username'],
			'password' => (string) $account['password'],
			'companyId' => (string) $account['oib'],
			'companyBU' => filled($account['businessUnit'] ?? null) ? (string) $account['businessUnit'] : null,
			'softwareId' => (string) $account['softwareId'],
		];
	}
}
