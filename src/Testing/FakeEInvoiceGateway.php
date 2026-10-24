<?php

namespace GTCrais\HrEInvoicing\Testing;

use DateTimeInterface;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use RuntimeException;

/**
 * Answers in place of the intermediary: an identifier for every e-invoice sent, the standing a test set for it,
 * and a record of the payments reported; told to refuse the next few calls, it does, and told to lose the next
 * answer, it keeps the document and fails as a timeout would. GatewayManager hands it out for every intermediary,
 * one for the whole run, when config('hrEInvoicing.gateway') is "fake", so a test resets it before it starts and
 * reads what was sent.
 */
class FakeEInvoiceGateway implements EInvoiceGateway
{
	/** @var list<string> */
	public array $sent = [];

	/** @var list<array{electronicId: string, paidOn: string, amount: float}> */
	public array $payments = [];

	public int $pings = 0;

	public bool $ready = true;

	/** @var array<string, array{status: DocumentStatus, fiscalization: ?FiscalizationStatus, error: ?string}> */
	public array $statuses = [];

	/** The standing a freshly sent document gets */
	public DocumentStatus $sendStatus = DocumentStatus::SENT;

	/** Sends whose document is kept but whose answer never comes back */
	public int $answersToLose = 0;

	/** @var list<RuntimeException> */
	protected array $failures = [];

	public function __construct()
	{
		$this->reset();
	}

	public function reset(): void
	{
		$this->sent = [];
		$this->payments = [];
		$this->pings = 0;
		$this->ready = true;
		$this->statuses = [];
		$this->sendStatus = DocumentStatus::SENT;
		$this->answersToLose = 0;
		$this->failures = [];
	}

	/**
	 * Refuse the next calls with the given error, the way the intermediary does when it is down or rejects a document.
	 */
	public function failNext(int $times = 1, string $error = 'cURL error 28: Connection timed out'): void
	{
		for ($i = 0; $i < $times; $i++) {
			$this->failures[] = new RuntimeException($error);
		}
	}

	/**
	 * Refuse the next call's document itself, as the intermediary does with one that breaks its rules.
	 */
	public function refuseNext(string $error = 'HTTP 400: [HR-BR-37] - Račun mora sadržavati oznaku operatera (HR-BT-4)'): void
	{
		$this->failures[] = new EInvoiceRefusedException($error);
	}

	/**
	 * Fail the next call as the intermediary does while its service is down, before it looks at the account.
	 */
	public function unavailableNext(string $error = 'HTTP 500'): void
	{
		$this->failures[] = new EInvoiceUnavailableException($error);
	}

	/**
	 * Keep the next document sent, but fail the send as a timeout would, as if the answer were lost on the way back.
	 */
	public function loseNextAnswer(): void
	{
		$this->answersToLose++;
	}

	/**
	 * What the intermediary will answer for a document from now on.
	 */
	public function setStatus(string $electronicId, DocumentStatus $status, ?FiscalizationStatus $fiscalization = null, ?string $error = null): void
	{
		$this->statuses[$electronicId] = ['status' => $status, 'fiscalization' => $fiscalization, 'error' => $error];
	}

	public function ready(): bool
	{
		return $this->ready;
	}

	public function send(string $xml): array
	{
		$this->refuseIfAsked();

		$this->sent[] = $xml;

		$electronicId = (string) (1000 + count($this->sent));

		$this->statuses[$electronicId] = ['status' => $this->sendStatus, 'fiscalization' => null, 'error' => null];

		if ($this->answersToLose > 0) {
			$this->answersToLose--;

			throw new RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds');
		}

		return ['electronicId' => $electronicId, 'status' => $this->sendStatus];
	}

	public function sentDocument(string $number, DateTimeInterface $since): ?array
	{
		$this->refuseIfAsked();

		foreach (array_reverse($this->sent, true) as $index => $xml) {
			if (str_contains($xml, '<cbc:ID>' . htmlspecialchars($number, ENT_XML1) . '</cbc:ID>')) {
				$electronicId = (string) (1001 + $index);

				return ['electronicId' => $electronicId, 'status' => $this->statuses[$electronicId]['status'] ?? DocumentStatus::SENT];
			}
		}

		return null;
	}

	public function status(string $electronicId): array
	{
		$this->refuseIfAsked();

		return $this->statuses[$electronicId] ?? ['status' => DocumentStatus::SENT, 'fiscalization' => null, 'error' => null];
	}

	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->refuseIfAsked();

		$this->payments[] = ['electronicId' => $electronicId, 'paidOn' => $paidOn, 'amount' => $amount];
	}

	public function ping(): void
	{
		$this->refuseIfAsked();

		$this->pings++;
	}

	protected function refuseIfAsked(): void
	{
		if ($this->failures !== []) {
			throw array_shift($this->failures);
		}
	}
}
