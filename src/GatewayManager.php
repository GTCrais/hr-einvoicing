<?php

namespace GTCrais\HrEInvoicing;

use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Contracts\FiscalizesAgain;
use GTCrais\HrEInvoicing\Contracts\ListsBusinessUnits;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider as EPoslovanjeAccountProvider;
use GTCrais\HrEInvoicing\EPoslovanje\EPoslovanjeClient;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider as MojEracunAccountProvider;
use GTCrais\HrEInvoicing\MojEracun\MojEracunClient;
use GTCrais\HrEInvoicing\Testing\FakeBusinessUnitsIntermediary;
use GTCrais\HrEInvoicing\Testing\FakeEInvoiceGateway;
use GTCrais\HrEInvoicing\Testing\FakeFiscalizingIntermediary;
use GTCrais\HrEInvoicing\Testing\FakeIntermediary;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * Hands out the gateway of an intermediary by name, one instance of each for the whole run: "mojeracun" and
 * "eposlovanje", or the one config('hrEInvoicing.gateway') names when asked for none; and, asked for a service
 * ("demo" or "production"), one held to that service with the account provider's account for it, which an app that
 * keeps where each document went follows the document up through, whatever service it is on now. When the
 * configuration says "fake", the gateway is the one Testing\FakeEInvoiceGateway of the run, and every name and service
 * gets it as that intermediary on that service (Testing\FakeIntermediary): able to do what the real client of the name
 * can, set up only where the real client's account provider gives an account, and finding only the documents sent
 * through it, so a test suite that sends through several reads everything off one fake and sees each intermediary as
 * it is. The fake is refused in a production installation.
 */
class GatewayManager
{
	/** The intermediaries the package talks to */
	public const array INTERMEDIARIES = ['mojeracun', 'eposlovanje'];

	/** @var array<string, EInvoiceGateway> */
	protected array $gateways = [];

	public function __construct(
		protected Container $container
	) {}

	public function driver(?string $name = null, ?string $environment = null): EInvoiceGateway
	{
		// A blank service (one stored empty with a document) names none: driver($name, '') is driver($name), the free client,
		// never one held to the demo service that driver($name) would then answer with too; any other is kept by the
		// service its client is held to
		$environment = blank($environment) ? null : ($environment === 'production' ? 'production' : 'demo');

		// The fake answers for every intermediary when the gateway is "fake", or when "fake" is on with the gateway naming
		// the intermediary a test suite stands in for
		$faked = config('hrEInvoicing.gateway') === 'fake' || (bool) config('hrEInvoicing.fake', false);

		// A stray "fake" in a production .env would mark documents sent that never left
		if (($faked || $name === 'fake') && app()->isProduction()) {
			throw new RuntimeException('The fake e-invoice gateway is refused in production.');
		}

		if ($faked && blank($name) && $environment === null) {
			return $this->fake();
		}

		$name = $name ?: (string) config('hrEInvoicing.gateway');

		if ($faked || $name === 'fake') {
			return $this->gateways['fake:' . $name . ':' . ($environment ?? '')] ??= $this->fakeFor($name, $environment);
		}

		return $this->gateways[$name . ':' . ($environment ?? '')] ??= match ($name) {
			'mojeracun' => $this->container->make(MojEracunClient::class)->onEnvironment($environment),
			'eposlovanje' => $this->container->make(EPoslovanjeClient::class)->onEnvironment($environment),
			default => throw new InvalidArgumentException("Unknown e-invoice gateway [{$name}]."),
		};
	}

	/**
	 * The one fake of the run, which a test resets before it starts and reads what was sent off.
	 */
	public function fake(): FakeEInvoiceGateway
	{
		/** @var FakeEInvoiceGateway */
		return $this->gateways['fake'] ??= new FakeEInvoiceGateway;
	}

	/**
	 * The fake as the named intermediary, with the account provider and the configured service of its real client,
	 * fiscalizeAgain() only where that client takes it and businessUnits() only where it also lists them (ePoslovanje);
	 * the fake's own name can do everything and is always set up, and
	 * a name the package does not know is refused, as the real manager refuses it.
	 */
	protected function fakeFor(string $name, ?string $environment): FakeIntermediary
	{
		[$client, $accounts, $configured] = match ($name) {
			'mojeracun' => [MojEracunClient::class, MojEracunAccountProvider::class, config('hrEInvoicing.mojEracun.environment')],
			'eposlovanje' => [EPoslovanjeClient::class, EPoslovanjeAccountProvider::class, config('hrEInvoicing.ePoslovanje.environment')],
			'fake' => [null, null, null],
			default => throw new InvalidArgumentException("Unknown e-invoice gateway [{$name}]."),
		};

		$fiscalizes = $client === null || is_a($client, FiscalizesAgain::class, true);
		$lists = $client === null || is_a($client, ListsBusinessUnits::class, true);
		$class = match (true) {
			$fiscalizes && $lists => FakeBusinessUnitsIntermediary::class,
			$fiscalizes => FakeFiscalizingIntermediary::class,
			default => FakeIntermediary::class,
		};

		$intermediary = new $class(
			$this->fake(),
			$name,
			$accounts === null ? null : $this->container->make($accounts),
			$configured === 'production' ? 'production' : 'demo'
		);

		return $intermediary->onEnvironment($environment);
	}

	/**
	 * Whether the name is one of the intermediaries the package talks to.
	 */
	public static function knows(?string $name): bool
	{
		return in_array($name, self::INTERMEDIARIES, true);
	}
}
