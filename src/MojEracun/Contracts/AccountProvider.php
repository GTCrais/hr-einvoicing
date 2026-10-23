<?php

namespace GTCrais\HrEInvoicing\MojEracun\Contracts;

/**
 * Where MojEracunClient finds the account it calls Moj-eRačun with. It is asked again before every call, so an account
 * entered or changed meanwhile is the one used. ConfigAccountProvider reads one account from the configuration; an
 * app that sends for many companies (an account per tenant, say) binds its own.
 */
interface AccountProvider
{
	/**
	 * The account, or null while any part of it is missing: the username and password, the issuer's OIB as the
	 * company, its business unit when it has one, and the software identifier Moj-eRačun issued for the software.
	 *
	 * @return array{username: string, password: string, companyId: string, companyBU: ?string, softwareId: string}|null
	 */
	public function account(): ?array;
}
