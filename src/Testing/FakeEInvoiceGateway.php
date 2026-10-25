<?php

namespace GTCrais\HrEInvoicing\Testing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Contracts\FiscalizesAgain;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use RuntimeException;

/**
 * Answers in place of the intermediary: an identifier for every e-invoice sent, the standing a test set for it, and a
 * record of the payments reported, with the tax authority's answer to them; told to refuse the next few calls, it
 * does, and told to lose the next answer, it keeps the document and fails as a timeout would. When
 * config('hrEInvoicing.gateway') is "fake" it is the gateway, one for the whole run, and GatewayManager hands it out
 * for every intermediary and service as a FakeIntermediary, which can do what that intermediary's real client can,
 * needs an account there as the client does, and makes its calls through this fake as that intermediary on that
 * service: every call is recorded with the intermediary and the service it was meant for (`calls`), and a document is
 * found only through the intermediary and the service it was sent through, as the real ones know nothing of the
 * others' documents. Asked directly, as a test does, it stands for none and finds every document. A test resets it
 * before it starts and reads what was sent.
 */
class FakeEInvoiceGateway implements EInvoiceGateway, FiscalizesAgain
{
	/** @var list<string> */
	public array $sent = [];

	/** @var list<array{electronicId: string, paidOn: string, amount: float, intermediary: ?string, environment: ?string}> */
	public array $payments = [];

	/** The documents asked to be fiscalized again @var list<string> */
	public array $refiscalized = [];

	/** Every call with the intermediary and service it stood in for @var list<array{method: string, electronicId: ?string, intermediary: ?string, environment: ?string}> */
	public array $calls = [];

	public int $pings = 0;

	public bool $ready = true;

	/** @var array<string, array{status: DocumentStatus, fiscalization: ?FiscalizationStatus, error: ?string, payment: ?FiscalizationStatus, paymentError: ?string}> */
	public array $statuses = [];

	/** The standing a freshly sent document gets */
	public DocumentStatus $sendStatus = DocumentStatus::SENT;

	/** The tax authority's answer to a payment as it is reported, which status() then tells */
	public ?FiscalizationStatus $paymentAnswer = FiscalizationStatus::FISCALIZED;

	/** Sends whose document is kept but whose answer never comes back */
	public int $answersToLose = 0;

	/** @var list<RuntimeException> */
	protected array $failures = [];

	/** When each document was sent, by its index in sent @var list<CarbonImmutable> */
	protected array $sentAt = [];

	/** The intermediary and service each document was sent through, by its identifier @var array<string, array{intermediary: string, environment: ?string}> */
	protected array $sentThrough = [];

	/** The intermediary and service the calls stand in for now, none when the fake is asked directly */
	protected ?string $intermediary = null;

	protected ?string $environment = null;

	public function __construct()
	{
		$this->reset();
	}

	public function reset(): void
	{
		$this->sent = [];
		$this->sentAt = [];
		$this->sentThrough = [];
		$this->payments = [];
		$this->refiscalized = [];
		$this->calls = [];
		$this->pings = 0;
		$this->ready = true;
		$this->statuses = [];
		$this->sendStatus = DocumentStatus::SENT;
		$this->paymentAnswer = FiscalizationStatus::FISCALIZED;
		$this->answersToLose = 0;
		$this->failures = [];
		$this->intermediary = null;
		$this->environment = null;
	}

