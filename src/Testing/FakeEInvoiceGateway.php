<?php

namespace GTCrais\HrEInvoicing\Testing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Contracts\FiscalizesAgain;
use GTCrais\HrEInvoicing\Contracts\ListsBusinessUnits;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\Exceptions\PaymentReportRefusedException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use RuntimeException;

/**
 * Answers in place of the intermediary: an identifier for every e-invoice sent, the standing a test set for it, and a
 * record of the payments reported, with the tax authority's answer to them and how many it holds of each document;
 * told to fail the next few calls, it does (a refusal of the document only when one is sent, the service down only when
 * the connection is tested, as the real clients throw them); told to refuse the next payment report, it refuses it at
 * once, as Moj-eRačun does, and still counts it; and told to lose the next answer, it keeps the document (or the
 * payment report) and fails as a timeout would, and can keep such a document out of the next lookups, as an
 * intermediary whose lists do not show it yet. Like the real ones, it refuses a document of a number it holds already
 * for the year, unless the one it holds was refused, an undelivered one included until a test deletes it
 * (deleteDocument(), as ePoslovanje's user deletes one in its web application); and it lists the business units a test
 * gave a company (businessUnits). When
 * config('hrEInvoicing.gateway') is "fake" it is the gateway, one for the whole run, and GatewayManager hands it out
 * for every intermediary and service as a FakeIntermediary, which can do what that intermediary's real client can,
 * needs an account there as the client does, and makes its calls through this fake as that intermediary on that
 * service: every call is recorded with the intermediary and the service it was meant for (`calls`), and a document is
 * found only through the intermediary and the service it was sent through, as the real ones know nothing of the
 * others' documents. Asked directly, as a test does, it stands for none and finds every document. A test resets it
 * before it starts and reads what was sent.
 */
