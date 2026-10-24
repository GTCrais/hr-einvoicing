<?php

namespace GTCrais\HrEInvoicing;

use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\EPoslovanje\EPoslovanjeClient;
use GTCrais\HrEInvoicing\MojEracun\MojEracunClient;
use GTCrais\HrEInvoicing\Testing\FakeEInvoiceGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Hands out the gateway of an intermediary by name, one instance of each for the whole run: "mojeracun" and
 * "eposlovanje", or the one config('hrEInvoicing.gateway') names when asked for none. When that configuration says
 * "fake", every name gets the one Testing\FakeEInvoiceGateway, so a test suite that sends through several
 * intermediaries reads everything off one fake. An app that keeps which intermediary carried each document asks for
 * that one by name when it follows the document up.
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

	public function driver(?string $name = null): EInvoiceGateway
	{
		if (config('hrEInvoicing.gateway') === 'fake') {
			return $this->fake();
		}

		$name = $name ?: (string) config('hrEInvoicing.gateway');

		return $this->gateways[$name] ??= match ($name) {
			'mojeracun' => $this->container->make(MojEracunClient::class),
			'eposlovanje' => $this->container->make(EPoslovanjeClient::class),
			'fake' => $this->fake(),
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
	 * Whether the name is one of the intermediaries the package talks to.
	 */
	public static function knows(?string $name): bool
	{
		return in_array($name, self::INTERMEDIARIES, true);
	}
}