	/**
	 * Stand in for the named intermediary on the given service (null: any) from now on, or for none; FakeIntermediary
	 * stands in for its own around each of its calls.
	 */
	public function standingInFor(?string $intermediary, ?string $environment = null): static
	{
		$this->intermediary = $intermediary;
		$this->environment = $environment;

		return $this;
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
	 * What the intermediary will answer for a document from now on; with answersError, the standing alone, the tax
	 * authority's answers not read.
	 */
	public function setStatus(string $electronicId, DocumentStatus $status, ?FiscalizationStatus $fiscalization = null, ?string $error = null, ?FiscalizationStatus $payment = null, ?string $paymentError = null, ?string $answersError = null): void
	{
		$this->statuses[$electronicId] = ['status' => $status, 'fiscalization' => $fiscalization, 'error' => $error, 'payment' => $payment, 'paymentError' => $paymentError, 'answersError' => $answersError];
	}

	public function ready(): bool
	{
		return $this->ready;
	}

	public function send(string $xml): array
	{
		$this->record('send', null);
		$this->refuseIfAsked();

		$this->sent[] = $xml;
		$this->sentAt[] = CarbonImmutable::now();

		$electronicId = (string) (1000 + count($this->sent));

		$this->statuses[$electronicId] = ['status' => $this->sendStatus, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'answersError' => null];

		if ($this->intermediary !== null) {
			$this->sentThrough[$electronicId] = ['intermediary' => $this->intermediary, 'environment' => $this->environment];
		}

		if ($this->answersToLose > 0) {
			$this->answersToLose--;

			throw new RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds');
		}

		return ['electronicId' => $electronicId, 'status' => $this->sendStatus];
	}

	/**
	 * The newest document sent since the day before the moment whose own number is the given one, as the real
	 * intermediaries match: not a number it refers to (a storno's or a credit note's billing reference); and, when a day
	 * of issue is given, issued that day.
	 */
	public function sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array
	{
		$this->record('sentDocument', null);
		$this->refuseIfAsked();

		$from = CarbonImmutable::instance($since)->subDay();

		foreach (array_reverse($this->sent, true) as $index => $xml) {
			$electronicId = (string) (1001 + $index);
			$issued = $this->issueDateOf($xml);

			if ($this->sentAt[$index]->lt($from) || $this->numberOf($xml) !== $number || !$this->holds($electronicId)
				|| ($issueDate !== null && $issued !== null && $issued !== $issueDate)) {
				continue;
			}

			return ['electronicId' => $electronicId, 'status' => $this->statuses[$electronicId]['status'] ?? DocumentStatus::SENT];
		}

		return null;
	}

	public function status(string $electronicId): array
	{
		$this->record('status', $electronicId);
		$this->refuseIfAsked();
		$this->knowOrRefuse($electronicId);

		return $this->statuses[$electronicId] ?? ['status' => DocumentStatus::SENT, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'answersError' => null];
	}

	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->record('reportPayment', $electronicId);
		$this->refuseIfAsked();
		$this->knowOrRefuse($electronicId);

		$this->payments[] = ['electronicId' => $electronicId, 'paidOn' => $paidOn, 'amount' => $amount, 'intermediary' => $this->intermediary, 'environment' => $this->environment];

		if (isset($this->statuses[$electronicId])) {
			$this->statuses[$electronicId]['payment'] = $this->paymentAnswer;
			$this->statuses[$electronicId]['paymentError'] = null;
		}
	}

	public function fiscalizeAgain(string $electronicId): void
	{
		$this->record('fiscalizeAgain', $electronicId);
		$this->refuseIfAsked();
		$this->knowOrRefuse($electronicId);

		$this->refiscalized[] = $electronicId;

		if (isset($this->statuses[$electronicId])) {
			$this->statuses[$electronicId]['fiscalization'] = FiscalizationStatus::WAITING;
			$this->statuses[$electronicId]['error'] = null;
		}
	}

	public function ping(): void
	{
		$this->record('ping', null);
		$this->refuseIfAsked();

		$this->pings++;
	}

	/**
	 * A document's own number: its first cbc:ID outside the extensions and before any aggregate, where UBL puts it.
	 */
	protected function numberOf(string $xml): ?string
	{
		return preg_match('#<cbc:ID>([^<]*)</cbc:ID>#', $this->headOf($xml), $match) ? html_entity_decode($match[1], ENT_XML1) : null;
	}

	/**
	 * A document's own day of issue, from the same place (not a billing reference's).
	 */
	protected function issueDateOf(string $xml): ?string
	{
		return preg_match('#<cbc:IssueDate>([^<]*)</cbc:IssueDate>#', $this->headOf($xml), $match) ? $match[1] : null;
	}

	/**
	 * What a document says of itself before its first aggregate, the extensions left out.
	 */
	protected function headOf(string $xml): string
	{
		return explode('<cac:', (string) preg_replace('#<ext:UBLExtensions>.*?</ext:UBLExtensions>#s', '', $xml), 2)[0];
	}

	/**
	 * Whether the intermediary the fake stands in for now holds the document: any when it stands in for none (a test
	 * asking it directly) or for the document a test only set a standing for; else only one sent through that
	 * intermediary, on that service when both are known.
	 */
	protected function holds(string $electronicId): bool
	{
		$through = $this->sentThrough[$electronicId] ?? null;

		if ($this->intermediary === null || $through === null) {
			return true;
		}

		return $through['intermediary'] === $this->intermediary
			&& ($through['environment'] === null || $this->environment === null || $through['environment'] === $this->environment);
	}

	/**
	 * Refuse a document sent through another intermediary or service, as the real one does with an identifier it never
	 * gave out.
	 */
	protected function knowOrRefuse(string $electronicId): void
	{
		if (!$this->holds($electronicId)) {
			throw new RuntimeException('The intermediary does not know the document ' . $electronicId . '.');
		}
	}

	protected function record(string $method, ?string $electronicId): void
	{
		$this->calls[] = ['method' => $method, 'electronicId' => $electronicId, 'intermediary' => $this->intermediary, 'environment' => $this->environment];
	}

	protected function refuseIfAsked(): void
	{
		if ($this->failures !== []) {
			throw array_shift($this->failures);
		}
	}
}