class FakeEInvoiceGateway implements EInvoiceGateway, FiscalizesAgain, ListsBusinessUnits
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

	/** Each document's standing: error is its failed fiscalization's reason (or a refused or undelivered one's), rejectionReason why the buyer rejected it, deliveryRetriesUntil until when a failed delivery is tried again, accepted whether the buyer approved or paid it @var array<string, array{status: DocumentStatus, fiscalization: ?FiscalizationStatus, error: ?string, payment: ?FiscalizationStatus, paymentError: ?string, paymentReports: int, answersError: ?string, rejectionReason: ?string, deliveryRetriesUntil: ?string, accepted: bool}> */
	public array $statuses = [];

	/** The business units of each company, by its OIB, as businessUnits() lists them @var array<string, list<array{name: string, code: string}>> */
	public array $businessUnits = [];

	/** The standing a freshly sent document gets */
	public DocumentStatus $sendStatus = DocumentStatus::SENT;

	/** The tax authority's answer to a payment as it is reported, which status() then tells */
	public ?FiscalizationStatus $paymentAnswer = FiscalizationStatus::FISCALIZED;

	/** Sends whose document is kept but whose answer never comes back */
	public int $answersToLose = 0;

	/** Payment reports kept but whose answer never comes back */
	public int $paymentAnswersToLose = 0;

	/** The failures a test told it to come, each with the calls it fails (null: any) @var list<array{exception: RuntimeException, methods: ?list<string>}> */
	protected array $failures = [];

	/** The payment reports the tax authority is to refuse at once, as Moj-eRačun answers them, each with its reason @var list<string> */
	protected array $paymentRefusals = [];

	/** The lookups the next document whose answer is lost is kept out of */
	protected int $lookupsToHide = 0;

	/** The documents kept out of lookups, with how many more lookups of them they are kept out of, by identifier @var array<string, int> */
	protected array $hiddenFromLookups = [];

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
		$this->businessUnits = [];
		$this->sendStatus = DocumentStatus::SENT;
		$this->paymentAnswer = FiscalizationStatus::FISCALIZED;
		$this->answersToLose = 0;
		$this->paymentAnswersToLose = 0;
		$this->failures = [];
		$this->paymentRefusals = [];
		$this->lookupsToHide = 0;
		$this->hiddenFromLookups = [];
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
	 * Fail the next calls, whatever they are, with the given error, the way the intermediary does when it cannot be
	 * reached or answers with an error.
	 */
	public function failNext(int $times = 1, string $error = 'cURL error 28: Connection timed out'): void
	{
		for ($i = 0; $i < $times; $i++) {
			$this->failures[] = ['exception' => new RuntimeException($error), 'methods' => null];
		}
	}

	/**
	 * Refuse the next document sent, as the intermediary does with one that breaks its rules; only a send is refused so,
	 * as only the real clients' send() throws it, and a lookup or a status asked before it passes.
	 */
	public function refuseNext(string $error = 'HTTP 400: [HR-BR-37] - Račun mora sadržavati oznaku operatera (HR-BT-4)'): void
	{
		$this->failures[] = ['exception' => new EInvoiceRefusedException($error), 'methods' => ['send']];
	}

	/**
	 * Fail the next connection test (ping()) as the intermediary does while its service is down, so the account is never
	 * tried; only the real clients' ping() throws it.
	 */
	public function unavailableNext(string $error = 'HTTP 500'): void
	{
		$this->failures[] = ['exception' => new EInvoiceUnavailableException($error), 'methods' => ['ping']];
	}

	/**
	 * Keep the next document sent, but fail the send as a timeout would, as if the answer were lost on the way back.
	 */
	public function loseNextAnswer(): void
	{
		$this->answersToLose++;
	}

	/**
	 * Keep the next document whose answer is lost (loseNextAnswer()) out of the next lookups of it (sentDocument()), as
	 * an intermediary whose lists do not show a document yet minutes after it took it: a send of it again meanwhile is
	 * refused for its number, as the real ones refuse it; the lookup after those finds it.
	 */
	public function hideFromLookup(int $calls = 1): void
	{
		$this->lookupsToHide = max(0, $calls);
	}

	/**
	 * Keep the next payment report, but fail the call as a timeout would, as if the answer were lost on the way back.
	 */
	public function loseNextPaymentAnswer(): void
	{
		$this->paymentAnswersToLose++;
	}

	/**
	 * Have the tax authority refuse the next payment report at once, as Moj-eRačun answers one: reportPayment() throws
	 * the refusal ("HTTP 400: " and the reason), and the report still counts among those the intermediary holds
	 * (paymentReports), its answer failed with the reason (payment, paymentError), as Moj-eRačun's status() then reads
	 * it. A report not refused so is answered as paymentAnswer says, later in status(), as ePoslovanje answers.
	 */
	public function refusePaymentNext(string $reason = 'S006 Pristupna točka nije ovlaštena za dostavu podataka'): void
	{
		$this->paymentRefusals[] = $reason;
	}

	/**
	 * What the intermediary will answer for a document from now on; with answersError, the standing alone, the tax
	 * authority's answers not read. The error is the failed fiscalization's reason (or a refused document's); for a
	 * REJECTED standing it is why the document will not reach the buyer, unless that is given apart as rejectionReason
	 * (the buyer's note), as ePoslovanje keeps it apart from the tax authority's answer. The payment reports it holds
	 * stay as many as were reported through it, unless a number is given.
	 */
	public function setStatus(string $electronicId, DocumentStatus $status, ?FiscalizationStatus $fiscalization = null, ?string $error = null, ?FiscalizationStatus $payment = null, ?string $paymentError = null, ?string $answersError = null, ?int $paymentReports = null, ?string $rejectionReason = null, ?string $deliveryRetriesUntil = null, bool $accepted = false): void
	{
		$rejected = $status === DocumentStatus::REJECTED;
		// A REJECTED standing given no rejectionReason takes its error for it, as before the two were kept apart
		$errorIsRejection = $rejected && $rejectionReason === null;

		$this->statuses[$electronicId] = [
			'status' => $status, 'fiscalization' => $fiscalization, 'error' => $errorIsRejection ? null : $error, 'payment' => $payment, 'paymentError' => $paymentError,
			'paymentReports' => $paymentReports ?? $this->statuses[$electronicId]['paymentReports'] ?? $this->reportsOf($electronicId), 'answersError' => $answersError,
			'rejectionReason' => $rejected ? ($rejectionReason ?? $error) : null,
			'deliveryRetriesUntil' => $status === DocumentStatus::SENT ? $deliveryRetriesUntil : null, 'accepted' => $accepted,
		];
	}

	/**
	 * Delete a document the intermediary holds, as ePoslovanje's user deletes an undelivered one in its web application:
	 * it is found no more, and its number may go again.
	 */
	public function deleteDocument(string $electronicId): void
	{
		$this->statuses[$electronicId] = [...($this->statuses[$electronicId] ?? []), 'status' => DocumentStatus::REFUSED];
		$this->hiddenFromLookups[$electronicId] = PHP_INT_MAX;
	}

	public function businessUnits(string $oib): array
	{
		$this->record('businessUnits', null);
		$this->refuseIfAsked('businessUnits');

		return $this->businessUnits[$oib] ?? [];
	}

	public function ready(): bool
	{
		return $this->ready;
	}

	public function send(string $xml): array
	{
		$this->record('send', null);
		$this->refuseIfAsked('send');
		$this->refuseDuplicate($xml);

		$this->sent[] = $xml;
		$this->sentAt[] = CarbonImmutable::now();

		$electronicId = (string) (1000 + count($this->sent));

		$this->statuses[$electronicId] = ['status' => $this->sendStatus, 'fiscalization' => null, 'error' => null, 'payment' => null, 'paymentError' => null, 'paymentReports' => 0, 'answersError' => null, 'rejectionReason' => null, 'deliveryRetriesUntil' => null, 'accepted' => false];
		// An identifier given out again, after a test emptied sent, is a new document
		unset($this->hiddenFromLookups[$electronicId]);

		if ($this->intermediary !== null) {
			$this->sentThrough[$electronicId] = ['intermediary' => $this->intermediary, 'environment' => $this->environment];
		}

		if ($this->answersToLose > 0) {
			$this->answersToLose--;

			if ($this->lookupsToHide > 0) {
				$this->hiddenFromLookups[$electronicId] = $this->lookupsToHide;
				$this->lookupsToHide = 0;
			}

			throw new RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds');
		}

		return ['electronicId' => $electronicId, 'status' => $this->sendStatus];
	}

	/**
	 * The newest document sent since the day before the moment whose own number is the given one, as the real
	 * intermediaries match: not a number it refers to (a storno's or a credit note's billing reference); and, when a day
	 * of issue is given, issued that day. One kept out of lookups (hideFromLookup()) is passed over while it is.
	 */
	public function sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array
	{
		$this->record('sentDocument', null);
		$this->refuseIfAsked('sentDocument');

		$from = CarbonImmutable::instance($since)->subDay();

		foreach (array_reverse($this->sent, true) as $index => $xml) {
			$electronicId = (string) (1001 + $index);
			$issued = $this->issueDateOf($xml);

			if ($this->sentAt[$index]->lt($from) || $this->numberOf($xml) !== $number || !$this->holds($electronicId)
				|| ($issueDate !== null && $issued !== null && $issued !== $issueDate)) {
				continue;
			}

			if (($this->hiddenFromLookups[$electronicId] ?? 0) > 0) {
				$this->hiddenFromLookups[$electronicId]--;

				continue;
			}

			return ['electronicId' => $electronicId, 'status' => $this->statuses[$electronicId]['status'] ?? DocumentStatus::SENT];
		}

		return null;
	}

	/**
	 * The standing a test set, or the one the document was sent with. The reason in `error` is the rejection's for a
	 * rejected document (`rejectionReason`), else the tax authority's about its fiscalization (`fiscalizationError`)
	 * when it failed, which a rejected document answers apart. With answersError, the tax authority's answers are
	 * unknown, as the real clients leave them: fiscalization, payment, their reasons and the payment reports held.
	 */
	public function status(string $electronicId): array
	{
		$this->record('status', $electronicId);
		$this->refuseIfAsked('status');
		$this->knowOrRefuse($electronicId);

		$answer = $this->statuses[$electronicId];
		$rejectionReason = $answer['status'] === DocumentStatus::REJECTED ? ($answer['rejectionReason'] ?? null) : null;

		if ($answer['answersError'] !== null) {
			return [
				'status' => $answer['status'], 'fiscalization' => null, 'error' => $rejectionReason, 'payment' => null, 'paymentError' => null, 'paymentReports' => null, 'answersError' => $answer['answersError'],
				'rejectionReason' => $rejectionReason, 'fiscalizationError' => null, 'deliveryRetriesUntil' => $answer['deliveryRetriesUntil'] ?? null, 'accepted' => $answer['accepted'] ?? false,
			];
		}

		return [
			'status' => $answer['status'], 'fiscalization' => $answer['fiscalization'], 'error' => $rejectionReason ?? $answer['error'], 'payment' => $answer['payment'], 'paymentError' => $answer['paymentError'],
			'paymentReports' => $answer['paymentReports'], 'answersError' => null,
			'rejectionReason' => $rejectionReason, 'fiscalizationError' => $answer['fiscalization'] === FiscalizationStatus::FAILED ? $answer['error'] : null,
			'deliveryRetriesUntil' => $answer['deliveryRetriesUntil'] ?? null, 'accepted' => $answer['accepted'] ?? false,
		];
	}

	/**
	 * Takes the report and answers it as paymentAnswer says, later in status(), as ePoslovanje answers; one a test had
	 * refused (refusePaymentNext()) is refused at once, as Moj-eRačun answers, and still counts among the reports held.
	 */
	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->record('reportPayment', $electronicId);
		$this->refuseIfAsked('reportPayment');
		$this->knowOrRefuse($electronicId);

		$this->payments[] = ['electronicId' => $electronicId, 'paidOn' => $paidOn, 'amount' => $amount, 'intermediary' => $this->intermediary, 'environment' => $this->environment];
		$refusal = array_shift($this->paymentRefusals);

		if (isset($this->statuses[$electronicId])) {
			$this->statuses[$electronicId]['payment'] = $refusal === null ? $this->paymentAnswer : FiscalizationStatus::FAILED;
			$this->statuses[$electronicId]['paymentError'] = $refusal;
			$this->statuses[$electronicId]['paymentReports']++;
		}

		if ($this->paymentAnswersToLose > 0) {
			$this->paymentAnswersToLose--;

			throw new RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds');
		}

		if ($refusal !== null) {
			throw new PaymentReportRefusedException('HTTP 400: ' . $refusal);
		}
	}

	/**
	 * Asked to fiscalize again, it waits for the tax authority's answer once more, the failed fiscalization's reason
	 * cleared and a rejection's kept, as ePoslovanje keeps the buyer's note whatever a new request does; unless the
	 * document is fiscalized already: as ePoslovanje's status() reads it, a fiscalization taken stays taken whatever a
	 * later request says.
	 */
	public function fiscalizeAgain(string $electronicId): void
	{
		$this->record('fiscalizeAgain', $electronicId);
		$this->refuseIfAsked('fiscalizeAgain');
		$this->knowOrRefuse($electronicId);

		$this->refiscalized[] = $electronicId;

		if (isset($this->statuses[$electronicId]) && $this->statuses[$electronicId]['fiscalization'] !== FiscalizationStatus::FISCALIZED) {
			$this->statuses[$electronicId]['fiscalization'] = FiscalizationStatus::WAITING;
			$this->statuses[$electronicId]['error'] = null;
		}
	}

	public function ping(): void
	{
		$this->record('ping', null);
		$this->refuseIfAsked('ping');

		$this->pings++;
	}

	/**
	 * Refuse a document of a number the intermediary holds already for the year (the year of its day of issue), as both
	 * real ones refuse it ("already exists"), unless the one it holds is refused: Moj-eRačun takes the corrected document
	 * under the number of one it could not process (50). The documents held are those of the intermediary and the
	 * service the fake stands in for now, as for a lookup.
	 */
	protected function refuseDuplicate(string $xml): void
	{
		$number = $this->numberOf($xml);

		if ($number === null || $number === '') {
			return;
		}

		$year = substr((string) $this->issueDateOf($xml), 0, 4);

		foreach ($this->sent as $index => $held) {
			$electronicId = (string) (1001 + $index);

			if ($this->numberOf($held) === $number && substr((string) $this->issueDateOf($held), 0, 4) === $year && $this->holds($electronicId)
				&& ($this->statuses[$electronicId]['status'] ?? DocumentStatus::SENT) !== DocumentStatus::REFUSED) {
				throw new EInvoiceRefusedException('HTTP 400: Document with identical internal number=' . $number . ($year !== '' ? ', for issue year ' . $year : '') . ' already exists with EID=' . $electronicId . '.');
			}
		}
	}

	/**
	 * How many payments of the document were reported through the fake.
	 */
	protected function reportsOf(string $electronicId): int
	{
		return count(array_filter($this->payments, fn (array $payment) => $payment['electronicId'] === $electronicId));
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
		// Neither sent nor given a standing by the test, as the real one with an identifier it never gave out
		if (!isset($this->statuses[$electronicId]) || !$this->holds($electronicId)) {
			throw new RuntimeException('The intermediary does not know the document ' . $electronicId . '.');
		}
	}

	protected function record(string $method, ?string $electronicId): void
	{
		$this->calls[] = ['method' => $method, 'electronicId' => $electronicId, 'intermediary' => $this->intermediary, 'environment' => $this->environment];
	}

	/**
	 * Fail the call as a test told it to: the first failure waiting that this call can meet (a refusal of the document
	 * only a send, the service down only a connection test, any other failure any call), the others left waiting.
	 */
	protected function refuseIfAsked(string $method): void
	{
		// Without an account (ready false) every call is refused, as the real client refuses it
		if (!$this->ready) {
			throw new RuntimeException('The intermediary account is not set up.');
		}

		foreach ($this->failures as $index => $failure) {
			if ($failure['methods'] === null || in_array($method, $failure['methods'], true)) {
				array_splice($this->failures, $index, 1);

				throw $failure['exception'];
			}
		}
	}
}
