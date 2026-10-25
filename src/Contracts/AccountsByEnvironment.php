<?php

namespace GTCrais\HrEInvoicing\Contracts;

/**
 * An account provider that keeps the issuer's account for each of the intermediary's services (demo and production),
 * so a document is followed up through the service that carried it, with that service's account, whichever service
 * the issuer is on now. A client built for one service (GatewayManager::driver($name, $environment)) asks for that
 * service's account; a provider without this answers only for the service its one account is on.
 */
interface AccountsByEnvironment
{
	/**
	 * The account for the service ("demo" or "production"), in the shape of the intermediary's AccountProvider;
	 * null when the issuer has none there.
	 *
	 * @return array<string, mixed>|null
	 */
	public function accountFor(string $environment): ?array;
}
