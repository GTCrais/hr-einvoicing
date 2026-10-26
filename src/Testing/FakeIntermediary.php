<?php

namespace GTCrais\HrEInvoicing\Testing;

use DateTimeInterface;
use GTCrais\HrEInvoicing\Concerns\ChoosesEnvironment;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use RuntimeException;

/**
 * The run's one FakeEInvoiceGateway as one intermediary on one service, the way GatewayManager hands it out for a name
 * and a service when the configuration says "fake". It can do what the real client of that name can
 * (FakeFiscalizingIntermediary where the client takes fiscalizeAgain()), it is ready and calls only with an account
 * there, from the same account provider and by the same rules as the real client (ChoosesEnvironment), its
 * production service refused outside a production installation as the real client's is, every call is recorded under
 * its name and service, and it finds only the documents sent through that intermediary on that service, as the real
 * one knows nothing of the others. The documents, their standings and what a test told the fake
 * to do are the shared fake's.
 */
class FakeIntermediary implements EInvoiceGateway
{
	use ChoosesEnvironment;

	/**
	 * @param  object|null  $accounts  the account provider of the real client; none for a name without one (the fake's
	 *                                  own), which is always set up and stands for any service it is not held to
	 * @param  string  $environment  the service the real client is configured with
	 */
	public function __construct(
		protected FakeEInvoiceGateway $fake,
		protected string $intermediary,
		protected ?object $accounts = null,
		protected string $environment = 'demo'
	) {}

	public function ready(): bool
	{
		return $this->fake->ready() && $this->account() !== null;
	}

	public function send(string $xml): array
	{
		return $this->call(fn () => $this->fake->send($xml));
	}

	public function sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array
	{
		return $this->call(fn () => $this->fake->sentDocument($number, $since, $issueDate));
	}

	public function status(string $electronicId): array
	{
		return $this->call(fn () => $this->fake->status($electronicId));
	}

	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->call(fn () => $this->fake->reportPayment($electronicId, $paidOn, $amount));
	}

	public function ping(): void
	{
		$this->call(fn () => $this->fake->ping());
	}

	/**
	 * Make the call as this intermediary on its service, refused like the real client's without an account there.
	 *
	 * @template T
	 *
	 * @param  callable(): T  $call
	 * @return T
	 */
	protected function call(callable $call): mixed
	{
		if (!$this->ready()) {
			throw new RuntimeException('The intermediary account is not set up.');
		}

		// The production service is refused outside a production installation, as the real client refuses it
		$this->serviceFor(['demo' => 'fake', 'production' => 'fake'], $this->account());

		$this->fake->standingInFor($this->intermediary, $this->service());

		try {
			return $call();
		} finally {
			$this->fake->standingInFor(null);
		}
	}

	/**
	 * The account the real client would call with; an empty one for a name without an account provider.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function account(): ?array
	{
		return $this->accounts === null ? [] : $this->accountOf($this->accounts);
	}

	/**
	 * The service this stands for: the one it is held to, else its account's, else the configured one, as the real
	 * client picks; for a name without an account provider only the one it is held to, else any.
	 */
	protected function service(): ?string
	{
		return $this->accounts === null ? $this->environmentOverride : $this->environmentOf($this->account());
	}
}
