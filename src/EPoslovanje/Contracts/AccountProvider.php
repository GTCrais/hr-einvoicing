<?php

namespace GTCrais\HrEInvoicing\EPoslovanje\Contracts;

/**
 * Where EPoslovanjeClient finds the account it calls ePoslovanje with. It is asked again before every call, so an
 * account entered or changed meanwhile is the one used. ConfigAccountProvider reads one account from the
 * configuration; an app that sends for many companies (an account per tenant, say) binds its own.
 */
interface AccountProvider
{
	/**
	 * The account, or null while any part of it is missing: the API key ePoslovanje shows in the account's control
	 * panel, the issuer's OIB, and the software identifier, a name of the app's own choosing. An "environment" of
	 * "demo" or "production" names the service the account is on (the test server's keys and the production server's
	 * are different accounts); without it the client's own environment stands.
	 *
	 * @return array{apiKey: string, oib: string, softwareId: string, environment?: string}|null
	 */
	public function account(): ?array;
}
